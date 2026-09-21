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
            $table->string('timezone')->nullable();
        });

        Schema::table('check_in_events', function (Blueprint $table): void {
            $table->string('execution_id', 36)->nullable();
            $table->string('timezone')->nullable();
        });

        Schema::create('schedule_executions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->string('execution_id', 36);
            $table->string('status');
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->double('runtime')->nullable();
            $table->integer('exit_code')->nullable();
            $table->boolean('background');
            $table->string('timezone');

            $table->unique(['monitor_id', 'execution_id']);
            $table->index(['monitor_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_executions');

        Schema::table('check_in_events', function (Blueprint $table): void {
            $table->dropColumn(['execution_id', 'timezone']);
        });

        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropColumn('timezone');
        });
    }
};
