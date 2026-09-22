<?php

declare(strict_types=1);

namespace ArtisanBuild\MusterClient;

use ArtisanBuild\MusterClient\Commands\InstallCommand;
use ArtisanBuild\MusterClient\Connection\LoopbackAuthorizer;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

final class MusterClientServiceProvider extends ServiceProvider
{
    private const HTTP_FACTORY = 'artisan-build.muster-client.http';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/muster.php', 'muster');

        $this->app->singleton(self::HTTP_FACTORY, static fn (): Factory => new Factory);

        $this->app->singleton(ScheduleEventReporter::class, function (Application $app): ScheduleEventReporter {
            return new ScheduleEventReporter(
                url: (string) config('muster.url'),
                token: (string) config('muster.token'),
                connectTimeout: (float) config('muster.connect_timeout'),
                timeout: (float) config('muster.timeout'),
                http: self::http($app),
                logger: $app->make(LoggerInterface::class),
            );
        });

        $this->app->singleton(ScheduleEventSubscriber::class);
        $this->app->singleton(
            LoopbackAuthorizer::class,
            static fn (Application $app): LoopbackAuthorizer => new LoopbackAuthorizer(self::http($app)),
        );
        $this->app->singleton(EnvironmentFileWriter::class);
    }

    public function boot(Dispatcher $events): void
    {
        $this->publishes([
            __DIR__.'/../config/muster.php' => config_path('muster.php'),
        ], 'muster-config');

        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class]);
        }

        $this->warnAboutConfiguration();

        $events->listen(
            ScheduledTaskStarting::class,
            fn (ScheduledTaskStarting $event) => $this->app->make(ScheduleEventSubscriber::class)->starting($event),
        );
        $events->listen(
            ScheduledTaskFinished::class,
            fn (ScheduledTaskFinished $event) => $this->app->make(ScheduleEventSubscriber::class)->finished($event),
        );
        $events->listen(
            ScheduledBackgroundTaskFinished::class,
            fn (ScheduledBackgroundTaskFinished $event) => $this->app->make(ScheduleEventSubscriber::class)->backgroundFinished($event),
        );
        $events->listen(
            ScheduledTaskFailed::class,
            fn (ScheduledTaskFailed $event) => $this->app->make(ScheduleEventSubscriber::class)->failed($event),
        );
        $events->listen(
            ScheduledTaskSkipped::class,
            fn (ScheduledTaskSkipped $event) => $this->app->make(ScheduleEventSubscriber::class)->skipped($event),
        );
    }

    private function warnAboutConfiguration(): void
    {
        $url = config('muster.url');
        $token = config('muster.token');

        if (blank($url) && blank($token)) {
            return;
        }

        if (blank($url) || blank($token)) {
            Log::warning('Muster is half-configured: set both MUSTER_URL and MUSTER_TOKEN, or neither.');

            return;
        }

        if (parse_url((string) $url, PHP_URL_SCHEME) !== 'https') {
            Log::warning('Muster URL is not HTTPS; MUSTER_TOKEN will be sent over plaintext transport.');
        }
    }

    private static function http(Application $app): Factory
    {
        /** @var Factory */
        return $app->make(self::HTTP_FACTORY);
    }
}
