<?php

declare(strict_types=1);

use App\Models\AlertDelivery;
use App\Models\CheckInEvent;
use App\Models\CredentialPartition;
use App\Models\Monitor;
use App\Models\ScheduleExecution;
use ArtisanBuild\BuiltForCloud\Actions\DecideDeviceAuthorization;
use ArtisanBuild\BuiltForCloud\Actions\PollDeviceAuthorization;
use ArtisanBuild\BuiltForCloud\Actions\StartDeviceAuthorization;
use ArtisanBuild\BuiltForCloud\StandaloneAccess;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

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
        'execution_id' => (string) Str::ulid(),
        'timezone' => null,
        ...$overrides,
    ];
}

/** @return array<string, string> */
function scheduleEventHeaders(string $secret): array
{
    return [
        'Authorization' => 'Bearer '.$secret,
        'Sec-Fetch-Site' => 'cross-site',
    ];
}

function signInForScheduleEvents(User $user): void
{
    test()->actingAs($user)->withSession([
        StandaloneAccess::SESSION_VERSION_KEY => $user->auth_session_version,
    ]);
}

it('auto-registers by command and updates schedule data without changing identity', function (): void {
    $this->travelTo('2026-09-21 12:00:00 UTC');
    $issued = issueScheduleEventBearer();
    $command = 'php artisan reports:daily --tenant=test-created';
    $headers = scheduleEventHeaders($issued['secret']);

    $this->withHeaders($headers)
        ->postJson(route('schedule-events.store'), scheduleEventPayload($command))
        ->assertOk();

    $monitor = Monitor::query()->sole();

    expect($monitor->credential_partition_id)->toBe(CredentialPartition::local()->id)
        ->and($monitor->schedule_key)->toBe(hash('sha256', $command))
        ->and($monitor->command)->toBe($command)
        ->and($monitor->display)->toBe('Test Created Scheduled Command')
        ->and($monitor->expression)->toBe('* * * * *')
        ->and($monitor->timezone)->toBe('UTC')
        ->and($monitor->is_enabled)->toBeTrue()
        ->and($monitor->status)->toBe('running')
        ->and($monitor->next_due_at?->toISOString())->toBe('2026-09-21T12:01:00.000000Z')
        ->and($monitor->events()->pluck('event_type')->all())->toBe([CheckInEvent::TYPE_STARTED])
        ->and($monitor->executions()->count())->toBe(1)
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
    $eventCount = $monitor->events()->count();
    $executionCount = $monitor->executions()->count();

    $this->withHeaders($headers)
        ->postJson(route('schedule-events.store'), scheduleEventPayload($command, [
            'key' => str_repeat('a', 64),
            'display' => 'Must Not Be Stored',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('key');

    expect(Monitor::query()->count())->toBe(1)
        ->and($monitor->refresh()->getAttributes())->toBe($before)
        ->and($monitor->events()->count())->toBe($eventCount)
        ->and($monitor->executions()->count())->toBe($executionCount);
});

it('correlates overlapping executions and keeps the unfinished invocation visibly open', function (): void {
    $this->travelTo('2026-09-21 13:00:00 UTC');
    $issued = issueScheduleEventBearer();
    $headers = scheduleEventHeaders($issued['secret']);
    $command = 'php artisan imports:overlapping --test-created';
    $executionA = '11111111-1111-4111-8111-111111111111';
    $executionB = '22222222-2222-4222-8222-222222222222';

    $this->withHeaders($headers)->postJson(route('schedule-events.store'), scheduleEventPayload($command, [
        'display' => 'Test Created Overlapping Import',
        'execution_id' => $executionA,
    ]))->assertOk();
    $this->travelTo('2026-09-21 13:01:00 UTC');
    $this->withHeaders($headers)->postJson(route('schedule-events.store'), scheduleEventPayload($command, [
        'display' => 'Test Created Overlapping Import',
        'execution_id' => $executionB,
    ]))->assertOk();
    $this->travelTo('2026-09-21 13:01:04 UTC');
    $this->withHeaders($headers)->postJson(route('schedule-events.store'), scheduleEventPayload($command, [
        'display' => 'Test Created Overlapping Import',
        'phase' => 'finished',
        'exit_code' => 0,
        'runtime' => 4.25,
        'execution_id' => $executionB,
    ]))->assertOk();

    $monitor = Monitor::query()->sole();
    $first = $monitor->executions()->where('execution_id', $executionA)->sole();
    $second = $monitor->executions()->where('execution_id', $executionB)->sole();

    expect($monitor->status)->toBe('running')
        ->and($monitor->last_exit_code)->toBe(0)
        ->and($monitor->last_runtime)->toBe(4.25)
        ->and($first->status)->toBe(ScheduleExecution::STATUS_RUNNING)
        ->and($first->completed_at)->toBeNull()
        ->and($second->status)->toBe(ScheduleExecution::STATUS_FINISHED)
        ->and($second->completed_at?->toISOString())->toBe('2026-09-21T13:01:04.000000Z')
        ->and($monitor->events()->orderBy('id')->pluck('event_type')->all())->toBe([
            CheckInEvent::TYPE_STARTED,
            CheckInEvent::TYPE_STARTED,
            CheckInEvent::TYPE_FINISHED,
        ]);

    $this->travelTo('2026-09-21 13:02:00 UTC');
    $this->withHeaders($headers)->postJson(route('schedule-events.store'), scheduleEventPayload($command, [
        'display' => 'Test Created Overlapping Import',
        'phase' => 'skipped',
        'execution_id' => null,
    ]))->assertOk();

    expect($monitor->refresh()->status)->toBe('running')
        ->and($first->refresh()->status)->toBe(ScheduleExecution::STATUS_RUNNING);

    $this->flushHeaders();
    signInForScheduleEvents($issued['user']);

    $this->get(route('monitors.show', $monitor))
        ->assertOk()
        ->assertSee('data-testid="monitor-schedule-details"', false)
        ->assertSee('data-testid="monitor-activity-event-details"', false)
        ->assertSee('data-testid="monitor-open-executions"', false)
        ->assertSee('data-testid="monitor-open-execution-list"', false)
        ->assertSee('data-testid="monitor-open-execution"', false)
        ->assertSee($command)
        ->assertSee($executionA);
});

it('makes duplicate starts and completions idempotent and rejects unknown completions without mutation', function (): void {
    $this->travelTo('2026-09-21 14:00:00 UTC');
    $issued = issueScheduleEventBearer();
    $headers = scheduleEventHeaders($issued['secret']);
    $command = 'php artisan idempotent:run --test-created';
    $executionId = '33333333-3333-4333-8333-333333333333';
    $start = scheduleEventPayload($command, ['execution_id' => $executionId]);

    $this->withHeaders($headers)->postJson(route('schedule-events.store'), $start)->assertOk();
    $monitor = Monitor::query()->sole();
    $afterStart = $monitor->getAttributes();
    $this->travelTo('2026-09-21 14:00:30 UTC');
    $this->withHeaders($headers)->postJson(route('schedule-events.store'), $start)->assertOk();

    expect($monitor->refresh()->getAttributes())->toBe($afterStart)
        ->and($monitor->executions()->count())->toBe(1)
        ->and($monitor->events()->count())->toBe(1);

    $finish = scheduleEventPayload($command, [
        'phase' => 'finished',
        'exit_code' => 0,
        'runtime' => 30.0,
        'execution_id' => $executionId,
    ]);
    $this->withHeaders($headers)->postJson(route('schedule-events.store'), $finish)->assertOk();
    $afterFinish = $monitor->refresh()->getAttributes();
    $executionAfterFinish = $monitor->executions()->sole()->getAttributes();
    $this->travelTo('2026-09-21 14:01:00 UTC');
    $this->withHeaders($headers)->postJson(route('schedule-events.store'), $finish)->assertOk();

    expect($monitor->refresh()->getAttributes())->toBe($afterFinish)
        ->and($monitor->executions()->sole()->getAttributes())->toBe($executionAfterFinish)
        ->and($monitor->events()->count())->toBe(2);

    $this->withHeaders($headers)->postJson(route('schedule-events.store'), scheduleEventPayload($command, [
        'phase' => 'finished',
        'exit_code' => 0,
        'runtime' => 1.0,
        'execution_id' => '44444444-4444-4444-8444-444444444444',
    ]))->assertUnprocessable()->assertJsonValidationErrors('execution_id');

    expect($monitor->refresh()->getAttributes())->toBe($afterFinish)
        ->and($monitor->executions()->sole()->getAttributes())->toBe($executionAfterFinish)
        ->and($monitor->events()->count())->toBe(2);
});

it('computes mutable expectations in the event timezone across DST and defaults null or absent to UTC', function (): void {
    $this->travelTo('2026-03-28 08:00:00 UTC');
    $issued = issueScheduleEventBearer();
    $headers = scheduleEventHeaders($issued['secret']);
    $command = 'php artisan timezone:daily --test-created';

    $this->withHeaders($headers)->postJson(route('schedule-events.store'), scheduleEventPayload($command, [
        'expression' => '0 9 * * *',
        'timezone' => 'Europe/Zurich',
    ]))->assertOk();

    $monitor = Monitor::query()->where('command', $command)->sole();

    expect($monitor->timezone)->toBe('Europe/Zurich')
        ->and($monitor->next_due_at?->toISOString())->toBe('2026-03-29T07:00:00.000000Z')
        ->and($monitor->events()->sole()->timezone)->toBe('Europe/Zurich')
        ->and($monitor->executions()->sole()->timezone)->toBe('Europe/Zurich');

    $this->travelTo('2026-03-29 07:01:00 UTC');
    $this->withHeaders($headers)->postJson(route('schedule-events.store'), scheduleEventPayload($command, [
        'expression' => '0 9 * * *',
        'timezone' => 'America/New_York',
    ]))->assertOk();

    expect(Monitor::query()->where('command', $command)->count())->toBe(1)
        ->and($monitor->refresh()->timezone)->toBe('America/New_York')
        ->and($monitor->next_due_at?->toISOString())->toBe('2026-03-29T13:00:00.000000Z')
        ->and($monitor->events()->oldest('id')->first()?->timezone)->toBe('Europe/Zurich')
        ->and($monitor->events()->latest('id')->first()?->timezone)->toBe('America/New_York');

    $this->travelTo('2026-03-28 08:00:00 UTC');
    $utcCommand = 'php artisan timezone:utc --test-created';
    $absentTimezone = scheduleEventPayload($utcCommand, ['expression' => '0 9 * * *']);
    unset($absentTimezone['timezone']);
    $this->withHeaders($headers)->postJson(route('schedule-events.store'), $absentTimezone)->assertOk();
    $this->withHeaders($headers)->postJson(route('schedule-events.store'), scheduleEventPayload($utcCommand, [
        'expression' => '0 9 * * *',
        'timezone' => null,
    ]))->assertOk();

    $utcMonitor = Monitor::query()->where('command', $utcCommand)->sole();

    expect($utcMonitor->timezone)->toBe('UTC')
        ->and($utcMonitor->next_due_at?->toISOString())->toBe('2026-03-28T09:00:00.000000Z')
        ->and($utcMonitor->executions()->pluck('timezone')->all())->toBe(['UTC', 'UTC']);
});

it('recovers exactly once on start while skips preserve missed and failed states', function (): void {
    $this->travelTo('2026-09-21 10:00:00 UTC');
    $issued = issueScheduleEventBearer();
    $headers = scheduleEventHeaders($issued['secret']);
    $command = 'php artisan recovery:run --test-created';
    $firstExecution = '55555555-5555-4555-8555-555555555555';

    $this->withHeaders($headers)->postJson(route('schedule-events.store'), scheduleEventPayload($command, [
        'execution_id' => $firstExecution,
    ]))->assertOk();
    $this->withHeaders($headers)->postJson(route('schedule-events.store'), scheduleEventPayload($command, [
        'phase' => 'finished',
        'exit_code' => 0,
        'runtime' => 1.0,
        'execution_id' => $firstExecution,
    ]))->assertOk();

    $monitor = Monitor::query()->sole();
    $monitor->forceFill(['status' => 'missed'])->save();
    $monitor->recordActivity([CheckInEvent::TYPE_MISSED], now());
    $recoveryExecution = '66666666-6666-4666-8666-666666666666';
    $recoveryStart = scheduleEventPayload($command, ['execution_id' => $recoveryExecution]);

    $this->withHeaders($headers)->postJson(route('schedule-events.store'), $recoveryStart)->assertOk();
    $this->withHeaders($headers)->postJson(route('schedule-events.store'), $recoveryStart)->assertOk();

    expect($monitor->refresh()->status)->toBe('running')
        ->and($monitor->events()->where('event_type', CheckInEvent::TYPE_RECOVERED)->count())->toBe(1)
        ->and($monitor->events()->where('execution_id', $recoveryExecution)->pluck('event_type')->all())->toBe([
            CheckInEvent::TYPE_RECOVERED,
            CheckInEvent::TYPE_STARTED,
        ]);

    $recoveryFinish = scheduleEventPayload($command, [
        'phase' => 'finished',
        'exit_code' => 0,
        'runtime' => 2.0,
        'execution_id' => $recoveryExecution,
    ]);
    $this->withHeaders($headers)->postJson(route('schedule-events.store'), $recoveryFinish)->assertOk();
    $this->withHeaders($headers)->postJson(route('schedule-events.store'), $recoveryFinish)->assertOk();

    $laterExecution = '67676767-6767-4676-8676-676767676767';
    $this->withHeaders($headers)->postJson(route('schedule-events.store'), scheduleEventPayload($command, [
        'execution_id' => $laterExecution,
    ]))->assertOk();
    $this->withHeaders($headers)->postJson(route('schedule-events.store'), scheduleEventPayload($command, [
        'phase' => 'finished',
        'exit_code' => 0,
        'runtime' => 1.0,
        'execution_id' => $laterExecution,
    ]))->assertOk();

    expect($monitor->events()->where('event_type', CheckInEvent::TYPE_RECOVERED)->count())->toBe(1);

    $monitor->forceFill(['status' => 'missed'])->save();
    $lastCheckIn = $monitor->last_check_in_at;
    $this->withHeaders($headers)->postJson(route('schedule-events.store'), scheduleEventPayload($command, [
        'phase' => 'skipped',
        'execution_id' => null,
    ]))->assertOk();

    expect($monitor->refresh()->status)->toBe('missed')
        ->and($monitor->last_check_in_at?->toISOString())->toBe($lastCheckIn?->toISOString())
        ->and($monitor->events()->where('event_type', CheckInEvent::TYPE_RECOVERED)->count())->toBe(1);

    $failedCommand = 'php artisan failed:then-skipped --test-created';
    $failedExecution = '77777777-7777-4777-8777-777777777777';
    $this->withHeaders($headers)->postJson(route('schedule-events.store'), scheduleEventPayload($failedCommand, [
        'execution_id' => $failedExecution,
    ]))->assertOk();
    $this->withHeaders($headers)->postJson(route('schedule-events.store'), scheduleEventPayload($failedCommand, [
        'phase' => 'finished',
        'exit_code' => 9,
        'runtime' => 3.0,
        'execution_id' => $failedExecution,
    ]))->assertOk();
    $this->withHeaders($headers)->postJson(route('schedule-events.store'), scheduleEventPayload($failedCommand, [
        'phase' => 'skipped',
        'execution_id' => null,
    ]))->assertOk();

    expect(Monitor::query()->where('command', $failedCommand)->sole()->status)->toBe('failed');
});

it('accepts two successful executions then records bounded non-zero history distinct from missed', function (): void {
    $this->travelTo('2026-09-21 14:00:00 UTC');
    $issued = issueScheduleEventBearer();
    $headers = scheduleEventHeaders($issued['secret']);
    $command = 'php artisan settlements:post --test-created';
    $executionIds = [
        '88888888-8888-4888-8888-888888888888',
        '99999999-9999-4999-8999-999999999999',
        'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    ];
    $run = function (string $executionId, int $exitCode) use ($headers, $command): void {
        $this->withHeaders($headers)->postJson(route('schedule-events.store'), scheduleEventPayload($command, [
            'execution_id' => $executionId,
        ]))->assertOk();
        $this->withHeaders($headers)->postJson(route('schedule-events.store'), scheduleEventPayload($command, [
            'phase' => 'finished',
            'exit_code' => $exitCode,
            'runtime' => 1.5,
            'execution_id' => $executionId,
        ]))->assertOk();
    };

    $run($executionIds[0], 0);
    $monitor = Monitor::query()->sole();

    foreach (range(1, 96) as $minutesAgo) {
        $monitor->events()->create([
            'event_type' => CheckInEvent::TYPE_FINISHED,
            'occurred_at' => now()->subMinutes($minutesAgo),
            'command' => $command,
        ]);
    }

    $oldestId = $monitor->events()->oldest('occurred_at')->value('id');
    $this->travelTo('2026-09-21 14:01:00 UTC');
    $run($executionIds[1], 0);
    $this->travelTo('2026-09-21 14:02:00 UTC');
    $run($executionIds[2], 23);

    expect(Monitor::query()->count())->toBe(1)
        ->and($monitor->refresh()->status)->toBe('failed')
        ->and($monitor->last_exit_code)->toBe(23)
        ->and($monitor->executions()->count())->toBe(3)
        ->and($monitor->events()->count())->toBe(100)
        ->and($monitor->events()->whereKey($oldestId)->exists())->toBeFalse()
        ->and($monitor->events()->where('event_type', CheckInEvent::TYPE_FINISHED)->count())->toBe(96)
        ->and($monitor->events()->where('event_type', CheckInEvent::TYPE_NON_ZERO_EXIT)->count())->toBe(1)
        ->and($monitor->events()->where('event_type', CheckInEvent::TYPE_MISSED)->count())->toBe(0)
        ->and($monitor->events()->latest('occurred_at')->orderByDesc('id')->first()?->event_type)
        ->toBe(CheckInEvent::TYPE_NON_ZERO_EXIT);
});

it('advances an existing skipped boundary without creating late missed or non-zero transitions', function (): void {
    $this->travelTo('2026-09-21 14:55:00 UTC');
    $issued = issueScheduleEventBearer();
    $headers = scheduleEventHeaders($issued['secret']);
    $command = 'php artisan guarded:sync --test-created';
    $executionId = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    $schedule = ['expression' => '*/5 * * * *', 'execution_id' => $executionId];

    $this->withHeaders($headers)->postJson(route('schedule-events.store'), scheduleEventPayload($command, $schedule))
        ->assertOk();
    $this->withHeaders($headers)->postJson(route('schedule-events.store'), scheduleEventPayload($command, [
        ...$schedule,
        'phase' => 'finished',
        'exit_code' => 0,
        'runtime' => 2.0,
    ]))->assertOk();

    $monitor = Monitor::query()->sole();

    expect($monitor->status)->toBe('healthy')
        ->and($monitor->next_due_at?->toISOString())->toBe('2026-09-21T15:00:00.000000Z');

    $this->travelTo('2026-09-21 15:00:00 UTC');
    $this->withHeaders($headers)->postJson(route('schedule-events.store'), scheduleEventPayload($command, [
        'phase' => 'skipped',
        'expression' => '*/5 * * * *',
        'execution_id' => null,
    ]))->assertOk();

    expect($monitor->refresh()->status)->toBe('healthy')
        ->and($monitor->next_due_at?->toISOString())->toBe('2026-09-21T15:05:00.000000Z');

    $this->travelTo('2026-09-21 15:01:00 UTC');
    $this->artisan('monitors:evaluate')
        ->expectsOutput('Evaluated monitors; 0 state transition(s) recorded.')
        ->assertSuccessful();

    expect($monitor->refresh()->status)->toBe('healthy')
        ->and($monitor->events()->orderBy('id')->pluck('event_type')->all())->toBe([
            CheckInEvent::TYPE_STARTED,
            CheckInEvent::TYPE_FINISHED,
            CheckInEvent::TYPE_SKIPPED,
        ])
        ->and($monitor->events()->whereIn('event_type', [
            CheckInEvent::TYPE_LATE,
            CheckInEvent::TYPE_MISSED,
            CheckInEvent::TYPE_NON_ZERO_EXIT,
        ])->count())->toBe(0);
});

it('returns an indistinguishable denial for schedule-event credential refusal classes', function (): void {
    $allowed = issueScheduleEventBearer();
    $wrongPurpose = issueScheduleEventBearer('muster.history.read', $allowed['user']);
    $wrongSubject = issueScheduleEventBearer('muster.check-in', $allowed['user']);
    $payload = scheduleEventPayload('php artisan auth:positive-control --test-created');

    $this->withHeaders(scheduleEventHeaders($allowed['secret']))
        ->postJson(route('schedule-events.store'), $payload)
        ->assertOk();

    expect(Monitor::query()->count())->toBe(1);
    $this->flushHeaders();

    $responses = [
        'missing' => $this->postJson(route('schedule-events.store'), $payload),
        'invalid' => $this->withHeaders(scheduleEventHeaders('tok_'.bin2hex(random_bytes(32))))
            ->postJson(route('schedule-events.store'), $payload),
        'wrong-purpose' => $this->withHeaders(scheduleEventHeaders($wrongPurpose['secret']))
            ->postJson(route('schedule-events.store'), $payload),
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
    $responses['wrong-subject'] = $this->withHeaders(scheduleEventHeaders($wrongSubject['secret']))
        ->postJson(route('schedule-events.store'), $payload);

    $expectedBody = $responses['missing']->getContent();

    foreach ($responses as $response) {
        $response->assertUnauthorized();
        expect($response->getContent())->toBe($expectedBody);
    }

    expect($expectedBody)->toBe('{"message":"Unauthenticated."}')
        ->and(Monitor::query()->count())->toBe(1)
        ->and(ScheduleExecution::query()->count())->toBe(1)
        ->and(CheckInEvent::query()->count())->toBe(1);
});

it('rejects output invalid correlation and every field outside the frozen payload without mutation', function (): void {
    $issued = issueScheduleEventBearer();
    $headers = scheduleEventHeaders($issued['secret']);
    $payload = scheduleEventPayload('php artisan secrets:must-not-leak --test-created');

    foreach ([
        ['payload' => [...$payload, 'output' => 'test-created-secret-output'], 'error' => 'output'],
        ['payload' => [...$payload, 'output' => null], 'error' => 'output'],
        ['payload' => [...$payload, 'host' => 'test-created-unfrozen-field'], 'error' => 'host'],
        ['payload' => [...$payload, 'execution_id' => null], 'error' => 'execution_id'],
        ['payload' => [...$payload, 'execution_id' => 'not-an-execution-id'], 'error' => 'execution_id'],
        ['payload' => [...$payload, 'timezone' => 'Test/Not-A-Timezone'], 'error' => 'timezone'],
    ] as $case) {
        $this->withHeaders($headers)
            ->postJson(route('schedule-events.store'), $case['payload'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors($case['error']);
    }

    expect(Monitor::query()->count())->toBe(0)
        ->and(ScheduleExecution::query()->count())->toBe(0)
        ->and(CheckInEvent::query()->count())->toBe(0)
        ->and(Schema::hasColumn('monitors', 'output'))->toBeFalse()
        ->and(Schema::hasColumn('schedule_executions', 'output'))->toBeFalse()
        ->and(Schema::hasColumn('check_in_events', 'output'))->toBeFalse();
});
