<?php

declare(strict_types=1);

use App\Console\Commands\GenerateWeeklyFailureDigest;
use App\Console\Commands\PublishWeeklyFailureDigests;
use App\Enums\AlertMode;
use App\Jobs\DeliverWeeklyFailureDigest;
use App\Mail\WeeklyFailureDigestMail;
use App\Models\CheckInEvent;
use App\Models\Monitor;
use App\Models\WeeklyDigestDelivery;
use App\Services\CurrentWeeklyDigestRecipient;
use App\Services\WeeklyDigestPublisher;
use ArtisanBuild\BuiltForCloud\Commands\SystemAuthorityCommand;
use ArtisanBuild\BuiltForCloud\Contracts\SystemAuthorityQueueEntry;
use ArtisanBuild\BuiltForCloud\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

function createWeeklyDigestUser(string $label, array $state = [], ?string $email = null): User
{
    $user = User::query()->create([
        'name' => "Test Created {$label} Digest Recipient",
        'email' => $email ?? strtolower(str_replace(' ', '-', $label)).'-'.bin2hex(random_bytes(4)).'@example.test',
        'password' => 'test-password',
    ]);

    if ($state !== []) {
        $user->forceFill($state)->save();
    }

    return $user;
}

function createWeeklyDigestMonitor(string $name, array $state = []): Monitor
{
    $monitor = Monitor::query()->create([
        'name' => $name,
        'cadence_seconds' => 300,
        'grace_seconds' => 30,
        ...$state,
    ]);

    if ($state !== []) {
        $monitor->forceFill($state)->save();
    }

    return $monitor;
}

function createWeeklyDigestEvent(
    Monitor $monitor,
    string $eventType,
    string $occurredAt,
    array $state = [],
): CheckInEvent {
    return $monitor->events()->create([
        'event_type' => $eventType,
        'occurred_at' => $occurredAt,
        'monitor_name' => $monitor->name,
        'command' => $monitor->command,
        ...$state,
    ]);
}

/**
 * @return array{week_key: string, window_start: string, window_end: string, monitor_count: int, missed: list<array{id: int, monitor_name: string, command: string|null, occurred_at: string}>, non_zero_exit: list<array{id: int, monitor_name: string, command: string|null, occurred_at: string, exit_code: int|null}>, still_missed: list<array{monitor_id: int, monitor_name: string, command: string|null, reference_at: string|null, reference_type: string}>}
 */
function weeklyDigestPayload(string $marker = 'Test Created Digest Payload'): array
{
    return [
        'week_key' => '2026-W39',
        'window_start' => '2026-09-21T00:00:00.000000Z',
        'window_end' => '2026-09-28T00:00:00.000000Z',
        'monitor_count' => 1,
        'missed' => [[
            'id' => 123,
            'monitor_name' => $marker,
            'command' => 'php artisan test-created:digest',
            'occurred_at' => '2026-09-22T12:00:00.000000Z',
        ]],
        'non_zero_exit' => [],
        'still_missed' => [],
    ];
}

function createWeeklyDigestDelivery(string $recipient = 'digest-recipient@example.test'): WeeklyDigestDelivery
{
    $user = createWeeklyDigestUser('Direct', [], $recipient);

    return WeeklyDigestDelivery::query()->create([
        'user_id' => $user->id,
        'week_key' => '2026-W39',
        'window_start' => '2026-09-21 00:00:00',
        'window_end' => '2026-09-28 00:00:00',
        'recipient' => $recipient,
        'payload' => weeklyDigestPayload(),
    ]);
}

function runWeeklyDigestDelivery(DeliverWeeklyFailureDigest $job): void
{
    $job->handle(resolve(CurrentWeeklyDigestRecipient::class));
}

it('frames digest generation and delivery as system authority and schedules Monday UTC', function (): void {
    $events = collect(resolve(Schedule::class)->events());
    $generator = $events
        ->first(fn (Event $event): bool => $event->command !== null
            && str_contains($event->command, 'digests:generate-weekly'));
    $publisher = $events
        ->first(fn (Event $event): bool => $event->command !== null
            && str_contains($event->command, 'digests:publish-weekly'));

    expect(is_a(GenerateWeeklyFailureDigest::class, SystemAuthorityCommand::class, true))->toBeTrue()
        ->and(is_a(PublishWeeklyFailureDigests::class, SystemAuthorityCommand::class, true))->toBeTrue()
        ->and(is_a(DeliverWeeklyFailureDigest::class, SystemAuthorityQueueEntry::class, true))->toBeTrue()
        ->and($generator)->not->toBeNull()
        ->and($generator?->expression)->toBe('5 0 * * 1')
        ->and($generator?->timezone)->toBe('UTC')
        ->and($publisher)->not->toBeNull()
        ->and($publisher?->expression)->toBe('* * * * *');
});

