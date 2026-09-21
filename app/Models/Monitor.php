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
 *
 * @mixin IdeHelperMonitor
 */
final class Monitor extends Model
{
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
            $event = $this->events()->create([
                ...$attributes,
                'event_type' => $eventType,
                'occurred_at' => $occurredAt,
                'monitor_name' => $this->name,
                'last_check_in_at' => $this->last_check_in_at,
                'next_due_at' => $this->next_due_at,
            ]);

            if ($deliverAlerts && AlertMode::forEventType($eventType) !== null) {
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
            ->whereDoesntHave('alertDeliveries', fn ($query) => $query
                ->whereIn('status', AlertDelivery::NONTERMINAL_STATUSES))
            ->delete();
    }

    private function createAlertDeliveries(CheckInEvent $event): void
    {
        $mode = AlertMode::forEventType($event->event_type);

        if ($mode === null) {
            return;
        }

        $destinations = [
            AlertDelivery::CHANNEL_EMAIL => $this->email_alert_modes->contains($mode)
                ? $this->email_alert_destination
                : null,
            AlertDelivery::CHANNEL_WEBHOOK => $this->webhook_alert_modes->contains($mode)
                ? $this->webhook_alert_destination
                : null,
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
