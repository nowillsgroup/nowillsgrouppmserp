<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2: seasonal rate management, QR self-ordering, loyalty ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Seasonal / date-range rate overrides with weekend pricing, minimum stay and stop-sell
        Schema::create('rate_overrides', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('room_type_id')->constrained()->cascadeOnDelete();
            $t->string('name', 80);
            $t->date('date_from');
            $t->date('date_to');
            $t->decimal('rate', 12, 2)->nullable();          // null = keep base rate (e.g. stop-sell only)
            $t->decimal('weekend_rate', 12, 2)->nullable();  // Fri & Sat nights
            $t->unsignedTinyInteger('min_stay')->default(1);
            $t->boolean('stop_sell')->default(false);        // closes online booking engine for these dates
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['hotel_id', 'room_type_id', 'date_from', 'date_to']);
        });

        // QR self-ordering
        Schema::table('pos_tables', function (Blueprint $t) {
            $t->string('qr_token', 40)->nullable()->unique()->after('section');
        });
        Schema::table('pos_orders', function (Blueprint $t) {
            $t->string('source', 10)->default('staff')->after('order_type'); // staff, qr
        });
        Schema::table('pos_order_items', function (Blueprint $t) {
            $t->boolean('is_pending')->default(false)->after('is_cancelled'); // guest QR item awaiting staff acceptance
        });

        // Loyalty points ledger (earn on check-out, redeem against folio)
        Schema::create('loyalty_ledger', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('guest_id')->constrained()->cascadeOnDelete();
            $t->foreignId('folio_id')->nullable()->constrained()->nullOnDelete();
            $t->string('type', 10);                 // earn, redeem, refund, adjust, opening
            $t->integer('points');                  // + credit, − debit
            $t->string('description');
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->index(['hotel_id', 'guest_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_ledger');
        Schema::table('pos_order_items', fn (Blueprint $t) => $t->dropColumn('is_pending'));
        Schema::table('pos_orders', fn (Blueprint $t) => $t->dropColumn('source'));
        Schema::table('pos_tables', function (Blueprint $t) {
            $t->dropUnique(['qr_token']);
            $t->dropColumn('qr_token');
        });
        Schema::dropIfExists('rate_overrides');
    }
};

