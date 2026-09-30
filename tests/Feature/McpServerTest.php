<?php

declare(strict_types=1);

use App\Mcp\MusterMcpServer;
use App\Mcp\Tools\MonitorHistoryTool;
use App\Mcp\Tools\UnhealthyMonitorsTool;
use App\Models\AlertDelivery;
use App\Models\CheckInEvent;
use App\Models\CredentialPartition;
use App\Models\Monitor;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\CredentialStatus;
use ArtisanBuild\BuiltForCloud\Http\Middleware\AuthenticateMcp;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolEffect;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\RespectsEffectCeiling;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use ArtisanBuild\BuiltForCloud\OperatorAbility;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\Testing\McpDelegatedTools;
use ArtisanBuild\BuiltForCloud\Testing\MintedTestCredential;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('synthetic_write')]
#[IsReadOnly(false)]
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Write)]
final class SyntheticWriteTool extends Tool
{
    use AdvertisesToolClassification;
    use AdvertisesToolEffect;
    use RespectsEffectCeiling;

    public function handle(): Response
    {
        return Response::json(['written' => true]);
    }
}

final class ReadDoorWithSyntheticWriteServer extends Server
{
    /** @var array<int, class-string<Tool>> */
    protected array $tools = [
        UnhealthyMonitorsTool::class,
        MonitorHistoryTool::class,
        SyntheticWriteTool::class,
    ];
}

function musterMcpCredential(): MintedTestCredential
{
    $plaintext = 'test_'.bin2hex(random_bytes(32));
    $credential = Credential::query()->create([
        'kind' => CredentialKind::Bearer,
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'muster-mcp-test',
        'status' => CredentialStatus::Active,
        'abilities' => [OperatorAbility::McpRead->value],
        'secret_hash' => hash('sha256', $plaintext),
    ]);

    return new MintedTestCredential($credential, $plaintext);
}

/** @param array<string, mixed> $params */
function musterMcpRequest(MintedTestCredential $credential, string $method, array $params = [], string $path = '/mcp'): TestResponse
{
    return test()->postJson($path, [
        'jsonrpc' => '2.0',
        'id' => bin2hex(random_bytes(4)),
        'method' => $method,
        'params' => $params,
    ], [
        'Authorization' => $credential->bearerHeader(),
    ]);
}

/** @param array<int|string, mixed>|object $arguments */
function musterMcpToolCall(
    MintedTestCredential $credential,
    string $tool,
    array|object $arguments = [],
    string $path = '/mcp',
): TestResponse {
    return musterMcpRequest($credential, 'tools/call', [
        'name' => $tool,
        'arguments' => $arguments,
    ], $path);
}

/** @return array<string, mixed> */
function musterMcpPayload(TestResponse $response): array
{
    $response->assertOk();
    $text = $response->json('result.content.0.text');
    expect($text)->toBeString();

    $payload = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
    expect($payload)->toBeArray();

    return $payload;
}

function createMcpPartition(string $suffix): CredentialPartition
{
    return CredentialPartition::query()->create([
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'mcp-subject-'.$suffix,
        'installation_ref' => 'mcp-installation-'.$suffix,
        'application_ref' => 'mcp-application-'.$suffix,
        'audience' => 'https://mcp-'.$suffix.'.example.test',
    ]);
}

function createMcpMonitor(CredentialPartition $partition, string $name, string $status = 'missed', bool $enabled = true): Monitor
{
    $monitor = $partition->monitors()->create([
        'name' => $name,
        'cadence_seconds' => 300,
        'grace_seconds' => 30,
    ]);
    $monitor->forceFill([
        'status' => $status,
        'is_enabled' => $enabled,
        'last_check_in_at' => '2026-09-30 10:00:00',
        'next_due_at' => '2026-09-30 10:05:00',
    ])->save();

    return $monitor->refresh();
}

it('advertises only the verified delegated effect-scoped read door', function (): void {
    $response = $this->getJson('/bfc/meta')->assertOk();
    $capabilities = $response->json('capabilities');

    expect($capabilities)->toBeArray()
        ->and($capabilities)->toContain('mcp-serve', 'mcp-delegated', 'mcp-effect-scoped')
        ->and($response->json('endpoints'))->toBe(['mcp' => '/mcp']);

    $route = Route::getRoutes()->match(Request::create('/mcp', 'POST'));

    expect(resolve('router')->gatherRouteMiddleware($route))
        ->toContain(AuthenticateMcp::class.':product,read');

    $this->postJson('/mcp')->assertUnauthorized();
});

