<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property string $week_key
 * @property CarbonImmutable $window_start
 * @property CarbonImmutable $window_end
 * @property string $recipient
 * @property array{week_key: string, window_start: string, window_end: string, missed: list<array{id: int, monitor_name: string, command: string|null, occurred_at: string}>, non_zero_exit: list<array{id: int, monitor_name: string, command: string|null, occurred_at: string, exit_code: int|null}>} $payload
 * @property string $status
 * @property int $attempt_count
 * @property string|null $last_error_code
 * @property CarbonImmutable|null $last_attempt_at
 * @property CarbonImmutable|null $delivered_at
 */
final class WeeklyDigestDelivery extends Model
{
    public const string STATUS_PENDING = 'pending';

    public const string STATUS_PROCESSING = 'processing';

    public const string STATUS_RETRYING = 'retrying';

    public const string STATUS_FAILED = 'failed';

    public const string STATUS_DELIVERED = 'delivered';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'week_key',
        'window_start',
        'window_end',
        'recipient',
        'payload',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'window_start' => 'immutable_datetime',
            'window_end' => 'immutable_datetime',
            'recipient' => 'encrypted',
            'payload' => 'encrypted:array',
            'attempt_count' => 'integer',
            'last_attempt_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
        ];
    }
}
