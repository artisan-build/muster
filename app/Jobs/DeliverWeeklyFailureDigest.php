<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\WeeklyFailureDigestMail;
use App\Models\WeeklyDigestDelivery;
use ArtisanBuild\BuiltForCloud\Contracts\SystemAuthorityQueueEntry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

final class DeliverWeeklyFailureDigest implements ShouldQueue, SystemAuthorityQueueEntry
{
    use Queueable;

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
        $claimed = WeeklyDigestDelivery::query()
            ->whereKey($this->deliveryId)
            ->whereIn('status', [
                WeeklyDigestDelivery::STATUS_PENDING,
                WeeklyDigestDelivery::STATUS_RETRYING,
            ])
            ->update([
                'status' => WeeklyDigestDelivery::STATUS_PROCESSING,
                'attempt_count' => DB::raw('attempt_count + 1'),
                'last_attempt_at' => now(),
                'last_error_code' => null,
            ]);

        if ($claimed !== 1) {
            return;
        }

        $delivery = WeeklyDigestDelivery::query()->findOrFail($this->deliveryId);

        try {
            $mailer = config('mail.default');

            if (! is_string($mailer) || ! $this->mailerCanDeliver($mailer)) {
                throw new RuntimeException('A delivery-capable mail transport is not configured.');
            }

            Mail::mailer($mailer)
                ->to($delivery->recipient)
                ->send(new WeeklyFailureDigestMail($delivery->recipient, $delivery->payload));

            $delivery->forceFill([
                'status' => WeeklyDigestDelivery::STATUS_DELIVERED,
                'delivered_at' => now(),
                'last_error_code' => null,
            ])->save();
        } catch (Throwable) {
            $delivery->forceFill([
                'status' => WeeklyDigestDelivery::STATUS_RETRYING,
                'last_error_code' => 'transport_failed',
            ])->save();

            throw new RuntimeException('Weekly failure digest delivery failed.');
        }
    }

    public function failed(?Throwable $exception): void
    {
        $delivery = WeeklyDigestDelivery::query()->find($this->deliveryId);

        if ($delivery === null || $delivery->status === WeeklyDigestDelivery::STATUS_DELIVERED) {
            return;
        }

        $delivery->forceFill([
            'status' => WeeklyDigestDelivery::STATUS_FAILED,
            'last_error_code' => $exception instanceof TimeoutExceededException
                ? 'timeout'
                : 'attempts_exhausted',
        ])->save();
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
