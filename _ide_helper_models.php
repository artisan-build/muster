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
 * @property int $check_in_event_id
 * @property string $channel
 * @property string $destination
 * @property string $status
 * @property int $attempt_count
 * @property string|null $last_error_code
 * @property CarbonImmutable|null $last_attempt_at
 * @property CarbonImmutable|null $delivered_at
 * @property CheckInEvent $event
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AlertDelivery newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AlertDelivery newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AlertDelivery query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AlertDelivery whereAttemptCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AlertDelivery whereChannel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AlertDelivery whereCheckInEventId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AlertDelivery whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AlertDelivery whereDeliveredAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AlertDelivery whereDestination($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AlertDelivery whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AlertDelivery whereLastAttemptAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AlertDelivery whereLastErrorCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AlertDelivery whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AlertDelivery whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	final class IdeHelperAlertDelivery {}
}

namespace App\Models{
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
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\AlertDelivery> $alertDeliveries
 * @property-read int|null $alert_deliveries_count
 * @property-read \App\Models\Monitor $monitor
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CheckInEvent newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CheckInEvent newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CheckInEvent query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CheckInEvent whereBackground($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CheckInEvent whereCommand($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CheckInEvent whereDisplay($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CheckInEvent whereEventType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CheckInEvent whereExecutionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CheckInEvent whereExitCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CheckInEvent whereExpression($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CheckInEvent whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CheckInEvent whereLastCheckInAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CheckInEvent whereMonitorId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CheckInEvent whereMonitorName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CheckInEvent whereNextDueAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CheckInEvent whereOccurredAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CheckInEvent whereRuntime($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CheckInEvent whereTimezone($value)
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
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @property-read \App\Models\CredentialPartition $credentialPartition
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\CheckInEvent> $events
 * @property-read int|null $events_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ScheduleExecution> $executions
 * @property-read int|null $executions_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Monitor newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Monitor newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Monitor query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Monitor whereBackground($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Monitor whereCadenceSeconds($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Monitor whereCommand($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Monitor whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Monitor whereCredentialPartitionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Monitor whereDisplay($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Monitor whereEmailAlertDestination($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Monitor whereEmailAlertModes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Monitor whereExpression($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Monitor whereGraceSeconds($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Monitor whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Monitor whereIsEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Monitor whereLastCheckInAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Monitor whereLastExitCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Monitor whereLastRuntime($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Monitor whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Monitor whereNextDueAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Monitor whereScheduleKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Monitor whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Monitor whereTimezone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Monitor whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Monitor whereWebhookAlertDestination($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Monitor whereWebhookAlertModes($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	final class IdeHelperMonitor {}
}

namespace App\Models{
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
 * @property-read \App\Models\Monitor $monitor
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduleExecution newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduleExecution newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduleExecution query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduleExecution whereBackground($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduleExecution whereCompletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduleExecution whereExecutionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduleExecution whereExitCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduleExecution whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduleExecution whereMonitorId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduleExecution whereRuntime($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduleExecution whereStartedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduleExecution whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduleExecution whereTimezone($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	final class IdeHelperScheduleExecution {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $user_id
 * @property string $week_key
 * @property CarbonImmutable $window_start
 * @property CarbonImmutable $window_end
 * @property string|null $recipient
 * @property array{week_key: string, window_start: string, window_end: string, monitor_count: int, missed: list<array{id: int, monitor_name: string, command: string|null, occurred_at: string}>, non_zero_exit: list<array{id: int, monitor_name: string, command: string|null, occurred_at: string, exit_code: int|null}>, still_missed: list<array{monitor_id: int, monitor_name: string, command: string|null, reference_at: string|null, reference_type: string}>}|null $payload
 * @property string $status
 * @property int $attempt_count
 * @property string|null $last_error_code
 * @property CarbonImmutable|null $last_attempt_at
 * @property string|null $publication_claim_id
 * @property CarbonImmutable|null $publication_claimed_at
 * @property CarbonImmutable|null $published_at
 * @property string|null $processing_claim_id
 * @property CarbonImmutable|null $processing_claimed_at
 * @property CarbonImmutable|null $delivered_at
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WeeklyDigestDelivery newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WeeklyDigestDelivery newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WeeklyDigestDelivery query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WeeklyDigestDelivery whereAttemptCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WeeklyDigestDelivery whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WeeklyDigestDelivery whereDeliveredAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WeeklyDigestDelivery whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WeeklyDigestDelivery whereLastAttemptAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WeeklyDigestDelivery whereLastErrorCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WeeklyDigestDelivery wherePayload($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WeeklyDigestDelivery whereProcessingClaimId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WeeklyDigestDelivery whereProcessingClaimedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WeeklyDigestDelivery wherePublicationClaimId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WeeklyDigestDelivery wherePublicationClaimedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WeeklyDigestDelivery wherePublishedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WeeklyDigestDelivery whereRecipient($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WeeklyDigestDelivery whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WeeklyDigestDelivery whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WeeklyDigestDelivery whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WeeklyDigestDelivery whereWeekKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WeeklyDigestDelivery whereWindowEnd($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WeeklyDigestDelivery whereWindowStart($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	final class IdeHelperWeeklyDigestDelivery {}
}
