<?php

declare(strict_types=1);

use App\Console\Commands\GenerateWeeklyFailureDigest;
use App\Enums\AlertMode;
use App\Jobs\DeliverWeeklyFailureDigest;
use App\Mail\WeeklyFailureDigestMail;
use App\Models\CheckInEvent;
use App\Models\Monitor;
use App\Models\WeeklyDigestDelivery;
use ArtisanBuild\BuiltForCloud\Commands\SystemAuthorityCommand;
use ArtisanBuild\BuiltForCloud\Contracts\SystemAuthorityQueueEntry;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

function createWeeklyDigestUser(string $label, array $state = []): User
{
    $user = User::query()->create([
        'name' => "Test Created {$label} Digest Recipient",
        'email' => strtolower(str_replace(' ', '-', $label)).'-'.bin2hex(random_bytes(4)).'@example.test',
        'password' => 'test-password',
    ]);

    if ($state !== []) {
        $user->forceFill($state)->save();
    }

    return $user;
}

function createWeeklyDigestMonitor(string $name, array $state = []): Monitor
{
    return Monitor::query()->create([
        'name' => $name,
        'cadence_seconds' => 300,
        'grace_seconds' => 30,
        ...$state,
    ]);
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
 * @return array{week_key: string, window_start: string, window_end: string, monitor_count: int, missed: list<array{id: int, monitor_name: string, command: string|null, occurred_at: string}>, non_zero_exit: list<array{id: int, monitor_name: string, command: string|null, occurred_at: string, exit_code: int|null}>}
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
    ];
}

function createWeeklyDigestDelivery(string $recipient = 'digest-recipient@example.test'): WeeklyDigestDelivery
{
    $user = createWeeklyDigestUser('Direct');

    return WeeklyDigestDelivery::query()->create([
        'user_id' => $user->id,
        'week_key' => '2026-W39',
        'window_start' => '2026-09-21 00:00:00',
        'window_end' => '2026-09-28 00:00:00',
        'recipient' => $recipient,
        'payload' => weeklyDigestPayload(),
    ]);
}

it('frames digest generation and delivery as system authority and schedules Monday UTC', function (): void {
    $event = collect(resolve(Schedule::class)->events())
        ->first(fn (Event $event): bool => $event->command !== null
            && str_contains($event->command, 'digests:generate-weekly'));

    expect(is_a(GenerateWeeklyFailureDigest::class, SystemAuthorityCommand::class, true))->toBeTrue()
        ->and(is_a(DeliverWeeklyFailureDigest::class, SystemAuthorityQueueEntry::class, true))->toBeTrue()
        ->and($event)->not->toBeNull()
        ->and($event?->expression)->toBe('5 0 * * 1')
        ->and($event?->timezone)->toBe('UTC');
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
            ->and($delivery->payload['non_zero_exit'][0]['monitor_name'])->toBe('Test Created Urgent Subscription Monitor')
            ->and($delivery->payload['non_zero_exit'][0]['exit_code'])->toBe(17);
    }

    Queue::assertPushed(DeliverWeeklyFailureDigest::class, 2);
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
            ->and($delivery->payload['non_zero_exit'])->toBe([]);
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
    $delivery->update(['payload' => $payload]);
    $job = new DeliverWeeklyFailureDigest($delivery->id);

    $job->handle();
    $job->handle();

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
            ->assertSeeInHtml("Here's what you just got: non-zero exits are now included in your weekly failure digest.", false)
            ->assertSeeInText('[weekly-failure-digest]')
            ->assertSeeInText('[weekly-failure-digest-context]')
            ->assertSeeInText('[weekly-failure-digest-missed]')
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
    $delivery->update(['payload' => $payload]);

    (new DeliverWeeklyFailureDigest($delivery->id))->handle();

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
        $job->handle();
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
    $job->handle();

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
});
