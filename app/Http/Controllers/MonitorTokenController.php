<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CheckInEvent;
use App\Models\Monitor;
use ArtisanBuild\BuiltForCloud\BoundBearerCredential;
use ArtisanBuild\BuiltForCloud\BoundBearerCredentialAuthenticator;
use ArtisanBuild\BuiltForCloud\Exceptions\CredentialAuthorizationRefused;
use ArtisanBuild\BuiltForCloud\Exceptions\CredentialVerbRefused;
use ArtisanBuild\BuiltForCloud\Exceptions\InvalidCredentialInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MonitorTokenController extends Controller
{
    public function __construct(
        private readonly BoundBearerCredentialAuthenticator $authenticator,
    ) {}

    public function checkIn(Request $request, string $monitorId): JsonResponse
    {
        $credential = $this->authenticate($request, 'muster.check-in');

        if ($credential === null) {
            return $this->unauthorized();
        }

        $accepted = DB::transaction(function () use ($credential, $monitorId): bool {
            $monitor = $this->enabledMonitor($credential, $monitorId, true);

            if ($monitor === null) {
                return false;
            }

            $acceptedAt = now();
            $wasMissed = $monitor->status === 'missed';
            $monitor->forceFill([
                'status' => 'healthy',
                'last_check_in_at' => $acceptedAt,
                'next_due_at' => $acceptedAt->addSeconds($monitor->cadence_seconds),
            ])->save();

            $eventTypes = $wasMissed
                ? [CheckInEvent::TYPE_RECOVERED, CheckInEvent::TYPE_CHECK_IN]
                : [CheckInEvent::TYPE_CHECK_IN];
            $monitor->recordActivity($eventTypes, $acceptedAt);

            return true;
        });

        return $accepted
            ? response()->json(['status' => 'ok'])
            : $this->unauthorized();
    }

    public function history(Request $request, string $monitorId): JsonResponse
    {
        $credential = $this->authenticate($request, 'muster.history.read');

        if ($credential === null) {
            return $this->unauthorized();
        }

        $monitor = $this->enabledMonitor($credential, $monitorId);

        if ($monitor === null) {
            return $this->unauthorized();
        }

        return response()->json([
            'events' => $monitor->events()
                ->latest('occurred_at')
                ->orderByDesc('id')
                ->get()
                ->map(static fn (CheckInEvent $event): array => [
                    'type' => $event->event_type,
                    'occurred_at' => $event->occurred_at->toISOString(),
                    'command' => $event->command,
                    'display' => $event->display,
                    'expression' => $event->expression,
                    'execution_id' => $event->execution_id,
                    'timezone' => $event->timezone,
                    'exit_code' => $event->exit_code,
                    'runtime' => $event->runtime,
                    'background' => $event->background,
                ])
                ->all(),
        ]);
    }

    private function authenticate(Request $request, string $appPurpose): ?BoundBearerCredential
    {
        try {
            return $this->authenticator->authenticate($request, $appPurpose);
        } catch (CredentialAuthorizationRefused|CredentialVerbRefused|InvalidCredentialInput) {
            return null;
        }
    }

    private function enabledMonitor(
        BoundBearerCredential $credential,
        string $monitorId,
        bool $lock = false,
    ): ?Monitor {
        if (! ctype_digit($monitorId)) {
            return null;
        }

        $query = Monitor::query()
            ->select('monitors.*')
            ->join('credential_partitions as partition', 'partition.id', '=', 'monitors.credential_partition_id')
            ->where('monitors.id', $monitorId)
            ->where('monitors.is_enabled', true)
            ->where('partition.subject_type', $credential->scope->subject->type->value)
            ->where('partition.subject_ref', $credential->scope->subject->ref);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function unauthorized(): JsonResponse
    {
        return response()->json(['message' => 'Unauthenticated.'], 401);
    }
}
