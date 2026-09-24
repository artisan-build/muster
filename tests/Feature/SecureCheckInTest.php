<?php

declare(strict_types=1);

use App\BuiltForCloud\MusterCredentialDeclaration;
use App\Models\CheckInEvent;
use App\Models\CredentialPartition;
use App\Models\Monitor;
use ArtisanBuild\BuiltForCloud\Actions\DecideDeviceAuthorization;
use ArtisanBuild\BuiltForCloud\Actions\PollDeviceAuthorization;
use ArtisanBuild\BuiltForCloud\Actions\StartDeviceAuthorization;
use ArtisanBuild\BuiltForCloud\AppPurposeRegistry;
use ArtisanBuild\BuiltForCloud\Contracts\CredentialDeclaration;
use ArtisanBuild\BuiltForCloud\Contracts\DeclaresCredentialAuthorizationProfiles;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialAuthorizationOwnership;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\StandaloneAccess;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** @return array{secret: string, credential_id: string, user: User} */
function issueMusterBearer(string $appPurpose, ?User $user = null): array
{
    if ($user === null) {
        $user = User::query()->create([
            'name' => 'Test Created Credential Owner',
            'email' => 'credential-owner-'.bin2hex(random_bytes(6)).'@example.test',
            'password' => 'test-password',
        ]);
        $user->refresh();
    }

    test()->actingAs($user)->withSession([
        StandaloneAccess::SESSION_VERSION_KEY => $user->auth_session_version,
    ]);

    $request = Request::create('/bfc/device-authorizations', 'POST');
    $request->setUserResolver(static fn (): User => $user);
    $request->setLaravelSession(resolve('session')->driver());

    $start = resolve(StartDeviceAuthorization::class)($request, $appPurpose, 'Test Created Muster Bearer');
    resolve(DecideDeviceAuthorization::class)(
        $request,
        $start->userCode->reveal(),
        $start->browserNonce->reveal(),
        true,
    );
    $token = resolve(PollDeviceAuthorization::class)($request, $start->deviceCode->reveal());

    return [
        'secret' => $token->accessToken->reveal(),
        'credential_id' => $token->credentialId,
        'user' => $user,
    ];
}

function createTestPartition(string $label): CredentialPartition
{
    return CredentialPartition::query()->create([
        'subject_type' => SubjectType::Installation,
        'subject_ref' => $label.'-subject-'.bin2hex(random_bytes(16)),
        'installation_ref' => $label.'-installation-'.bin2hex(random_bytes(16)),
        'application_ref' => $label.'-application-'.bin2hex(random_bytes(16)),
        'audience' => $label.'-audience-'.bin2hex(random_bytes(16)),
    ]);
}

function createTestMonitor(array $attributes = []): Monitor
{
    return Monitor::query()->create([
        'name' => 'Test Created Secure Monitor',
        'cadence_seconds' => 300,
        'grace_seconds' => 30,
        ...$attributes,
    ]);
}

function signInForSecureCheckIns(): void
{
    $user = User::query()->create([
        'name' => 'Test Created History Operator',
        'email' => 'history-operator-'.bin2hex(random_bytes(6)).'@example.test',
        'password' => 'test-password',
    ]);
    $user->refresh();

    test()->actingAs($user)->withSession([
        StandaloneAccess::SESSION_VERSION_KEY => $user->auth_session_version,
    ]);
}

