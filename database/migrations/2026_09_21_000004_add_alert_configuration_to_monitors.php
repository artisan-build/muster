<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->boolean('email_alerts_enabled')->default(false);
            $table->text('email_alert_destination')->nullable();
            $table->boolean('webhook_alerts_enabled')->default(false);
            $table->text('webhook_alert_destination')->nullable();
        });

        Schema::table('check_in_events', function (Blueprint $table): void {
            $table->timestamp('last_check_in_at')->nullable();
            $table->timestamp('next_due_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('check_in_events', function (Blueprint $table): void {
            $table->dropColumn(['last_check_in_at', 'next_due_at']);
        });

        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropColumn([
                'email_alerts_enabled',
                'email_alert_destination',
                'webhook_alerts_enabled',
                'webhook_alert_destination',
            ]);
        });
    }
};
