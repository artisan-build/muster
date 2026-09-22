<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\WeeklyFailureDigestMail;
use App\Models\WeeklyDigestDelivery;
use App\Services\CurrentWeeklyDigestRecipient;
use ArtisanBuild\BuiltForCloud\Contracts\SystemAuthorityQueueEntry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
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

    private const int MINIMUM_PROCESSING_LEASE_SECONDS = 30;

    private const int PROCESSING_LEASE_TIMEOUT_MULTIPLIER = 2;

    public int $tries = 3;

    public int $timeout = 15;

    public bool $failOnTimeout = true;

    public readonly string $jobId;

    public function __construct(public readonly int $deliveryId)
    {
        $this->jobId = (string) Str::uuid();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(CurrentWeeklyDigestRecipient $recipients): void
    {
        $staleBefore = now()->subSeconds($this->processingLeaseSeconds());
        $claimed = WeeklyDigestDelivery::query()
            ->whereKey($this->deliveryId)
            ->where(fn ($query) => $query
                ->whereIn('status', [
                    WeeklyDigestDelivery::STATUS_PENDING,
                    WeeklyDigestDelivery::STATUS_RETRYING,
                ])
                ->orWhere(fn ($query) => $query
                    ->where('status', WeeklyDigestDelivery::STATUS_PROCESSING)
                    ->where('processing_claimed_at', '<=', $staleBefore)))
            ->update([
                'status' => WeeklyDigestDelivery::STATUS_PROCESSING,
                'attempt_count' => DB::raw('attempt_count + 1'),
                'last_attempt_at' => now(),
                'last_error_code' => null,
                'processing_claim_id' => $this->jobId,
                'processing_claimed_at' => now(),
            ]);

        if ($claimed !== 1) {
            return;
        }

        $delivery = WeeklyDigestDelivery::query()->findOrFail($this->deliveryId);

        try {
            $recipient = $recipients->forUser($delivery->user_id);

            if ($recipient === null) {
                $this->cancel($delivery, $this->jobId);

                return;
            }

            $delivery->forceFill(['recipient' => $recipient])->save();
            $payload = $delivery->payload;

            if ($payload === null) {
                throw new RuntimeException('The weekly failure digest payload is unavailable.');
            }

            $mailer = config('mail.default');

            if (! is_string($mailer) || ! $this->mailerCanDeliver($mailer)) {
                throw new RuntimeException('A delivery-capable mail transport is not configured.');
            }

            Mail::mailer($mailer)
                ->to($recipient)
                ->send(new WeeklyFailureDigestMail($recipient, $payload));

            WeeklyDigestDelivery::query()
                ->whereKey($delivery->id)
                ->where('processing_claim_id', $this->jobId)
                ->update([
                    'status' => WeeklyDigestDelivery::STATUS_DELIVERED,
                    'delivered_at' => now(),
                    'last_error_code' => null,
                    'processing_claim_id' => null,
                    'processing_claimed_at' => null,
                ]);
        } catch (Throwable) {
            WeeklyDigestDelivery::query()
                ->whereKey($delivery->id)
                ->where('processing_claim_id', $this->jobId)
                ->update([
                    'status' => WeeklyDigestDelivery::STATUS_RETRYING,
                    'last_error_code' => 'transport_failed',
                    'processing_claim_id' => null,
                    'processing_claimed_at' => null,
                ]);

            throw new RuntimeException('Weekly failure digest delivery failed.');
        }
    }

    public function failed(?Throwable $exception): void
    {
        WeeklyDigestDelivery::query()
            ->whereKey($this->deliveryId)
            ->where(fn ($query) => $query
                ->whereIn('status', [
                    WeeklyDigestDelivery::STATUS_PENDING,
                    WeeklyDigestDelivery::STATUS_RETRYING,
                ])
                ->orWhere(fn ($query) => $query
                    ->where('status', WeeklyDigestDelivery::STATUS_PROCESSING)
                    ->where('processing_claim_id', $this->jobId)))
            ->update([
                'status' => WeeklyDigestDelivery::STATUS_FAILED,
                'last_error_code' => $exception instanceof TimeoutExceededException
                    ? 'timeout'
                    : 'attempts_exhausted',
                'processing_claim_id' => null,
                'processing_claimed_at' => null,
            ]);
    }

    private function processingLeaseSeconds(): int
    {
        // Queue visibility must exceed the job timeout, so the first valid redelivery
        // can safely reclaim after this timeout-derived margin.
        return max(
            self::MINIMUM_PROCESSING_LEASE_SECONDS,
            $this->timeout * self::PROCESSING_LEASE_TIMEOUT_MULTIPLIER,
        );
    }

    private function cancel(WeeklyDigestDelivery $delivery, string $claimId): void
    {
        WeeklyDigestDelivery::query()
            ->whereKey($delivery->id)
            ->where('processing_claim_id', $claimId)
            ->update([
                'status' => WeeklyDigestDelivery::STATUS_CANCELLED,
                'recipient' => null,
                'payload' => null,
                'last_error_code' => 'recipient_ineligible',
                'processing_claim_id' => null,
                'processing_claimed_at' => null,
            ]);
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
