<?php

declare(strict_types=1);

use ArtisanBuild\MusterClient\Tests\TestCase;
use Illuminate\Http\Client\Factory;

uses(TestCase::class)->in(__DIR__);

function musterClientPrivateHttp(): Factory
{
    $http = app('artisan-build.muster-client.http');

    if (! $http instanceof Factory) {
        throw new RuntimeException('The Muster client HTTP factory is not available.');
    }

    return $http;
}
