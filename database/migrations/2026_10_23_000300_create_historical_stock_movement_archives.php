<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Non-operational, append-only retention for legacy stock movement imports.
 * This table is intentionally not part of stock valuation or GL posting.
 */
return new class extends Migration
{
    public function up(): void
    {
                if (Schema::hasTable('historical_stock_movement_archives')) {
            return;
        }

        Schema::create('historical_stock_movement_archives', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('batch_id')->nullable()->constrained('data_migration_batches')->nullOnDelete();
            $t->string('external_id', 100);
            $t->string('item_external_id', 100);
            $t->string('item_code_snapshot', 30)->nullable();
            $t->string('item_name_snapshot', 120);
            $t->string('item_unit_snapshot', 20);
            $t->string('store_name', 80);
            $t->date('cutover_date');
            $t->date('movement_date');
            $t->string('movement_type', 20);
            $t->decimal('quantity', 12, 3);
            $t->decimal('unit_cost', 12, 2)->nullable();
            $t->text('reference')->nullable();
            $t->string('department', 80)->nullable();
            $t->text('remarks')->nullable();
            $t->json('source_payload')->nullable();
            $t->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'external_id'], 'hsma_hotel_external_unique');
            $t->index(['hotel_id', 'movement_date'], 'hsma_hotel_date_idx');
            $t->index(['hotel_id', 'item_external_id', 'movement_date'], 'hsma_hotel_item_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historical_stock_movement_archives');
    }
};
