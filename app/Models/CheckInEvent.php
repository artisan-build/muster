<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $monitor_id
 * @property CarbonImmutable $checked_in_at
 *
 * @mixin IdeHelperCheckInEvent
 */
final class CheckInEvent extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'checked_in_at',
    ];

    /** @return BelongsTo<Monitor, $this> */
    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'checked_in_at' => 'immutable_datetime',
        ];
    }
}
