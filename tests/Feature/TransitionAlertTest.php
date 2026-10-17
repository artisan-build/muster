<?php

declare(strict_types=1);

use App\Enums\AlertMode;
use App\Jobs\DeliverTransitionAlert;
use App\Mail\TransitionAlertMail;
use App\Models\AlertDelivery;
use App\Models\CheckInEvent;
use App\Models\Monitor;
use ArtisanBuild\BuiltForCloud\Actions\DecideDeviceAuthorization;
use ArtisanBuild\BuiltForCloud\Actions\PollDeviceAuthorization;
use ArtisanBuild\BuiltForCloud\Actions\StartDeviceAuthorization;
use ArtisanBuild\BuiltForCloud\StandaloneAccess;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Request as LaravelRequest;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

function createTransitionAlertMonitor(array $state = []): Monitor
{
    $monitor = Monitor::query()->create([
        'name' => 'Test Created Transition Alert Monitor '.bin2hex(random_bytes(3)),
        'cadence_seconds' => 300,
        'grace_seconds' => 30,
        'email_alert_modes' => AlertMode::Missed->value | AlertMode::NonZeroExit->value,
        'email_alert_destination' => 'transition-alerts@example.test',
        'webhook_alert_modes' => AlertMode::Missed->value | AlertMode::NonZeroExit->value,
        'webhook_alert_destination' => 'https://hooks.example.test/muster/'.bin2hex(random_bytes(8)).'?key='.bin2hex(random_bytes(8)),
    ]);

    if ($state !== []) {
        $monitor->forceFill($state)->save();
    }

    return $monitor;
}

function issueTransitionAlertBearer(): string
{
    $user = User::query()->create([
        'name' => 'Test Created Transition Alert Credential Owner',
        'email' => 'transition-owner-'.bin2hex(random_bytes(6)).'@example.test',
        'password' => 'test-password',
    ]);
    $user->refresh();

    test()->actingAs($user)->withSession([
        StandaloneAccess::SESSION_VERSION_KEY => $user->auth_session_version,
    ]);

    $request = LaravelRequest::create('/bfc/device-authorizations', 'POST');
    $request->setUserResolver(static fn (): User => $user);
    $request->setLaravelSession(resolve('session')->driver());

    $start = resolve(StartDeviceAuthorization::class)(
        $request,
        'muster.check-in',
        'Test Created Transition Alert Bearer',
    );
    resolve(DecideDeviceAuthorization::class)(
        $request,
        $start->userCode->reveal(),
        $start->browserNonce->reveal(),
        true,
    );

    return resolve(PollDeviceAuthorization::class)($request, $start->deviceCode->reveal())
        ->accessToken
        ->reveal();
}

function createAlertDelivery(string $channel): AlertDelivery
{
    $monitor = createTransitionAlertMonitor([
        'status' => 'missed',
        'last_check_in_at' => '2026-09-21 12:00:00',
        'next_due_at' => '2026-09-21 12:05:00',
    ]);
    $event = $monitor->events()->create([
        'event_type' => CheckInEvent::TYPE_MISSED,
        'occurred_at' => '2026-09-21 12:05:30',
        'monitor_name' => $monitor->name,
        'last_check_in_at' => $monitor->last_check_in_at,
        'next_due_at' => $monitor->next_due_at,
    ]);

    return $event->alertDeliveries()->create([
        'channel' => $channel,
        'destination' => $channel === AlertDelivery::CHANNEL_EMAIL
            ? $monitor->email_alert_destination
            : $monitor->webhook_alert_destination,
    ]);
}

function runTransitionAlertWorkerOnce(): void
{
    test()->artisan('queue:work database --once --tries=3 --no-interaction')->assertSuccessful();
}

function releaseTransitionAlertRetry(): void
{
    DB::table('jobs')->update(['available_at' => now()->subSecond()->timestamp]);
}