it('conforms and exposes the exact closed read tool set', function (): void {
    $expected = [MonitorHistoryTool::class, UnhealthyMonitorsTool::class];
    sort($expected);

    $conformance = McpDelegatedTools::discover(MusterMcpServer::class);

    expect($conformance['tools'])->toBe($expected)
        ->and($conformance['violations'])->toBe([]);

    foreach ($expected as $toolClass) {
        $reflection = new ReflectionClass($toolClass);
        $tool = resolve($toolClass);
        $serialized = $tool->toArray();

        expect($reflection->getTraitNames())
            ->toContain(
                AdvertisesToolClassification::class,
                AdvertisesToolEffect::class,
                RespectsEffectCeiling::class,
            )
            ->and(ToolClassification::of($tool)?->value)->toBe(Classification::Content)
            ->and(ToolEffect::of($tool)?->value)->toBe(Effect::Read)
            ->and($serialized['_meta'])->toMatchArray([
                'classification' => 'content',
                'effect' => 'read',
            ])
            ->and($serialized['inputSchema']['additionalProperties'] ?? null)->toBeFalse();
    }

    $credential = musterMcpCredential();
    $tools = musterMcpRequest($credential, 'tools/list')->assertOk()->json('result.tools');

    expect(collect($tools)->pluck('name')->sort()->values()->all())
        ->toBe(['monitor_history', 'unhealthy_monitors'])
        ->and(collect($tools)->pluck('_meta.effect')->unique()->values()->all())->toBe(['read'])
        ->and(collect($tools)->pluck('_meta.classification')->unique()->values()->all())->toBe(['content']);

    $schemas = collect($tools)->keyBy('name')->map(fn (array $tool): array => $tool['inputSchema'])->all();

    expect(array_keys($schemas['unhealthy_monitors']['properties']))->toBe(['statuses', 'cursor', 'limit'])
        ->and(array_keys($schemas['monitor_history']['properties']))->toBe(['monitor_id', 'event_types', 'cursor', 'limit'])
        ->and($schemas['unhealthy_monitors']['additionalProperties'])->toBeFalse()
        ->and($schemas['monitor_history']['additionalProperties'])->toBeFalse();
});

it('neither lists nor calls a synthetic write tool through a read door', function (): void {
    Mcp::web('/_test/mcp/read', ReadDoorWithSyntheticWriteServer::class)
        ->middleware('bfc.mcp:product,read');
    $credential = musterMcpCredential();

    $tools = musterMcpRequest($credential, 'tools/list', path: '/_test/mcp/read')
        ->assertOk()
        ->json('result.tools');

    expect(collect($tools)->pluck('name')->all())
        ->toContain('unhealthy_monitors', 'monitor_history')
        ->not->toContain('synthetic_write');

    musterMcpToolCall($credential, 'synthetic_write', path: '/_test/mcp/read')
        ->assertBadRequest()
        ->assertJsonPath('error.message', 'effect_above_ceiling');
});

it('paginates more than one hundred local unhealthy monitors without duplicate or skip', function (): void {
    $local = CredentialPartition::local();
    $foreign = createMcpPartition('unhealthy-foreign');
    $expectedIds = [];

    foreach (range(1, 105) as $index) {
        $expectedIds[] = createMcpMonitor($local, 'Local unhealthy '.$index, match ($index % 3) {
            0 => 'late',
            1 => 'missed',
            default => 'failed',
        })->id;
    }

    createMcpMonitor($local, 'Local healthy', 'healthy');
    createMcpMonitor($local, 'Local disabled', 'missed', false);
    $foreignMonitor = createMcpMonitor($foreign, 'Foreign unhealthy');
    $credential = musterMcpCredential();
    $seen = [];
    $cursor = null;

    do {
        $arguments = $cursor === null ? [] : ['cursor' => $cursor];
        $page = musterMcpPayload(musterMcpToolCall($credential, 'unhealthy_monitors', $arguments));

        expect($page['monitors'])->toHaveCount($cursor === null ? 25 : count($page['monitors']));
        array_push($seen, ...array_column($page['monitors'], 'id'));
        $cursor = $page['next_cursor'];
    } while ($cursor !== null);

    expect($seen)->toBe($expectedIds)
        ->and($seen)->toHaveCount(105)
        ->and(array_unique($seen))->toHaveCount(105)
        ->and($seen)->not->toContain($foreignMonitor->id);
});

