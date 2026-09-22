<?php

declare(strict_types=1);

use ArtisanBuild\MusterClient\Connection\LoopbackAuthorizer;
use ArtisanBuild\MusterClient\Connection\LoopbackCallbackListener;
use ArtisanBuild\MusterClient\Connection\NativeLoopbackCallbackListener;
use ArtisanBuild\MusterClient\EnvironmentFileWriter;
use ArtisanBuild\MusterClient\ScheduleEventReporter;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class MusterClientTestLoopbackListener implements LoopbackCallbackListener
{
    public ?string $expectedState = null;

    public bool $closed = false;

    public function __construct(private readonly string $code) {}

    public function redirectUri(): string
    {
        return 'http://127.0.0.1:41234/muster/callback';
    }

    public function receive(string $expectedState, int $timeoutSeconds): string
    {
        $this->expectedState = $expectedState;

        expect($timeoutSeconds)->toBe(180);

        return $this->code;
    }

    public function close(): void
    {
        $this->closed = true;
    }
}

final class MusterClientTestLoopbackAuthorizer extends LoopbackAuthorizer
{
    public function __construct(Factory $http, private readonly LoopbackCallbackListener $listener)
    {
        parent::__construct($http);
    }

    protected function createListener(): LoopbackCallbackListener
    {
        return $this->listener;
    }
}

final class MusterClientTestInstallAuthorizer extends LoopbackAuthorizer
{
    public ?string $origin = null;

    public bool $called = false;

    public function __construct(private readonly string|Throwable $result) {}

    public function authorize(string $origin, Closure $presentAuthorizationUrl): string
    {
        $this->called = true;
        $this->origin = $origin;
        $presentAuthorizationUrl($origin.'/bfc/loopback/authorize?state=test-created-state');

        if ($this->result instanceof Throwable) {
            throw $this->result;
        }

        return $this->result;
    }
}

function musterClientEnvironmentDirectory(): string
{
    $path = sys_get_temp_dir().'/muster-client-test-'.bin2hex(random_bytes(8));

    if (! mkdir($path, 0700, true) && ! is_dir($path)) {
        throw new RuntimeException('Could not create the test environment directory.');
    }

    return $path;
}

function useMusterClientEnvironment(string $path): void
{
    app()->setBasePath($path);
    app()->useEnvironmentPath($path);
}

function removeMusterClientEnvironment(string $path): void
{
    (new Filesystem)->deleteDirectory($path);
}

it('uses the public installer loopback path by default without printing credential material', function (): void {
    $directory = musterClientEnvironmentDirectory();
    $token = 'test-created-loopback-token';
    $authorizer = new MusterClientTestInstallAuthorizer($token);
    file_put_contents($directory.'/.env', 'APP_NAME=Test'.PHP_EOL);
    useMusterClientEnvironment($directory);
    app()->instance(LoopbackAuthorizer::class, $authorizer);

    try {
        $this->artisan('muster:install', [
            '--url' => 'https://muster.test:8443/api/schedule-events',
            '--no-interaction' => true,
        ])->expectsOutputToContain('https://muster.test:8443/bfc/loopback/authorize')
            ->doesntExpectOutputToContain($token)
            ->assertExitCode(0);

        expect($authorizer->called)->toBeTrue()
            ->and($authorizer->origin)->toBe('https://muster.test:8443')
            ->and(file_get_contents($directory.'/.env'))->toContain('MUSTER_TOKEN='.$token);
    } finally {
        removeMusterClientEnvironment($directory);
    }
});

it('leaves the environment untouched when loopback authorization fails', function (): void {
    $directory = musterClientEnvironmentDirectory();
    $original = 'APP_NAME=Test'.PHP_EOL;
    file_put_contents($directory.'/.env', $original);
    useMusterClientEnvironment($directory);
    app()->instance(LoopbackAuthorizer::class, new MusterClientTestInstallAuthorizer(
        new RuntimeException('test-created authorization failure with secret-like-token'),
    ));

    try {
        $this->artisan('muster:install', [
            '--url' => 'https://muster.test/api/schedule-events',
            '--no-interaction' => true,
        ])->doesntExpectOutputToContain('secret-like-token')
            ->assertExitCode(1);

        expect(file_get_contents($directory.'/.env'))->toBe($original)
            ->and(file_exists($directory.'/.env.muster.lock'))->toBeFalse();
    } finally {
        removeMusterClientEnvironment($directory);
    }
});