it('declares two installation-owned consumption profiles with exact server-owned scopes', function (): void {
    $declaration = resolve(CredentialDeclaration::class);
    $partition = CredentialPartition::local();

    expect($declaration)->toBeInstanceOf(MusterCredentialDeclaration::class)
        ->and($declaration)->toBeInstanceOf(DeclaresCredentialAuthorizationProfiles::class);

    assert($declaration instanceof DeclaresCredentialAuthorizationProfiles);
    $profiles = $declaration->credentialAuthorizationProfiles(request());

    expect($profiles)->toHaveCount(2)
        ->and(array_map(static fn ($profile): string => $profile->appPurpose, $profiles))->toBe([
            'muster.check-in',
            'muster.history.read',
        ]);

    foreach ($profiles as $profile) {
        expect(resolve(AppPurposeRegistry::class)->purpose($profile->appPurpose))->toBe(CredentialPurpose::Consumption)
            ->and($profile->ownership)->toBe(CredentialAuthorizationOwnership::Installation)
            ->and($profile->abilities)->toBe([])
            ->and($profile->scope->subject->type)->toBe($partition->subject_type)
            ->and($profile->scope->subject->ref)->toBe($partition->subject_ref)
            ->and($profile->scope->installation)->toBe($partition->installation_ref)
            ->and($profile->scope->application)->toBe($partition->application_ref)
            ->and($profile->scope->audience)->toBe($partition->audience);
    }
});

it('binds monitor creation to the server partition and keeps that relation immutable', function (): void {
    signInForSecureCheckIns();
    $local = CredentialPartition::local();
    $other = createTestPartition('test-created-untrusted-owner');

    $this->post(route('monitors.store'), [
        'name' => 'Test Created Server Bound Monitor',
        'cadence_seconds' => 300,
        'grace_seconds' => 30,
        'credential_partition_id' => $other->id,
    ])->assertRedirect();

    $monitor = Monitor::query()->sole();
    expect($monitor->credential_partition_id)->toBe($local->id)
        ->and(fn () => $monitor->forceFill(['credential_partition_id' => $other->id])->save())
        ->toThrow(LogicException::class, 'A monitor credential partition cannot be changed.');
});

it('accepts the same framework-issued bearer twice and advances the monitor atomically', function (): void {
    $this->travelTo('2026-09-21 12:00:00 UTC');
    $monitor = createTestMonitor();
    $issued = issueMusterBearer('muster.check-in');

    expect($issued['secret'])->toMatch('/^tok_[a-f0-9]{64}$/')
        ->and(Credential::query()->findOrFail($issued['credential_id'])->user_id)->toBeNull();

    $request = fn () => $this->withHeaders([
        'Authorization' => 'Bearer '.$issued['secret'],
        'Sec-Fetch-Site' => 'cross-site',
    ])->postJson(route('monitor-check-ins.store', $monitor));

    $request()->assertOk()->assertExactJson(['status' => 'ok']);
    $monitor->refresh();

    expect($monitor->status)->toBe('healthy')
        ->and($monitor->last_check_in_at?->toISOString())->toBe('2026-09-21T12:00:00.000000Z')
        ->and($monitor->next_due_at?->toISOString())->toBe('2026-09-21T12:05:00.000000Z')
        ->and($monitor->events()->where('event_type', CheckInEvent::TYPE_CHECK_IN)->count())->toBe(1);

    $this->travelTo('2026-09-21 12:10:00 UTC');
    $request()->assertOk()->assertExactJson(['status' => 'ok']);
    $monitor->refresh();

    expect($monitor->last_check_in_at?->toISOString())->toBe('2026-09-21T12:10:00.000000Z')
        ->and($monitor->next_due_at?->toISOString())->toBe('2026-09-21T12:15:00.000000Z')
        ->and($monitor->events()->where('event_type', CheckInEvent::TYPE_CHECK_IN)->count())->toBe(2);
});

