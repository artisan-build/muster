<?php

declare(strict_types=1);

namespace App\Casts;

use App\ValueObjects\AlertModes;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** @implements CastsAttributes<AlertModes, AlertModes|int> */
final class AlertModesCast implements CastsAttributes
{
    /** @param array<string, mixed> $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): AlertModes
    {
        return AlertModes::fromInt((int) $value);
    }

    /** @param array<string, mixed> $attributes */
    public function set(Model $model, string $key, mixed $value, array $attributes): int
    {
        if ($value instanceof AlertModes) {
            return $value->value;
        }

        if (is_int($value)) {
            return AlertModes::fromInt($value)->value;
        }

        throw new InvalidArgumentException("{$key} must be an alert mode mask.");
    }
}
