<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\DeliverWeeklyFailureDigest;
use App\Models\WeeklyDigestDelivery;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Throwable;

final readonly class WeeklyDigestPublisher
{
    /** @var list<string> */
    private const array NON_DURABLE_DRIVERS = [
        'background',
        'deferred',
        'null',
        'sync',
    ];

    private const int PUBLICATION_LEASE_SECONDS = 300;

    private const string UNSUPPORTED_QUEUE_ERROR = 'queue_not_durable';

    public function __construct(private Dispatcher $dispatcher) {}

    public function publishEligible(?string $weekKey = null): int
    {
        if (! $this->queueCanPublishDurably()) {
            $this->eligible($weekKey)->update([
                'last_error_code' => self::UNSUPPORTED_QUEUE_ERROR,
            ]);

            return 0;
        }

        $published = 0;

        $this->eligible($weekKey)
            ->select('id')
            ->chunkById(100, function ($deliveries) use (&$published): void {
                foreach ($deliveries as $delivery) {
                    $claimId = (string) Str::uuid();
                    $claimed = $this->eligible()
                        ->whereKey($delivery->id)
                        ->update([
                            'publication_claim_id' => $claimId,
                            'publication_claimed_at' => now(),
                            'last_error_code' => null,
                        ]);

                    if ($claimed !== 1) {
                        continue;
                    }

                    try {
                        $this->dispatcher->dispatch(new DeliverWeeklyFailureDigest($delivery->id));
                    } catch (Throwable) {
                        WeeklyDigestDelivery::query()
                            ->whereKey($delivery->id)
                            ->where('publication_claim_id', $claimId)
                            ->update([
                                'publication_claim_id' => null,
                                'publication_claimed_at' => null,
                            ]);

                        continue;
                    }

                    WeeklyDigestDelivery::query()
                        ->whereKey($delivery->id)
                        ->where('publication_claim_id', $claimId)
                        ->update([
                            'publication_claim_id' => null,
                            'publication_claimed_at' => null,
                            'published_at' => now(),
                        ]);
                    $published++;
                }
            });

        return $published;
    }

    private function queueCanPublishDurably(): bool
    {
        $connection = config('queue.default');

        return ! is_string($connection) || $this->queueConnectionCanPublishDurably($connection);
    }

    /** @param array<string, true> $ancestors */
    private function queueConnectionCanPublishDurably(string $connection, array $ancestors = []): bool
    {
        if (isset($ancestors[$connection])) {
            return false;
        }

        $configuration = config("queue.connections.{$connection}");

        if (! is_array($configuration)) {
            return true;
        }

        $driver = $configuration['driver'] ?? null;

        if (! is_string($driver)) {
            return true;
        }

        if (in_array($driver, self::NON_DURABLE_DRIVERS, true)) {
            return false;
        }

        if ($driver !== 'failover') {
            return true;
        }

        $members = $configuration['connections'] ?? null;

        if (! is_array($members)) {
            return true;
        }

        $ancestors[$connection] = true;

        foreach ($members as $member) {
            if (is_string($member) && ! $this->queueConnectionCanPublishDurably($member, $ancestors)) {
                return false;
            }
        }

        return true;
    }

    /** @return Builder<WeeklyDigestDelivery> */
    private function eligible(?string $weekKey = null): Builder
    {
        return WeeklyDigestDelivery::query()
            ->where('status', WeeklyDigestDelivery::STATUS_PENDING)
            ->whereNull('published_at')
            ->when($weekKey !== null, fn (Builder $query) => $query->where('week_key', $weekKey))
            ->where(fn (Builder $query) => $query
                ->whereNull('publication_claimed_at')
                ->orWhere('publication_claimed_at', '<=', now()->subSeconds(self::PUBLICATION_LEASE_SECONDS)));
    }
}
