<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\AlertModesCast;
use App\Enums\AlertMode;
use App\Jobs\DeliverTransitionAlert;
use App\ValueObjects\AlertModes;
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
 * @property string|null $schedule_key
 * @property string|null $command
 * @property string|null $display
 * @property string|null $expression
 * @property string|null $timezone
 * @property int $cadence_seconds
 * @property int $grace_seconds
 * @property bool $is_enabled
 * @property string $status
 * @property CarbonImmutable|null $last_check_in_at
 * @property CarbonImmutable|null $next_due_at
 * @property int|null $last_exit_code
 * @property float|null $last_runtime
 * @property bool $background
 * @property AlertModes $email_alert_modes
 * @property string|null $email_alert_destination
 * @property AlertModes $webhook_alert_modes
 * @property string|null $webhook_alert_destination
 * @mixin IdeHelperMonitor
 */
final class Monitor extends Model
{
    /** @var list<string> */
    private const array FAILURE_EVENT_TYPES = [
        CheckInEvent::TYPE_MISSED,
        CheckInEvent::TYPE_NON_ZERO_EXIT,
    ];

    /** @var list<string> */
    protected $fillable = [
        'name',
        'schedule_key',
        'command',
        'display',
        'expression',
        'timezone',
        'cadence_seconds',
        'grace_seconds',
        'background',
        'email_alert_modes',
        'email_alert_destination',
        'webhook_alert_modes',
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

    public static function scheduleKeyForCommand(string $command): string
    {
        return hash('sha256', $command);
    }

    public function resolveRouteBinding($value, $field = null): ?Model
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            return null;
        }

        return parent::resolveRouteBinding($value, $field);
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

    /** @return HasMany<ScheduleExecution, $this> */
    public function executions(): HasMany
    {
        return $this->hasMany(ScheduleExecution::class);
    }

    public function hasActiveFailureIncident(): bool
    {
        $latestIncidentEvent = $this->events()
            ->whereIn('event_type', [...self::FAILURE_EVENT_TYPES, CheckInEvent::TYPE_RECOVERED])
            ->latest('id')
            ->value('event_type');

        return in_array($latestIncidentEvent, self::FAILURE_EVENT_TYPES, true);
    }

    /**
     * @param  list<string>  $eventTypes
     * @param  array<string, mixed>  $attributes
     */
    public function recordActivity(
        array $eventTypes,
        CarbonInterface $occurredAt,
        array $attributes = [],
        bool $deliverAlerts = true,
    ): void {
        foreach ($eventTypes as $eventType) {
            $deliveryChannels = $deliverAlerts
                ? $this->deliveryChannelsFor($eventType)
                : [];
            $event = $this->events()->create([
                ...$attributes,
                'event_type' => $eventType,
                'occurred_at' => $occurredAt,
                'monitor_name' => $this->name,
                'last_check_in_at' => $this->last_check_in_at,
                'next_due_at' => $this->next_due_at,
            ]);

            if ($deliveryChannels !== []) {
                $this->createAlertDeliveries($event, $deliveryChannels);
            }
        }

        /** @var Collection<int, CheckInEvent> $retainedEvents */
        $retainedEvents = $this->events()
            ->latest('occurred_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get(['id']);
        $weeklyFailureCutoff = CarbonImmutable::now('UTC')
            ->startOfWeek(CarbonInterface::MONDAY)
            ->subWeek();

        CheckInEvent::query()
            ->where('monitor_id', $this->id)
            ->whereNotIn('id', $retainedEvents->modelKeys())
            ->where(fn ($query) => $query
                ->whereNotIn('event_type', self::FAILURE_EVENT_TYPES)
                ->orWhere('occurred_at', '<', $weeklyFailureCutoff))
            ->whereDoesntHave('alertDeliveries', fn ($query) => $query
                ->whereIn('status', AlertDelivery::NONTERMINAL_STATUSES))
            ->delete();
    }

    /** @return list<string> */
    private function deliveryChannelsFor(string $eventType): array
    {
        $mode = AlertMode::forEventType($eventType);

        if ($mode === null) {
            return [];
        }

        if ($eventType === CheckInEvent::TYPE_RECOVERED) {
            if (! $this->hasActiveFailureIncident()) {
                return [];
            }

            return array_values(array_intersect(
                $this->subscribedChannels(),
                $this->activeFailureIncidentDeliveryChannels(),
            ));
        }

        $channels = $this->subscribedChannels($mode);

        if (in_array($eventType, self::FAILURE_EVENT_TYPES, true)) {
            $channels = array_diff($channels, $this->activeFailureIncidentDeliveryChannels());
        }

        return array_values($channels);
    }

    /** @return list<string> */
    private function activeFailureIncidentDeliveryChannels(): array
    {
        if (! $this->hasActiveFailureIncident()) {
            return [];
        }

        $lastRecoveryId = (int) ($this->events()
            ->where('event_type', CheckInEvent::TYPE_RECOVERED)
            ->max('id') ?? 0);

        /** @var list<string> $channels */
        $channels = AlertDelivery::query()
            ->whereHas('event', fn ($query) => $query
                ->where('monitor_id', $this->id)
                ->whereIn('event_type', self::FAILURE_EVENT_TYPES)
                ->where('id', '>', $lastRecoveryId))
            ->distinct()
            ->orderBy('channel')
            ->pluck('channel')
            ->all();

        return $channels;
    }

    /** @return list<string> */
    private function subscribedChannels(?AlertMode $mode = null): array
    {
        $destinations = [
            AlertDelivery::CHANNEL_EMAIL => ($mode === null
                ? $this->email_alert_modes->value !== 0
                : $this->email_alert_modes->contains($mode))
                ? $this->email_alert_destination
                : null,
            AlertDelivery::CHANNEL_WEBHOOK => ($mode === null
                ? $this->webhook_alert_modes->value !== 0
                : $this->webhook_alert_modes->contains($mode))
                ? $this->webhook_alert_destination
                : null,
        ];

        return array_keys(array_filter(
            $destinations,
            static fn (?string $destination): bool => $destination !== null,
        ));
    }

    /** @param list<string> $channels */
    private function createAlertDeliveries(CheckInEvent $event, array $channels): void
    {
        $destinations = [
            AlertDelivery::CHANNEL_EMAIL => $this->email_alert_destination,
            AlertDelivery::CHANNEL_WEBHOOK => $this->webhook_alert_destination,
        ];

        foreach ($channels as $channel) {
            $destination = $destinations[$channel] ?? null;

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
            'last_exit_code' => 'integer',
            'last_runtime' => 'float',
            'background' => 'boolean',
            'email_alert_modes' => AlertModesCast::class,
            'email_alert_destination' => 'encrypted',
            'webhook_alert_modes' => AlertModesCast::class,
            'webhook_alert_destination' => 'encrypted',
            'last_check_in_at' => 'immutable_datetime',
            'next_due_at' => 'immutable_datetime',
        ];
    }
}