it('creates one logical delivery per subscribed channel for missed and recovered transitions', function (): void {
    Queue::fake();
    $monitor = createTransitionAlertMonitor([
        'status' => 'healthy',
        'last_check_in_at' => '2026-09-21 12:00:00',
        'next_due_at' => '2026-09-21 12:05:00',
    ]);

    $this->travelTo('2026-09-21 12:05:00 UTC');
    $this->artisan('monitors:evaluate')->assertSuccessful();

    expect($monitor->refresh()->status)->toBe('late')
        ->and(AlertDelivery::query()->count())->toBe(0);

    $this->travelTo('2026-09-21 12:05:30 UTC');
    $this->artisan('monitors:evaluate')->assertSuccessful();
    $this->artisan('monitors:evaluate')->assertSuccessful();

    $missed = $monitor->events()->where('event_type', CheckInEvent::TYPE_MISSED)->sole();

    expect($missed->alertDeliveries()->orderBy('channel')->pluck('channel')->all())->toBe([
        AlertDelivery::CHANNEL_EMAIL,
        AlertDelivery::CHANNEL_WEBHOOK,
    ])->and(AlertDelivery::query()->count())->toBe(2);

    Queue::assertPushed(DeliverTransitionAlert::class, 2);
    Queue::assertPushed(
        DeliverTransitionAlert::class,
        fn (DeliverTransitionAlert $job): bool => $job->afterCommit === true,
    );

    $secret = issueTransitionAlertBearer();
    $this->travelTo('2026-09-21 12:10:00 UTC');
    $checkIn = fn () => $this->withHeaders([
        'Authorization' => 'Bearer '.$secret,
        'Sec-Fetch-Site' => 'cross-site',
    ])->postJson(route('monitor-check-ins.store', $monitor));

    $checkIn()->assertOk();
    $checkIn()->assertOk();

    expect($monitor->events()->where('event_type', CheckInEvent::TYPE_RECOVERED)->count())->toBe(1)
        ->and($monitor->events()->where('event_type', CheckInEvent::TYPE_CHECK_IN)->count())->toBe(2)
        ->and(AlertDelivery::query()->count())->toBe(4)
        ->and(AlertDelivery::query()->select('check_in_event_id', 'channel')->distinct()->count())->toBe(4);

    Queue::assertPushed(DeliverTransitionAlert::class, 4);
});

it('enforces delivery uniqueness and serializes only an opaque identifier into the queue command', function (): void {
    $delivery = createAlertDelivery(AlertDelivery::CHANNEL_WEBHOOK);
    $monitor = $delivery->event->monitor;

    expect(fn () => DB::transaction(fn () => DB::table('alert_deliveries')->insert([
        'check_in_event_id' => $delivery->check_in_event_id,
        'channel' => $delivery->channel,
        'destination' => 'duplicate',
        'created_at' => now(),
        'updated_at' => now(),
    ])))->toThrow(UniqueConstraintViolationException::class);

    $serialized = serialize(new DeliverTransitionAlert($delivery->id));
    $queue = Queue::connection('database');

    if (! $queue instanceof DatabaseQueue) {
        throw new LogicException('The database queue connection was not resolved.');
    }

    $queue->push(new DeliverTransitionAlert($delivery->id));
    $queuePayload = DB::table('jobs')->value('payload');
    $storedDestination = DB::table('alert_deliveries')->where('id', $delivery->id)->value('destination');

    expect($serialized.(string) $queuePayload)->toContain((string) $delivery->id)
        ->not->toContain($monitor->name)
        ->not->toContain($monitor->email_alert_destination)
        ->not->toContain($monitor->webhook_alert_destination)
        ->not->toContain('event')
        ->not->toContain('payload')
        ->and($storedDestination)->not->toBe($delivery->destination);
});

it('dispatches production transition jobs that serialize to nothing but the opaque delivery identifier', function (): void {
    Queue::fake();
    $monitor = createTransitionAlertMonitor([
        'status' => 'missed',
        'last_check_in_at' => '2026-09-21 12:00:00',
        'next_due_at' => '2026-09-21 12:05:00',
    ]);

    $monitor->recordActivity([CheckInEvent::TYPE_MISSED], now());

    Queue::assertPushed(DeliverTransitionAlert::class, 2);
    Queue::assertPushed(DeliverTransitionAlert::class, function (DeliverTransitionAlert $job): bool {
        expect(serialize($job))
            ->toContain(DeliverTransitionAlert::class)
            ->not->toContain(AlertDelivery::class)
            ->not->toContain('destination');

        return true;
    });
});

it('creates deliveries only for channels enabled when the transition occurs', function (): void {
    Queue::fake();
    $monitor = createTransitionAlertMonitor([
        'email_alert_modes' => 0,
        'status' => 'late',
        'last_check_in_at' => '2026-09-21 12:00:00',
        'next_due_at' => '2026-09-21 12:05:00',
    ]);

    $this->travelTo('2026-09-21 12:05:30 UTC');
    $this->artisan('monitors:evaluate')->assertSuccessful();

    expect(AlertDelivery::query()->pluck('channel')->all())->toBe([
        AlertDelivery::CHANNEL_WEBHOOK,
    ]);

    Queue::assertPushed(DeliverTransitionAlert::class, 1);
});

