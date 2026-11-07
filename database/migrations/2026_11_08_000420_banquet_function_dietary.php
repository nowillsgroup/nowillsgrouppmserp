<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dietary needs per function.
 *
 * The kitchen menu sheet used to print a blank grid because the booking held nothing but a
 * paragraph of prose. A function now carries its own counts — one row per diet, with the
 * number of covers and the dish that answers it — so the sheet can print what to cook
 * instead of asking a chef to guess.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banquet_functions', function (Blueprint $table) {
            $table->json('dietary')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('banquet_functions', function (Blueprint $table) {
            $table->dropColumn('dietary');
        });
    }
};
