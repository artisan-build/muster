<?php

declare(strict_types=1);

namespace App\Models;

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
            $this->events()->create([
                'event_type' => $eventType,
                'occurred_at' => $occurredAt,
            ]);
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