it('selects the first matching failure from every channel mask combination', function (
    string $channel,
    int $mask,
    array $expectedEventTypes,
): void {
    Queue::fake();
    $monitor = createTransitionAlertMonitor([
        'email_alert_modes' => $channel === AlertDelivery::CHANNEL_EMAIL ? $mask : 0,
        'webhook_alert_modes' => $channel === AlertDelivery::CHANNEL_WEBHOOK ? $mask : 0,
    ]);

    $monitor->recordActivity([
        CheckInEvent::TYPE_MISSED,
        CheckInEvent::TYPE_NON_ZERO_EXIT,
    ], now());

    $deliveries = AlertDelivery::query()
        ->with('event')
        ->orderBy('id')
        ->get();

    expect($deliveries->pluck('channel')->unique()->values()->all())
        ->toBe($expectedEventTypes === [] ? [] : [$channel])
        ->and($deliveries->map(fn (AlertDelivery $delivery): string => $delivery->event->event_type)->all())
        ->toBe($expectedEventTypes);

    Queue::assertPushed(DeliverTransitionAlert::class, count($expectedEventTypes));
})->with([
    'email neither' => [AlertDelivery::CHANNEL_EMAIL, 0, []],
    'email missed only' => [AlertDelivery::CHANNEL_EMAIL, AlertMode::Missed->value, [CheckInEvent::TYPE_MISSED]],
    'email non-zero only' => [AlertDelivery::CHANNEL_EMAIL, AlertMode::NonZeroExit->value, [CheckInEvent::TYPE_NON_ZERO_EXIT]],
    'email both' => [AlertDelivery::CHANNEL_EMAIL, 3, [CheckInEvent::TYPE_MISSED]],
    'webhook neither' => [AlertDelivery::CHANNEL_WEBHOOK, 0, []],
    'webhook missed only' => [AlertDelivery::CHANNEL_WEBHOOK, AlertMode::Missed->value, [CheckInEvent::TYPE_MISSED]],
    'webhook non-zero only' => [AlertDelivery::CHANNEL_WEBHOOK, AlertMode::NonZeroExit->value, [CheckInEvent::TYPE_NON_ZERO_EXIT]],
    'webhook both' => [AlertDelivery::CHANNEL_WEBHOOK, 3, [CheckInEvent::TYPE_MISSED]],
]);

it('sends generated transition identity and timing through email and webhook handlers', function (): void {
    config(['mail.default' => 'smtp']);
    Mail::fake();
    Http::fake(['*' => Http::response(status: 204)]);

    $emailDelivery = createAlertDelivery(AlertDelivery::CHANNEL_EMAIL);
    $webhookDelivery = createAlertDelivery(AlertDelivery::CHANNEL_WEBHOOK);

    (new DeliverTransitionAlert($emailDelivery->id))->handle();
    (new DeliverTransitionAlert($webhookDelivery->id))->handle();

    $emailPayload = $emailDelivery->fresh('event')->payload();
    Mail::assertSent(TransitionAlertMail::class, function (TransitionAlertMail $mail) use ($emailPayload): bool {
        expect($mail->envelope()->subject)
            ->toContain($emailPayload['monitor']['name'])
            ->toContain($emailPayload['event']['transition'])
            ->toContain((string) $emailPayload['event']['id']);

        $mail->assertSeeInText($emailPayload['monitor']['name'])
            ->assertSeeInText((string) $emailPayload['monitor']['id'])
            ->assertSeeInText((string) $emailPayload['event']['id'])
            ->assertSeeInText($emailPayload['event']['occurred_at'])
            ->assertSeeInText((string) $emailPayload['monitor']['last_check_in_at'])
            ->assertSeeInText((string) $emailPayload['monitor']['next_due_at']);

        return true;
    });

    $webhookPayload = $webhookDelivery->fresh('event')->payload();
    Http::assertSent(fn (Request $request): bool => $request->url() === $webhookDelivery->destination
        && $request->data() === $webhookPayload);

    expect($emailDelivery->refresh()->status)->toBe(AlertDelivery::STATUS_DELIVERED)
        ->and($emailDelivery->attempt_count)->toBe(1)
        ->and($webhookDelivery->refresh()->status)->toBe(AlertDelivery::STATUS_DELIVERED)
        ->and($webhookDelivery->attempt_count)->toBe(1);
});

