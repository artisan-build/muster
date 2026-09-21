<?php

declare(strict_types=1);

use App\Console\Commands\EvaluateMonitorLateness;
use App\Models\CheckInEvent;
use App\Models\Monitor;
use ArtisanBuild\BuiltForCloud\Actions\DecideDeviceAuthorization;
use ArtisanBuild\BuiltForCloud\Actions\PollDeviceAuthorization;
use ArtisanBuild\BuiltForCloud\Actions\StartDeviceAuthorization;
use ArtisanBuild\BuiltForCloud\Commands\SystemAuthorityCommand;
use ArtisanBuild\BuiltForCloud\StandaloneAccess;
use ArtisanBuild\BuiltForCloud\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;

function createStateEngineMonitor(array $state = []): Monitor
{
    $monitor = Monitor::query()->create([
        'name' => 'Test Created State Engine Monitor',
        'cadence_seconds' => 300,
        'grace_seconds' => 30,
    ]);

    if ($state !== []) {
        $monitor->forceFill($state)->save();
    }

    return $monitor;
}

/** @return array{secret: string, user: User} */
function issueStateEngineBearer(): array
{
    $user = User::query()->create([
        'name' => 'Test Created State Engine Operator',
        'email' => 'state-engine-'.bin2hex(random_bytes(6)).'@example.test',
        'password' => 'test-password',
    ]);
    $user->refresh();

    test()->actingAs($user)->withSession([
        StandaloneAccess::SESSION_VERSION_KEY => $user->auth_session_version,
    ]);

    $request = Request::create('/bfc/device-authorizations', 'POST');
    $request->setUserResolver(static fn (): User => $user);
    $request->setLaravelSession(resolve('session')->driver());

    $start = resolve(StartDeviceAuthorization::class)(
        $request,
        'muster.check-in',
        'Test Created State Engine Bearer',
    );
    resolve(DecideDeviceAuthorization::class)(
        $request,
        $start->userCode->reveal(),
        $start->browserNonce->reveal(),
        true,
    );
    $token = resolve(PollDeviceAuthorization::class)($request, $start->deviceCode->reveal());

    return ['secret' => $token->accessToken->reveal(), 'user' => $user];
}

it('frames the lateness evaluator as system authority and schedules it every minute', function (): void {
    $event = collect(resolve(Schedule::class)->events())
        ->first(fn (Event $event): bool => $event->command !== null
            && str_contains($event->command, 'monitors:evaluate'));

    expect(is_a(EvaluateMonitorLateness::class, SystemAuthorityCommand::class, true))->toBeTrue()
        ->and($event)->not->toBeNull()
        ->and($event?->expression)->toBe('* * * * *');
});

it('keeps a healthy monitor healthy before its boundary then records one late transition', function (): void {
    $monitor = createStateEngineMonitor([
        'status' => 'healthy',
        'last_check_in_at' => '2026-09-21 12:00:00',
        'next_due_at' => '2026-09-21 12:05:00',
    ]);

    $this->travelTo('2026-09-21 12:04:59 UTC');
    $this->artisan('monitors:evaluate')->assertSuccessful();

    expect($monitor->refresh()->status)->toBe('healthy')
        ->and($monitor->events()->count())->toBe(0);

    $this->travelTo('2026-09-21 12:05:00 UTC');
    $this->artisan('monitors:evaluate')->assertSuccessful();
    $this->artisan('monitors:evaluate')->assertSuccessful();

    $event = $monitor->events()->sole();

    expect($monitor->refresh()->status)->toBe('late')
        ->and($event->event_type)->toBe(CheckInEvent::TYPE_LATE)
        ->and($event->occurred_at->toISOString())->toBe('2026-09-21T12:05:00.000000Z');
});

