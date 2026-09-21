<?php

declare(strict_types=1);

use App\Enums\AlertMode;
use App\Models\CredentialPartition;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('migrates boolean subscriptions to missed-only masks and rolls masks back deterministically', function (): void {
    $migration = require database_path('migrations/2026_09_21_000008_replace_alert_booleans_with_mode_masks.php');

    expect($migration)->toBeInstanceOf(Migration::class);

    $migration->down();

    $now = now();
    $partitionId = CredentialPartition::local()->id;
    $ids = [];

    foreach ([
        [false, false],
        [true, false],
        [false, true],
        [true, true],
    ] as $sequence => [$emailEnabled, $webhookEnabled]) {
        $ids[] = DB::table('monitors')->insertGetId([
            'credential_partition_id' => $partitionId,
            'name' => "Test Created Migrated Monitor {$sequence}",
            'cadence_seconds' => 300,
            'grace_seconds' => 30,
            'email_alerts_enabled' => $emailEnabled,
            'webhook_alerts_enabled' => $webhookEnabled,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    $migration->up();

    $migrated = DB::table('monitors')->whereIn('id', $ids)->orderBy('id')->get();

    expect(Schema::hasColumn('monitors', 'email_alerts_enabled'))->toBeFalse()
        ->and(Schema::hasColumn('monitors', 'webhook_alerts_enabled'))->toBeFalse()
        ->and($migrated->pluck('email_alert_modes')->all())->toBe([0, AlertMode::Missed->value, 0, AlertMode::Missed->value])
        ->and($migrated->pluck('webhook_alert_modes')->all())->toBe([0, 0, AlertMode::Missed->value, AlertMode::Missed->value]);

    foreach ([0, AlertMode::Missed->value, AlertMode::NonZeroExit->value, 3] as $index => $mask) {
        DB::table('monitors')->where('id', $ids[$index])->update([
            'email_alert_modes' => $mask,
            'webhook_alert_modes' => $mask,
        ]);
    }

    $migration->down();

    $rolledBack = DB::table('monitors')->whereIn('id', $ids)->orderBy('id')->get();

    expect($rolledBack->map(fn (object $row): bool => (bool) $row->email_alerts_enabled)->all())
        ->toBe([false, true, true, true])
        ->and($rolledBack->map(fn (object $row): bool => (bool) $row->webhook_alerts_enabled)->all())
        ->toBe([false, true, true, true]);

    $migration->up();
});
