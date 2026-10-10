<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 (v1.4): kitchen display, POS split/merge/move, online pre-arrival check-in,
 * cancellation & no-show fees, travel-agent commissions, stock take.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---- Kitchen display
        Schema::table('menu_categories', fn (Blueprint $t) => $t->string('kitchen_station', 40)->nullable()->after('name'));
        Schema::table('pos_order_items', function (Blueprint $t) {
            $t->string('kitchen_status', 12)->default('new')->after('kot_no'); // new, preparing, ready, served
            $t->timestamp('ready_at')->nullable()->after('kitchen_status');
            $t->timestamp('served_at')->nullable()->after('ready_at');
        });
        DB::table('pos_order_items')->update(['kitchen_status' => 'served']);

        // ---- POS split / merge
        Schema::table('pos_orders', function (Blueprint $t) {
            $t->foreignId('split_from_id')->nullable()->after('folio_id')->constrained('pos_orders')->nullOnDelete();
            $t->foreignId('merged_into_id')->nullable()->after('split_from_id')->constrained('pos_orders')->nullOnDelete();
        });

        // ---- Online pre-arrival check-in + cancellation fees
        Schema::table('reservations', function (Blueprint $t) {
            $t->string('checkin_token', 48)->nullable()->unique()->after('ota_reference');
            $t->timestamp('prechecked_in_at')->nullable()->after('checkin_token');
            $t->decimal('cancellation_fee', 12, 2)->default(0)->after('cancelled_at');
        });

        // ---- Travel-agent commissions
        Schema::table('companies', fn (Blueprint $t) => $t->decimal('commission_pct', 5, 2)->default(0)->after('discount_pct'));
        Schema::create('agent_commissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('reservation_room_id')->constrained()->cascadeOnDelete();
            $t->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $t->date('business_date');
            $t->unsignedSmallInteger('nights')->default(0);
            $t->decimal('room_revenue', 14, 2);
            $t->decimal('pct', 5, 2);
            $t->decimal('amount', 12, 2);
            $t->string('status', 10)->default('due'); // due, paid, void
            $t->foreignId('voucher_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('paid_at')->nullable();
            $t->timestamps();
            $t->unique('reservation_room_id');
            $t->index(['hotel_id', 'company_id', 'status']);
        });

        // ---- Stock take
        Schema::create('stock_takes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('take_no', 30);
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->date('count_date');
            $t->string('status', 10)->default('draft'); // draft, posted, cancelled
            $t->text('notes')->nullable();
            $t->decimal('variance_value', 14, 2)->default(0);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('posted_at')->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'take_no']);
        });
        Schema::create('stock_take_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('stock_take_id')->constrained()->cascadeOnDelete();
            $t->foreignId('inventory_item_id')->constrained()->restrictOnDelete();
            $t->decimal('system_qty', 12, 2);
            $t->decimal('counted_qty', 12, 2)->nullable();
            $t->decimal('unit_cost', 12, 2)->default(0);
            $t->string('remarks', 120)->nullable();
            $t->unique(['stock_take_id', 'inventory_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_take_lines');
        Schema::dropIfExists('stock_takes');
        Schema::dropIfExists('agent_commissions');
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn('commission_pct'));
        Schema::table('reservations', function (Blueprint $t) {
            $t->dropUnique(['checkin_token']);
            $t->dropColumn(['checkin_token', 'prechecked_in_at', 'cancellation_fee']);
        });
        Schema::table('pos_orders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('merged_into_id');
            $t->dropConstrainedForeignId('split_from_id');
        });
        Schema::table('pos_order_items', fn (Blueprint $t) => $t->dropColumn(['kitchen_status', 'ready_at', 'served_at']));
        Schema::table('menu_categories', fn (Blueprint $t) => $t->dropColumn('kitchen_station'));
    }
};

