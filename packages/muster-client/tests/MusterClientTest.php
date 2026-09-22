<?php

declare(strict_types=1);

use ArtisanBuild\MusterClient\ScheduleEventSubscriber;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;

final class MusterClientTestMutex implements EventMutex
{
    public int $createCalls = 0;

    public int $existsCalls = 0;

    public function __construct(
        public bool $held = false,
        public bool $acquirable = true,
    ) {}

    public function create(ScheduledEvent $event): bool
    {
        $this->createCalls++;

        if ($this->held || ! $this->acquirable) {
            return false;
        }

        return $this->held = true;
    }

    public function exists(ScheduledEvent $event): bool
    {
        $this->existsCalls++;

        return $this->held;
    }

    public function forget(ScheduledEvent $event): void
    {
        $this->held = false;
    }
}

function configureMusterClient(): void
{
    config()->set('muster.url', 'https://muster.test/api/schedule-events');
    config()->set('muster.token', 'test-created-token');
    config()->set('bfc-client.identity', 'test-created-installation');
}

function musterScheduledEvent(
    string $command = 'php artisan reports:daily --test-created',
    string $expression = '* * * * *',
    string|DateTimeZone|null $timezone = null,
    bool $background = false,
    ?MusterClientTestMutex $mutex = null,
): ScheduledEvent {
    $event = new ScheduledEvent($mutex ?? new MusterClientTestMutex, $command, $timezone);
    $event->cron($expression);

    if ($background) {
        $event->runInBackground();
    }

    return $event;
}

/** @return list<array<string, mixed>> */
function recordedMusterPayloads(): array
{
    return musterClientPrivateHttp()->recorded()
        ->map(fn (array $record): array => $record[0]->data())
        ->values()
        ->all();
}

function runMusterForeground(ScheduledEvent $task, float $runtime = 1.25, int $exitCode = 0): void
{
    Event::dispatch(new ScheduledTaskStarting($task));
    $task->callBeforeCallbacks(app());
    $task->exitCode = $exitCode;
    Event::dispatch(new ScheduledTaskFinished($task, $runtime));

    if ($exitCode !== 0) {
        Event::dispatch(new ScheduledTaskFailed($task, new RuntimeException('Test-created failure.')));
    }
}

it('reports distinct starts and finishes with stable command identity and no output', function (): void {
    configureMusterClient();
    musterClientPrivateHttp()->fake();
    $task = musterScheduledEvent(timezone: new DateTimeZone('Europe/Zurich'));

    runMusterForeground($task, runtime: 2.75);

    $payloads = recordedMusterPayloads();

    expect($payloads)->toHaveCount(2)
        ->and($payloads[0]['phase'])->toBe('started')
        ->and($payloads[1]['phase'])->toBe('finished')
        ->and($payloads[0]['execution_id'])->toBe($payloads[1]['execution_id'])
        ->and($payloads[0]['key'])->toBe(hash('sha256', 'php artisan reports:daily --test-created'))
        ->and($payloads[0]['timezone'])->toBe('Europe/Zurich')
        ->and($payloads[1]['exit_code'])->toBe(0)
        ->and($payloads[1]['runtime'])->toBe(2.75);

    foreach ($payloads as $payload) {
        expect($payload)->not->toHaveKey('output');
    }

    musterClientPrivateHttp()->assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-created-token')
        && $request->hasHeader('X-BfC-Client-Id', 'test-created-installation'));
});

it('keeps the same key when only the cron expression changes', function (): void {
    configureMusterClient();
    musterClientPrivateHttp()->fake();

    runMusterForeground(musterScheduledEvent(expression: '* * * * *'));
    runMusterForeground(musterScheduledEvent(expression: '*/5 * * * *'));

    $starts = collect(recordedMusterPayloads())->where('phase', 'started')->values();

    expect($starts)->toHaveCount(2)
        ->and($starts[0]['key'])->toBe($starts[1]['key'])
        ->and($starts[0]['expression'])->toBe('* * * * *')
        ->and($starts[1]['expression'])->toBe('*/5 * * * *');
});

it('does not finish a background task at foreground dispatch and correlates the child completion', function (): void {
    configureMusterClient();
    musterClientPrivateHttp()->fake();
    $task = musterScheduledEvent(background: true);
    $originalCommand = $task->command;
    $originalMutex = $task->mutexName();

    Event::dispatch(new ScheduledTaskStarting($task));
    $task->callBeforeCallbacks(app());

    $started = recordedMusterPayloads()[0];

    expect(recordedMusterPayloads())->toHaveCount(1)
        ->and($started['phase'])->toBe('started')
        ->and($task->command)->toContain(ScheduleEventSubscriber::BACKGROUND_EXECUTION_ENV)
        ->and($task->mutexName())->toBe($originalMutex);

    $task->exitCode = 0;
    Event::dispatch(new ScheduledTaskFinished($task, 0.01));

    expect(recordedMusterPayloads())->toHaveCount(1)
        ->and($task->command)->toBe($originalCommand);

    putenv(ScheduleEventSubscriber::BACKGROUND_EXECUTION_ENV.'='.$started['execution_id']);
    $completionTask = musterScheduledEvent(background: true);
    $completionTask->exitCode = 0;
    Event::dispatch(new ScheduledBackgroundTaskFinished($completionTask));

    $payloads = recordedMusterPayloads();

    expect($payloads)->toHaveCount(2)
        ->and($payloads[1]['phase'])->toBe('finished')
        ->and($payloads[1]['execution_id'])->toBe($started['execution_id'])
        ->and($payloads[1]['runtime'])->toBeNull();
});

