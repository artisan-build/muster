<?php

declare(strict_types=1);

namespace ArtisanBuild\MusterClient\Connection;

interface LoopbackCallbackListener
{
    public function redirectUri(): string;

    public function receive(string $expectedState, int $timeoutSeconds): string;

    public function close(): void;
}
