<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CheckInEvent;
use App\Models\Monitor;
use App\Models\WeeklyDigestDelivery;
use App\Services\WeeklyDigestPublisher;
use ArtisanBuild\BuiltForCloud\Commands\SystemAuthorityCommand;
use ArtisanBuild\BuiltForCloud\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

final class GenerateWeeklyFailureDigest extends SystemAuthorityCommand
{
    protected $signature = 'digests:generate-weekly';

    protected $description = 'Queue the automatic account-level failure digest for the last complete UTC week.';

    public function handle(WeeklyDigestPublisher $publisher): int
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
            ->oldest('occurred_at')
            ->orderBy('id')
            ->get([
                'id',
                'event_type',
                'monitor_name',
                'command',
                'occurred_at',
                'exit_code',
            ]);

        $payload = $this->payload(
            $events,
            $this->stillMissed($windowStart, $windowEnd),
            $weekKey,
            $windowStart,
            $windowEnd,
            $monitorCount,
        );

        User::query()
            ->where('status', 'active')
            ->where('email_is_generated', false)
            ->select(['id', 'email'])
            ->orderBy('id')
            ->chunkById(100, function ($users) use ($payload, $weekKey, $windowStart, $windowEnd): void {
                foreach ($users as $user) {
                    WeeklyDigestDelivery::query()->firstOrCreate(
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

                }
            });

        $queued = $publisher->publishEligible($weekKey);

        $this->info("Queued {$queued} weekly failure digest delivery(s) for {$weekKey}.");

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, CheckInEvent>  $events
     * @param  list<array{monitor_id: int, monitor_name: string, command: string|null, reference_at: string|null, reference_type: string}>  $stillMissed
     * @return array{week_key: string, window_start: string, window_end: string, monitor_count: int, missed: list<array{id: int, monitor_name: string, command: string|null, occurred_at: string}>, non_zero_exit: list<array{id: int, monitor_name: string, command: string|null, occurred_at: string, exit_code: int|null}>, still_missed: list<array{monitor_id: int, monitor_name: string, command: string|null, reference_at: string|null, reference_type: string}>}
     */
    private function payload(
        Collection $events,
        array $stillMissed,
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
            'still_missed' => $stillMissed,
        ];
    }

    /**
     * @return list<array{monitor_id: int, monitor_name: string, command: string|null, reference_at: string|null, reference_type: string}>
     */
    private function stillMissed(CarbonImmutable $windowStart, CarbonImmutable $windowEnd): array
    {
        $stillMissed = [];

        Monitor::query()
            ->where('status', 'missed')
            ->select(['id', 'name', 'command', 'last_check_in_at'])
            ->orderBy('id')
            ->chunkById(100, function ($monitors) use ($windowStart, $windowEnd, &$stillMissed): void {
                foreach ($monitors as $monitor) {
                    $missedEvent = $monitor->events()
                        ->where('event_type', CheckInEvent::TYPE_MISSED)
                        ->latest('occurred_at')
                        ->latest('id')
                        ->first();

                    if ($missedEvent === null) {
                        $stillMissed[] = [
                            'monitor_id' => $monitor->id,
                            'monitor_name' => $monitor->name,
                            'command' => $monitor->command,
                            'reference_at' => $monitor->last_check_in_at?->toISOString(),
                            'reference_type' => 'last_check_in_at',
                        ];

                        continue;
                    }

                    if ($missedEvent->occurred_at->greaterThanOrEqualTo($windowStart)
                        && $missedEvent->occurred_at->lessThan($windowEnd)) {
                        continue;
                    }

                    $stillMissed[] = [
                        'monitor_id' => $monitor->id,
                        'monitor_name' => $missedEvent->monitor_name ?? $monitor->name,
                        'command' => $missedEvent->command ?? $monitor->command,
                        'reference_at' => $missedEvent->occurred_at->toISOString(),
                        'reference_type' => 'missed_at',
                    ];
                }
            });

        return $stillMissed;
    }
}
