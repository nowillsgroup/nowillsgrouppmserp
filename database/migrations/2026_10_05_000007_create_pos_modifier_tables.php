<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_modifier_groups', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('outlet_id')->nullable()->constrained()->nullOnDelete();
            $t->string('name', 80);
            $t->unsignedTinyInteger('min_selections')->default(0);
            $t->unsignedTinyInteger('max_selections')->default(1);
            $t->boolean('is_required')->default(false);
            $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
            $t->index(['hotel_id', 'outlet_id', 'is_active']);
        });

        Schema::create('menu_modifiers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('menu_modifier_group_id')->constrained()->cascadeOnDelete();
            $t->string('name', 100);
            $t->decimal('price_delta', 12, 2)->default(0);
            $t->boolean('is_available')->default(true);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
            // Named explicitly: the generated name
            // menu_modifiers_hotel_id_menu_modifier_group_id_is_available_index
            // is 65 characters, and MySQL rejects identifiers over 64. SQLite
            // accepts it, so this only breaks on MySQL.
            $t->index(['hotel_id', 'menu_modifier_group_id', 'is_available'], 'menu_modifiers_avail_idx');
        });

        Schema::create('menu_item_modifier_groups', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('menu_item_id')->constrained('menu_items')->cascadeOnDelete();
            $t->foreignId('menu_modifier_group_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
            // Same reason as above: the generated unique name is 68 characters.
            $t->unique(['menu_item_id', 'menu_modifier_group_id'], 'menu_item_mod_group_unique');
            $t->index(['hotel_id', 'menu_item_id']);
        });

        Schema::table('pos_order_items', function (Blueprint $t) {
            $t->decimal('modifier_amount', 12, 2)->default(0)->after('rate');
            $t->json('modifiers')->nullable()->after('modifier_amount');
        });
    }

    public function down(): void
    {
        Schema::table('pos_order_items', function (Blueprint $t) {
            $t->dropColumn(['modifier_amount', 'modifiers']);
        });
        Schema::dropIfExists('menu_item_modifier_groups');
        Schema::dropIfExists('menu_modifiers');
        Schema::dropIfExists('menu_modifier_groups');
    }
};
