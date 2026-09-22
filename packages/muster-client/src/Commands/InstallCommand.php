<?php

declare(strict_types=1);

namespace ArtisanBuild\MusterClient\Commands;

use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

final class InstallCommand extends Command
{
    protected $signature = 'muster:install
                            {--url= : The Muster schedule event endpoint}
                            {--token= : The Muster check-in token}';

    protected $description = 'Connect this Laravel application to Muster.';

    public function handle(): int
    {
        $url = $this->stringOption('url') ?: text('Muster schedule event URL', required: true);
        $token = $this->stringOption('token') ?: password('Muster check-in token', required: true);
        $path = $this->laravel->environmentFilePath();
        $contents = is_file($path) ? (string) file_get_contents($path) : '';
        $updated = $this->setEnvironmentValue($contents, 'MUSTER_URL', $url);
        $updated = $this->setEnvironmentValue($updated, 'MUSTER_TOKEN', $token);

        if ($updated === $contents) {
            $this->info('Muster is already configured.');

            return self::SUCCESS;
        }

        if ($this->input->isInteractive()
            && ! confirm('Write Muster environment settings? ['.$path.']', default: true)) {
            $this->warn('Muster configuration was not changed.');

            return self::SUCCESS;
        }

        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($path, $updated);
        $this->info('Muster connection settings updated. Run `php artisan config:clear`.');

        return self::SUCCESS;
    }

    private function setEnvironmentValue(string $contents, string $key, string $value): string
    {
        $line = $key.'='.$this->formatEnvironmentValue($value);
        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

        if (preg_match($pattern, $contents) === 1) {
            return preg_replace($pattern, $line, $contents) ?? $contents;
        }

        if ($contents !== '' && ! str_ends_with($contents, PHP_EOL)) {
            $contents .= PHP_EOL;
        }

        return $contents.$line.PHP_EOL;
    }

    private function formatEnvironmentValue(string $value): string
    {
        if ($value === '' || preg_match('/\s|#|=|"|\'/', $value) === 1) {
            return '"'.str_replace('"', '\\"', $value).'"';
        }

        return $value;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