it('rejects malformed and non-http endpoints before starting authorization', function (string $url): void {
    $directory = musterClientEnvironmentDirectory();
    $authorizer = new MusterClientTestInstallAuthorizer('test-created-token');
    useMusterClientEnvironment($directory);
    app()->instance(LoopbackAuthorizer::class, $authorizer);

    try {
        $this->artisan('muster:install', [
            '--url' => $url,
            '--no-interaction' => true,
        ])->assertExitCode(1);

        expect($authorizer->called)->toBeFalse()
            ->and(file_exists($directory.'/.env'))->toBeFalse();
    } finally {
        removeMusterClientEnvironment($directory);
    }
})->with([
    'non-http' => 'ftp://muster.test/api/schedule-events',
    'credentials' => 'https://user:password@muster.test/api/schedule-events',
    'query' => 'https://muster.test/api/schedule-events?token=nope',
    'malformed port' => 'https://muster.test:not-a-port/api/schedule-events',
    'encoded host' => 'https://%65xample.com/api/schedule-events',
]);

it('preserves a bracketed IPv6 authority when deriving the Muster origin', function (): void {
    $directory = musterClientEnvironmentDirectory();
    $authorizer = new MusterClientTestInstallAuthorizer('test-created-token');
    useMusterClientEnvironment($directory);
    app()->instance(LoopbackAuthorizer::class, $authorizer);

    try {
        $this->artisan('muster:install', [
            '--url' => 'http://[::1]:8080/api/schedule-events',
            '--no-interaction' => true,
        ])->assertExitCode(0);

        expect($authorizer->origin)->toBe('http://[::1]:8080');
    } finally {
        removeMusterClientEnvironment($directory);
    }
});

it('keeps the pasted token fallback idempotent and warns against real argv use', function (): void {
    $directory = musterClientEnvironmentDirectory();
    file_put_contents($directory.'/.env', 'APP_NAME=Test'.PHP_EOL);
    useMusterClientEnvironment($directory);

    try {
        foreach (range(1, 2) as $attempt) {
            $this->artisan('muster:install', [
                '--url' => 'https://muster.test/api/schedule-events',
                '--token' => 'test-created-token',
                '--no-interaction' => true,
            ])->expectsOutputToContain('Avoid passing real Muster tokens')
                ->doesntExpectOutputToContain('test-created-token')
                ->assertExitCode(0);
        }

        $contents = (string) file_get_contents($directory.'/.env');

        expect(substr_count($contents, 'MUSTER_URL='))->toBe(1)
            ->and(substr_count($contents, 'MUSTER_TOKEN='))->toBe(1)
            ->and($contents)->toContain('MUSTER_URL=https://muster.test/api/schedule-events')
            ->and($contents)->toContain('MUSTER_TOKEN=test-created-token');
    } finally {
        removeMusterClientEnvironment($directory);
    }
});

it('completes loopback PKCE exchange while containing the code verifier and bearer', function (): void {
    $code = str_repeat('c', 43);
    $token = 'test-created-exchanged-token';
    $listener = new MusterClientTestLoopbackListener($code);
    $presentedUrl = null;
    musterClientPrivateHttp()->fake([
        'https://muster.test/bfc/loopback/token' => Http::response([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'credential_id' => 'test-created-credential-id',
            'app_purpose' => 'muster.check-in',
        ]),
    ]);
    $authorizer = new MusterClientTestLoopbackAuthorizer(musterClientPrivateHttp(), $listener);

    $result = $authorizer->authorize('https://muster.test', function (string $url) use (&$presentedUrl): void {
        $presentedUrl = $url;
    });

    $request = musterClientPrivateHttp()->recorded()->sole()[0];
    $exchange = $request->data();
    $query = [];
    parse_str((string) parse_url((string) $presentedUrl, PHP_URL_QUERY), $query);

    expect($result)->toBe($token)
        ->and($listener->closed)->toBeTrue()
        ->and($listener->expectedState)->toBe($query['state'])
        ->and($query['app_purpose'])->toBe('muster.check-in')
        ->and($query['redirect_uri'])->toBe($listener->redirectUri())
        ->and($query['code_challenge_method'])->toBe('S256')
        ->and($query['code_challenge'])->toMatch('/\A[A-Za-z0-9_-]{43}\z/D')
        ->and($exchange['code'])->toBe($code)
        ->and($exchange['redirect_uri'])->toBe($listener->redirectUri())
        ->and($exchange['code_verifier'])->toMatch('/\A[A-Za-z0-9._~-]{43,128}\z/D')
        ->and($presentedUrl)->not->toContain($code)
        ->and($presentedUrl)->not->toContain($token)
        ->and($presentedUrl)->not->toContain($exchange['code_verifier']);
});

