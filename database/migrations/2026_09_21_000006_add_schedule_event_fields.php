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
            $table->string('schedule_key', 64)->nullable();
            $table->text('command')->nullable();
            $table->string('display')->nullable();
            $table->string('expression')->nullable();
            $table->integer('last_exit_code')->nullable();
            $table->double('last_runtime')->nullable();
            $table->boolean('background')->default(false);

            $table->unique(['credential_partition_id', 'schedule_key']);
        });

        Schema::table('check_in_events', function (Blueprint $table): void {
            $table->text('command')->nullable();
            $table->string('display')->nullable();
            $table->string('expression')->nullable();
            $table->integer('exit_code')->nullable();
            $table->double('runtime')->nullable();
            $table->boolean('background')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('check_in_events', function (Blueprint $table): void {
            $table->dropColumn([
                'command',
                'display',
                'expression',
                'exit_code',
                'runtime',
                'background',
            ]);
        });

        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropUnique(['credential_partition_id', 'schedule_key']);
            $table->dropColumn([
                'schedule_key',
                'command',
                'display',
                'expression',
                'last_exit_code',
                'last_runtime',
                'background',
            ]);
        });
    }
};
