<?php

declare(strict_types=1);

namespace ArtisanBuild\MusterClient\Tests;

use ArtisanBuild\BfcClient\BfcClientServiceProvider;
use ArtisanBuild\MusterClient\MusterClientServiceProvider;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [BfcClientServiceProvider::class, MusterClientServiceProvider::class];
    }

    protected function tearDown(): void
    {
        putenv('MUSTER_CLIENT_EXECUTION_ID');

        parent::tearDown();
    }
}