it('refuses denied and state-mismatched loopback callbacks', function (string $target, string $state): void {
    expect(fn (): string => NativeLoopbackCallbackListener::codeFromTarget($target, $state))
        ->toThrow(RuntimeException::class);
})->with([
    'denied' => ['/muster/callback?error=access_denied&state=test-created-state', 'test-created-state'],
    'state mismatch' => ['/muster/callback?code='.str_repeat('c', 43).'&state=wrong-state', 'test-created-state'],
    'duplicate code' => ['/muster/callback?code='.str_repeat('c', 43).'&code='.str_repeat('d', 43).'&state=test-created-state', 'test-created-state'],
    'wrong path' => ['/callback?code='.str_repeat('c', 43).'&state=test-created-state', 'test-created-state'],
]);

it('accepts one well-formed code on the exact loopback callback', function (): void {
    $code = str_repeat('c', 43);
    $state = 'test-created-state';

    expect(NativeLoopbackCallbackListener::codeFromTarget(
        '/muster/callback?code='.$code.'&state='.$state,
        $state,
    ))->toBe($code);
});

it('rejects unsuccessful or incorrectly bound loopback exchanges', function (int $status, array $payload): void {
    $listener = new MusterClientTestLoopbackListener(str_repeat('c', 43));
    musterClientPrivateHttp()->fake([
        'https://muster.test/bfc/loopback/token' => Http::response($payload, $status),
    ]);
    $authorizer = new MusterClientTestLoopbackAuthorizer(musterClientPrivateHttp(), $listener);

    expect(fn (): string => $authorizer->authorize('https://muster.test', static function (): void {}))
        ->toThrow(RuntimeException::class, 'Muster could not exchange the authorization grant.')
        ->and($listener->closed)->toBeTrue();
})->with([
    'failed status' => [503, ['error' => 'temporarily_unavailable']],
    'wrong token type' => [200, ['access_token' => 'test-created-token', 'token_type' => 'Basic', 'credential_id' => 'id', 'app_purpose' => 'muster.check-in']],
    'wrong app purpose' => [200, ['access_token' => 'test-created-token', 'token_type' => 'Bearer', 'credential_id' => 'id', 'app_purpose' => 'muster.history.read']],
    'missing credential id' => [200, ['access_token' => 'test-created-token', 'token_type' => 'Bearer', 'app_purpose' => 'muster.check-in']],
]);

it('rejects an exchange response while more than 24 KiB is being received', function (): void {
    $listener = new MusterClientTestLoopbackListener(str_repeat('c', 43));
    $progressCalled = false;
    musterClientPrivateHttp()->fake(function (Request $request, array $options) use (&$progressCalled) {
        expect($request->url())->toBe('https://muster.test/bfc/loopback/token')
            ->and($options['progress'] ?? null)->toBeCallable();

        $progressCalled = true;
        $options['progress'](25 * 1024, 24 * 1024, 0, 0);
        $options['progress'](25 * 1024, (24 * 1024) + 1, 0, 0);

        return Http::response([
            'access_token' => 'test-created-token',
            'token_type' => 'Bearer',
            'credential_id' => 'test-created-credential-id',
            'app_purpose' => 'muster.check-in',
        ]);
    });
    $authorizer = new MusterClientTestLoopbackAuthorizer(musterClientPrivateHttp(), $listener);

    expect(fn (): string => $authorizer->authorize('https://muster.test', static function (): void {}))
        ->toThrow(RuntimeException::class, 'Muster authorization response exceeded 24 KiB.')
        ->and($progressCalled)->toBeTrue();
});

