<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('check_in_events', function (Blueprint $table): void {
            $table->string('event_type')->default('check_in')->after('monitor_id');
            $table->renameColumn('checked_in_at', 'occurred_at');
        });
    }

    public function down(): void
    {
        Schema::table('check_in_events', function (Blueprint $table): void {
            $table->renameColumn('occurred_at', 'checked_in_at');
            $table->dropColumn('event_type');
        });
    }
};