it('sends email alerts through the default scalpels mailer', function (): void {
    config(['mail.default' => 'scalpels']);

    expect(config('mail.mailers.scalpels'))->toBe(['transport' => 'scalpels']);

    Mail::fake();
    $delivery = createAlertDelivery(AlertDelivery::CHANNEL_EMAIL);

    (new DeliverTransitionAlert($delivery->id))->handle();

    Mail::assertSent(TransitionAlertMail::class, fn (TransitionAlertMail $mail): bool => $mail->hasTo($delivery->destination));
    expect($delivery->refresh()->status)->toBe(AlertDelivery::STATUS_DELIVERED)
        ->and($delivery->attempt_count)->toBe(1);
});

it('uses bounded retries and sanitizes non-2xx failures without duplicating logical delivery', function (): void {
    Http::fake(['*' => Http::response(['secret' => 'response-secret'], 500)]);
    $delivery = createAlertDelivery(AlertDelivery::CHANNEL_WEBHOOK);
    $destination = $delivery->destination;
    $job = new DeliverTransitionAlert($delivery->id);

    expect($job->tries)->toBe(3)
        ->and($job->timeout)->toBe(15)
        ->and($job->failOnTimeout)->toBeTrue()
        ->and($job->backoff())->toBe([10, 60])
        ->and(DeliverTransitionAlert::WEBHOOK_CONNECT_TIMEOUT)->toBe(3)
        ->and(DeliverTransitionAlert::WEBHOOK_REQUEST_TIMEOUT)->toBe(10);

    try {
        $job->handle();
        $this->fail('Expected the webhook delivery to fail.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Transition alert delivery failed.')
            ->not->toContain($destination)
            ->not->toContain('response-secret')
            ->and($exception->getPrevious())->toBeNull();
    }

    expect($delivery->refresh()->status)->toBe(AlertDelivery::STATUS_RETRYING)
        ->and($delivery->attempt_count)->toBe(1)
        ->and($delivery->last_error_code)->toBe('transport_failed')
        ->and(AlertDelivery::query()->count())->toBe(1);
});

it('refuses the default logging mail transport without logging or claiming delivery', function (): void {
    config(['mail.default' => 'log']);
    $handler = new TestHandler;
    app()->instance(LoggerInterface::class, new Logger('transition-alert-test', [$handler]));
    $delivery = createAlertDelivery(AlertDelivery::CHANNEL_EMAIL);
    $destination = $delivery->destination;

    try {
        (new DeliverTransitionAlert($delivery->id))->handle();
        $this->fail('Expected the non-delivery mail transport to be rejected.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Transition alert delivery failed.')
            ->not->toContain($destination)
            ->and($exception->getPrevious())->toBeNull();
    }

    expect(json_encode($handler->getRecords(), JSON_THROW_ON_ERROR))->not->toContain($destination)
        ->and($delivery->refresh()->status)->toBe(AlertDelivery::STATUS_RETRYING)
        ->and($delivery->delivered_at)->toBeNull();
});

it('rejects non-delivery mailers and unsafe composites', function (string $mailer, array $configuration): void {
    config([
        'mail.default' => $mailer,
        "mail.mailers.{$mailer}" => $configuration,
    ]);
    Mail::fake();
    $delivery = createAlertDelivery(AlertDelivery::CHANNEL_EMAIL);

    expect(fn () => (new DeliverTransitionAlert($delivery->id))->handle())
        ->toThrow(RuntimeException::class, 'Transition alert delivery failed.');

    Mail::assertNothingSent();
    expect($delivery->refresh()->status)->toBe(AlertDelivery::STATUS_RETRYING)
        ->and($delivery->delivered_at)->toBeNull();
})->with([
    'array transport' => ['test-array', ['transport' => 'array']],
    'failover containing log' => ['test-failover', [
        'transport' => 'failover',
        'mailers' => ['smtp', 'log'],
    ]],
]);

