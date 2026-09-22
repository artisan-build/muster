<?php

declare(strict_types=1);

namespace ArtisanBuild\MusterClient\Connection;

use Closure;
use Illuminate\Http\Client\Factory;
use RuntimeException;

class LoopbackAuthorizer
{
    private const APP_PURPOSE = 'muster.check-in';

    private const CALLBACK_TIMEOUT_SECONDS = 180;

    private const RESPONSE_LIMIT_BYTES = 24 * 1024;

    public function __construct(private readonly Factory $http) {}

    /** @param Closure(string): void $presentAuthorizationUrl */
    public function authorize(string $origin, Closure $presentAuthorizationUrl): string
    {
        $listener = $this->createListener();
        $verifier = self::opaque(64);
        $state = self::opaque(32);
        $redirectUri = $listener->redirectUri();
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $authorizationUrl = $origin.'/bfc/loopback/authorize?'.http_build_query([
            'app_purpose' => self::APP_PURPOSE,
            'redirect_uri' => $redirectUri,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);

        try {
            $presentAuthorizationUrl($authorizationUrl);
            $code = $listener->receive($state, self::CALLBACK_TIMEOUT_SECONDS);
        } finally {
            $listener->close();
        }

        $response = $this->http
            ->acceptJson()
            ->asJson()
            ->connectTimeout(3)
            ->timeout(10)
            ->withOptions([
                'allow_redirects' => false,
                'progress' => static function (
                    int $downloadTotal,
                    int $downloadedBytes,
                    int $uploadTotal,
                    int $uploadedBytes,
                ): void {
                    if ($downloadedBytes > self::RESPONSE_LIMIT_BYTES) {
                        throw new RuntimeException('Muster authorization response exceeded 24 KiB.');
                    }
                },
            ])
            ->post($origin.'/bfc/loopback/token', [
                'code' => $code,
                'redirect_uri' => $redirectUri,
                'code_verifier' => $verifier,
            ]);

        $body = $response->body();
        $payload = strlen($body) <= self::RESPONSE_LIMIT_BYTES ? json_decode($body, true) : null;

        if ($response->status() !== 200
            || ! is_array($payload)
            || ! is_string($payload['access_token'] ?? null)
            || $payload['access_token'] === ''
            || ($payload['token_type'] ?? null) !== 'Bearer'
            || ($payload['app_purpose'] ?? null) !== self::APP_PURPOSE
            || ! is_string($payload['credential_id'] ?? null)
            || $payload['credential_id'] === '') {
            throw new RuntimeException('Muster could not exchange the authorization grant.');
        }

        return $payload['access_token'];
    }

    protected function createListener(): LoopbackCallbackListener
    {
        return NativeLoopbackCallbackListener::bind();
    }

    private static function opaque(int $bytes): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
