<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitors', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('cadence_seconds');
            $table->unsignedInteger('grace_seconds')->default(0);
            $table->boolean('is_enabled')->default(true)->index();
            $table->string('status')->default('pending');
            $table->timestamp('last_check_in_at')->nullable();
            $table->timestamp('next_due_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitors');
    }
};