it('reports a non-zero foreground exit as failed rather than finished', function (): void {
    configureMusterClient();
    musterClientPrivateHttp()->fake();

    runMusterForeground(musterScheduledEvent(), exitCode: 12);

    $payloads = recordedMusterPayloads();

    expect($payloads)->toHaveCount(2)
        ->and(array_column($payloads, 'phase'))->toBe(['started', 'failed'])
        ->and($payloads[1]['exit_code'])->toBe(12)
        ->and($payloads[1])->not->toHaveKey('output');
});

it('reports a thrown failure with a zero exit code without sending a contradictory zero', function (): void {
    configureMusterClient();
    musterClientPrivateHttp()->fake();
    $task = musterScheduledEvent();

    Event::dispatch(new ScheduledTaskStarting($task));
    $task->callBeforeCallbacks(app());
    $task->exitCode = 0;
    Event::dispatch(new ScheduledTaskFailed($task, new RuntimeException('Test-created callback failure.')));

    $payloads = recordedMusterPayloads();

    expect($payloads)->toHaveCount(2)
        ->and(array_column($payloads, 'phase'))->toBe(['started', 'failed'])
        ->and($payloads[1]['exit_code'])->toBeNull();
});

it('reports overlap and filter skips without creating a running execution', function (): void {
    configureMusterClient();
    musterClientPrivateHttp()->fake();
    $overlap = musterScheduledEvent(
        command: 'php artisan reports:overlap --test-created',
        mutex: new MusterClientTestMutex(held: true),
    );
    $overlap->withoutOverlapping();

    Event::dispatch(new ScheduledTaskStarting($overlap));
    $overlap->skippedBecauseOverlapping = true;
    Event::dispatch(new ScheduledTaskFinished($overlap, 0.01));

    $filtered = musterScheduledEvent(command: 'php artisan reports:filtered --test-created');
    Event::dispatch(new ScheduledTaskSkipped($filtered));

    $payloads = recordedMusterPayloads();

    expect($payloads)->toHaveCount(2)
        ->and(array_column($payloads, 'phase'))->toBe(['skipped', 'skipped'])
        ->and($payloads[0]['execution_id'])->toBeNull()
        ->and($payloads[1]['execution_id'])->toBeNull();
});

it('does not report a start when the overlap mutex is lost after the starting event', function (): void {
    configureMusterClient();
    musterClientPrivateHttp()->fake();
    $mutex = new MusterClientTestMutex(acquirable: false);
    $task = musterScheduledEvent(
        command: 'php artisan reports:raced --test-created',
        mutex: $mutex,
    );
    $task->withoutOverlapping();

    Event::dispatch(new ScheduledTaskStarting($task));
    $task->run(app());
    Event::dispatch(new ScheduledTaskFinished($task, 0.01));

    $payloads = recordedMusterPayloads();

    expect($mutex->existsCalls)->toBe(0)
        ->and($mutex->createCalls)->toBe(1)
        ->and($task->skippedBecauseOverlapping)->toBeTrue()
        ->and($payloads)->toHaveCount(1)
        ->and($payloads[0]['phase'])->toBe('skipped')
        ->and($payloads[0]['execution_id'])->toBeNull();
});

it('reports two identical successful runs as distinct executions for one command key', function (): void {
    configureMusterClient();
    musterClientPrivateHttp()->fake();
    $task = musterScheduledEvent();
    $task->everySecond();

    runMusterForeground($task);
    runMusterForeground($task);

    $payloads = recordedMusterPayloads();
    $starts = collect($payloads)->where('phase', 'started')->values();
    $finishes = collect($payloads)->where('phase', 'finished')->values();

    expect($payloads)->toHaveCount(4)
        ->and($starts)->toHaveCount(2)
        ->and($finishes)->toHaveCount(2)
        ->and($starts[0]['key'])->toBe($starts[1]['key'])
        ->and($starts[0]['execution_id'])->not->toBe($starts[1]['execution_id'])
        ->and($finishes[0]['execution_id'])->toBe($starts[0]['execution_id'])
        ->and($finishes[1]['execution_id'])->toBe($starts[1]['execution_id'])
        ->and((new ReflectionProperty($task, 'beforeCallbacks'))->getValue($task))->toHaveCount(1);
});

it('leaves background events operationally untouched unless both connection values are configured', function (): void {
    config()->set('muster.url', 'https://muster.test/api/schedule-events');
    config()->set('muster.token', null);
    musterClientPrivateHttp()->fake();
    $task = musterScheduledEvent(background: true);
    $originalCommand = $task->command;
    $originalMutexName = $task->mutexName();
    $originalMutexResolver = $task->mutexNameResolver;
    $originalBeforeCallbacks = (new ReflectionProperty($task, 'beforeCallbacks'))->getValue($task);

    runMusterForeground($task);

    musterClientPrivateHttp()->assertNothingSent();
    expect($task->command)->toBe($originalCommand)
        ->and($task->mutexName())->toBe($originalMutexName)
        ->and($task->mutexNameResolver)->toBe($originalMutexResolver)
        ->and((new ReflectionProperty($task, 'beforeCallbacks'))->getValue($task))->toBe($originalBeforeCallbacks);
});

it('fails open when Muster cannot receive an event', function (): void {
    configureMusterClient();
    musterClientPrivateHttp()->fake(fn (): never => throw new RuntimeException('Test-created outage.'));

    runMusterForeground(musterScheduledEvent());

    expect(true)->toBeTrue();
});
