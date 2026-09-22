<?php

declare(strict_types=1);

namespace ArtisanBuild\MusterClient;

use Closure;
use RuntimeException;

class EnvironmentFileWriter
{
    public const CHANGED = 'changed';

    public const DECLINED = 'declined';

    public const UNCHANGED = 'unchanged';

    /**
     * @param  array<string, string>  $values
     * @param  (Closure(): bool)|null  $confirm
     * @return self::CHANGED|self::DECLINED|self::UNCHANGED
     */
    public function write(string $path, array $values, ?Closure $confirm = null): string
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Muster could not create the environment directory.');
        }

        $lock = @fopen($path.'.muster.lock', 'c+b');

        if (! is_resource($lock) || ! @chmod($path.'.muster.lock', 0600) || ! @flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }

            throw new RuntimeException('Muster could not lock the environment file.');
        }

        $temporaryPath = null;

        try {
            [$exists, $original, $mode] = $this->readOriginal($path);
            $updated = $original;

            foreach ($values as $key => $value) {
                $updated = $this->setEnvironmentValue($updated, $key, $value);
            }

            if ($updated === $original) {
                return self::UNCHANGED;
            }

            if ($confirm instanceof Closure && ! $confirm()) {
                return self::DECLINED;
            }

            $temporaryPath = @tempnam($directory, '.muster-env-');

            if (! is_string($temporaryPath)) {
                throw new RuntimeException('Muster could not create a temporary environment file.');
            }

            $this->prepareTemporaryFile($temporaryPath, $updated, $mode);
            $this->beforeCompare($path);

            $liveExists = is_file($path);
            $live = $liveExists ? @file_get_contents($path) : '';

            if (is_link($path)
                || $liveExists !== $exists
                || ! is_string($live)
                || ! hash_equals($original, $live)) {
                throw new RuntimeException('The environment file changed while Muster was preparing its update.');
            }

            if (! $this->replace($temporaryPath, $path)) {
                throw new RuntimeException('Muster could not replace the environment file.');
            }

            $temporaryPath = null;

            return self::CHANGED;
        } finally {
            if (is_string($temporaryPath) && is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }

            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    protected function beforeCompare(string $path): void {}

    protected function replace(string $temporaryPath, string $path): bool
    {
        return @rename($temporaryPath, $path);
    }

    /** @param resource $handle */
    protected function writeContents($handle, string $contents): bool
    {
        $remaining = $contents;

        while ($remaining !== '') {
            $written = @fwrite($handle, $remaining);

            if (! is_int($written) || $written < 1) {
                return false;
            }

            $remaining = substr($remaining, $written);
        }

        return true;
    }

    /** @return array{bool, string, int} */
    private function readOriginal(string $path): array
    {
        if (is_link($path)) {
            throw new RuntimeException('Muster will not replace a symbolic-link environment file.');
        }

        $exists = is_file($path);

        if (file_exists($path) && ! $exists) {
            throw new RuntimeException('The environment path is not a regular file.');
        }

        if (! $exists) {
            return [false, '', 0600];
        }

        $contents = @file_get_contents($path);
        $permissions = @fileperms($path);

        if (! is_string($contents) || ! is_int($permissions)) {
            throw new RuntimeException('Muster could not read the environment file.');
        }

        return [true, $contents, $permissions & 0777];
    }

    private function prepareTemporaryFile(string $path, string $contents, int $mode): void
    {
        $handle = @fopen($path, 'wb');

        if (! is_resource($handle)) {
            throw new RuntimeException('Muster could not open its temporary environment file.');
        }

        $prepared = $this->writeContents($handle, $contents)
            && @chmod($path, $mode)
            && @fflush($handle)
            && @fsync($handle);
        $closed = fclose($handle);

        if (! $prepared || ! $closed) {
            throw new RuntimeException('Muster could not prepare its temporary environment file.');
        }
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
}