it('snapshots all and only failures in the half-open UTC week for every active human recipient', function (): void {
    Queue::fake();
    $this->travelTo('2026-09-28 00:05:00 UTC');
    $firstRecipient = createWeeklyDigestUser('First Human');
    $secondRecipient = createWeeklyDigestUser('Second Human');
    createWeeklyDigestUser('Inactive Human', ['status' => 'inactive']);
    createWeeklyDigestUser('Generated Address', ['email_is_generated' => true]);

    $unsubscribed = createWeeklyDigestMonitor('Test Created Unsubscribed Boundary Monitor', [
        'command' => 'php artisan test-created:boundary',
        'email_alert_modes' => 0,
        'webhook_alert_modes' => 0,
    ]);
    $subscribed = createWeeklyDigestMonitor('Test Created Urgent Subscription Monitor', [
        'command' => 'php artisan test-created:urgent',
        'email_alert_modes' => AlertMode::Missed->value | AlertMode::NonZeroExit->value,
        'email_alert_destination' => 'urgent@example.test',
        'webhook_alert_modes' => AlertMode::Missed->value,
        'webhook_alert_destination' => 'https://hooks.example.test/urgent',
    ]);
    $excluded = createWeeklyDigestMonitor('Test Created Excluded Events Monitor');

    $missed = createWeeklyDigestEvent(
        $unsubscribed,
        CheckInEvent::TYPE_MISSED,
        '2026-09-21 00:00:00',
    );
    $unsubscribed->forceFill(['status' => 'missed'])->save();
    $nonZero = createWeeklyDigestEvent(
        $subscribed,
        CheckInEvent::TYPE_NON_ZERO_EXIT,
        '2026-09-27 23:59:59',
        ['exit_code' => 17],
    );
    $subscribed->update(['name' => 'Test Created Renamed Current Monitor']);

    foreach ([
        CheckInEvent::TYPE_CHECK_IN,
        CheckInEvent::TYPE_LATE,
        CheckInEvent::TYPE_RECOVERED,
        CheckInEvent::TYPE_STARTED,
        CheckInEvent::TYPE_FINISHED,
        CheckInEvent::TYPE_SKIPPED,
    ] as $eventType) {
        createWeeklyDigestEvent($excluded, $eventType, '2026-09-24 12:00:00');
    }

    createWeeklyDigestEvent($excluded, CheckInEvent::TYPE_NON_ZERO_EXIT, '2026-09-20 23:59:59', ['exit_code' => 9]);
    createWeeklyDigestEvent($excluded, CheckInEvent::TYPE_MISSED, '2026-09-28 00:00:00');

    $this->artisan('digests:generate-weekly')
        ->expectsOutput('Queued 2 weekly failure digest delivery(s) for 2026-W39.')
        ->assertSuccessful();
    $this->artisan('digests:generate-weekly')
        ->expectsOutput('Queued 0 weekly failure digest delivery(s) for 2026-W39.')
        ->assertSuccessful();

    $deliveries = WeeklyDigestDelivery::query()->orderBy('user_id')->get();

    expect($deliveries)->toHaveCount(2)
        ->and($deliveries->pluck('user_id')->all())->toBe([$firstRecipient->id, $secondRecipient->id])
        ->and($deliveries->pluck('week_key')->unique()->all())->toBe(['2026-W39'])
        ->and($deliveries->first()?->window_start->toISOString())->toBe('2026-09-21T00:00:00.000000Z')
        ->and($deliveries->first()?->window_end->toISOString())->toBe('2026-09-28T00:00:00.000000Z');

    foreach ($deliveries as $delivery) {
        expect($delivery->payload['monitor_count'])->toBe(3)
            ->and(array_column($delivery->payload['missed'], 'id'))->toBe([$missed->id])
            ->and(array_column($delivery->payload['non_zero_exit'], 'id'))->toBe([$nonZero->id])
            ->and($delivery->payload['still_missed'])->toBe([])
            ->and($delivery->payload['non_zero_exit'][0]['monitor_name'])->toBe('Test Created Urgent Subscription Monitor')
            ->and($delivery->payload['non_zero_exit'][0]['exit_code'])->toBe(17);
    }

    Queue::assertPushed(DeliverWeeklyFailureDigest::class, 2);
});

