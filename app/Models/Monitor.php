<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property int $cadence_seconds
 * @property int $grace_seconds
 * @property bool $is_enabled
 * @property string $status
 * @property CarbonImmutable|null $last_check_in_at
 * @property CarbonImmutable|null $next_due_at
 *
 * @mixin IdeHelperMonitor
 */
final class Monitor extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'name',
        'cadence_seconds',
        'grace_seconds',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'cadence_seconds' => 'integer',
            'grace_seconds' => 'integer',
            'is_enabled' => 'boolean',
            'last_check_in_at' => 'immutable_datetime',
            'next_due_at' => 'immutable_datetime',
        ];
    }
}