it('rejects an allowlisted mailer whose URL selects the logging transport before receiving the destination', function (): void {
    config([
        'mail.default' => 'url-overridden-mailer',
        'mail.mailers.url-overridden-mailer' => [
            'transport' => 'smtp',
            'url' => 'log://default',
        ],
    ]);
    $handler = new TestHandler;
    app()->instance(LoggerInterface::class, new Logger('transition-alert-url-test', [$handler]));
    $delivery = createAlertDelivery(AlertDelivery::CHANNEL_EMAIL);
    $destination = $delivery->destination;

    try {
        (new DeliverTransitionAlert($delivery->id))->handle();
        $this->fail('Expected the URL-selected logging transport to be rejected.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Transition alert delivery failed.')
            ->not->toContain($destination)
            ->and($exception->getPrevious())->toBeNull();
    }

    expect(json_encode($handler->getRecords(), JSON_THROW_ON_ERROR))->not->toContain($destination)
        ->and($delivery->refresh()->status)->toBe(AlertDelivery::STATUS_RETRYING)
        ->and($delivery->delivered_at)->toBeNull();
});

it('rejects a nested allowlisted mailer whose URL selects the logging transport before receiving the destination', function (): void {
    config([
        'mail.default' => 'url-overridden-failover',
        'mail.mailers.url-overridden-failover' => [
            'transport' => 'failover',
            'mailers' => ['url-overridden-member', 'smtp'],
        ],
        'mail.mailers.url-overridden-member' => [
            'transport' => 'smtp',
            'url' => 'log://default',
        ],
    ]);
    $handler = new TestHandler;
    app()->instance(LoggerInterface::class, new Logger('transition-alert-nested-url-test', [$handler]));
    $delivery = createAlertDelivery(AlertDelivery::CHANNEL_EMAIL);
    $destination = $delivery->destination;

    try {
        (new DeliverTransitionAlert($delivery->id))->handle();
        $this->fail('Expected the nested URL-selected logging transport to be rejected.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Transition alert delivery failed.')
            ->not->toContain($destination)
            ->and($exception->getPrevious())->toBeNull();
    }

    expect(json_encode($handler->getRecords(), JSON_THROW_ON_ERROR))->not->toContain($destination)
        ->and($delivery->refresh()->status)->toBe(AlertDelivery::STATUS_RETRYING)
        ->and($delivery->delivered_at)->toBeNull();
});