it('retains each production-recorded weekly failure beyond the 100-event history cap', function (
    string $failureType,
    string $payloadKey,
    ?int $exitCode,
): void {
    Queue::fake();
    $this->travelTo('2026-09-27 12:00:00 UTC');
    createWeeklyDigestUser("Retention {$failureType}");
    $monitor = createWeeklyDigestMonitor("Test Created Retained {$failureType} Monitor", [
        'command' => "php artisan test-created:retained-{$failureType}",
        'email_alert_modes' => 0,
        'webhook_alert_modes' => 0,
    ]);
    $attributes = [
        'command' => $monitor->command,
        'exit_code' => $exitCode,
    ];

    $monitor->recordActivity(
        [$failureType],
        CarbonImmutable::parse('2026-09-22 10:00:00 UTC'),
        $attributes,
    );
    $monitor->recordActivity(
        array_fill(0, 101, CheckInEvent::TYPE_CHECK_IN),
        CarbonImmutable::parse('2026-09-26 10:00:00 UTC'),
    );

    expect($monitor->events()->where('event_type', $failureType)->count())->toBe(1)
        ->and($monitor->events()->count())->toBe(101);

    $this->travelTo('2026-09-28 00:05:00 UTC');
    $this->artisan('digests:generate-weekly')->assertSuccessful();
    $payload = WeeklyDigestDelivery::query()->sole()->payload;

    expect($payload)->not->toBeNull()
        ->and($payload[$payloadKey])->toHaveCount(1)
        ->and($payload[$payloadKey][0]['monitor_name'])->toBe("Test Created Retained {$failureType} Monitor");
})->with([
    'missed' => [CheckInEvent::TYPE_MISSED, 'missed', null],
    'non-zero exit' => [CheckInEvent::TYPE_NON_ZERO_EXIT, 'non_zero_exit', 19],
]);

it('keeps distinct non-zero executions as distinct digest rows', function (): void {
    Queue::fake();
    $this->travelTo('2026-09-28 00:05:00 UTC');
    createWeeklyDigestUser('Distinct Exits');
    $monitor = createWeeklyDigestMonitor('Test Created Repeated Exit Monitor', [
        'command' => 'php artisan test-created:repeated-exit',
    ]);

    $first = createWeeklyDigestEvent(
        $monitor,
        CheckInEvent::TYPE_NON_ZERO_EXIT,
        '2026-09-23 10:00:00',
        ['exit_code' => 7],
    );
    $second = createWeeklyDigestEvent(
        $monitor,
        CheckInEvent::TYPE_NON_ZERO_EXIT,
        '2026-09-23 10:05:00',
        ['exit_code' => 7],
    );

    $this->artisan('digests:generate-weekly')->assertSuccessful();

    expect(array_column(WeeklyDigestDelivery::query()->sole()->payload['non_zero_exit'], 'id'))
        ->toBe([$first->id, $second->id]);
});

it('reports an evaluator-marked monitor that stays missed throughout the following week', function (): void {
    Queue::fake();
    createWeeklyDigestUser('Still Missed');
    $monitor = createWeeklyDigestMonitor('Test Created Persistently Missed Monitor', [
        'command' => 'php artisan test-created:persistently-missed',
        'status' => 'healthy',
        'last_check_in_at' => '2026-09-20 11:55:00',
        'next_due_at' => '2026-09-20 12:05:00',
        'grace_seconds' => 60,
    ]);

    $this->travelTo('2026-09-20 12:06:00 UTC');
    $this->artisan('monitors:evaluate')->assertSuccessful();

    expect($monitor->refresh()->status)->toBe('missed')
        ->and($monitor->events()->where('event_type', CheckInEvent::TYPE_MISSED)->count())->toBe(1);

    $this->travelTo('2026-09-27 23:59:00 UTC');
    $this->artisan('monitors:evaluate')->assertSuccessful();
    expect($monitor->events()->where('event_type', CheckInEvent::TYPE_MISSED)->count())->toBe(1);

    $this->travelTo('2026-09-28 00:05:00 UTC');
    $this->artisan('digests:generate-weekly')->assertSuccessful();
    $payload = WeeklyDigestDelivery::query()->sole()->payload;

    expect($payload['missed'])->toBe([])
        ->and($payload['non_zero_exit'])->toBe([])
        ->and($payload['still_missed'])->toHaveCount(1)
        ->and($payload['still_missed'][0])->toMatchArray([
            'monitor_id' => $monitor->id,
            'monitor_name' => 'Test Created Persistently Missed Monitor',
            'command' => 'php artisan test-created:persistently-missed',
            'reference_type' => 'missed_at',
        ])
        ->and($payload['still_missed'][0]['reference_at'])->toBe('2026-09-20T12:06:00.000000Z');
});

