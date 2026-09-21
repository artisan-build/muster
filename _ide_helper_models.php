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
 * @property string $name
 * @property int $cadence_seconds
 * @property int $grace_seconds
 * @property bool $is_enabled
 * @property string $status
 * @property CarbonImmutable|null $last_check_in_at
 * @property CarbonImmutable|null $next_due_at
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Monitor newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Monitor newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Monitor query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Monitor whereCadenceSeconds($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Monitor whereCreatedAt($value)
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
