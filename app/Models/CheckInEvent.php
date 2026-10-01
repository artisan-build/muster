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
 * @property string|null $command
 * @property string|null $display
 * @property string|null $expression
 * @property string|null $execution_id
 * @property string|null $timezone
 * @property int|null $exit_code
 * @property float|null $runtime
 * @property bool $background
 * @property CarbonImmutable|null $last_check_in_at
 * @property CarbonImmutable|null $next_due_at
 * @mixin IdeHelperCheckInEvent
 */
final class CheckInEvent extends Model
{
    public const string TYPE_CHECK_IN = 'check_in';

    public const string TYPE_LATE = 'late';

    public const string TYPE_MISSED = 'missed';

    public const string TYPE_RECOVERED = 'recovered';

    public const string TYPE_STARTED = 'started';

    public const string TYPE_FINISHED = 'finished';

    public const string TYPE_SKIPPED = 'skipped';

    public const string TYPE_NON_ZERO_EXIT = 'non_zero_exit';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'event_type',
        'occurred_at',
        'monitor_name',
        'command',
        'display',
        'expression',
        'execution_id',
        'timezone',
        'exit_code',
        'runtime',
        'background',
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
            'exit_code' => 'integer',
            'runtime' => 'float',
            'background' => 'boolean',
        ];
    }
}
