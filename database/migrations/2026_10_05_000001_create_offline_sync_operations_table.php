<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The browser outbox is deliberately mirrored server-side. The operation id
     * makes replay safe after an outage, a tab refresh, or a repeated request.
     */
    public function up(): void
    {
        Schema::create('offline_sync_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('operation_id', 80);
            $table->string('device_id', 80)->nullable();
            $table->string('operation', 80);
            $table->string('status', 20)->default('applied'); // applied | conflict | rejected
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('payload');
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->unique(['hotel_id', 'operation_id']);
            $table->index(['hotel_id', 'status', 'created_at']);
            $table->index(['hotel_id', 'operation', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_sync_operations');
    }
};
