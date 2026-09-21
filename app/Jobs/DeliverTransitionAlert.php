<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\TransitionAlertMail;
use App\Models\AlertDelivery;
use ArtisanBuild\BuiltForCloud\Contracts\SystemAuthorityQueueEntry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

final class DeliverTransitionAlert implements ShouldQueue, SystemAuthorityQueueEntry
{
    use Queueable;

    public const int WEBHOOK_CONNECT_TIMEOUT = 3;

    public const int WEBHOOK_REQUEST_TIMEOUT = 10;

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
            ->with('event.monitor')
            ->find($this->deliveryId);

        if ($delivery === null || $delivery->status === AlertDelivery::STATUS_DELIVERED) {
            return;
        }

        $delivery->forceFill([
            'attempt_count' => $delivery->attempt_count + 1,
            'last_attempt_at' => now(),
            'last_error_code' => null,
        ])->save();

        try {
            match ($delivery->channel) {
                AlertDelivery::CHANNEL_EMAIL => Mail::to($delivery->destination)
                    ->send(new TransitionAlertMail($delivery->payload())),
                AlertDelivery::CHANNEL_WEBHOOK => $this->sendWebhook($delivery),
                default => throw new RuntimeException('Unsupported transition alert channel.'),
            };
        } catch (Throwable) {
            $delivery->forceFill([
                'status' => AlertDelivery::STATUS_FAILED,
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
}
