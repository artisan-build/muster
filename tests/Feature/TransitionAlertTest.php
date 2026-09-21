<?php

declare(strict_types=1);

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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

function createTransitionAlertMonitor(array $state = []): Monitor
{
    $monitor = Monitor::query()->create([
        'name' => 'Test Created Transition Alert Monitor '.bin2hex(random_bytes(3)),
        'cadence_seconds' => 300,
        'grace_seconds' => 30,
        'email_alerts_enabled' => true,
        'email_alert_destination' => 'transition-alerts@example.test',
        'webhook_alerts_enabled' => true,
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

it('creates one logical delivery per enabled channel only for missed and recovered transitions', function (): void {
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

    expect(fn () => DB::table('alert_deliveries')->insert([
        'check_in_event_id' => $delivery->check_in_event_id,
        'channel' => $delivery->channel,
        'destination' => 'duplicate',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);

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

it('creates deliveries only for channels enabled when the transition occurs', function (): void {
    Queue::fake();
    $monitor = createTransitionAlertMonitor([
        'email_alerts_enabled' => false,
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

it('sends generated transition identity and timing through email and webhook handlers', function (): void {
    Mail::fake();
    Http::fake(['*' => Http::response(status: 204)]);

    $emailDelivery = createAlertDelivery(AlertDelivery::CHANNEL_EMAIL);
    $webhookDelivery = createAlertDelivery(AlertDelivery::CHANNEL_WEBHOOK);

    (new DeliverTransitionAlert($emailDelivery->id))->handle();
    (new DeliverTransitionAlert($webhookDelivery->id))->handle();

    $emailPayload = $emailDelivery->fresh('event.monitor')->payload();
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

    $webhookPayload = $webhookDelivery->fresh('event.monitor')->payload();
    Http::assertSent(fn (Request $request): bool => $request->url() === $webhookDelivery->destination
        && $request->data() === $webhookPayload);

    expect($emailDelivery->refresh()->status)->toBe(AlertDelivery::STATUS_DELIVERED)
        ->and($emailDelivery->attempt_count)->toBe(1)
        ->and($webhookDelivery->refresh()->status)->toBe(AlertDelivery::STATUS_DELIVERED)
        ->and($webhookDelivery->attempt_count)->toBe(1);
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

    expect($delivery->refresh()->status)->toBe(AlertDelivery::STATUS_FAILED)
        ->and($delivery->attempt_count)->toBe(1)
        ->and($delivery->last_error_code)->toBe('transport_failed')
        ->and(AlertDelivery::query()->count())->toBe(1);
});