it('materializes every recipient before publication and recovers a failed publication on rerun', function (): void {
    $this->travelTo('2026-09-28 00:05:00 UTC');
    createWeeklyDigestUser('Publication First');
    createWeeklyDigestUser('Publication Second');
    createWeeklyDigestMonitor('Test Created Publication Recovery Monitor');
    $dispatches = 0;
    $firstDispatcher = Mockery::mock(Dispatcher::class);
    $firstDispatcher->shouldReceive('dispatch')->twice()->andReturnUsing(
        function () use (&$dispatches): null {
            $dispatches++;

            if ($dispatches === 1) {
                throw new RuntimeException('Test Created Queue Secret Detail');
            }

            return null;
        },
    );
    app()->instance(Dispatcher::class, $firstDispatcher);

    $this->artisan('digests:generate-weekly')
        ->expectsOutput('Queued 1 weekly failure digest delivery(s) for 2026-W39.')
        ->assertSuccessful();

    $deliveries = WeeklyDigestDelivery::query()->orderBy('id')->get();

    expect($deliveries)->toHaveCount(2)
        ->and($deliveries[0]->published_at)->toBeNull()
        ->and($deliveries[0]->publication_claim_id)->toBeNull()
        ->and($deliveries[1]->published_at)->not->toBeNull();

    $recoveredId = null;
    $recoveryDispatcher = Mockery::mock(Dispatcher::class);
    $recoveryDispatcher->shouldReceive('dispatch')->once()->andReturnUsing(
        function (DeliverWeeklyFailureDigest $job) use (&$recoveredId): null {
            $recoveredId = $job->deliveryId;

            return null;
        },
    );
    app()->instance(Dispatcher::class, $recoveryDispatcher);

    $this->artisan('digests:generate-weekly')
        ->expectsOutput('Queued 1 weekly failure digest delivery(s) for 2026-W39.')
        ->assertSuccessful();

    expect($recoveredId)->toBe($deliveries[0]->id)
        ->and($deliveries[0]->refresh()->published_at)->not->toBeNull();
});

it('reclaims stale publication claims without stealing live claims or republishing completed claims', function (): void {
    $live = createWeeklyDigestDelivery('live-publication@example.test');
    $stale = createWeeklyDigestDelivery('stale-publication@example.test');
    $live->forceFill([
        'publication_claim_id' => '3a793bea-3346-43f0-8254-e183e7ec7050',
        'publication_claimed_at' => now(),
    ])->save();
    $stale->forceFill([
        'publication_claim_id' => 'a4a0c250-f7fe-4ff7-b980-19350e659e01',
        'publication_claimed_at' => now()->subMinutes(10),
    ])->save();

    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')->once()->withArgs(
        fn (DeliverWeeklyFailureDigest $job): bool => $job->deliveryId === $stale->id,
    )->andReturn(null);
    $publisher = new WeeklyDigestPublisher($dispatcher);

    expect($publisher->publishEligible())->toBe(1)
        ->and($publisher->publishEligible())->toBe(0)
        ->and($live->refresh()->published_at)->toBeNull()
        ->and($live->publication_claim_id)->not->toBeNull()
        ->and($stale->refresh()->published_at)->not->toBeNull()
        ->and($stale->publication_claim_id)->toBeNull();
});

