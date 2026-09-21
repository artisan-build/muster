<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('check_in_event_id')->constrained()->cascadeOnDelete();
            $table->string('channel');
            $table->text('destination');
            $table->string('status')->default('pending');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->string('last_error_code')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->unique(['check_in_event_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_deliveries');
    }
};