it('samples the accepted timestamp after the scoped monitor lock is acquired', function (): void {
    $this->travelTo('2026-09-21 12:00:00 UTC');
    $monitor = createTestMonitor();
    $issued = issueMusterBearer('muster.check-in');
    $lockQueryCompleted = false;

    DB::listen(function (QueryExecuted $query) use (&$lockQueryCompleted): void {
        if ($lockQueryCompleted
            || ! str_contains($query->sql, 'monitors')
            || ! str_contains($query->sql, 'credential_partitions')
            || ! str_contains($query->sql, 'is_enabled')) {
            return;
        }

        $lockQueryCompleted = true;
        $this->travelTo('2026-09-21 12:02:00 UTC');
    });

    $this->withHeaders([
        'Authorization' => 'Bearer '.$issued['secret'],
        'Sec-Fetch-Site' => 'cross-site',
    ])->postJson(route('monitor-check-ins.store', $monitor))
        ->assertOk();

    $monitor->refresh();
    $event = $monitor->events()->sole();

    expect($lockQueryCompleted)->toBeTrue()
        ->and($monitor->last_check_in_at?->toISOString())->toBe('2026-09-21T12:02:00.000000Z')
        ->and($monitor->next_due_at?->toISOString())->toBe('2026-09-21T12:07:00.000000Z')
        ->and($event->event_type)->toBe(CheckInEvent::TYPE_CHECK_IN)
        ->and($event->occurred_at->toISOString())->toBe('2026-09-21T12:02:00.000000Z');
});

it('returns one indistinguishable denial for every credential refusal class', function (): void {
    $denialMonitor = createTestMonitor(['name' => 'Test Created Denial Monitor']);
    $allowedMonitor = createTestMonitor(['name' => 'Test Created Allowed Monitor']);
    $wrongPurpose = issueMusterBearer('muster.history.read');
    $expired = issueMusterBearer('muster.check-in', $wrongPurpose['user']);
    $revoked = issueMusterBearer('muster.check-in', $wrongPurpose['user']);
    $pending = issueMusterBearer('muster.check-in', $wrongPurpose['user']);
    $wrongAbility = issueMusterBearer('muster.check-in', $wrongPurpose['user']);
    $wrongScope = issueMusterBearer('muster.check-in', $wrongPurpose['user']);
    $allowed = issueMusterBearer('muster.check-in', $wrongPurpose['user']);

    $this->withHeaders([
        'Authorization' => 'Bearer '.$allowed['secret'],
        'Sec-Fetch-Site' => 'cross-site',
    ])->postJson(route('monitor-check-ins.store', $allowedMonitor))
        ->assertOk()
        ->assertExactJson(['status' => 'ok']);
    $this->flushHeaders();

    DB::table('credentials')->where('id', $expired['credential_id'])->update(['expires_at' => now()->subSecond()]);
    DB::table('credentials')->where('id', $revoked['credential_id'])->update(['revoked_at' => now()]);
    DB::table('credentials')->where('id', $pending['credential_id'])->update(['status' => 'pending']);
    DB::table('credentials')->where('id', $wrongAbility['credential_id'])->update([
        'abilities' => json_encode(['mcp:read'], JSON_THROW_ON_ERROR),
    ]);

    $post = fn (?string $secret) => $this->withHeaders(array_filter([
        'Authorization' => $secret === null ? null : 'Bearer '.$secret,
        'Sec-Fetch-Site' => 'cross-site',
    ]))->postJson(route('monitor-check-ins.store', $denialMonitor));

    $responses = [
        'missing' => $post(null),
        'unknown' => $post('tok_'.bin2hex(random_bytes(32))),
        'expired' => $post($expired['secret']),
        'revoked' => $post($revoked['secret']),
        'pending' => $post($pending['secret']),
        'wrong-purpose' => $post($wrongPurpose['secret']),
        'wrong-ability' => $post($wrongAbility['secret']),
    ];

    $local = CredentialPartition::local();
    $other = createTestPartition('test-created-wrong-scope');
    DB::table('credential_partitions')->where('id', $local->id)->update(['local_key' => null]);
    DB::table('credential_partitions')->where('id', $other->id)->update(['local_key' => 'current']);
    $responses['wrong-scope'] = $post($wrongScope['secret']);

    $expectedBody = $responses['missing']->getContent();
    foreach ($responses as $response) {
        $response->assertUnauthorized();
        expect($response->getContent())->toBe($expectedBody);
    }

    expect($expectedBody)->toBe('{"message":"Unauthenticated."}')
        ->and($denialMonitor->refresh()->status)->toBe('pending')
        ->and($denialMonitor->last_check_in_at)->toBeNull()
        ->and($denialMonitor->events()->count())->toBe(0)
        ->and($allowedMonitor->refresh()->status)->toBe('healthy')
        ->and($allowedMonitor->events()->count())->toBe(1);
});

