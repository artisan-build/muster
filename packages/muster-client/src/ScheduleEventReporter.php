<?php

declare(strict_types=1);

namespace ArtisanBuild\MusterClient;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Psr\Log\LoggerInterface;
use Throwable;

final class ScheduleEventReporter
{
    private const float MINIMUM_TIMEOUT = 0.05;

    private readonly float $connectTimeout;

    private readonly float $timeout;

    public function __construct(
        private readonly string $url,
        private readonly string $token,
        float $connectTimeout,
        float $timeout,
        private readonly Factory $http,
        private readonly LoggerInterface $logger,
    ) {
        $this->connectTimeout = max(self::MINIMUM_TIMEOUT, $connectTimeout);
        $this->timeout = max(self::MINIMUM_TIMEOUT, $timeout);
    }

    /** @param array<string, mixed> $payload */
    public function report(array $payload): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        try {
            $this->pendingRequest()
                ->withToken($this->token)
                ->connectTimeout($this->connectTimeout)
                ->timeout($this->timeout)
                ->post($this->url, $payload)
                ->throw();
        } catch (Throwable $exception) {
            $this->debug('Muster schedule event delivery failed; the host command will continue.', $exception);
        }
    }

    public function isConfigured(): bool
    {
        return $this->url !== '' && $this->token !== '';
    }

    private function pendingRequest(): PendingRequest
    {
        try {
            return $this->http->withClientIdentity();
        } catch (Throwable $exception) {
            $this->debug('Muster could not resolve the BfC client identity; sending without it.', $exception);

            return $this->http->createPendingRequest();
        }
    }

    private function debug(string $message, Throwable $exception): void
    {
        try {
            $this->logger->debug($message, ['exception_class' => $exception::class]);
        } catch (Throwable) {
            // Monitoring must never fail the command being monitored.
        }
    }
}
