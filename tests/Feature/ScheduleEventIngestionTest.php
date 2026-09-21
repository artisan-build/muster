<?php

declare(strict_types=1);

use App\Models\AlertDelivery;
use App\Models\CheckInEvent;
use App\Models\CredentialPartition;
use App\Models\Monitor;
use ArtisanBuild\BuiltForCloud\Actions\DecideDeviceAuthorization;
use ArtisanBuild\BuiltForCloud\Actions\PollDeviceAuthorization;
use ArtisanBuild\BuiltForCloud\Actions\StartDeviceAuthorization;
use ArtisanBuild\BuiltForCloud\StandaloneAccess;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** @return array{secret: string, credential_id: string, user: User} */
function issueScheduleEventBearer(string $appPurpose = 'muster.check-in', ?User $user = null): array
{
    if ($user === null) {
        $user = User::query()->create([
            'name' => 'Test Created Schedule Event Owner',
            'email' => 'schedule-event-owner-'.bin2hex(random_bytes(6)).'@example.test',
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

    $start = resolve(StartDeviceAuthorization::class)(
        $request,
        $appPurpose,
        'Test Created Schedule Event Bearer',
    );
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

/** @return array<string, mixed> */
function scheduleEventPayload(string $command, array $overrides = []): array
{
    return [
        'key' => Monitor::scheduleKeyForCommand($command),
        'command' => $command,
        'display' => 'Test Created Scheduled Command',
        'expression' => '* * * * *',
        'phase' => 'started',
        'exit_code' => null,
        'runtime' => null,
        'background' => false,
        ...$overrides,
    ];
}

function signInForScheduleEvents(User $user): void
{
    test()->actingAs($user)->withSession([
        StandaloneAccess::SESSION_VERSION_KEY => $user->auth_session_version,
    ]);
}

it('auto-registers by command and updates a changed expression without changing identity', function (): void {
    $this->travelTo('2026-09-21 12:00:00 UTC');
    $issued = issueScheduleEventBearer();
    $command = 'php artisan reports:daily --tenant=test-created';
    $headers = [
        'Authorization' => 'Bearer '.$issued['secret'],
        'Sec-Fetch-Site' => 'cross-site',
    ];

    $this->withHeaders($headers)
        ->postJson(route('schedule-events.store'), scheduleEventPayload($command))
        ->assertOk();

    $monitor = Monitor::query()->sole();

    expect($monitor->schedule_key)->toBe(hash('sha256', $command))
        ->and($monitor->command)->toBe($command)
        ->and($monitor->display)->toBe('Test Created Scheduled Command')
        ->and($monitor->expression)->toBe('* * * * *')
        ->and($monitor->is_enabled)->toBeTrue()
        ->and($monitor->status)->toBe('running')
        ->and($monitor->next_due_at?->toISOString())->toBe('2026-09-21T12:01:00.000000Z')
        ->and($monitor->events()->pluck('event_type')->all())->toBe([CheckInEvent::TYPE_STARTED])
        ->and(AlertDelivery::query()->count())->toBe(0);

    $this->withHeaders($headers)
        ->postJson(route('schedule-events.store'), scheduleEventPayload($command, [
            'display' => 'Test Created Renamed Command',
            'expression' => '*/5 * * * *',
        ]))
        ->assertOk();

    expect(Monitor::query()->count())->toBe(1)
        ->and($monitor->refresh()->display)->toBe('Test Created Renamed Command')
        ->and($monitor->name)->toBe('Test Created Renamed Command')
        ->and($monitor->expression)->toBe('*/5 * * * *')
        ->and($monitor->next_due_at?->toISOString())->toBe('2026-09-21T12:05:00.000000Z');

    $before = $monitor->getAttributes();

    $this->withHeaders($headers)
        ->postJson(route('schedule-events.store'), scheduleEventPayload($command, [
            'key' => str_repeat('a', 64),
            'display' => 'Must Not Be Stored',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('key');

    expect(Monitor::query()->count())->toBe(1)
        ->and($monitor->refresh()->getAttributes())->toBe($before);
});

it('records distinct running, successful, and unclosed schedule states in the human UI', function (): void {
    $this->travelTo('2026-09-21 13:00:00 UTC');
    $issued = issueScheduleEventBearer();
    $headers = [
        'Authorization' => 'Bearer '.$issued['secret'],
        'Sec-Fetch-Site' => 'cross-site',
    ];
    $finishedCommand = 'php artisan billing:close --test-created';
    $runningCommand = 'php artisan imports:long-running --test-created';

    $this->withHeaders($headers)
        ->postJson(route('schedule-events.store'), scheduleEventPayload($finishedCommand, [
            'display' => 'Test Created Billing Close',
        ]))
        ->assertOk();
    $this->travelTo('2026-09-21 13:00:04 UTC');
    $this->withHeaders($headers)
        ->postJson(route('schedule-events.store'), scheduleEventPayload($finishedCommand, [
            'display' => 'Test Created Billing Close',
            'phase' => 'finished',
            'exit_code' => 0,
            'runtime' => 4.25,
        ]))
        ->assertOk();
    $this->withHeaders($headers)
        ->postJson(route('schedule-events.store'), scheduleEventPayload($runningCommand, [
            'display' => 'Test Created Long Import',
            'background' => true,
        ]))
        ->assertOk();

    $finished = Monitor::query()->where('schedule_key', Monitor::scheduleKeyForCommand($finishedCommand))->sole();
    $running = Monitor::query()->where('schedule_key', Monitor::scheduleKeyForCommand($runningCommand))->sole();

    expect($finished->status)->toBe('healthy')
        ->and($finished->last_exit_code)->toBe(0)
        ->and($finished->last_runtime)->toBe(4.25)
        ->and($finished->events()->orderBy('id')->pluck('event_type')->all())->toBe([
            CheckInEvent::TYPE_STARTED,
            CheckInEvent::TYPE_FINISHED,
        ])
        ->and($running->status)->toBe('running')
        ->and($running->events()->pluck('event_type')->all())->toBe([CheckInEvent::TYPE_STARTED]);

    $this->flushHeaders();
    signInForScheduleEvents($issued['user']);

    $this->get(route('monitors.show', $finished))
        ->assertOk()
        ->assertSee('data-testid="monitor-schedule-details"', false)
        ->assertSee('data-testid="monitor-activity-event-details"', false)
        ->assertSee($finishedCommand)
        ->assertSee('Test Created Billing Close')
        ->assertSee('Finished')
        ->assertSee('Exit code');

    $this->get(route('monitors.show', $running))
        ->assertOk()
        ->assertSee('data-testid="monitor-schedule-details"', false)
        ->assertSee($runningCommand)
        ->assertSee('Test Created Long Import')
        ->assertSee('Running')
        ->assertSee('Started');
});

it('accepts repeated success then records a bounded non-zero failure distinct from missed', function (): void {
    $this->travelTo('2026-09-21 14:00:00 UTC');
    $issued = issueScheduleEventBearer();
    $command = 'php artisan settlements:post --test-created';
    $headers = [
        'Authorization' => 'Bearer '.$issued['secret'],
        'Sec-Fetch-Site' => 'cross-site',
    ];
    $success = scheduleEventPayload($command, [
        'phase' => 'finished',
        'exit_code' => 0,
        'runtime' => 1.5,
    ]);

    $this->withHeaders($headers)->postJson(route('schedule-events.store'), $success)->assertOk();
    $monitor = Monitor::query()->sole();

    foreach (range(1, 98) as $minutesAgo) {
        $monitor->events()->create([
            'event_type' => CheckInEvent::TYPE_FINISHED,
            'occurred_at' => now()->subMinutes($minutesAgo),
            'command' => $command,
        ]);
    }

    $oldestId = $monitor->events()->oldest('occurred_at')->value('id');
    $this->travelTo('2026-09-21 14:01:00 UTC');
    $this->withHeaders($headers)->postJson(route('schedule-events.store'), $success)->assertOk();
    $this->travelTo('2026-09-21 14:02:00 UTC');
    $this->withHeaders($headers)
        ->postJson(route('schedule-events.store'), scheduleEventPayload($command, [
            'phase' => 'finished',
            'exit_code' => 23,
            'runtime' => 2.75,
        ]))
        ->assertOk();

    expect(Monitor::query()->count())->toBe(1)
        ->and($monitor->refresh()->status)->toBe('failed')
        ->and($monitor->last_exit_code)->toBe(23)
        ->and($monitor->events()->count())->toBe(100)
        ->and($monitor->events()->whereKey($oldestId)->exists())->toBeFalse()
        ->and($monitor->events()->where('event_type', CheckInEvent::TYPE_FINISHED)->count())->toBe(99)
        ->and($monitor->events()->where('event_type', CheckInEvent::TYPE_NON_ZERO_EXIT)->count())->toBe(1)
        ->and($monitor->events()->where('event_type', CheckInEvent::TYPE_MISSED)->count())->toBe(0)
        ->and($monitor->events()->latest('occurred_at')->orderByDesc('id')->first()?->event_type)
        ->toBe(CheckInEvent::TYPE_NON_ZERO_EXIT);
});

it('advances a skipped occurrence without creating a missed or non-zero transition', function (): void {
    $this->travelTo('2026-09-21 15:00:00 UTC');
    $issued = issueScheduleEventBearer();
    $command = 'php artisan guarded:sync --test-created';

    $this->withHeaders([
        'Authorization' => 'Bearer '.$issued['secret'],
        'Sec-Fetch-Site' => 'cross-site',
    ])->postJson(route('schedule-events.store'), scheduleEventPayload($command, [
        'phase' => 'skipped',
        'expression' => '*/5 * * * *',
    ]))->assertOk();

    $monitor = Monitor::query()->sole();

    expect($monitor->status)->toBe('healthy')
        ->and($monitor->next_due_at?->toISOString())->toBe('2026-09-21T15:05:00.000000Z')
        ->and($monitor->events()->pluck('event_type')->all())->toBe([CheckInEvent::TYPE_SKIPPED]);

    $this->travelTo('2026-09-21 15:01:00 UTC');
    $this->artisan('monitors:evaluate')
        ->expectsOutput('Evaluated monitors; 0 state transition(s) recorded.')
        ->assertSuccessful();

    expect($monitor->refresh()->status)->toBe('healthy')
        ->and($monitor->events()->where('event_type', CheckInEvent::TYPE_MISSED)->count())->toBe(0)
        ->and($monitor->events()->where('event_type', CheckInEvent::TYPE_NON_ZERO_EXIT)->count())->toBe(0);
});

it('returns an indistinguishable denial for schedule-event credential refusal classes', function (): void {
    $allowed = issueScheduleEventBearer();
    $wrongPurpose = issueScheduleEventBearer('muster.history.read', $allowed['user']);
    $wrongSubject = issueScheduleEventBearer('muster.check-in', $allowed['user']);
    $payload = scheduleEventPayload('php artisan auth:positive-control --test-created');

    $this->withHeaders([
        'Authorization' => 'Bearer '.$allowed['secret'],
        'Sec-Fetch-Site' => 'cross-site',
    ])->postJson(route('schedule-events.store'), $payload)
        ->assertOk();

    expect(Monitor::query()->count())->toBe(1);
    $this->flushHeaders();

    $responses = [
        'missing' => $this->postJson(route('schedule-events.store'), $payload),
        'invalid' => $this->withHeaders([
            'Authorization' => 'Bearer tok_'.bin2hex(random_bytes(32)),
            'Sec-Fetch-Site' => 'cross-site',
        ])->postJson(route('schedule-events.store'), $payload),
        'wrong-purpose' => $this->withHeaders([
            'Authorization' => 'Bearer '.$wrongPurpose['secret'],
            'Sec-Fetch-Site' => 'cross-site',
        ])->postJson(route('schedule-events.store'), $payload),
    ];

    $local = CredentialPartition::local();
    $other = CredentialPartition::query()->create([
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'test-created-foreign-subject',
        'installation_ref' => 'test-created-foreign-installation',
        'application_ref' => 'test-created-foreign-application',
        'audience' => 'test-created-foreign-audience',
    ]);
    DB::table('credential_partitions')->where('id', $local->id)->update(['local_key' => null]);
    DB::table('credential_partitions')->where('id', $other->id)->update(['local_key' => 'current']);
    $responses['wrong-subject'] = $this->withHeaders([
        'Authorization' => 'Bearer '.$wrongSubject['secret'],
        'Sec-Fetch-Site' => 'cross-site',
    ])->postJson(route('schedule-events.store'), $payload);

    $expectedBody = $responses['missing']->getContent();

    foreach ($responses as $response) {
        $response->assertUnauthorized();
        expect($response->getContent())->toBe($expectedBody);
    }

    expect($expectedBody)->toBe('{"message":"Unauthenticated."}')
        ->and(Monitor::query()->count())->toBe(1)
        ->and(CheckInEvent::query()->count())->toBe(1);
});

it('rejects command output and every field outside the frozen payload without mutation', function (): void {
    $issued = issueScheduleEventBearer();
    $headers = [
        'Authorization' => 'Bearer '.$issued['secret'],
        'Sec-Fetch-Site' => 'cross-site',
    ];
    $payload = scheduleEventPayload('php artisan secrets:must-not-leak --test-created');

    $this->withHeaders($headers)
        ->postJson(route('schedule-events.store'), [
            ...$payload,
            'output' => 'test-created-secret-output',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('output');

    $this->withHeaders($headers)
        ->postJson(route('schedule-events.store'), [
            ...$payload,
            'host' => 'test-created-unfrozen-field',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('host');

    expect(Monitor::query()->count())->toBe(0)
        ->and(CheckInEvent::query()->count())->toBe(0)
        ->and(Schema::hasColumn('monitors', 'output'))->toBeFalse()
        ->and(Schema::hasColumn('check_in_events', 'output'))->toBeFalse();
});