it('keeps credential material outside host HTTP middleware and events', function (): void {
    $code = str_repeat('c', 43);
    $exchangeToken = 'test-created-exchanged-token';
    $runtimeToken = 'test-created-runtime-token';
    $listener = new MusterClientTestLoopbackListener($code);
    $observations = [];
    $privateHttp = musterClientPrivateHttp();

    config()->set('muster.url', 'https://muster.test/api/schedule-events');
    config()->set('muster.token', $runtimeToken);
    config()->set('bfc-client.identity', 'test-created-installation');

    $responseFor = static fn (Request $request) => str_ends_with($request->url(), '/bfc/loopback/token')
        ? Http::response([
            'access_token' => $exchangeToken,
            'token_type' => 'Bearer',
            'credential_id' => 'test-created-credential-id',
            'app_purpose' => 'muster.check-in',
        ])
        : Http::response(status: 204);

    $privateHttp->fake($responseFor);
    Http::fake($responseFor);
    Http::globalRequestMiddleware(function (RequestInterface $request) use (&$observations): RequestInterface {
        $observations[] = 'middleware-request '.json_encode($request->getHeaders(), JSON_THROW_ON_ERROR).' '.$request->getBody();

        return $request;
    });
    Http::globalResponseMiddleware(function (ResponseInterface $response) use (&$observations): ResponseInterface {
        $observations[] = 'middleware-response '.$response->getBody();

        return $response;
    });
    Event::listen(RequestSending::class, function (RequestSending $event) use (&$observations): void {
        $observations[] = 'request-event '.json_encode($event->request->headers(), JSON_THROW_ON_ERROR).' '.$event->request->body();
    });
    Event::listen(ResponseReceived::class, function (ResponseReceived $event) use (&$observations): void {
        $observations[] = 'response-event '.$event->response->body();
    });

    $resolvedAuthorizer = app(LoopbackAuthorizer::class);
    $authorizerHttp = (new ReflectionProperty(LoopbackAuthorizer::class, 'http'))->getValue($resolvedAuthorizer);
    $authorizer = new MusterClientTestLoopbackAuthorizer($authorizerHttp, $listener);
    $result = $authorizer->authorize('https://muster.test', static function (): void {});

    app(ScheduleEventReporter::class)->report(['phase' => 'started']);

    $records = $privateHttp->recorded()->all();
    $exchangeRequest = $records[0][0];
    $exchangeResponse = $records[0][1];
    $runtimeRequest = $records[1][0];
    $verifier = $exchangeRequest->data()['code_verifier'];

    expect($authorizerHttp)->toBe($privateHttp)
        ->and($result)->toBe($exchangeToken)
        ->and($records)->toHaveCount(2)
        ->and($exchangeRequest->url())->toBe('https://muster.test/bfc/loopback/token')
        ->and($exchangeRequest->data()['code'])->toBe($code)
        ->and($verifier)->toMatch('/\A[A-Za-z0-9._~-]{43,128}\z/D')
        ->and($exchangeResponse?->json('access_token'))->toBe($exchangeToken)
        ->and($runtimeRequest->url())->toBe('https://muster.test/api/schedule-events')
        ->and($runtimeRequest->hasHeader('Authorization', 'Bearer '.$runtimeToken))->toBeTrue()
        ->and($runtimeRequest->hasHeader('X-BfC-Client-Id', 'test-created-installation'))->toBeTrue()
        ->and($observations)->toBe([]);

    foreach ([$code, $verifier, $exchangeToken, $runtimeToken] as $secret) {
        expect(implode("\n", $observations))->not->toContain($secret);
    }

    Http::assertNothingSent();
});

