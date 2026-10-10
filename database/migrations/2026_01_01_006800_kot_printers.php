<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v7.6 Multiple KOT printers: station and captain printers per outlet, item routing, and a print log
 * so a ticket can be split, reprinted or sent to a network printer (ESC/POS) or a browser print station.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kot_printers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('outlet_id')->nullable()->constrained()->nullOnDelete(); // null = every outlet
            $t->string('code', 12);
            $t->string('name', 80);
            $t->string('station', 40)->nullable();          // matches menu category kitchen_station; "Kitchen" is the default station
            $t->string('role', 12)->default('station');     // station | captain
            $t->string('connection', 12)->default('browser'); // browser | network
            $t->string('host', 80)->nullable();
            $t->unsignedSmallInteger('port')->default(9100);
            $t->string('paper', 4)->default('80');          // 80 | 58
            $t->unsignedTinyInteger('copies')->default(1);
            $t->string('header', 80)->nullable();
            $t->boolean('beep')->default(true);
            $t->boolean('is_fallback')->default(false);     // items with no station printer
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->string('notes', 200)->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'code']);
        });

        Schema::create('kot_print_jobs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('pos_order_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('kot_no');
            $t->foreignId('kot_printer_id')->nullable()->constrained()->nullOnDelete();
            $t->string('kind', 12)->default('station');     // station | captain | cancel | combined | unrouted
            $t->string('status', 12)->default('queued');    // queued | printing | sent | printed | failed | skipped
            $t->unsignedTinyInteger('copies')->default(1);
            $t->json('item_ids')->nullable();
            $t->string('mode', 12)->default('auto');        // auto | manual | reprint | void
            $t->string('error', 250)->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('printed_at')->nullable();
            $t->timestamps();
            $t->index(['hotel_id', 'status', 'kot_printer_id']);
        });

        Schema::table('menu_categories', function (Blueprint $t) {
            $t->foreignId('kot_printer_id')->nullable()->after('kitchen_station')->constrained('kot_printers')->nullOnDelete();
        });
        Schema::table('menu_items', function (Blueprint $t) {
            $t->foreignId('kot_printer_id')->nullable()->after('menu_category_id')->constrained('kot_printers')->nullOnDelete();
        });
        Schema::table('pos_order_items', function (Blueprint $t) {
            $t->foreignId('kot_printer_id')->nullable()->after('kot_no')->constrained('kot_printers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pos_order_items', fn (Blueprint $t) => $t->dropConstrainedForeignId('kot_printer_id'));
        Schema::table('menu_items', fn (Blueprint $t) => $t->dropConstrainedForeignId('kot_printer_id'));
        Schema::table('menu_categories', fn (Blueprint $t) => $t->dropConstrainedForeignId('kot_printer_id'));
        Schema::dropIfExists('kot_print_jobs');
        Schema::dropIfExists('kot_printers');
    }
};

