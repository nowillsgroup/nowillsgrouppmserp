<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v7.35 — Data migration & cutover tooling (legacy PMS → Nowills PMS).
 *
 * Batches hold one uploaded CSV per dataset; rows record the per-row outcome of
 * validate/import runs; the id map makes imports idempotent and lets later
 * datasets (e.g. reservations) reference earlier ones (e.g. guests) by the
 * legacy system's external ids.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_migration_batches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('code', 20);
            $t->string('dataset', 40);
            $t->string('source_name', 120)->nullable(); // e.g. "Exceed PMS export 2026-10"
            $t->string('file_path', 255)->nullable();
            $t->string('status', 20)->default('registered'); // registered, validated, imported, failed
            $t->unsignedInteger('rows_total')->default(0);
            $t->unsignedInteger('rows_created')->default(0);
            $t->unsignedInteger('rows_updated')->default(0);
            $t->unsignedInteger('rows_skipped')->default(0);
            $t->unsignedInteger('rows_failed')->default(0);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('notes')->nullable();
            $t->timestamp('validated_at')->nullable();
            $t->timestamp('imported_at')->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'code']);
            $t->index(['hotel_id', 'dataset']);
        });

        Schema::create('data_migration_rows', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('batch_id')->constrained('data_migration_batches')->cascadeOnDelete();
            $t->unsignedInteger('row_no');
            $t->string('external_id', 100)->nullable();
            // pending, would_create, would_update, unchanged, created, updated, skipped, failed
            $t->string('status', 20)->default('pending');
            $t->string('message', 500)->nullable();
            $t->string('model_type', 60)->nullable();
            $t->unsignedBigInteger('model_id')->nullable();
            $t->json('payload')->nullable();
            $t->timestamps();
            $t->index(['batch_id', 'status']);
        });

        Schema::create('data_migration_id_map', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('batch_id')->constrained('data_migration_batches')->cascadeOnDelete();
            $t->string('dataset', 40);
            $t->string('external_id', 100);
            $t->string('model_type', 60);
            $t->unsignedBigInteger('model_id');
            $t->timestamps();
            $t->unique(['hotel_id', 'dataset', 'external_id']);
            $t->index(['hotel_id', 'model_type', 'model_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_migration_id_map');
        Schema::dropIfExists('data_migration_rows');
        Schema::dropIfExists('data_migration_batches');
    }
};
