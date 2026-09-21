<?php

declare(strict_types=1);

namespace App\Models;

use App\Jobs\DeliverTransitionAlert;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $credential_partition_id
 * @property string $name
 * @property int $cadence_seconds
 * @property int $grace_seconds
 * @property bool $is_enabled
 * @property string $status
 * @property CarbonImmutable|null $last_check_in_at
 * @property CarbonImmutable|null $next_due_at
 * @property bool $email_alerts_enabled
 * @property string|null $email_alert_destination
 * @property bool $webhook_alerts_enabled
 * @property string|null $webhook_alert_destination
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
        'email_alerts_enabled',
        'email_alert_destination',
        'webhook_alerts_enabled',
        'webhook_alert_destination',
    ];

    protected static function booted(): void
    {
        self::creating(function (self $monitor): void {
            $monitor->credential_partition_id ??= CredentialPartition::local()->id;
        });

        self::updating(function (self $monitor): void {
            if ($monitor->isDirty('credential_partition_id')) {
                throw new \LogicException('A monitor credential partition cannot be changed.');
            }
        });
    }

    /** @return BelongsTo<CredentialPartition, $this> */
    public function credentialPartition(): BelongsTo
    {
        return $this->belongsTo(CredentialPartition::class);
    }

    /** @return HasMany<CheckInEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(CheckInEvent::class);
    }

    /** @param list<string> $eventTypes */
    public function recordActivity(array $eventTypes, CarbonInterface $occurredAt): void
    {
        foreach ($eventTypes as $eventType) {
            $event = $this->events()->create([
                'event_type' => $eventType,
                'occurred_at' => $occurredAt,
                'last_check_in_at' => $this->last_check_in_at,
                'next_due_at' => $this->next_due_at,
            ]);

            if (in_array($eventType, [CheckInEvent::TYPE_MISSED, CheckInEvent::TYPE_RECOVERED], true)) {
                $this->createAlertDeliveries($event);
            }
        }

        /** @var Collection<int, CheckInEvent> $retainedEvents */
        $retainedEvents = $this->events()
            ->latest('occurred_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get(['id']);

        CheckInEvent::query()
            ->where('monitor_id', $this->id)
            ->whereNotIn('id', $retainedEvents->modelKeys())
            ->delete();
    }

    private function createAlertDeliveries(CheckInEvent $event): void
    {
        $destinations = [
            AlertDelivery::CHANNEL_EMAIL => $this->email_alerts_enabled ? $this->email_alert_destination : null,
            AlertDelivery::CHANNEL_WEBHOOK => $this->webhook_alerts_enabled ? $this->webhook_alert_destination : null,
        ];

        foreach ($destinations as $channel => $destination) {
            if ($destination === null) {
                continue;
            }

            $delivery = $event->alertDeliveries()->firstOrCreate(
                ['channel' => $channel],
                ['destination' => $destination],
            );

            if ($delivery->wasRecentlyCreated) {
                dispatch(new DeliverTransitionAlert($delivery->id))->afterCommit();
            }
        }
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'cadence_seconds' => 'integer',
            'grace_seconds' => 'integer',
            'is_enabled' => 'boolean',
            'email_alerts_enabled' => 'boolean',
            'email_alert_destination' => 'encrypted',
            'webhook_alerts_enabled' => 'boolean',
            'webhook_alert_destination' => 'encrypted',
            'last_check_in_at' => 'immutable_datetime',
            'next_due_at' => 'immutable_datetime',
        ];
    }
}
