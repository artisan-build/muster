<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\TransitionAlertMail;
use App\Models\AlertDelivery;
use ArtisanBuild\BuiltForCloud\Contracts\SystemAuthorityQueueEntry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

final class DeliverTransitionAlert implements ShouldQueue, SystemAuthorityQueueEntry
{
    use Queueable;

    public const int WEBHOOK_CONNECT_TIMEOUT = 3;

    public const int WEBHOOK_REQUEST_TIMEOUT = 10;

    /** @var list<string> */
    private const array DELIVERY_MAIL_TRANSPORTS = [
        'mailgun',
        'postmark',
        'resend',
        'sendmail',
        'ses',
        'ses-v2',
        'smtp',
    ];

    public int $tries = 3;

    public int $timeout = 15;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $deliveryId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(): void
    {
        $delivery = AlertDelivery::query()
            ->with('event')
            ->find($this->deliveryId);

        if ($delivery === null || in_array($delivery->status, [
            AlertDelivery::STATUS_DELIVERED,
            AlertDelivery::STATUS_FAILED,
        ], true)) {
            return;
        }

        $delivery->forceFill([
            'attempt_count' => $delivery->attempt_count + 1,
            'last_attempt_at' => now(),
            'last_error_code' => null,
        ])->save();

        try {
            match ($delivery->channel) {
                AlertDelivery::CHANNEL_EMAIL => $this->sendEmail($delivery),
                AlertDelivery::CHANNEL_WEBHOOK => $this->sendWebhook($delivery),
                default => throw new RuntimeException('Unsupported transition alert channel.'),
            };
        } catch (Throwable) {
            $delivery->forceFill([
                'status' => AlertDelivery::STATUS_RETRYING,
                'last_error_code' => 'transport_failed',
            ])->save();

            throw new RuntimeException('Transition alert delivery failed.');
        }

        $delivery->forceFill([
            'status' => AlertDelivery::STATUS_DELIVERED,
            'delivered_at' => now(),
            'last_error_code' => null,
        ])->save();
    }

    public function failed(?Throwable $exception): void
    {
        $delivery = AlertDelivery::query()->find($this->deliveryId);

        if ($delivery === null || $delivery->status === AlertDelivery::STATUS_DELIVERED) {
            return;
        }

        $delivery->forceFill([
            'status' => AlertDelivery::STATUS_FAILED,
            'last_error_code' => $exception instanceof TimeoutExceededException
                ? 'timeout'
                : 'attempts_exhausted',
        ])->save();
    }

    private function sendEmail(AlertDelivery $delivery): void
    {
        $mailer = config('mail.default');

        if (! is_string($mailer) || ! $this->mailerCanDeliver($mailer)) {
            throw new RuntimeException('A delivery-capable mail transport is not configured.');
        }

        Mail::mailer($mailer)
            ->to($delivery->destination)
            ->send(new TransitionAlertMail($delivery->payload()));
    }

    private function sendWebhook(AlertDelivery $delivery): void
    {
        $response = Http::connectTimeout(self::WEBHOOK_CONNECT_TIMEOUT)
            ->timeout(self::WEBHOOK_REQUEST_TIMEOUT)
            ->withoutRedirecting()
            ->acceptJson()
            ->post($delivery->destination, $delivery->payload());

        if (! $response->successful()) {
            throw new RuntimeException('Webhook returned an unsuccessful response.');
        }
    }

    /** @param array<string, true> $visited */
    private function mailerCanDeliver(string $mailer, array $visited = []): bool
    {
        if (isset($visited[$mailer])) {
            return false;
        }

        $visited[$mailer] = true;
        $mailers = config('mail.mailers');
        $configuration = is_array($mailers) ? ($mailers[$mailer] ?? null) : null;

        if (! is_array($configuration)) {
            return false;
        }

        $transport = $configuration['transport'] ?? null;

        if (! empty($configuration['url'])) {
            $parsedConfiguration = (new ConfigurationUrlParser)->parseConfiguration($configuration);
            $transport = $parsedConfiguration['driver'] ?? null;
        }

        if (! is_string($transport)) {
            return false;
        }

        if (in_array($transport, self::DELIVERY_MAIL_TRANSPORTS, true)) {
            return true;
        }

        if (! in_array($transport, ['failover', 'roundrobin'], true)) {
            return false;
        }

        $members = $configuration['mailers'] ?? null;

        if (! is_array($members) || $members === []) {
            return false;
        }

        foreach ($members as $member) {
            if (! is_string($member) || ! $this->mailerCanDeliver($member, $visited)) {
                return false;
            }
        }

        return true;
    }
}
