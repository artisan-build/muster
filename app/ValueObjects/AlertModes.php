<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Enums\AlertMode;
use InvalidArgumentException;

final readonly class AlertModes
{
    private const int ALL = AlertMode::Missed->value | AlertMode::NonZeroExit->value;

    private function __construct(public int $value)
    {
        if ($value < 0 || ($value & ~self::ALL) !== 0) {
            throw new InvalidArgumentException('The alert mode mask contains unsupported bits.');
        }
    }

    public static function fromInt(int $value): self
    {
        return new self($value);
    }

    /** @param list<int|string> $values */
    public static function fromValues(array $values): self
    {
        $mask = 0;

        foreach ($values as $value) {
            $mask |= AlertMode::from((int) $value)->value;
        }

        return new self($mask);
    }

    public function contains(AlertMode $mode): bool
    {
        return ($this->value & $mode->value) === $mode->value;
    }

    /** @return list<int> */
    public function values(): array
    {
        return array_values(array_map(
            static fn (AlertMode $mode): int => $mode->value,
            array_filter(AlertMode::cases(), $this->contains(...)),
        ));
    }

    public function summary(): string
    {
        $labels = array_values(array_map(
            static fn (AlertMode $mode): string => $mode->label(),
            array_filter(AlertMode::cases(), $this->contains(...)),
        ));

        return $labels === [] ? 'Disabled' : implode(', ', $labels);
    }
}
