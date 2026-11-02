<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('menu_item_variants')) {
            return;
        }

        Schema::create('menu_item_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('menu_item_id')->constrained('menu_items')->cascadeOnDelete();
            $table->string('name', 100);
            $table->decimal('rate', 12, 2)->default(0);
            $table->decimal('cost', 12, 2)->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['menu_item_id', 'name']);
            $table->index(['hotel_id', 'menu_item_id', 'sort_order'], 'menu_item_variants_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_item_variants');
    }
};
