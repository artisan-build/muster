<?php

declare(strict_types=1);

use App\Enums\AlertMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->unsignedSmallInteger('email_alert_modes')->default(0);
            $table->unsignedSmallInteger('webhook_alert_modes')->default(0);
        });

        DB::table('monitors')->where('email_alerts_enabled', true)->update([
            'email_alert_modes' => AlertMode::Missed->value,
        ]);
        DB::table('monitors')->where('webhook_alerts_enabled', true)->update([
            'webhook_alert_modes' => AlertMode::Missed->value,
        ]);

        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropColumn(['email_alerts_enabled', 'webhook_alerts_enabled']);
        });
    }

    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->boolean('email_alerts_enabled')->default(false);
            $table->boolean('webhook_alerts_enabled')->default(false);
        });

        DB::table('monitors')->where('email_alert_modes', '<>', 0)->update([
            'email_alerts_enabled' => true,
        ]);
        DB::table('monitors')->where('webhook_alert_modes', '<>', 0)->update([
            'webhook_alerts_enabled' => true,
        ]);

        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropColumn(['email_alert_modes', 'webhook_alert_modes']);
        });
    }
};