it('refuses unknown and disabled monitors without mutation or disclosure', function (): void {
    $monitor = createTestMonitor();
    $monitor->forceFill(['is_enabled' => false])->save();
    $issued = issueMusterBearer('muster.check-in');
    $history = issueMusterBearer('muster.history.read', $issued['user']);
    $headers = [
        'Authorization' => 'Bearer '.$issued['secret'],
        'Sec-Fetch-Site' => 'cross-site',
    ];

    $unknown = $this->withHeaders($headers)->postJson(route('monitor-check-ins.store', 'not-a-monitor'));
    $disabled = $this->withHeaders($headers)->postJson(route('monitor-check-ins.store', $monitor));
    $unknownHistory = $this->withToken($history['secret'])
        ->getJson(route('monitor-check-ins.index', 'not-a-monitor'));
    $disabledHistory = $this->withToken($history['secret'])
        ->getJson(route('monitor-check-ins.index', $monitor));

    foreach ([$unknown, $disabled, $unknownHistory, $disabledHistory] as $response) {
        $response->assertUnauthorized();
    }

    expect($disabled->getContent())->toBe($unknown->getContent())
        ->and($disabledHistory->getContent())->toBe($unknownHistory->getContent())
        ->and($monitor->refresh()->status)->toBe('pending')
        ->and($monitor->last_check_in_at)->toBeNull()
        ->and(CheckInEvent::query()->count())->toBe(0);
});

it('issues no monitor query for a malformed identifier behind a valid bearer', function (): void {
    $issued = issueMusterBearer('muster.check-in');
    $monitorQueries = 0;

    DB::listen(function (QueryExecuted $query) use (&$monitorQueries): void {
        if (str_contains($query->sql, 'from "monitors"')) {
            $monitorQueries++;
        }
    });

    $post = fn (string $monitorId) => $this->withHeaders([
        'Authorization' => 'Bearer '.$issued['secret'],
        'Sec-Fetch-Site' => 'cross-site',
    ])->postJson(route('monitor-check-ins.store', $monitorId));

    $post('not-a-monitor')->assertUnauthorized();
    $post('99999999999999999999')->assertUnauthorized();

    expect($monitorQueries)->toBe(0);
});

it('keeps browser request forgery protection everywhere but the raw bearer ingestion URIs', function (): void {
    $this->app['env'] = 'production';

    $this->post(route('monitors.store'), [
        'name' => 'Test Created Csrf Probe',
        'cadence_seconds' => 600,
        'grace_seconds' => 90,
    ])->assertStatus(419);

    $this->patch(route('monitors.disable', 1))->assertStatus(419);

    $this->postJson(route('monitor-check-ins.store', 1))->assertUnauthorized();
    $this->postJson(route('schedule-events.store'))->assertUnauthorized();
});

