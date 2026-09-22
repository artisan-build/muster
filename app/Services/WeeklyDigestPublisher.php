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
    private const int PUBLICATION_LEASE_SECONDS = 300;

    public function __construct(private Dispatcher $dispatcher) {}

    public function publishEligible(?string $weekKey = null): int
    {
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
