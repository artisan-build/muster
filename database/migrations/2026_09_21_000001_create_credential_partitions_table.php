<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credential_partitions', function (Blueprint $table): void {
            $table->id();
            $table->string('local_key')->nullable()->unique();
            $table->string('subject_type');
            $table->string('subject_ref');
            $table->string('installation_ref');
            $table->string('application_ref');
            $table->string('audience');
            $table->timestamps();

            $table->unique(['subject_type', 'subject_ref']);
        });

        $partitionId = DB::table('credential_partitions')->insertGetId([
            'local_key' => 'current',
            'subject_type' => 'installation',
            'subject_ref' => 'subject-'.bin2hex(random_bytes(32)),
            'installation_ref' => 'installation-'.bin2hex(random_bytes(32)),
            'application_ref' => 'application-'.bin2hex(random_bytes(32)),
            'audience' => 'audience-'.bin2hex(random_bytes(32)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::table('monitors', function (Blueprint $table) use ($partitionId): void {
            $table->foreignId('credential_partition_id')
                ->default($partitionId)
                ->after('id')
                ->constrained('credential_partitions')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('credential_partition_id');
        });

        Schema::dropIfExists('credential_partitions');
    }
};