it('catches up through zero grace at the cadence boundary in one evaluator run', function (): void {
    $monitor = createStateEngineMonitor([
        'grace_seconds' => 0,
        'status' => 'healthy',
        'last_check_in_at' => '2026-09-21 12:00:00',
        'next_due_at' => '2026-09-21 12:05:00',
    ]);

    $this->travelTo('2026-09-21 12:05:00 UTC');
    $this->artisan('monitors:evaluate')
        ->expectsOutput('Evaluated monitors; 2 state transition(s) recorded.')
        ->assertSuccessful();

    $events = $monitor->events()->orderBy('id')->get();

    expect($monitor->refresh()->status)->toBe('missed')
        ->and($events)->toHaveCount(2)
        ->and($events->pluck('event_type')->all())->toBe([
            CheckInEvent::TYPE_LATE,
            CheckInEvent::TYPE_MISSED,
        ])
        ->and($events->pluck('occurred_at')->map->toISOString()->all())->toBe([
            '2026-09-21T12:05:00.000000Z',
            '2026-09-21T12:05:00.000000Z',
        ]);

    $this->artisan('monitors:evaluate')
        ->expectsOutput('Evaluated monitors; 0 state transition(s) recorded.')
        ->assertSuccessful();

    expect($monitor->refresh()->status)->toBe('missed')
        ->and($monitor->events()->orderBy('id')->pluck('event_type')->all())->toBe([
            CheckInEvent::TYPE_LATE,
            CheckInEvent::TYPE_MISSED,
        ]);
});

it('catches up a delayed healthy monitor through both expired thresholds in one run', function (): void {
    $monitor = createStateEngineMonitor([
        'status' => 'healthy',
        'last_check_in_at' => '2026-09-21 12:00:00',
        'next_due_at' => '2026-09-21 12:05:00',
    ]);

    $this->travelTo('2026-09-21 12:10:00 UTC');
    $this->artisan('monitors:evaluate')
        ->expectsOutput('Evaluated monitors; 2 state transition(s) recorded.')
        ->assertSuccessful();

    $events = $monitor->events()->orderBy('id')->get();

    expect($monitor->refresh()->status)->toBe('missed')
        ->and($events)->toHaveCount(2)
        ->and($events->pluck('event_type')->all())->toBe([
            CheckInEvent::TYPE_LATE,
            CheckInEvent::TYPE_MISSED,
        ])
        ->and($events->pluck('occurred_at')->map->toISOString()->all())->toBe([
            '2026-09-21T12:10:00.000000Z',
            '2026-09-21T12:10:00.000000Z',
        ]);

    $this->artisan('monitors:evaluate')
        ->expectsOutput('Evaluated monitors; 0 state transition(s) recorded.')
        ->assertSuccessful();

    expect($monitor->refresh()->status)->toBe('missed')
        ->and($monitor->events()->orderBy('id')->pluck('event_type')->all())->toBe([
            CheckInEvent::TYPE_LATE,
            CheckInEvent::TYPE_MISSED,
        ]);
});

it('keeps a late monitor late through grace then records one missed transition', function (): void {
    $monitor = createStateEngineMonitor([
        'status' => 'late',
        'last_check_in_at' => '2026-09-21 12:00:00',
        'next_due_at' => '2026-09-21 12:05:00',
    ]);
    $monitor->recordActivity([CheckInEvent::TYPE_LATE], CarbonImmutable::parse('2026-09-21 12:05:00 UTC'));

    $this->travelTo('2026-09-21 12:05:29 UTC');
    $this->artisan('monitors:evaluate')->assertSuccessful();

    expect($monitor->refresh()->status)->toBe('late')
        ->and($monitor->events()->where('event_type', CheckInEvent::TYPE_MISSED)->count())->toBe(0);

    $this->travelTo('2026-09-21 12:05:30 UTC');
    $this->artisan('monitors:evaluate')->assertSuccessful();
    $this->artisan('monitors:evaluate')->assertSuccessful();

    expect($monitor->refresh()->status)->toBe('missed')
        ->and($monitor->events()->pluck('event_type')->all())->toBe([
            CheckInEvent::TYPE_LATE,
            CheckInEvent::TYPE_MISSED,
        ])
        ->and($monitor->events()->where('event_type', CheckInEvent::TYPE_MISSED)->sole()->occurred_at->toISOString())
        ->toBe('2026-09-21T12:05:30.000000Z');
});

