<?php

// @formatter:off
// phpcs:ignoreFile
/**
 * A helper file for your Eloquent Models
 * Copy the phpDocs from this file to the correct Model,
 * And remove them from this file, to prevent double declarations.
 *
 * @author Barry vd. Heuvel <barryvdh@gmail.com>
 */


namespace App\Models{
/**
 * @property int $id
 * @property int $monitor_id
 * @property string $event_type
 * @property CarbonImmutable $occurred_at
 * @property-read \App\Models\Monitor $monitor
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CheckInEvent newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CheckInEvent newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CheckInEvent query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CheckInEvent whereEventType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CheckInEvent whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CheckInEvent whereMonitorId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CheckInEvent whereOccurredAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	final class IdeHelperCheckInEvent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string|null $local_key
 * @property SubjectType $subject_type
 * @property string $subject_ref
 * @property string $installation_ref
 * @property string $application_ref
 * @property string $audience
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Monitor> $monitors
 * @property-read int|null $monitors_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CredentialPartition newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CredentialPartition newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CredentialPartition query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CredentialPartition whereApplicationRef($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CredentialPartition whereAudience($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CredentialPartition whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CredentialPartition whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CredentialPartition whereInstallationRef($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CredentialPartition whereLocalKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CredentialPartition whereSubjectRef($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CredentialPartition whereSubjectType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CredentialPartition whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	final class IdeHelperCredentialPartition {}
}

namespace App\Models{
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
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @property-read \App\Models\CredentialPartition|null $credentialPartition
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\CheckInEvent> $events
 * @property-read int|null $events_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Monitor newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Monitor newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Monitor query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Monitor whereCadenceSeconds($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Monitor whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Monitor whereCredentialPartitionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Monitor whereGraceSeconds($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Monitor whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Monitor whereIsEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Monitor whereLastCheckInAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Monitor whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Monitor whereNextDueAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Monitor whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Monitor whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	final class IdeHelperMonitor {}
}
