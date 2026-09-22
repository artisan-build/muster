<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\DeliverWeeklyFailureDigest;
use App\Models\CheckInEvent;
use App\Models\Monitor;
use App\Models\WeeklyDigestDelivery;
use ArtisanBuild\BuiltForCloud\Commands\SystemAuthorityCommand;
use ArtisanBuild\BuiltForCloud\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

final class GenerateWeeklyFailureDigest extends SystemAuthorityCommand
{
    protected $signature = 'digests:generate-weekly';

    protected $description = 'Queue the automatic account-level failure digest for the last complete UTC week.';

    public function handle(): int
    {
        // A digest covers the previous complete UTC ISO week: [Monday 00:00, Monday 00:00).
        $windowEnd = CarbonImmutable::now('UTC')->startOfWeek(CarbonInterface::MONDAY);
        $windowStart = $windowEnd->subWeek();
        $weekKey = $windowStart->format('o-\WW');
        $monitorCount = Monitor::query()->count();

        if ($monitorCount === 0) {
            $this->info('No monitored commands exist; no weekly digest was queued.');

            return self::SUCCESS;
        }

        $events = CheckInEvent::query()
            ->whereIn('event_type', [
                CheckInEvent::TYPE_MISSED,
                CheckInEvent::TYPE_NON_ZERO_EXIT,
            ])
            ->where('occurred_at', '>=', $windowStart)
            ->where('occurred_at', '<', $windowEnd)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get([
                'id',
                'event_type',
                'monitor_name',
                'command',
                'occurred_at',
                'exit_code',
            ]);

        $payload = $this->payload($events, $weekKey, $windowStart, $windowEnd, $monitorCount);
        $queued = 0;

        User::query()
            ->where('status', 'active')
            ->where('email_is_generated', false)
            ->select(['id', 'email'])
            ->orderBy('id')
            ->chunkById(100, function ($users) use ($payload, $weekKey, $windowStart, $windowEnd, &$queued): void {
                foreach ($users as $user) {
                    $delivery = WeeklyDigestDelivery::query()->firstOrCreate(
                        [
                            'user_id' => $user->id,
                            'week_key' => $weekKey,
                        ],
                        [
                            'window_start' => $windowStart,
                            'window_end' => $windowEnd,
                            'recipient' => $user->email,
                            'payload' => $payload,
                        ],
                    );

                    if (! $delivery->wasRecentlyCreated) {
                        continue;
                    }

                    dispatch(new DeliverWeeklyFailureDigest($delivery->id))->afterCommit();
                    $queued++;
                }
            });

        $this->info("Queued {$queued} weekly failure digest delivery(s) for {$weekKey}.");

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, CheckInEvent>  $events
     * @return array{week_key: string, window_start: string, window_end: string, monitor_count: int, missed: list<array{id: int, monitor_name: string, command: string|null, occurred_at: string}>, non_zero_exit: list<array{id: int, monitor_name: string, command: string|null, occurred_at: string, exit_code: int|null}>}
     */
    private function payload(
        Collection $events,
        string $weekKey,
        CarbonImmutable $windowStart,
        CarbonImmutable $windowEnd,
        int $monitorCount,
    ): array {
        $missed = [];
        $nonZeroExit = [];

        foreach ($events as $event) {
            $failure = [
                'id' => $event->id,
                'monitor_name' => $event->monitor_name ?? 'Unknown monitor',
                'command' => $event->command,
                'occurred_at' => $event->occurred_at->toISOString(),
            ];

            if ($event->event_type === CheckInEvent::TYPE_MISSED) {
                $missed[] = $failure;

                continue;
            }

            $nonZeroExit[] = [
                ...$failure,
                'exit_code' => $event->exit_code,
            ];
        }

        return [
            'week_key' => $weekKey,
            'window_start' => $windowStart->toISOString(),
            'window_end' => $windowEnd->toISOString(),
            'monitor_count' => $monitorCount,
            'missed' => $missed,
            'non_zero_exit' => $nonZeroExit,
        ];
    }
}
