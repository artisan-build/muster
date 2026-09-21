<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CheckInEvent;
use App\Models\CredentialPartition;
use App\Models\Monitor;
use ArtisanBuild\BuiltForCloud\BoundBearerCredential;
use ArtisanBuild\BuiltForCloud\BoundBearerCredentialAuthenticator;
use ArtisanBuild\BuiltForCloud\Exceptions\CredentialAuthorizationRefused;
use ArtisanBuild\BuiltForCloud\Exceptions\CredentialVerbRefused;
use ArtisanBuild\BuiltForCloud\Exceptions\InvalidCredentialInput;
use Carbon\CarbonImmutable;
use Closure;
use Cron\CronExpression;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ScheduleEventController extends Controller
{
    private const int DEFAULT_GRACE_SECONDS = 60;

    public function __construct(
        private readonly BoundBearerCredentialAuthenticator $authenticator,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $credential = $this->authenticate($request);

        if ($credential === null) {
            return $this->unauthorized();
        }

        $partition = $this->partitionFor($credential);

        if ($partition === null) {
            return $this->unauthorized();
        }

        $payload = $this->validatedPayload($request);
        $expectedKey = Monitor::scheduleKeyForCommand($payload['command']);

        if (! hash_equals($expectedKey, $payload['key'])) {
            throw ValidationException::withMessages([
                'key' => 'The key must be derived from the command.',
            ]);
        }

        $monitor = DB::transaction(function () use ($partition, $payload, $expectedKey): Monitor {
            $registered = $partition->monitors()->firstOrCreate(
                ['schedule_key' => $expectedKey],
                [
                    'name' => $payload['display'],
                    'command' => $payload['command'],
                    'display' => $payload['display'],
                    'expression' => $payload['expression'],
                    'cadence_seconds' => 1,
                    'grace_seconds' => self::DEFAULT_GRACE_SECONDS,
                    'background' => $payload['background'],
                ],
            );

            $monitor = Monitor::query()->lockForUpdate()->findOrFail($registered->id);
            $acceptedAt = now()->toImmutable();
            [$nextDueAt, $cadenceSeconds] = $this->expectation($payload['expression'], $acceptedAt);
            $expressionChanged = $monitor->expression !== $payload['expression'];
            $updates = [
                'name' => $payload['display'],
                'command' => $payload['command'],
                'display' => $payload['display'],
                'expression' => $payload['expression'],
                'background' => $payload['background'],
                'last_check_in_at' => $acceptedAt,
            ];

            if ($registered->wasRecentlyCreated
                || $expressionChanged
                || in_array($payload['phase'], ['started', 'skipped'], true)) {
                $updates['cadence_seconds'] = $cadenceSeconds;
                $updates['next_due_at'] = $nextDueAt;
            }

            if ($registered->wasRecentlyCreated) {
                $updates['is_enabled'] = true;
            }

            $eventType = match ($payload['phase']) {
                'started' => CheckInEvent::TYPE_STARTED,
                'skipped' => CheckInEvent::TYPE_SKIPPED,
                'failed' => CheckInEvent::TYPE_NON_ZERO_EXIT,
                'finished' => $payload['exit_code'] === 0
                    ? CheckInEvent::TYPE_FINISHED
                    : CheckInEvent::TYPE_NON_ZERO_EXIT,
            };

            if ($payload['phase'] === 'started') {
                $updates['status'] = 'running';
                $updates['last_exit_code'] = null;
                $updates['last_runtime'] = null;
            } elseif ($payload['phase'] === 'skipped') {
                $updates['status'] = $monitor->status === 'running' ? 'running' : 'healthy';
            } elseif ($eventType === CheckInEvent::TYPE_FINISHED) {
                $updates['status'] = 'healthy';
                $updates['last_exit_code'] = $payload['exit_code'];
                $updates['last_runtime'] = $payload['runtime'];
            } else {
                $updates['status'] = 'failed';
                $updates['last_exit_code'] = $payload['exit_code'];
                $updates['last_runtime'] = $payload['runtime'];
            }

            $monitor->forceFill($updates)->save();
            $monitor->recordActivity([$eventType], $acceptedAt, [
                'command' => $payload['command'],
                'display' => $payload['display'],
                'expression' => $payload['expression'],
                'exit_code' => $payload['exit_code'],
                'runtime' => $payload['runtime'],
                'background' => $payload['background'],
            ]);

            return $monitor;
        });

        return response()->json([
            'status' => 'ok',
            'monitor_id' => $monitor->id,
        ]);
    }

    /**
     * @return array{
     *     key: string,
     *     command: string,
     *     display: string,
     *     expression: string,
     *     phase: 'started'|'finished'|'skipped'|'failed',
     *     exit_code: int|null,
     *     runtime: float|null,
     *     background: bool
     * }
     */
    private function validatedPayload(Request $request): array
    {
        $allowedFields = [
            'key',
            'command',
            'display',
            'expression',
            'phase',
            'exit_code',
            'runtime',
            'background',
        ];
        $validator = Validator::make($request->all(), [
            'key' => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/'],
            'command' => ['required', 'string', 'max:4096'],
            'display' => ['required', 'string', 'max:255'],
            'expression' => [
                'required',
                'string',
                'max:255',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && ! CronExpression::isValidExpression($value)) {
                        $fail('The expression must be a valid cron expression.');
                    }
                },
            ],
            'phase' => ['required', 'string', 'in:started,finished,skipped,failed'],
            'exit_code' => ['nullable', 'integer'],
            'runtime' => ['nullable', 'numeric', 'min:0'],
            'background' => ['required', 'boolean'],
            'output' => ['prohibited'],
        ]);

        $validator->after(function (\Illuminate\Contracts\Validation\Validator $validator) use ($request, $allowedFields): void {
            if ($request->exists('output')) {
                $validator->errors()->add('output', 'The output field is prohibited.');
            }

            foreach (array_diff(array_keys($request->all()), [...$allowedFields, 'output']) as $field) {
                $validator->errors()->add((string) $field, 'This field is not accepted.');
            }

            $phase = $request->input('phase');
            $background = $request->boolean('background');

            if ($phase === 'finished' && $request->input('exit_code') === null) {
                $validator->errors()->add('exit_code', 'The exit code field is required when phase is finished.');
            }

            if ($phase === 'finished' && ! $background && $request->input('runtime') === null) {
                $validator->errors()->add('runtime', 'The runtime field is required for a foreground finish.');
            }

            if ($phase === 'failed'
                && $request->input('exit_code') !== null
                && $request->integer('exit_code') === 0) {
                $validator->errors()->add('exit_code', 'The exit code must be non-zero when phase is failed.');
            }

            if (in_array($phase, ['started', 'skipped'], true)) {
                if ($request->input('exit_code') !== null) {
                    $validator->errors()->add('exit_code', 'The exit code field is only accepted for a completion.');
                }

                if ($request->input('runtime') !== null) {
                    $validator->errors()->add('runtime', 'The runtime field is only accepted for a completion.');
                }
            }
        });

        /** @var array{key: string, command: string, display: string, expression: string, phase: 'started'|'finished'|'skipped'|'failed', exit_code?: int|null, runtime?: int|float|null, background: bool} $validated */
        $validated = $validator->validate();

        return [
            ...$validated,
            'exit_code' => $validated['exit_code'] ?? null,
            'runtime' => isset($validated['runtime']) ? (float) $validated['runtime'] : null,
        ];
    }

    /** @return array{CarbonImmutable, int} */
    private function expectation(string $expression, CarbonImmutable $acceptedAt): array
    {
        $cron = new CronExpression($expression);
        $nextDueAt = CarbonImmutable::instance($cron->getNextRunDate($acceptedAt, 0, false, 'UTC'));
        $followingDueAt = CarbonImmutable::instance($cron->getNextRunDate($nextDueAt, 0, false, 'UTC'));

        return [$nextDueAt, max(1, (int) $nextDueAt->diffInSeconds($followingDueAt))];
    }

    private function authenticate(Request $request): ?BoundBearerCredential
    {
        try {
            return $this->authenticator->authenticate($request, 'muster.check-in');
        } catch (CredentialAuthorizationRefused|CredentialVerbRefused|InvalidCredentialInput) {
            return null;
        }
    }

    private function partitionFor(BoundBearerCredential $credential): ?CredentialPartition
    {
        return CredentialPartition::query()
            ->where('subject_type', $credential->scope->subject->type->value)
            ->where('subject_ref', $credential->scope->subject->ref)
            ->where('installation_ref', $credential->scope->installation)
            ->where('application_ref', $credential->scope->application)
            ->where('audience', $credential->scope->audience)
            ->first();
    }

    private function unauthorized(): JsonResponse
    {
        return response()->json(['message' => 'Unauthenticated.'], 401);
    }
}