it('isolates check-ins and history between credential subject partitions', function (): void {
    $teamAMonitor = createTestMonitor(['name' => 'Test Created Team A Monitor']);
    $teamB = createTestPartition('test-created-team-b');
    $teamBMonitor = $teamB->monitors()->create([
        'name' => 'Test Created Team B Monitor',
        'cadence_seconds' => 600,
        'grace_seconds' => 60,
    ]);
    $teamBEvent = $teamBMonitor->events()->create([
        'event_type' => CheckInEvent::TYPE_CHECK_IN,
        'occurred_at' => '2026-09-21 12:34:56',
    ]);
    $checkIn = issueMusterBearer('muster.check-in');
    $history = issueMusterBearer('muster.history.read', $checkIn['user']);

    $foreignCheckIn = $this->withHeaders([
        'Authorization' => 'Bearer '.$checkIn['secret'],
        'Sec-Fetch-Site' => 'cross-site',
    ])->postJson(route('monitor-check-ins.store', $teamBMonitor));
    $unknownCheckIn = $this->withHeaders([
        'Authorization' => 'Bearer '.$checkIn['secret'],
        'Sec-Fetch-Site' => 'cross-site',
    ])->postJson(route('monitor-check-ins.store', $teamBMonitor->id + 1000));
    $foreignHistory = $this->withToken($history['secret'])
        ->getJson(route('monitor-check-ins.index', $teamBMonitor));
    $unknownHistory = $this->withToken($history['secret'])
        ->getJson(route('monitor-check-ins.index', $teamBMonitor->id + 1000));

    foreach ([$foreignCheckIn, $unknownCheckIn, $foreignHistory, $unknownHistory] as $response) {
        $response->assertUnauthorized();
    }

    expect($foreignCheckIn->getContent())->toBe($unknownCheckIn->getContent())
        ->and($foreignHistory->getContent())->toBe($unknownHistory->getContent())
        ->and($foreignHistory->getContent())->not->toContain($teamBEvent->occurred_at->toISOString())
        ->and($teamAMonitor->refresh()->last_check_in_at)->toBeNull()
        ->and($teamBMonitor->refresh()->last_check_in_at)->toBeNull()
        ->and($teamBMonitor->events()->count())->toBe(1);
});

it('retains the newest 100 events and renders test-created history to authenticated humans', function (): void {
    $monitor = createTestMonitor(['name' => 'Test Created Retained History Monitor']);
    $this->travelTo('2026-09-21 14:00:00 UTC');

    foreach (range(0, 99) as $minutesAgo) {
        $monitor->events()->create([
            'event_type' => CheckInEvent::TYPE_CHECK_IN,
            'occurred_at' => now()->subMinutes(100 - $minutesAgo),
        ]);
    }

    $oldestId = $monitor->events()->oldest('occurred_at')->value('id');
    $checkIn = issueMusterBearer('muster.check-in');
    $history = issueMusterBearer('muster.history.read', $checkIn['user']);

    $this->withHeaders([
        'Authorization' => 'Bearer '.$checkIn['secret'],
        'Sec-Fetch-Site' => 'cross-site',
    ])->postJson(route('monitor-check-ins.store', $monitor))
        ->assertOk();

    expect($monitor->events()->count())->toBe(100)
        ->and($monitor->events()->whereKey($oldestId)->exists())->toBeFalse();

    $this->withToken($history['secret'])
        ->getJson(route('monitor-check-ins.index', $monitor))
        ->assertOk()
        ->assertJsonCount(100, 'events')
        ->assertJsonPath('events.0.type', CheckInEvent::TYPE_CHECK_IN)
        ->assertJsonPath('events.0.occurred_at', '2026-09-21T14:00:00.000000Z');

    $this->withToken($checkIn['secret'])
        ->getJson(route('monitor-check-ins.index', $monitor))
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);

    signInForSecureCheckIns();
    $this->get(route('monitors.show', $monitor))
        ->assertOk()
        ->assertSee('data-testid="monitor-activity-history"', false)
        ->assertSee('data-testid="monitor-activity-events"', false)
        ->assertSee('data-testid="monitor-activity-event"', false)
        ->assertSee('Check In')
        ->assertSee('datetime="2026-09-21T14:00:00.000000Z"', false)
        ->assertSeeInOrder([
            'datetime="2026-09-21T14:00:00.000000Z"',
            'datetime="2026-09-21T13:59:00.000000Z"',
        ], false)
        ->assertDontSee('No activity recorded yet.');
});
