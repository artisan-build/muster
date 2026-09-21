<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $check_in_event_id
 * @property string $channel
 * @property string $destination
 * @property string $status
 * @property int $attempt_count
 * @property string|null $last_error_code
 * @property CarbonImmutable|null $last_attempt_at
 * @property CarbonImmutable|null $delivered_at
 * @property CheckInEvent $event
 *
 * @mixin IdeHelperAlertDelivery
 */
final class AlertDelivery extends Model
{
    public const string CHANNEL_EMAIL = 'email';

    public const string CHANNEL_WEBHOOK = 'webhook';

    public const string STATUS_PENDING = 'pending';

    public const string STATUS_RETRYING = 'retrying';

    public const string STATUS_FAILED = 'failed';

    public const string STATUS_DELIVERED = 'delivered';

    /** @var list<string> */
    public const array NONTERMINAL_STATUSES = [self::STATUS_PENDING, self::STATUS_RETRYING];

    /** @var list<string> */
    protected $fillable = [
        'channel',
        'destination',
    ];

    /** @return BelongsTo<CheckInEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(CheckInEvent::class, 'check_in_event_id');
    }

    /**
     * @return array{
     *     event: array{id: int, transition: string, occurred_at: string},
     *     monitor: array{id: int, name: string, last_check_in_at: string|null, next_due_at: string|null}
     * }
     */
    public function payload(): array
    {
        $event = $this->event;

        if ($event->monitor_name === null) {
            throw new \LogicException('Transition event monitor identity is missing.');
        }

        return [
            'event' => [
                'id' => $event->id,
                'transition' => $event->event_type,
                'occurred_at' => $event->occurred_at->toISOString(),
            ],
            'monitor' => [
                'id' => $event->monitor_id,
                'name' => $event->monitor_name,
                'last_check_in_at' => $event->last_check_in_at?->toISOString(),
                'next_due_at' => $event->next_due_at?->toISOString(),
            ],
        ];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'destination' => 'encrypted',
            'attempt_count' => 'integer',
            'last_attempt_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
        ];
    }
}
