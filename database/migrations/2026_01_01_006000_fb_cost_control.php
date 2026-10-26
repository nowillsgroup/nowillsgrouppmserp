<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v6.8 F&B cost control & menu engineering:
 *  - standard recipe cards (batch yield, portion, method, prep time, target cost % override, chef approval) on top of the
 *    existing menu_item_recipes ingredient lines; ingredient trim yield % from butcher / yield tests;
 *  - food / beverage classification of menu categories (revenue) and item categories (cost);
 *  - daily plate-cost snapshots (cost creep), a menu price change log, yield tests and the staff-meal register used to
 *    move employee meals out of the cost of food sold (USALI).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $t) {
            $t->decimal('yield_pct', 5, 2)->default(100)->after('last_cost');        // usable share after trimming / cleaning
        });
        Schema::table('menu_categories', function (Blueprint $t) {
            $t->string('fb_class', 10)->default('food')->after('kitchen_station'); // food | beverage – revenue class
        });
        Schema::table('item_categories', function (Blueprint $t) {
            $t->string('fb_class', 10)->nullable()->after('name');                 // food | beverage | null = not F&B cost
        });

                if (Schema::hasTable('fb_recipe_cards')) {
            return;
        }

        Schema::create('fb_recipe_cards', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('menu_item_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('batch_portions')->default(1);                // the method below makes this many portions
            $t->string('portion_size', 60)->nullable();                             // 250 g, 1 bowl, 60 ml…
            $t->unsignedSmallInteger('prep_minutes')->nullable();
            $t->string('station', 40)->nullable();
            $t->string('garnish', 190)->nullable();
            $t->text('method')->nullable();
            $t->decimal('target_pct', 5, 2)->nullable();                            // overrides the class target
            $t->unsignedSmallInteger('version')->default(1);
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('approved_at')->nullable();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'menu_item_id']);
        });

                if (Schema::hasTable('fb_cost_snapshots')) {
            return;
        }

        Schema::create('fb_cost_snapshots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('menu_item_id')->constrained()->cascadeOnDelete();
            $t->date('business_date');
            $t->decimal('cost', 12, 4);
            $t->decimal('price', 12, 2);
            $t->timestamps();
            $t->unique(['hotel_id', 'menu_item_id', 'business_date']);
            $t->index(['hotel_id', 'business_date']);
        });

                if (Schema::hasTable('fb_price_changes')) {
            return;
        }

        Schema::create('fb_price_changes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('menu_item_id')->constrained()->cascadeOnDelete();
            $t->date('effective_on');
            $t->decimal('old_price', 12, 2);
            $t->decimal('new_price', 12, 2);
            $t->decimal('cost_at', 12, 4);
            $t->string('reason', 190);
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->index(['hotel_id', 'effective_on']);
        });

                if (Schema::hasTable('fb_yield_tests')) {
            return;
        }

        Schema::create('fb_yield_tests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('number', 20);
            $t->foreignId('inventory_item_id')->constrained()->cascadeOnDelete();
            $t->date('tested_on');
            $t->decimal('gross_qty', 10, 3);                                        // as purchased
            $t->decimal('trim_qty', 10, 3)->default(0);                             // bones, skin, peel, fat
            $t->decimal('cooking_loss_qty', 10, 3)->default(0);
            $t->decimal('usable_qty', 10, 3);
            $t->decimal('yield_pct', 5, 2);
            $t->decimal('unit_cost', 12, 2);                                         // purchase cost per unit at the test
            $t->string('notes', 255)->nullable();
            $t->boolean('applied')->default(false);
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'number']);
        });

                if (Schema::hasTable('fb_staff_meals')) {
            return;
        }

        Schema::create('fb_staff_meals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->date('business_date');
            $t->string('meal', 12);                                                   // breakfast | lunch | dinner | night
            $t->unsignedSmallInteger('covers');
            $t->decimal('cost_per_cover', 10, 2);
            $t->decimal('total', 12, 2);
            $t->string('notes', 190)->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'business_date', 'meal']);
        });
    }

    public function down(): void
    {
        foreach (['fb_staff_meals', 'fb_yield_tests', 'fb_price_changes', 'fb_cost_snapshots', 'fb_recipe_cards'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('item_categories', fn (Blueprint $t) => $t->dropColumn('fb_class'));
        Schema::table('menu_categories', fn (Blueprint $t) => $t->dropColumn('fb_class'));
        Schema::table('inventory_items', fn (Blueprint $t) => $t->dropColumn('yield_pct'));
    }
};

