<?php

use App\Console\Commands\ConfigureBuiltForCloud;
use App\Console\Commands\Fresh;
use App\Console\Commands\InstallFluxPro;
use App\Console\Commands\OptimizeTailwind;
use ArtisanBuild\BuiltForCloud\Http\Middleware\EnsureUserIsAuthenticated;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        ConfigureBuiltForCloud::class,
        Fresh::class,
        InstallFluxPro::class,
        OptimizeTailwind::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->validateCsrfTokens(except: [
            'api/monitors/*/check-ins',
        ]);

        $middleware->prependToPriorityList(
            SubstituteBindings::class,
            EnsureUserIsAuthenticated::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
