<?php

declare(strict_types=1);

namespace ArtisanBuild\MusterClient\Commands;

use ArtisanBuild\MusterClient\Connection\LoopbackAuthorizer;
use ArtisanBuild\MusterClient\EnvironmentFileWriter;
use Illuminate\Console\Command;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

final class InstallCommand extends Command
{
    protected $signature = 'muster:install
                            {--url= : The Muster schedule event endpoint}
                            {--manual-token : Prompt for a pre-issued token instead of browser authorization}
                            {--token= : Automation fallback; real tokens should not be passed in shell arguments}';

    protected $description = 'Connect this Laravel application to Muster.';

    public function handle(LoopbackAuthorizer $authorizer, EnvironmentFileWriter $environment): int
    {
        $url = $this->stringOption('url') ?: text('Muster schedule event URL', required: true);
        $origin = $this->origin($url);

        if ($origin === null) {
            $this->error('The Muster schedule event endpoint must be a valid HTTP or HTTPS URL without credentials, query, or fragment.');

            return self::FAILURE;
        }

        $token = $this->stringOption('token');

        if (is_string($token)) {
            $this->warn('Avoid passing real Muster tokens through shell arguments; use browser authorization or --manual-token.');
        } elseif ($this->option('manual-token') === true) {
            $token = password('Muster check-in token', required: true);
        } else {
            try {
                $token = $authorizer->authorize($origin, function (string $authorizationUrl): void {
                    $this->newLine();
                    $this->line('Authorize Muster in your browser:');
                    $this->line($authorizationUrl);
                    $this->openBrowser($authorizationUrl);
                });
            } catch (Throwable) {
                $this->error('Muster authorization failed. The environment file was not changed.');

                return self::FAILURE;
            }
        }

        $path = $this->laravel->environmentFilePath();

        try {
            $result = $environment->write(
                $path,
                ['MUSTER_URL' => $url, 'MUSTER_TOKEN' => $token],
                $this->input->isInteractive()
                    ? fn (): bool => confirm('Write Muster environment settings? ['.$path.']', default: true)
                    : null,
            );
        } catch (Throwable) {
            $this->error('Muster could not safely update the environment file. It was not replaced.');

            return self::FAILURE;
        }

        if ($result === EnvironmentFileWriter::UNCHANGED) {
            $this->info('Muster is already configured.');

            return self::SUCCESS;
        }

        if ($result === EnvironmentFileWriter::DECLINED) {
            $this->warn('Muster configuration was not changed.');

            return self::SUCCESS;
        }

        $this->info('Muster connection settings updated. Run `php artisan config:clear`.');

        return self::SUCCESS;
    }

    private function origin(string $url): ?string
    {
        if ($url === '' || preg_match('/[\x00-\x20\\\\]/', $url) === 1) {
            return null;
        }

        try {
            $parts = parse_url($url);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($parts)
            || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || ! is_string($parts['host'] ?? null)
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            return null;
        }

        $host = $parts['host'];

        if (str_starts_with($host, '[') || str_ends_with($host, ']')) {
            if (! str_starts_with($host, '[')
                || ! str_ends_with($host, ']')
                || filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                return null;
            }
        } elseif (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            && preg_match('/\A(?=.{1,253}\z)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*\.?\z/D', $host) !== 1) {
            return null;
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $parts['scheme'].'://'.$host.$port;
    }

    private function openBrowser(string $authorizationUrl): void
    {
        if (PHP_OS_FAMILY !== 'Darwin' || $this->laravel->environment('testing')) {
            return;
        }

        $process = @proc_open(['osascript', '-'], [['pipe', 'r'], ['file', '/dev/null', 'a'], ['file', '/dev/null', 'a']], $pipes);

        if (! is_resource($process) || ! isset($pipes[0]) || ! is_resource($pipes[0])) {
            return;
        }

        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $authorizationUrl);
        fwrite($pipes[0], 'open location "'.$escaped.'"'.PHP_EOL);
        fclose($pipes[0]);
        proc_close($process);
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
