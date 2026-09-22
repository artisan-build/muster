<?php

declare(strict_types=1);

namespace ArtisanBuild\MusterClient;

use DateTimeZone;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Str;
use Throwable;
use WeakMap;

final class ScheduleEventSubscriber
{
    public const string BACKGROUND_EXECUTION_ENV = 'MUSTER_CLIENT_EXECUTION_ID';

    /**
     * @var WeakMap<Event, array{
     *     payload: array<string, mixed>,
     *     original_command: string,
     *     original_mutex_resolver: mixed
     * }>
     */
    private WeakMap $executions;

    public function __construct(
        private readonly ScheduleEventReporter $reporter,
    ) {
        $this->executions = new WeakMap;
    }

    public function starting(ScheduledTaskStarting $event): void
    {
        $task = $event->task;
        $payload = $this->payloadFor($task);

        if ($payload === null) {
            return;
        }

        if ($this->willBeSkippedBecauseOverlapping($task)) {
            $this->reporter->report([
                ...$payload,
                'phase' => 'skipped',
                'exit_code' => null,
                'runtime' => null,
                'execution_id' => null,
            ]);

            return;
        }

        $executionId = (string) Str::ulid();
        $payload['execution_id'] = $executionId;
        $this->executions[$task] = [
            'payload' => $payload,
            'original_command' => $payload['command'],
            'original_mutex_resolver' => $task->mutexNameResolver,
        ];

        if ($task->runInBackground) {
            $this->propagateBackgroundExecutionId($task, $executionId);
        }

        $this->reporter->report([
            ...$payload,
            'phase' => 'started',
            'exit_code' => null,
            'runtime' => null,
        ]);
    }

    public function finished(ScheduledTaskFinished $event): void
    {
        $task = $event->task;
        $context = $this->executions[$task] ?? null;

        if ($context === null) {
            return;
        }

        if ($task->runInBackground) {
            $this->restoreBackgroundTask($task, $context);
            unset($this->executions[$task]);

            return;
        }

        if ($task->skippedBecauseOverlapping) {
            unset($this->executions[$task]);

            return;
        }

        if ($task->exitCode === null || $task->exitCode !== 0) {
            return;
        }

        $this->reporter->report([
            ...$context['payload'],
            'phase' => 'finished',
            'exit_code' => $task->exitCode,
            'runtime' => $event->runtime,
            'execution_id' => $context['payload']['execution_id'],
        ]);
        unset($this->executions[$task]);
    }

    public function backgroundFinished(ScheduledBackgroundTaskFinished $event): void
    {
        $executionId = getenv(self::BACKGROUND_EXECUTION_ENV);
        $payload = $this->payloadFor($event->task);

        if ($payload === null
            || ! is_string($executionId)
            || (! Str::isUlid($executionId) && ! Str::isUuid($executionId))) {
            return;
        }

        $exitCode = $event->task->exitCode;
        $this->reporter->report([
            ...$payload,
            'phase' => $exitCode === 0 ? 'finished' : 'failed',
            'exit_code' => $exitCode,
            'runtime' => null,
            'execution_id' => $executionId,
        ]);
    }

    public function failed(ScheduledTaskFailed $event): void
    {
        $task = $event->task;
        $context = $this->executions[$task] ?? null;

        if ($context === null) {
            return;
        }

        if ($task->runInBackground) {
            $this->restoreBackgroundTask($task, $context);
        }

        $this->reporter->report([
            ...$context['payload'],
            'phase' => 'failed',
            'exit_code' => $task->exitCode === 0 ? null : $task->exitCode,
            'runtime' => null,
            'execution_id' => $context['payload']['execution_id'],
        ]);
        unset($this->executions[$task]);
    }

    public function skipped(ScheduledTaskSkipped $event): void
    {
        $payload = $this->payloadFor($event->task);

        if ($payload === null) {
            return;
        }

        $this->reporter->report([
            ...$payload,
            'phase' => 'skipped',
            'exit_code' => null,
            'runtime' => null,
            'execution_id' => null,
        ]);
    }

    /** @return array{key: string, command: string, display: string, expression: string, background: bool, timezone: string|null}|null */
    private function payloadFor(Event $task): ?array
    {
        if (! is_string($task->command) || $task->command === '') {
            return null;
        }

        $command = $task->command;
        $timezone = $task->timezone;

        return [
            'key' => hash('sha256', $command),
            'command' => $command,
            'display' => Str::limit($task->getSummaryForDisplay(), 255, ''),
            'expression' => $task->getExpression(),
            'background' => (bool) $task->runInBackground,
            'timezone' => $timezone instanceof DateTimeZone ? $timezone->getName() : $timezone,
        ];
    }

    private function willBeSkippedBecauseOverlapping(Event $task): bool
    {
        if (! $task->withoutOverlapping) {
            return false;
        }

        try {
            return (bool) $task->mutex->exists($task);
        } catch (Throwable) {
            return false;
        }
    }

    private function propagateBackgroundExecutionId(Event $task, string $executionId): void
    {
        $mutexName = $task->mutexName();
        $task->createMutexNameUsing($mutexName);

        $task->command = DIRECTORY_SEPARATOR === '\\'
            ? sprintf('set "%s=%s" && %s', self::BACKGROUND_EXECUTION_ENV, $executionId, $task->command)
            : sprintf("export %s='%s'; %s", self::BACKGROUND_EXECUTION_ENV, $executionId, $task->command);
    }

    /**
     * @param  array{payload: array<string, mixed>, original_command: string, original_mutex_resolver: mixed}  $context
     */
    private function restoreBackgroundTask(Event $task, array $context): void
    {
        $task->command = $context['original_command'];
        $task->mutexNameResolver = $context['original_mutex_resolver'];
    }
}
