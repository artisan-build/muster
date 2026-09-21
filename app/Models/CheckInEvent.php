<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $monitor_id
 * @property string $event_type
 * @property CarbonImmutable $occurred_at
 * @property string|null $monitor_name
 * @property CarbonImmutable|null $last_check_in_at
 * @property CarbonImmutable|null $next_due_at
 *
 * @mixin IdeHelperCheckInEvent
 */
final class CheckInEvent extends Model
{
    public const string TYPE_CHECK_IN = 'check_in';

    public const string TYPE_LATE = 'late';

    public const string TYPE_MISSED = 'missed';

    public const string TYPE_RECOVERED = 'recovered';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'event_type',
        'occurred_at',
        'monitor_name',
        'last_check_in_at',
        'next_due_at',
    ];

    /** @return BelongsTo<Monitor, $this> */
    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    /** @return HasMany<AlertDelivery, $this> */
    public function alertDeliveries(): HasMany
    {
        return $this->hasMany(AlertDelivery::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'last_check_in_at' => 'immutable_datetime',
            'next_due_at' => 'immutable_datetime',
        ];
    }
}