it('keeps a failed attempt retryable and records a later worker success', function (): void {
    config(['queue.default' => 'database']);
    Http::fakeSequence()
        ->push(['ignored' => true], 500)
        ->push(status: 204);
    $delivery = createAlertDelivery(AlertDelivery::CHANNEL_WEBHOOK);
    Queue::connection('database')->push(new DeliverTransitionAlert($delivery->id));

    runTransitionAlertWorkerOnce();

    expect($delivery->refresh()->status)->toBe(AlertDelivery::STATUS_RETRYING)
        ->and($delivery->attempt_count)->toBe(1)
        ->and($delivery->last_error_code)->toBe('transport_failed')
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and(DB::table('failed_jobs')->count())->toBe(0);

    releaseTransitionAlertRetry();
    runTransitionAlertWorkerOnce();

    expect($delivery->refresh()->status)->toBe(AlertDelivery::STATUS_DELIVERED)
        ->and($delivery->attempt_count)->toBe(2)
        ->and($delivery->last_error_code)->toBeNull()
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

it('marks a delivery terminally failed only after worker exhaustion', function (): void {
    config(['queue.default' => 'database']);
    Http::fake(['*' => Http::response(['secret' => 'worker-response-secret'], 500)]);
    $delivery = createAlertDelivery(AlertDelivery::CHANNEL_WEBHOOK);
    $destination = $delivery->destination;
    Queue::connection('database')->push(new DeliverTransitionAlert($delivery->id));

    runTransitionAlertWorkerOnce();
    releaseTransitionAlertRetry();
    runTransitionAlertWorkerOnce();
    releaseTransitionAlertRetry();
    runTransitionAlertWorkerOnce();

    $failedException = (string) DB::table('failed_jobs')->value('exception');

    expect($delivery->refresh()->status)->toBe(AlertDelivery::STATUS_FAILED)
        ->and($delivery->attempt_count)->toBe(3)
        ->and($delivery->last_error_code)->toBe('attempts_exhausted')
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and($failedException)->toContain('Transition alert delivery failed.')
        ->not->toContain($destination)
        ->not->toContain('worker-response-secret');
});

it('marks a timed out job terminally failed through the failure callback', function (): void {
    $delivery = createAlertDelivery(AlertDelivery::CHANNEL_WEBHOOK);
    $job = new DeliverTransitionAlert($delivery->id);

    expect($job->failOnTimeout)->toBeTrue();

    $job->failed(new TimeoutExceededException('Test-created timeout marker.'));

    expect($delivery->refresh()->status)->toBe(AlertDelivery::STATUS_FAILED)
        ->and($delivery->last_error_code)->toBe('timeout')
        ->and($delivery->delivered_at)->toBeNull();
});

it('retains a pending delivery event beyond the newest one hundred events until it is terminal', function (): void {
    Queue::fake();
    Http::fake(['*' => Http::response(status: 204)]);
    $monitor = createTransitionAlertMonitor([
        'email_alert_modes' => 0,
        'status' => 'missed',
    ]);
    $occurredAt = now();
    $monitor->recordActivity([CheckInEvent::TYPE_MISSED], $occurredAt);
    $delivery = AlertDelivery::query()->sole();
    $eventId = $delivery->check_in_event_id;

    foreach (range(1, 101) as $offset) {
        $monitor->recordActivity([CheckInEvent::TYPE_CHECK_IN], $occurredAt->addSeconds($offset));
    }

    expect(CheckInEvent::query()->find($eventId))->not->toBeNull()
        ->and(AlertDelivery::query()->find($delivery->id))->not->toBeNull()
        ->and($monitor->events()->count())->toBe(101);

    (new DeliverTransitionAlert($delivery->id))->handle();

    expect($delivery->refresh()->status)->toBe(AlertDelivery::STATUS_DELIVERED);

    $this->travelTo($occurredAt->addWeeks(2));
    $monitor->recordActivity([CheckInEvent::TYPE_CHECK_IN], now());

    expect(CheckInEvent::query()->find($eventId))->toBeNull()
        ->and(AlertDelivery::query()->find($delivery->id))->toBeNull()
        ->and($monitor->events()->count())->toBe(100);
});

it('keeps event-time monitor identity and destinations stable across rename reconfiguration and retry', function (): void {
    config(['mail.default' => 'smtp']);
    Queue::fake();
    Mail::fake();
    Http::fakeSequence()
        ->push(['ignored' => true], 500)
        ->push(status: 204);
    $monitor = createTransitionAlertMonitor([
        'name' => 'Test Created Original Alert Identity',
        'status' => 'missed',
        'last_check_in_at' => '2026-09-21 12:00:00',
        'next_due_at' => '2026-09-21 12:05:00',
    ]);
    $originalName = $monitor->name;
    $originalEmail = $monitor->email_alert_destination;
    $originalWebhook = $monitor->webhook_alert_destination;
    $monitor->recordActivity([CheckInEvent::TYPE_MISSED], now());
    $deliveries = AlertDelivery::query()->orderBy('channel')->get()->keyBy('channel');
    $emailDelivery = $deliveries->get(AlertDelivery::CHANNEL_EMAIL);
    $webhookDelivery = $deliveries->get(AlertDelivery::CHANNEL_WEBHOOK);

    if (! $emailDelivery instanceof AlertDelivery || ! $webhookDelivery instanceof AlertDelivery) {
        throw new LogicException('Expected both transition alert delivery channels.');
    }

    $monitor->update([
        'name' => 'Test Created Renamed Before Delivery',
        'email_alert_destination' => 'changed-email@example.test',
        'webhook_alert_destination' => 'https://hooks.example.test/changed?key=changed',
    ]);

    (new DeliverTransitionAlert($emailDelivery->id))->handle();

    try {
        (new DeliverTransitionAlert($webhookDelivery->id))->handle();
        $this->fail('Expected the first webhook attempt to fail.');
    } catch (RuntimeException) {
        // The retry must render from the same event snapshot.
    }

    $monitor->update(['name' => 'Test Created Renamed Before Retry']);
    (new DeliverTransitionAlert($webhookDelivery->id))->handle();

    $emailPayload = $emailDelivery->fresh('event')->payload();
    $webhookPayload = $webhookDelivery->fresh('event')->payload();

    expect($emailPayload)->toBe($webhookPayload)
        ->and($emailPayload['monitor']['name'])->toBe($originalName)
        ->and($emailDelivery->destination)->toBe($originalEmail)
        ->and($webhookDelivery->destination)->toBe($originalWebhook)
        ->and($webhookDelivery->refresh()->attempt_count)->toBe(2)
        ->and($webhookDelivery->status)->toBe(AlertDelivery::STATUS_DELIVERED);

    Mail::assertSent(TransitionAlertMail::class, fn (TransitionAlertMail $mail): bool => $mail->hasTo($originalEmail)
        && str_contains($mail->envelope()->subject, $originalName));
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->url() === $originalWebhook
        && $request->data() === $webhookPayload);
});
