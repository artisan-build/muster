<?php

declare(strict_types=1);

namespace App\Mcp\Support;

use InvalidArgumentException;
use JsonException;

final class Cursor
{
    /** @param array<string, int|string> $values */
    public static function encode(array $values): string
    {
        $json = json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /** @return array<string, mixed> */
    public static function decode(string $cursor): array
    {
        if ($cursor === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $cursor) !== 1) {
            throw new InvalidArgumentException('Invalid cursor.');
        }

        $padding = (4 - strlen($cursor) % 4) % 4;
        $json = base64_decode(strtr($cursor, '-_', '+/').str_repeat('=', $padding), true);

        if ($json === false) {
            throw new InvalidArgumentException('Invalid cursor.');
        }

        try {
            $values = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Invalid cursor.', previous: $exception);
        }

        if (! is_array($values) || array_is_list($values)) {
            throw new InvalidArgumentException('Invalid cursor.');
        }

        foreach (array_keys($values) as $key) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Invalid cursor.');
            }
        }

        return $values;
    }
}
