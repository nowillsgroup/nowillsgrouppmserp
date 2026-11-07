<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A side dish that has to be chosen.
     *
     * Until now every side dish was optional: the terminal asked, and "Continue with none" was as good an answer as
     * any. A kitchen that serves Banku & Okro with a side, or a bar that sells a beer with pepper sauce, could not say
     * so — the waiter could send the dish without one, and the kitchen then guessed.
     *
     * side_dish_required is the dish's own answer. It is set on the menu item (Masters › Menu items › Variants), it is
     * carried to every screen that rings the dish up, and the server refuses a line that leaves it out — so the rule
     * holds even when the request does not come from our own screen.
     *
     * Nothing is backfilled: every existing dish keeps asking without insisting.
     *
     * Dated ahead of the settings-trail migration so the trail still runs last, which the audit holds us to: this column
     * depends on nothing that comes after it, and a dish that insists on a side dish has nothing to add to the trail.
     */
    public function up(): void
    {
        if (! Schema::hasTable('menu_items') || Schema::hasColumn('menu_items', 'side_dish_required')) {
            return;
        }

        Schema::table('menu_items', function (Blueprint $table) {
            $table->boolean('side_dish_required')->default(false)->after('is_available');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('menu_items', 'side_dish_required')) {
            return;
        }

        Schema::table('menu_items', function (Blueprint $table) {
            $table->dropColumn('side_dish_required');
        });
    }
};