it('does not queue a digest when no monitored commands exist', function (): void {
    Queue::fake();
    $this->travelTo('2026-09-28 00:05:00 UTC');
    createWeeklyDigestUser('No Monitors');

    $this->artisan('digests:generate-weekly')
        ->expectsOutput('No monitored commands exist; no weekly digest was queued.')
        ->assertSuccessful();

    expect(WeeklyDigestDelivery::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('queues an all-clear digest for every active recipient when monitored commands had a clean week', function (): void {
    Queue::fake();
    $this->travelTo('2026-09-28 00:05:00 UTC');
    $firstRecipient = createWeeklyDigestUser('Healthy Week First');
    $secondRecipient = createWeeklyDigestUser('Healthy Week Second');
    $monitor = createWeeklyDigestMonitor('Test Created Healthy Week Monitor');
    createWeeklyDigestEvent($monitor, CheckInEvent::TYPE_LATE, '2026-09-24 12:00:00');
    createWeeklyDigestEvent($monitor, CheckInEvent::TYPE_FINISHED, '2026-09-24 12:01:00');

    $this->artisan('digests:generate-weekly')
        ->expectsOutput('Queued 2 weekly failure digest delivery(s) for 2026-W39.')
        ->assertSuccessful();
    $this->artisan('digests:generate-weekly')
        ->expectsOutput('Queued 0 weekly failure digest delivery(s) for 2026-W39.')
        ->assertSuccessful();

    $deliveries = WeeklyDigestDelivery::query()->orderBy('user_id')->get();

    expect($deliveries)->toHaveCount(2)
        ->and($deliveries->pluck('user_id')->all())->toBe([$firstRecipient->id, $secondRecipient->id]);

    foreach ($deliveries as $delivery) {
        expect($delivery->payload['monitor_count'])->toBe(1)
            ->and($delivery->payload['missed'])->toBe([])
            ->and($delivery->payload['non_zero_exit'])->toBe([])
            ->and($delivery->payload['still_missed'])->toBe([]);
    }

    Queue::assertPushed(DeliverWeeklyFailureDigest::class, 2);
});

it('enforces one opaque encrypted delivery identity per account recipient and week', function (): void {
    config(['queue.default' => 'database']);
    $recipient = 'opaque-digest-recipient@example.test';
    $marker = 'Test Created Secret Digest Monitor';
    $delivery = createWeeklyDigestDelivery($recipient);
    $delivery->update(['payload' => weeklyDigestPayload($marker)]);
    $raw = DB::table('weekly_digest_deliveries')->where('id', $delivery->id)->first();

    expect(fn () => DB::transaction(fn () => DB::table('weekly_digest_deliveries')->insert([
        'user_id' => $delivery->user_id,
        'week_key' => $delivery->week_key,
        'window_start' => $delivery->window_start,
        'window_end' => $delivery->window_end,
        'recipient' => (string) $raw?->recipient,
        'payload' => (string) $raw?->payload,
        'created_at' => now(),
        'updated_at' => now(),
    ])))->toThrow(UniqueConstraintViolationException::class);

    $serialized = serialize(new DeliverWeeklyFailureDigest($delivery->id));
    $queue = Queue::connection('database');

    if (! $queue instanceof DatabaseQueue) {
        throw new LogicException('The database queue connection was not resolved.');
    }

    $queue->push(new DeliverWeeklyFailureDigest($delivery->id));
    $queuePayload = (string) DB::table('jobs')->value('payload');

    expect($serialized.$queuePayload)->toContain((string) $delivery->id)
        ->not->toContain($recipient)
        ->not->toContain($marker)
        ->not->toContain('test-created:digest')
        ->and((string) $raw?->recipient)->not->toContain($recipient)
        ->and((string) DB::table('weekly_digest_deliveries')->where('id', $delivery->id)->value('payload'))
        ->not->toContain($marker);
});

it('renders escaped HTML and text structures then marks real transport success exactly once', function (): void {
    config(['mail.default' => 'smtp']);
    Mail::fake();
    $recipient = 'rendered-digest@example.test';
    $maliciousName = '<script>Test Created Digest Monitor</script>';
    $maliciousCommand = 'php artisan test-created:run --value="<unsafe>"';
    $delivery = createWeeklyDigestDelivery($recipient);
    $payload = weeklyDigestPayload($maliciousName);
    $payload['missed'][0]['command'] = $maliciousCommand;
    $payload['non_zero_exit'] = [[
        'id' => 124,
        'monitor_name' => 'Test Created Non-Zero Monitor',
        'command' => 'php artisan test-created:fail',
        'occurred_at' => '2026-09-27T23:59:59.000000Z',
        'exit_code' => 42,
    ]];
    $payload['still_missed'] = [[
        'monitor_id' => 125,
        'monitor_name' => 'Test Created Still Missed Monitor',
        'command' => 'php artisan test-created:still-missed',
        'reference_at' => '2026-09-14T10:00:00.000000Z',
        'reference_type' => 'missed_at',
    ]];
    $delivery->update(['payload' => $payload]);
    $job = new DeliverWeeklyFailureDigest($delivery->id);

    runWeeklyDigestDelivery($job);
    runWeeklyDigestDelivery($job);

    Mail::assertSent(WeeklyFailureDigestMail::class, 1);
    Mail::assertSent(WeeklyFailureDigestMail::class, function (WeeklyFailureDigestMail $mail) use (
        $maliciousCommand,
        $maliciousName,
        $recipient,
    ): bool {
        expect($mail->hasTo($recipient))->toBeTrue()
            ->and($mail->envelope()->subject)->toBe('Muster weekly failure digest: 2026-W39');

        $mail->assertSeeInHtml('data-testid="weekly-failure-digest"', false)
            ->assertSeeInHtml('data-testid="weekly-failure-digest-context"', false)
            ->assertSeeInHtml('data-testid="weekly-failure-digest-missed"', false)
            ->assertSeeInHtml('data-testid="weekly-failure-digest-still-missed"', false)
            ->assertSeeInHtml('data-testid="weekly-failure-digest-non-zero-exit"', false)
            ->assertSeeInHtml('data-testid="weekly-failure-digest-row"', false)
            ->assertSeeInHtml('data-testid="weekly-failure-digest-monitor-count"', false)
            ->assertSeeInHtml('Monitored commands: 1')
            ->assertSeeInHtml('data-testid="weekly-failure-digest-urgent-alerts"', false)
            ->assertSeeInHtml('data-testid="weekly-failure-digest-monitor-configuration"', false)
            ->assertSeeInHtml('href="'.route('monitors.index').'"', false)
            ->assertSeeInHtml($recipient)
            ->assertSeeInHtml('Week: 2026-W39')
            ->assertSeeInHtml($maliciousName)
            ->assertSeeInHtml($maliciousCommand)
            ->assertDontSeeInHtml('<script>', false)
            ->assertDontSeeInHtml('<unsafe>', false)
            ->assertDontSeeInHtml('urgent@example.test')
            ->assertDontSeeInHtml('https://hooks.example.test/urgent')
            ->assertSeeInHtml('Test Created Non-Zero Monitor')
            ->assertSeeInHtml('2026-09-27T23:59:59.000000Z')
            ->assertSeeInHtml('Test Created Still Missed Monitor')
            ->assertSeeInHtml('Missed since: 2026-09-14T10:00:00.000000Z')
            ->assertSeeInHtml("Here's what you just got: non-zero exits are now included in your weekly failure digest.", false)
            ->assertSeeInText('[weekly-failure-digest]')
            ->assertSeeInText('[weekly-failure-digest-context]')
            ->assertSeeInText('[weekly-failure-digest-missed]')
            ->assertSeeInText('[weekly-failure-digest-still-missed]')
            ->assertSeeInText('[weekly-failure-digest-non-zero-exit]')
            ->assertSeeInText('[weekly-failure-digest-row]')
            ->assertSeeInText('[weekly-failure-digest-monitor-count]')
            ->assertSeeInText('Monitored commands: 1')
            ->assertSeeInText('[weekly-failure-digest-urgent-alerts]')
            ->assertSeeInText('[weekly-failure-digest-monitor-configuration]')
            ->assertSeeInText('Configure monitor alerts: '.route('monitors.index'))
            ->assertSeeInText("Recipient: {$recipient}")
            ->assertSeeInText('Week: 2026-W39')
            ->assertSeeInText('&lt;script&gt;Test Created Digest Monitor&lt;/script&gt;')
            ->assertSeeInText('php artisan test-created:run --value=&quot;&lt;unsafe&gt;&quot;')
            ->assertSeeInText('Exit code: 42')
            ->assertSeeInText('Test Created Still Missed Monitor')
            ->assertSeeInText('Missed since: 2026-09-14T10:00:00.000000Z')
            ->assertDontSeeInText('urgent@example.test')
            ->assertDontSeeInText('https://hooks.example.test/urgent')
            ->assertSeeInText("Here's what you just got: non-zero exits are now included in your weekly failure digest.");

        return true;
    });

    expect($job->tries)->toBe(3)
        ->and($job->timeout)->toBe(15)
        ->and($job->failOnTimeout)->toBeTrue()
        ->and($job->backoff())->toBe([10, 60])
        ->and($delivery->refresh()->status)->toBe(WeeklyDigestDelivery::STATUS_DELIVERED)
        ->and($delivery->attempt_count)->toBe(1)
        ->and($delivery->delivered_at)->not->toBeNull();
});

it('renders an unambiguous all-clear with monitor count and urgent-alert configuration link', function (): void {
    config(['mail.default' => 'smtp']);
    Mail::fake();
    $delivery = createWeeklyDigestDelivery('all-clear@example.test');
    $payload = weeklyDigestPayload();
    $payload['monitor_count'] = 4;
    $payload['missed'] = [];
    $payload['non_zero_exit'] = [];
    $payload['still_missed'] = [];
    $delivery->update(['payload' => $payload]);

    runWeeklyDigestDelivery(new DeliverWeeklyFailureDigest($delivery->id));

    Mail::assertSent(WeeklyFailureDigestMail::class, function (WeeklyFailureDigestMail $mail): bool {
        $mail->assertSeeInHtml('data-testid="weekly-failure-digest-all-clear"', false)
            ->assertSeeInHtml('data-testid="weekly-failure-digest-monitor-count"', false)
            ->assertSeeInHtml('No missed or non-zero exit failures occurred across 4 monitored command(s) this week.')
            ->assertSeeInHtml('data-testid="weekly-failure-digest-urgent-alerts"', false)
            ->assertSeeInHtml('href="'.route('monitors.index').'"', false)
            ->assertDontSeeInHtml('weekly-failure-digest-release-note')
            ->assertSeeInText('[weekly-failure-digest-all-clear]')
            ->assertSeeInText('All clear: No missed or non-zero exit failures occurred across 4 monitored command(s) this week.')
            ->assertSeeInText('[weekly-failure-digest-monitor-count]')
            ->assertSeeInText('[weekly-failure-digest-urgent-alerts]')
            ->assertSeeInText('Configure monitor alerts: '.route('monitors.index'))
            ->assertDontSeeInText('[weekly-failure-digest-release-note]')
            ->assertDontSeeInHtml('urgent@example.test')
            ->assertDontSeeInText('urgent@example.test');

        return true;
    });
});

it('renders a still-missed-only week as a failure in both message parts', function (): void {
    config(['mail.default' => 'smtp']);
    Mail::fake();
    $delivery = createWeeklyDigestDelivery('still-missed-only@example.test');
    $payload = weeklyDigestPayload();
    $payload['missed'] = [];
    $payload['non_zero_exit'] = [];
    $payload['still_missed'] = [[
        'monitor_id' => 987,
        'monitor_name' => 'Test Created Silent Entire Week Monitor',
        'command' => 'php artisan test-created:silent-week',
        'reference_at' => '2026-09-14T09:30:00.000000Z',
        'reference_type' => 'missed_at',
    ]];
    $delivery->update(['payload' => $payload]);

    runWeeklyDigestDelivery(new DeliverWeeklyFailureDigest($delivery->id));

    Mail::assertSent(WeeklyFailureDigestMail::class, function (WeeklyFailureDigestMail $mail): bool {
        $mail->assertSeeInHtml('data-testid="weekly-failure-digest-still-missed"', false)
            ->assertSeeInHtml('Test Created Silent Entire Week Monitor')
            ->assertDontSeeInHtml('data-testid="weekly-failure-digest-all-clear"', false)
            ->assertSeeInText('[weekly-failure-digest-still-missed]')
            ->assertSeeInText('Test Created Silent Entire Week Monitor')
            ->assertDontSeeInText('[weekly-failure-digest-all-clear]');

        return true;
    });
});

it('reclaims only stale processing work and treats delivered replay as harmless', function (): void {
    config(['mail.default' => 'smtp']);
    Mail::fake();
    $stale = createWeeklyDigestDelivery('stale-processing@example.test');
    $stale->forceFill([
        'status' => WeeklyDigestDelivery::STATUS_PROCESSING,
        'processing_claim_id' => 'de2ed076-0291-424d-aa9b-f7c5f3ff1787',
        'processing_claimed_at' => now()->subMinutes(10),
    ])->save();
    $live = createWeeklyDigestDelivery('live-processing@example.test');
    $live->forceFill([
        'status' => WeeklyDigestDelivery::STATUS_PROCESSING,
        'processing_claim_id' => '42b0807d-1a36-4ca0-a918-f5b86c0ac809',
        'processing_claimed_at' => now(),
    ])->save();

    $staleJob = new DeliverWeeklyFailureDigest($stale->id);
    runWeeklyDigestDelivery($staleJob);
    $obsoleteLiveJob = new DeliverWeeklyFailureDigest($live->id);
    runWeeklyDigestDelivery($obsoleteLiveJob);
    $obsoleteLiveJob->failed(new RuntimeException('Test Created Obsolete Failure'));
    runWeeklyDigestDelivery($staleJob);

    Mail::assertSent(WeeklyFailureDigestMail::class, 1);
    expect($stale->refresh()->status)->toBe(WeeklyDigestDelivery::STATUS_DELIVERED)
        ->and($stale->attempt_count)->toBe(1)
        ->and($stale->processing_claim_id)->toBeNull()
        ->and($live->refresh()->status)->toBe(WeeklyDigestDelivery::STATUS_PROCESSING)
        ->and($live->attempt_count)->toBe(0)
        ->and($live->processing_claim_id)->toBe('42b0807d-1a36-4ca0-a918-f5b86c0ac809');
});

it('reclaims the same physical job at the default database retry boundary without stealing a live claim', function (): void {
    config([
        'mail.default' => 'smtp',
        'queue.default' => 'database',
    ]);
    Mail::fake();
    $claimedAt = now();
    $retryAfter = config('queue.connections.database.retry_after');
    $delivery = createWeeklyDigestDelivery('retry-boundary@example.test');
    $job = new DeliverWeeklyFailureDigest($delivery->id);
    $delivery->forceFill([
        'status' => WeeklyDigestDelivery::STATUS_PROCESSING,
        'processing_claim_id' => $job->jobId,
        'processing_claimed_at' => $claimedAt,
    ])->save();

    expect($retryAfter)->toBe(90)
        ->and($job->timeout)->toBe(15);

    $this->travelTo($claimedAt->addSeconds(($job->timeout * 2) - 1));
    runWeeklyDigestDelivery($job);

    Mail::assertNothingSent();
    expect($delivery->refresh()->status)->toBe(WeeklyDigestDelivery::STATUS_PROCESSING)
        ->and($delivery->attempt_count)->toBe(0)
        ->and($delivery->processing_claim_id)->toBe($job->jobId);

    $this->travelTo($claimedAt->addSeconds($retryAfter));
    runWeeklyDigestDelivery($job);

    Mail::assertSent(WeeklyFailureDigestMail::class, 1);
    expect($delivery->refresh()->status)->toBe(WeeklyDigestDelivery::STATUS_DELIVERED)
        ->and($delivery->attempt_count)->toBe(1)
        ->and($delivery->processing_claim_id)->toBeNull();
});

it('cancels and scrubs a delivery when its current recipient becomes ineligible', function (array $state): void {
    Queue::fake();
    config(['mail.default' => 'smtp']);
    Mail::fake();
    $this->travelTo('2026-09-28 00:05:00 UTC');
    $oldAddress = 'former-digest-recipient@example.test';
    $user = createWeeklyDigestUser('Recipient Cancellation', [], $oldAddress);
    createWeeklyDigestMonitor('Test Created Cancellation Monitor');
    $this->artisan('digests:generate-weekly')->assertSuccessful();
    $delivery = WeeklyDigestDelivery::query()->sole();
    $user->forceFill($state)->save();
    $job = new DeliverWeeklyFailureDigest($delivery->id);

    runWeeklyDigestDelivery($job);
    runWeeklyDigestDelivery($job);
    $job->failed(new RuntimeException('Test Created Exhaustion Detail'));

    Mail::assertNothingSent();
    $raw = DB::table('weekly_digest_deliveries')->where('id', $delivery->id)->first();
    expect($delivery->refresh()->status)->toBe(WeeklyDigestDelivery::STATUS_CANCELLED)
        ->and($delivery->recipient)->toBeNull()
        ->and($delivery->payload)->toBeNull()
        ->and($delivery->last_error_code)->toBe('recipient_ineligible')
        ->and($raw?->recipient)->toBeNull()
        ->and($raw?->payload)->toBeNull()
        ->and(serialize($job))->not->toContain($oldAddress);
})->with([
    'deactivated' => [['status' => 'inactive']],
    'generated address' => [['email_is_generated' => true]],
    'invalid address' => [[
        'email' => 'not-an-email',
    ]],
]);

it('sends to a current changed address and never transports to the generated address snapshot', function (): void {
    Queue::fake();
    config(['mail.default' => 'smtp']);
    Mail::fake();
    $this->travelTo('2026-09-28 00:05:00 UTC');
    $oldAddress = 'old-digest-address@example.test';
    $newAddress = 'current-digest-address@example.test';
    $user = createWeeklyDigestUser('Recipient Address Change', [], $oldAddress);
    createWeeklyDigestMonitor('Test Created Address Change Monitor');
    $this->artisan('digests:generate-weekly')->assertSuccessful();
    $delivery = WeeklyDigestDelivery::query()->sole();
    $user->forceFill([
        'email' => $newAddress,
    ])->save();

    runWeeklyDigestDelivery(new DeliverWeeklyFailureDigest($delivery->id));

    Mail::assertSent(WeeklyFailureDigestMail::class, function (WeeklyFailureDigestMail $mail) use (
        $newAddress,
        $oldAddress,
    ): bool {
        expect($mail->hasTo($newAddress))->toBeTrue()
            ->and($mail->hasTo($oldAddress))->toBeFalse();

        return true;
    });
    $rawRecipient = (string) DB::table('weekly_digest_deliveries')
        ->where('id', $delivery->id)
        ->value('recipient');
    expect($delivery->refresh()->recipient)->toBe($newAddress)
        ->and($rawRecipient)->not->toContain($oldAddress)
        ->not->toContain($newAddress);
});

it('keeps transport failure retryable and exposes only sanitized terminal failure state', function (): void {
    config(['mail.default' => 'log']);
    $handler = new TestHandler;
    app()->instance(LoggerInterface::class, new Logger('weekly-digest-test', [$handler]));
    $recipient = 'retry-secret-recipient@example.test';
    $marker = 'Test Created Retry Secret Monitor';
    $delivery = createWeeklyDigestDelivery($recipient);
    $delivery->update(['payload' => weeklyDigestPayload($marker)]);
    $job = new DeliverWeeklyFailureDigest($delivery->id);

    try {
        runWeeklyDigestDelivery($job);
        $this->fail('Expected the digest delivery to fail.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Weekly failure digest delivery failed.')
            ->not->toContain($recipient)
            ->not->toContain($marker)
            ->and($exception->getPrevious())->toBeNull();
    }

    expect(json_encode($handler->getRecords(), JSON_THROW_ON_ERROR))->not->toContain($recipient)
        ->not->toContain($marker)
        ->and($delivery->refresh()->status)->toBe(WeeklyDigestDelivery::STATUS_RETRYING)
        ->and($delivery->attempt_count)->toBe(1)
        ->and($delivery->delivered_at)->toBeNull();

    config(['mail.default' => 'smtp']);
    Mail::fake();
    runWeeklyDigestDelivery($job);

    expect($delivery->refresh()->status)->toBe(WeeklyDigestDelivery::STATUS_DELIVERED)
        ->and($delivery->attempt_count)->toBe(2);
    Mail::assertSent(WeeklyFailureDigestMail::class, 1);

    $terminal = createWeeklyDigestDelivery('terminal-secret-recipient@example.test');
    $terminalJob = new DeliverWeeklyFailureDigest($terminal->id);
    $terminalJob->failed(new TimeoutExceededException('Test Created Secret Timeout Detail'));

    expect($terminal->refresh()->status)->toBe(WeeklyDigestDelivery::STATUS_FAILED)
        ->and($terminal->last_error_code)->toBe('timeout')
        ->and($terminal->delivered_at)->toBeNull()
        ->and(json_encode($terminal->getAttributes(), JSON_THROW_ON_ERROR))
        ->not->toContain('Test Created Secret Timeout Detail');

    $exhausted = createWeeklyDigestDelivery('exhausted-secret-recipient@example.test');
    (new DeliverWeeklyFailureDigest($exhausted->id))
        ->failed(new RuntimeException('Test Created Secret Exhaustion Detail'));

    expect($exhausted->refresh()->status)->toBe(WeeklyDigestDelivery::STATUS_FAILED)
        ->and($exhausted->last_error_code)->toBe('attempts_exhausted')
        ->and(json_encode($exhausted->getAttributes(), JSON_THROW_ON_ERROR))
        ->not->toContain('Test Created Secret Exhaustion Detail');
});
