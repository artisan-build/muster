<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $monitor_id
 * @property string $execution_id
 * @property string $status
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $completed_at
 * @property float|null $runtime
 * @property int|null $exit_code
 * @property bool $background
 * @property string $timezone
 */
final class ScheduleExecution extends Model
{
    public const string STATUS_RUNNING = 'running';

    public const string STATUS_FINISHED = 'finished';

    public const string STATUS_FAILED = 'failed';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'execution_id',
        'status',
        'started_at',
        'completed_at',
        'runtime',
        'exit_code',
        'background',
        'timezone',
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
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'runtime' => 'float',
            'exit_code' => 'integer',
            'background' => 'boolean',
        ];
    }
}