it('paginates local monitor history and never serializes alert destinations', function (): void {
    $local = CredentialPartition::local();
    $foreign = createMcpPartition('history-foreign');
    $monitor = createMcpMonitor($local, 'History local');
    $foreignMonitor = createMcpMonitor($foreign, 'History foreign');
    $events = [];

    foreach (range(1, 105) as $index) {
        $events[] = $monitor->events()->create([
            'event_type' => $index % 2 === 0 ? CheckInEvent::TYPE_CHECK_IN : CheckInEvent::TYPE_MISSED,
            'occurred_at' => sprintf('2026-09-30 09:%02d:%02d', intdiv($index - 1, 60), ($index - 1) % 60),
            'monitor_name' => $monitor->name,
            'command' => 'php artisan reports:run',
            'display' => 'reports:run',
            'expression' => '* * * * *',
            'execution_id' => 'execution-'.$index,
            'timezone' => 'UTC',
            'exit_code' => $index % 2,
            'runtime' => $index / 10,
            'background' => false,
            'last_check_in_at' => '2026-09-30 08:59:00',
            'next_due_at' => '2026-09-30 09:00:00',
        ]);
    }

    $sentinel = 'DESTINATION-MUST-NEVER-LEAVE-'.bin2hex(random_bytes(8));
    $delivery = $events[104]->alertDeliveries()->create([
        'channel' => AlertDelivery::CHANNEL_WEBHOOK,
        'destination' => 'https://'.$sentinel.'.example.test/hook',
    ]);
    $delivery->forceFill([
        'status' => AlertDelivery::STATUS_FAILED,
        'attempt_count' => 3,
        'last_error_code' => 'connection_refused',
        'last_attempt_at' => '2026-09-30 10:00:00',
    ])->save();
    $foreignMonitor->events()->create([
        'event_type' => CheckInEvent::TYPE_MISSED,
        'occurred_at' => '2026-09-30 11:00:00',
        'monitor_name' => $foreignMonitor->name,
    ]);

    $expectedIds = collect($events)
        ->sortByDesc(fn (CheckInEvent $event): string => $event->occurred_at->format('Y-m-d H:i:s.u').str_pad((string) $event->id, 20, '0', STR_PAD_LEFT))
        ->pluck('id')
        ->values()
        ->all();
    $credential = musterMcpCredential();
    $seen = [];
    $cursor = null;
    $serialized = '';

    do {
        $arguments = ['monitor_id' => $monitor->id];
        if ($cursor !== null) {
            $arguments['cursor'] = $cursor;
        }

        $response = musterMcpToolCall($credential, 'monitor_history', $arguments);
        $serialized .= $response->getContent();
        $page = musterMcpPayload($response);
        expect($page['events'])->toHaveCount($cursor === null ? 25 : count($page['events']));
        array_push($seen, ...array_column($page['events'], 'id'));
        $cursor = $page['next_cursor'];
    } while ($cursor !== null);

    expect($seen)->toBe($expectedIds)
        ->and($seen)->toHaveCount(105)
        ->and(array_unique($seen))->toHaveCount(105)
        ->and($serialized)->not->toContain($sentinel, 'destination')
        ->and($serialized)->toContain('connection_refused', 'attempt_count', 'last_attempt_at');

    $foreignResponse = musterMcpToolCall($credential, 'monitor_history', ['monitor_id' => $foreignMonitor->id]);
    $missingResponse = musterMcpToolCall($credential, 'monitor_history', ['monitor_id' => PHP_INT_MAX]);

    expect($foreignResponse->json('result'))->toBe($missingResponse->json('result'));
});

it('rejects malformed arguments for every tool through the HTTP transport', function (): void {
    $monitor = createMcpMonitor(CredentialPartition::local(), 'Validation local');
    $credential = musterMcpCredential();
    $cases = [
        ['unhealthy_monitors', ['unknown' => true]],
        ['unhealthy_monitors', (object) ['0' => 'numeric-key']],
        ['unhealthy_monitors', ['statuses' => ['unknown']]],
        ['unhealthy_monitors', ['statuses' => ['late' => true]]],
        ['unhealthy_monitors', ['cursor' => 'not-a-cursor']],
        ['unhealthy_monitors', ['limit' => 0]],
        ['unhealthy_monitors', ['limit' => 101]],
        ['monitor_history', ['monitor_id' => $monitor->id, 'unknown' => true]],
        ['monitor_history', (object) ['0' => 'numeric-key', 'monitor_id' => $monitor->id]],
        ['monitor_history', ['monitor_id' => $monitor->id, 'event_types' => ['unknown']]],
        ['monitor_history', ['monitor_id' => $monitor->id, 'event_types' => ['missed' => true]]],
        ['monitor_history', ['monitor_id' => $monitor->id, 'cursor' => 'not-a-cursor']],
        ['monitor_history', ['monitor_id' => $monitor->id, 'limit' => 0]],
        ['monitor_history', ['monitor_id' => $monitor->id, 'limit' => 101]],
    ];

    foreach ($cases as [$tool, $arguments]) {
        $response = musterMcpToolCall($credential, $tool, $arguments);

        expect($response->getStatusCode())->toBeIn([200, 400])
            ->and($response->json('result.isError') === true || is_array($response->json('error')))->toBeTrue();
    }
});