it('ignores disabled and never-checked-in pending monitors', function (): void {
    $disabled = createStateEngineMonitor([
        'name' => 'Test Created Disabled State Monitor',
        'is_enabled' => false,
        'status' => 'healthy',
        'last_check_in_at' => '2026-09-21 12:00:00',
        'next_due_at' => '2026-09-21 12:05:00',
    ]);
    $pending = createStateEngineMonitor([
        'name' => 'Test Created Pending State Monitor',
    ]);

    $this->travelTo('2026-09-22 12:00:00 UTC');
    $this->artisan('monitors:evaluate')->assertSuccessful();

    expect($disabled->refresh()->status)->toBe('healthy')
        ->and($disabled->events()->count())->toBe(0)
        ->and($pending->refresh()->status)->toBe('pending')
        ->and($pending->last_check_in_at)->toBeNull()
        ->and($pending->next_due_at)->toBeNull()
        ->and($pending->events()->count())->toBe(0);
});

it('drives one monitor from healthy through missed recovery and two authenticated check-ins', function (): void {
    $monitor = createStateEngineMonitor([
        'name' => 'Test Created Recovered Monitor',
        'status' => 'healthy',
        'last_check_in_at' => '2026-09-21 12:00:00',
        'next_due_at' => '2026-09-21 12:05:00',
    ]);
    $issued = issueStateEngineBearer();

    $this->travelTo('2026-09-21 12:05:00 UTC');
    $this->artisan('monitors:evaluate')->assertSuccessful();
    $this->travelTo('2026-09-21 12:05:30 UTC');
    $this->artisan('monitors:evaluate')->assertSuccessful();

    expect($monitor->refresh()->status)->toBe('missed')
        ->and($monitor->events()->orderBy('id')->pluck('event_type')->all())->toBe([
            CheckInEvent::TYPE_LATE,
            CheckInEvent::TYPE_MISSED,
        ]);

    $this->travelTo('2026-09-21 12:10:00 UTC');

    $checkIn = fn () => $this->withHeaders([
        'Authorization' => 'Bearer '.$issued['secret'],
        'Sec-Fetch-Site' => 'cross-site',
    ])->postJson(route('monitor-check-ins.store', $monitor));

    $checkIn()->assertOk()->assertExactJson(['status' => 'ok']);

    expect($monitor->refresh()->status)->toBe('healthy')
        ->and($monitor->last_check_in_at?->toISOString())->toBe('2026-09-21T12:10:00.000000Z')
        ->and($monitor->next_due_at?->toISOString())->toBe('2026-09-21T12:15:00.000000Z')
        ->and($monitor->events()->orderBy('id')->pluck('event_type')->all())->toBe([
            CheckInEvent::TYPE_LATE,
            CheckInEvent::TYPE_MISSED,
            CheckInEvent::TYPE_RECOVERED,
            CheckInEvent::TYPE_CHECK_IN,
        ]);

    $checkIn()->assertOk()->assertExactJson(['status' => 'ok']);

    expect($monitor->events()->where('event_type', CheckInEvent::TYPE_RECOVERED)->count())->toBe(1)
        ->and($monitor->events()->where('event_type', CheckInEvent::TYPE_CHECK_IN)->count())->toBe(2)
        ->and($monitor->events()
            ->latest('occurred_at')
            ->orderByDesc('id')
            ->pluck('event_type')
            ->all())->toBe([
                CheckInEvent::TYPE_CHECK_IN,
                CheckInEvent::TYPE_CHECK_IN,
                CheckInEvent::TYPE_RECOVERED,
                CheckInEvent::TYPE_MISSED,
                CheckInEvent::TYPE_LATE,
            ])
        ->and($monitor->events()
            ->whereIn('event_type', [CheckInEvent::TYPE_RECOVERED, CheckInEvent::TYPE_CHECK_IN])
            ->pluck('occurred_at')
            ->map->toISOString()
            ->unique()
            ->all())
        ->toBe(['2026-09-21T12:10:00.000000Z']);

    $this->flushHeaders();
    $this->actingAs($issued['user'])->withSession([
        StandaloneAccess::SESSION_VERSION_KEY => $issued['user']->auth_session_version,
    ]);

    $this->get(route('monitors.show', $monitor))
        ->assertOk()
        ->assertSee('data-testid="monitor-activity-history"', false)
        ->assertSee('data-testid="monitor-activity-events"', false)
        ->assertSee('data-testid="monitor-activity-event"', false)
        ->assertSee('Test Created Recovered Monitor')
        ->assertSee('Recovered')
        ->assertSee('Check In')
        ->assertSee('datetime="2026-09-21T12:10:00.000000Z"', false);
});
