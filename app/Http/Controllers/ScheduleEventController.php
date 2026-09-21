<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CheckInEvent;
use App\Models\CredentialPartition;
use App\Models\Monitor;
use App\Models\ScheduleExecution;
use ArtisanBuild\BuiltForCloud\BoundBearerCredential;
use ArtisanBuild\BuiltForCloud\BoundBearerCredentialAuthenticator;
use ArtisanBuild\BuiltForCloud\Exceptions\CredentialAuthorizationRefused;
use ArtisanBuild\BuiltForCloud\Exceptions\CredentialVerbRefused;
use ArtisanBuild\BuiltForCloud\Exceptions\InvalidCredentialInput;
use Carbon\CarbonImmutable;
use Closure;
use Cron\CronExpression;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ScheduleEventController extends Controller
{
    private const int COMPLETED_EXECUTION_LIMIT = 100;

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
            $monitor = $partition->monitors()
                ->where('schedule_key', $expectedKey)
                ->lockForUpdate()
                ->first();

            if ($monitor === null && in_array($payload['phase'], ['finished', 'failed'], true)) {
                throw ValidationException::withMessages([
                    'execution_id' => 'The execution does not exist.',
                ]);
            }

            if ($monitor === null) {
                $registered = $partition->monitors()->firstOrCreate(
                    ['schedule_key' => $expectedKey],
                    [
                        'name' => $payload['display'],
                        'command' => $payload['command'],
                        'display' => $payload['display'],
                        'expression' => $payload['expression'],
                        'timezone' => $payload['timezone'],
                        'cadence_seconds' => 1,
                        'grace_seconds' => self::DEFAULT_GRACE_SECONDS,
                        'background' => $payload['background'],
                    ],
                );
                $monitor = Monitor::query()->lockForUpdate()->findOrFail($registered->id);
            }

            $acceptedAt = now()->toImmutable();

            if ($payload['phase'] === 'started') {
                return $this->recordStart($monitor, $payload, $acceptedAt);
            }

            if (in_array($payload['phase'], ['finished', 'failed'], true)) {
                return $this->recordCompletion($monitor, $payload, $acceptedAt);
            }

            return $this->recordSkip($monitor, $payload, $acceptedAt);
        });

        return response()->json([
            'status' => 'ok',
            'monitor_id' => $monitor->id,
        ]);
    }

    /**
     * @param  array{key: string, command: string, display: string, expression: string, phase: 'started'|'finished'|'skipped'|'failed', exit_code: int|null, runtime: float|null, background: bool, execution_id: string|null, timezone: string}  $payload
     */
    private function recordStart(Monitor $monitor, array $payload, CarbonImmutable $acceptedAt): Monitor
    {
        $existingExecution = $monitor->executions()
            ->where('execution_id', $payload['execution_id'])
            ->lockForUpdate()
            ->first();

        if ($existingExecution !== null) {
            return $monitor;
        }

        [$nextDueAt, $cadenceSeconds] = $this->expectation(
            $payload['expression'],
            $payload['timezone'],
            $acceptedAt,
        );
        $hasActiveFailureIncident = $monitor->hasActiveFailureIncident();

        $monitor->executions()->create([
            'execution_id' => $payload['execution_id'],
            'status' => ScheduleExecution::STATUS_RUNNING,
            'started_at' => $acceptedAt,
            'background' => $payload['background'],
            'timezone' => $payload['timezone'],
        ]);
        $monitor->forceFill([
            ...$this->scheduleData($payload),
            'status' => $hasActiveFailureIncident ? 'failed' : 'running',
            'last_check_in_at' => $acceptedAt,
            'next_due_at' => $nextDueAt,
            'cadence_seconds' => $cadenceSeconds,
            ...($hasActiveFailureIncident ? [] : [
                'last_exit_code' => null,
                'last_runtime' => null,
            ]),
        ])->save();

        $monitor->recordActivity([CheckInEvent::TYPE_STARTED], $acceptedAt, $this->eventData($payload));

        return $monitor;
    }

    /**
     * @param  array{key: string, command: string, display: string, expression: string, phase: 'started'|'finished'|'skipped'|'failed', exit_code: int|null, runtime: float|null, background: bool, execution_id: string|null, timezone: string}  $payload
     */
    private function recordCompletion(Monitor $monitor, array $payload, CarbonImmutable $acceptedAt): Monitor
    {
        $execution = $monitor->executions()
            ->where('execution_id', $payload['execution_id'])
            ->lockForUpdate()
            ->first();

        if ($execution === null) {
            throw ValidationException::withMessages([
                'execution_id' => 'The execution does not exist.',
            ]);
        }

        if ($execution->status !== ScheduleExecution::STATUS_RUNNING) {
            return $monitor;
        }

        if ($execution->background !== $payload['background']) {
            throw ValidationException::withMessages([
                'background' => 'The background value must match the started execution.',
            ]);
        }

        $eventType = $payload['phase'] === 'finished' && $payload['exit_code'] === 0
            ? CheckInEvent::TYPE_FINISHED
            : CheckInEvent::TYPE_NON_ZERO_EXIT;
        $hasActiveFailureIncident = $monitor->hasActiveFailureIncident();
        $executionStatus = $eventType === CheckInEvent::TYPE_FINISHED
            ? ScheduleExecution::STATUS_FINISHED
            : ScheduleExecution::STATUS_FAILED;
        $execution->forceFill([
            'status' => $executionStatus,
            'completed_at' => $acceptedAt,
            'runtime' => $payload['runtime'],
            'exit_code' => $payload['exit_code'],
        ])->save();

        $hasOpenExecution = $monitor->executions()
            ->where('status', ScheduleExecution::STATUS_RUNNING)
            ->exists();
        $latestCompletedExecution = $monitor->executions()
            ->whereNotNull('completed_at')
            ->latest('started_at')
            ->orderByDesc('id')
            ->firstOrFail();
        $isLatestCompletion = $latestCompletedExecution->is($execution);
        $updates = [
            'status' => $latestCompletedExecution->status === ScheduleExecution::STATUS_FAILED
                ? 'failed'
                : ($hasOpenExecution ? 'running' : 'healthy'),
            'last_check_in_at' => $acceptedAt,
            ...($isLatestCompletion ? [
                'last_exit_code' => $payload['exit_code'],
                'last_runtime' => $payload['runtime'],
            ] : []),
        ];

        $monitor->forceFill($updates)->save();
        $eventTypes = [$eventType];

        if ($eventType === CheckInEvent::TYPE_FINISHED
            && $isLatestCompletion
            && $hasActiveFailureIncident) {
            $eventTypes[] = CheckInEvent::TYPE_RECOVERED;
        }

        $monitor->recordActivity(
            $eventTypes,
            $acceptedAt,
            $this->eventData($payload),
        );
        $this->pruneCompletedExecutions($monitor);

        return $monitor;
    }

    /**
     * @param  array{key: string, command: string, display: string, expression: string, phase: 'started'|'finished'|'skipped'|'failed', exit_code: int|null, runtime: float|null, background: bool, execution_id: string|null, timezone: string}  $payload
     */
    private function recordSkip(Monitor $monitor, array $payload, CarbonImmutable $acceptedAt): Monitor
    {
        [$nextDueAt, $cadenceSeconds] = $this->expectation(
            $payload['expression'],
            $payload['timezone'],
            $acceptedAt,
        );
        $updates = [
            ...$this->scheduleData($payload),
            'next_due_at' => $nextDueAt,
            'cadence_seconds' => $cadenceSeconds,
        ];

        if ($monitor->status === 'late') {
            $updates['status'] = $monitor->hasActiveFailureIncident()
                ? 'failed'
                : ($monitor->executions()
                    ->where('status', ScheduleExecution::STATUS_RUNNING)
                    ->exists() ? 'running' : 'healthy');
        }

        $monitor->forceFill($updates)->save();
        $monitor->recordActivity([CheckInEvent::TYPE_SKIPPED], $acceptedAt, $this->eventData($payload));

        return $monitor;
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
     *     background: bool,
     *     execution_id: string|null,
     *     timezone: string
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
            'execution_id',
            'timezone',
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
            'execution_id' => [
                'nullable',
                'string',
                'max:36',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && ! Str::isUlid($value) && ! Str::isUuid($value)) {
                        $fail('The execution id must be a valid ULID or UUID.');
                    }
                },
            ],
            'timezone' => [
                'nullable',
                'string',
                'max:255',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value)
                        && ! in_array($value, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
                        $fail('The timezone must be a valid IANA timezone.');
                    }
                },
            ],
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

            if (in_array($phase, ['started', 'finished', 'failed'], true)
                && $request->input('execution_id') === null) {
                $validator->errors()->add('execution_id', 'The execution id field is required for this phase.');
            }

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

        /** @var array{key: string, command: string, display: string, expression: string, phase: 'started'|'finished'|'skipped'|'failed', exit_code?: int|null, runtime?: int|float|null, background: bool, execution_id?: string|null, timezone?: string|null} $validated */
        $validated = $validator->validate();

        return [
            ...$validated,
            'exit_code' => $validated['exit_code'] ?? null,
            'runtime' => isset($validated['runtime']) ? (float) $validated['runtime'] : null,
            'execution_id' => $validated['execution_id'] ?? null,
            'timezone' => $validated['timezone'] ?? 'UTC',
        ];
    }

    /**
     * @param  array{command: string, display: string, expression: string, background: bool, timezone: string}  $payload
     * @return array{name: string, command: string, display: string, expression: string, background: bool, timezone: string}
     */
    private function scheduleData(array $payload): array
    {
        return [
            'name' => $payload['display'],
            'command' => $payload['command'],
            'display' => $payload['display'],
            'expression' => $payload['expression'],
            'background' => $payload['background'],
            'timezone' => $payload['timezone'],
        ];
    }

    /**
     * @param  array{command: string, display: string, expression: string, exit_code: int|null, runtime: float|null, background: bool, execution_id: string|null, timezone: string}  $payload
     * @return array{command: string, display: string, expression: string, exit_code: int|null, runtime: float|null, background: bool, execution_id: string|null, timezone: string}
     */
    private function eventData(array $payload): array
    {
        return [
            'command' => $payload['command'],
            'display' => $payload['display'],
            'expression' => $payload['expression'],
            'exit_code' => $payload['exit_code'],
            'runtime' => $payload['runtime'],
            'background' => $payload['background'],
            'execution_id' => $payload['execution_id'],
            'timezone' => $payload['timezone'],
        ];
    }

    private function pruneCompletedExecutions(Monitor $monitor): void
    {
        $retainedIds = $monitor->executions()
            ->whereNotNull('completed_at')
            ->latest('completed_at')
            ->orderByDesc('id')
            ->limit(self::COMPLETED_EXECUTION_LIMIT)
            ->pluck('id');

        if ($retainedIds->count() < self::COMPLETED_EXECUTION_LIMIT) {
            return;
        }

        $monitor->executions()
            ->whereNotNull('completed_at')
            ->whereNotIn('id', $retainedIds)
            ->delete();
    }

    /** @return array{CarbonImmutable, int} */
    private function expectation(string $expression, string $timezone, CarbonImmutable $acceptedAt): array
    {
        $cron = new CronExpression($expression);
        $nextDueAt = CarbonImmutable::instance(
            $cron->getNextRunDate($acceptedAt, 0, false, $timezone),
        )->utc();
        $followingDueAt = CarbonImmutable::instance(
            $cron->getNextRunDate($nextDueAt, 0, false, $timezone),
        )->utc();

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
