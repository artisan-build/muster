<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_digest_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('week_key', 8);
            $table->timestamp('window_start');
            $table->timestamp('window_end');
            $table->text('recipient')->nullable();
            $table->longText('payload')->nullable();
            $table->string('status')->default('pending');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->string('last_error_code')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->uuid('publication_claim_id')->nullable();
            $table->timestamp('publication_claimed_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->uuid('processing_claim_id')->nullable();
            $table->timestamp('processing_claimed_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'week_key']);
            $table->index(['status', 'published_at', 'publication_claimed_at']);
        });

        Schema::table('check_in_events', function (Blueprint $table): void {
            $table->index(['event_type', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::table('check_in_events', function (Blueprint $table): void {
            $table->dropIndex(['event_type', 'occurred_at']);
        });

        Schema::dropIfExists('weekly_digest_deliveries');
    }
};
