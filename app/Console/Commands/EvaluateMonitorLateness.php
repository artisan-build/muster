<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CheckInEvent;
use App\Models\Monitor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class EvaluateMonitorLateness extends Command
{
    protected $signature = 'monitors:evaluate';

    protected $description = 'Mark enabled monitors late or missed when their check-ins are overdue.';

    public function handle(): int
    {
        $transitionCount = 0;

        Monitor::query()
            ->where('is_enabled', true)
            ->whereNotNull('next_due_at')
            ->whereIn('status', ['healthy', 'late'])
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function ($monitors) use (&$transitionCount): void {
                foreach ($monitors as $candidate) {
                    $transitioned = DB::transaction(function () use ($candidate): bool {
                        $monitor = Monitor::query()->lockForUpdate()->find($candidate->id);

                        if ($monitor === null || ! $monitor->is_enabled || $monitor->next_due_at === null) {
                            return false;
                        }

                        $evaluatedAt = now();

                        if ($monitor->status === 'healthy' && $evaluatedAt->greaterThanOrEqualTo($monitor->next_due_at)) {
                            $monitor->forceFill(['status' => 'late'])->save();
                            $monitor->recordActivity([CheckInEvent::TYPE_LATE], $evaluatedAt);

                            return true;
                        }

                        if ($monitor->status === 'late'
                            && $evaluatedAt->greaterThanOrEqualTo($monitor->next_due_at->addSeconds($monitor->grace_seconds))) {
                            $monitor->forceFill(['status' => 'missed'])->save();
                            $monitor->recordActivity([CheckInEvent::TYPE_MISSED], $evaluatedAt);

                            return true;
                        }

                        return false;
                    });

                    if ($transitioned) {
                        $transitionCount++;
                    }
                }
            });

        $this->info("Evaluated monitors; {$transitionCount} state transition(s) recorded.");

        return self::SUCCESS;
    }
}
