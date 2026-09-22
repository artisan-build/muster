<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\CheckInEvent;

enum AlertMode: int
{
    case Missed = 1;
    case NonZeroExit = 2;

    public static function forEventType(string $eventType): ?self
    {
        return match ($eventType) {
            CheckInEvent::TYPE_MISSED, CheckInEvent::TYPE_RECOVERED => self::Missed,
            CheckInEvent::TYPE_NON_ZERO_EXIT => self::NonZeroExit,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Missed => 'Missed and recovered',
            self::NonZeroExit => 'Non-zero exits',
        };
    }
}