it('aborts on an outside environment edit without overwriting it', function (): void {
    $directory = musterClientEnvironmentDirectory();
    $path = $directory.'/.env';
    file_put_contents($path, 'APP_NAME=Original'.PHP_EOL);
    $writer = new class extends EnvironmentFileWriter
    {
        protected function beforeCompare(string $path): void
        {
            file_put_contents($path, 'APP_NAME=OutsideEdit'.PHP_EOL);
        }
    };

    try {
        expect(fn (): string => $writer->write($path, ['MUSTER_TOKEN' => 'test-created-token']))
            ->toThrow(RuntimeException::class, 'changed while Muster was preparing')
            ->and(file_get_contents($path))->toBe('APP_NAME=OutsideEdit'.PHP_EOL);
    } finally {
        removeMusterClientEnvironment($directory);
    }
});

it('keeps the original environment bytes when temporary writing or atomic replacement fails', function (): void {
    $directory = musterClientEnvironmentDirectory();
    $path = $directory.'/.env';
    $original = 'APP_NAME=Original'.PHP_EOL;
    file_put_contents($path, $original);
    $writeFailure = new class extends EnvironmentFileWriter
    {
        protected function writeContents($handle, string $contents): bool
        {
            return false;
        }
    };
    $renameFailure = new class extends EnvironmentFileWriter
    {
        protected function replace(string $temporaryPath, string $path): bool
        {
            return false;
        }
    };

    try {
        expect(fn (): string => $writeFailure->write($path, ['MUSTER_TOKEN' => 'test-created-token']))
            ->toThrow(RuntimeException::class)
            ->and(file_get_contents($path))->toBe($original)
            ->and(glob($directory.'/.muster-env-*') ?: [])->toBe([]);

        expect(fn (): string => $renameFailure->write($path, ['MUSTER_TOKEN' => 'test-created-token']))
            ->toThrow(RuntimeException::class)
            ->and(file_get_contents($path))->toBe($original)
            ->and(glob($directory.'/.muster-env-*') ?: [])->toBe([]);
    } finally {
        removeMusterClientEnvironment($directory);
    }
});

it('does not replace an unchanged environment file and creates new files with mode 0600', function (): void {
    $directory = musterClientEnvironmentDirectory();
    $existing = $directory.'/.env';
    $contents = 'MUSTER_URL=https://muster.test/api/schedule-events'.PHP_EOL.'MUSTER_TOKEN=test-created-token'.PHP_EOL;
    file_put_contents($existing, $contents);
    $inode = fileinode($existing);
    $writer = new EnvironmentFileWriter;

    try {
        expect($writer->write($existing, [
            'MUSTER_URL' => 'https://muster.test/api/schedule-events',
            'MUSTER_TOKEN' => 'test-created-token',
        ]))->toBe(EnvironmentFileWriter::UNCHANGED)
            ->and(file_get_contents($existing))->toBe($contents)
            ->and(fileinode($existing))->toBe($inode)
            ->and(file_exists($existing.'.muster.lock'))->toBeTrue();

        $new = $directory.'/.env.new';
        expect($writer->write($new, ['MUSTER_TOKEN' => 'test-created-token']))
            ->toBe(EnvironmentFileWriter::CHANGED)
            ->and(fileperms($new) & 0777)->toBe(0600);

        $modePreserved = $directory.'/.env.mode';
        file_put_contents($modePreserved, 'APP_NAME=Test'.PHP_EOL);
        chmod($modePreserved, 0640);
        expect($writer->write($modePreserved, ['MUSTER_TOKEN' => 'test-created-token']))
            ->toBe(EnvironmentFileWriter::CHANGED)
            ->and(fileperms($modePreserved) & 0777)->toBe(0640);
    } finally {
        removeMusterClientEnvironment($directory);
    }
});

it('pins standalone development and audit metadata to the declared PHP floor', function (): void {
    $root = dirname(__DIR__);
    $composer = json_decode((string) file_get_contents($root.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $lock = json_decode((string) file_get_contents($root.'/composer.lock'), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['require']['php'])->toBe('^8.3')
        ->and($composer['config']['platform']['php'])->toBe('8.3.0')
        ->and($composer['scripts']['check'])->toContain('composer audit --locked --no-interaction')
        ->and($lock['platform-overrides']['php'])->toBe('8.3.0');
});
