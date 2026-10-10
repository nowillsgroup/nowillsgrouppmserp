<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Back office: employees, POS (F&B), banquet, inventory & purchasing, accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------- HR ----------
        Schema::create('departments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('name', 80);
            $t->timestamps();
        });

        Schema::create('employees', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $t->string('emp_code', 30);
            $t->string('name');
            $t->string('designation', 80)->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('email')->nullable();
            $t->date('joining_date')->nullable();
            $t->decimal('salary', 12, 2)->default(0);
            $t->string('id_proof', 60)->nullable();
            $t->string('address')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('attendances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->date('date');
            $t->string('status', 10)->default('P'); // P, A, L (leave), H (half day), WO (week off)
            $t->time('in_time')->nullable();
            $t->time('out_time')->nullable();
            $t->string('remarks')->nullable();
            $t->timestamps();
            $t->unique(['employee_id', 'date']);
        });

        // ---------- POS ----------
        Schema::create('outlets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('name', 80);
            $t->string('type', 20)->default('restaurant'); // restaurant, bar, room_service, cafe, spa, laundry
            $t->string('kot_prefix', 10)->default('KOT');
            $t->decimal('service_charge_pct', 5, 2)->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('pos_tables', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $t->string('table_no', 20);
            $t->unsignedTinyInteger('capacity')->default(4);
            $t->string('section', 40)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('menu_categories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('outlet_id')->nullable()->constrained()->nullOnDelete();
            $t->string('name', 80);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
        });

        Schema::create('menu_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('menu_category_id')->constrained()->cascadeOnDelete();
            $t->foreignId('outlet_id')->nullable()->constrained()->nullOnDelete();
            $t->string('code', 20)->nullable();
            $t->string('name', 120);
            $t->decimal('price', 12, 2);
            $t->decimal('tax_rate', 5, 2)->default(5);
            $t->string('food_type', 10)->default('veg'); // veg, non_veg, egg, beverage
            $t->string('description')->nullable();
            $t->boolean('is_available')->default(true);
            $t->timestamps();
        });

        Schema::create('pos_orders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('order_no', 30);
            $t->string('bill_no', 30)->nullable();
            $t->foreignId('outlet_id')->constrained()->restrictOnDelete();
            $t->foreignId('pos_table_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('reservation_room_id')->nullable()->constrained()->nullOnDelete();
            $t->string('order_type', 20)->default('dine_in'); // dine_in, takeaway, room_service, delivery
            $t->string('customer_name')->nullable();
            $t->string('customer_phone', 30)->nullable();
            $t->unsignedTinyInteger('covers')->default(1);
            $t->string('steward')->nullable();
            $t->date('business_date');
            $t->string('status', 20)->default('open'); // open, billed, settled, void
            $t->decimal('subtotal', 14, 2)->default(0);
            $t->decimal('discount', 14, 2)->default(0);
            $t->decimal('service_charge', 14, 2)->default(0);
            $t->decimal('tax', 14, 2)->default(0);
            $t->decimal('round_off', 8, 2)->default(0);
            $t->decimal('total', 14, 2)->default(0);
            $t->string('settlement', 20)->nullable(); // payment, room_post, complimentary
            $t->foreignId('payment_mode_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('folio_id')->nullable()->constrained()->nullOnDelete();
            $t->string('void_reason')->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('settled_at')->nullable();
            $t->timestamps();
            $t->index(['hotel_id', 'business_date']);
        });

        Schema::create('pos_order_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('pos_order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('menu_item_id')->nullable()->constrained()->nullOnDelete();
            $t->string('item_name', 120);
            $t->decimal('qty', 8, 2);
            $t->decimal('rate', 12, 2);
            $t->decimal('tax_rate', 5, 2)->default(0);
            $t->decimal('amount', 14, 2);
            $t->unsignedInteger('kot_no')->nullable();
            $t->string('notes')->nullable();
            $t->boolean('is_cancelled')->default(false);
            $t->timestamps();
        });

        // ---------- Banquet ----------
        Schema::create('banquet_halls', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('name', 80);
            $t->unsignedInteger('capacity')->default(100);
            $t->decimal('rate_per_session', 12, 2)->default(0);
            $t->string('facilities', 500)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('banquet_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('event_no', 30);
            $t->foreignId('banquet_hall_id')->constrained()->restrictOnDelete();
            $t->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $t->string('contact_name');
            $t->string('contact_phone', 30)->nullable();
            $t->string('event_type', 40)->default('meeting'); // wedding, conference, birthday, meeting, party
            $t->string('title')->nullable();
            $t->date('event_date');
            $t->time('start_time');
            $t->time('end_time');
            $t->unsignedInteger('expected_pax')->default(0);
            $t->unsignedInteger('guaranteed_pax')->default(0);
            $t->string('menu_package')->nullable();
            $t->decimal('rate_per_pax', 12, 2)->default(0);
            $t->decimal('hall_charge', 12, 2)->default(0);
            $t->decimal('other_charges', 12, 2)->default(0);
            $t->decimal('tax_rate', 5, 2)->default(18);
            $t->decimal('advance', 12, 2)->default(0);
            $t->string('status', 20)->default('enquiry'); // enquiry, tentative, confirmed, completed, cancelled
            $t->text('requirements')->nullable();
            $t->foreignId('folio_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
        });

        // ---------- Inventory ----------
        Schema::create('stores', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('name', 80);
            $t->string('type', 20)->default('main'); // main, kitchen, bar, housekeeping
            $t->timestamps();
        });

        Schema::create('item_categories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('name', 80);
            $t->timestamps();
        });

        Schema::create('inventory_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('item_category_id')->nullable()->constrained()->nullOnDelete();
            $t->string('code', 30)->nullable();
            $t->string('name', 120);
            $t->string('unit', 20)->default('pcs');
            $t->decimal('reorder_level', 12, 2)->default(0);
            $t->decimal('last_cost', 12, 2)->default(0);
            $t->decimal('tax_rate', 5, 2)->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('suppliers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('contact_person')->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('email')->nullable();
            $t->string('address')->nullable();
            $t->string('tax_number', 40)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('purchase_orders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('po_no', 30);
            $t->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->date('po_date');
            $t->date('expected_date')->nullable();
            $t->string('status', 20)->default('draft'); // draft, approved, received, cancelled
            $t->string('invoice_no', 60)->nullable();
            $t->decimal('subtotal', 14, 2)->default(0);
            $t->decimal('tax', 14, 2)->default(0);
            $t->decimal('total', 14, 2)->default(0);
            $t->decimal('paid', 14, 2)->default(0);
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('received_at')->nullable();
            $t->timestamps();
        });

        Schema::create('purchase_order_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('inventory_item_id')->constrained()->restrictOnDelete();
            $t->decimal('qty', 12, 2);
            $t->decimal('rate', 12, 2);
            $t->decimal('tax_rate', 5, 2)->default(0);
            $t->decimal('amount', 14, 2);
            $t->timestamps();
        });

        Schema::create('stock_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('inventory_item_id')->constrained()->cascadeOnDelete();
            $t->foreignId('store_id')->constrained()->cascadeOnDelete();
            $t->date('date');
            $t->string('type', 20); // purchase, issue, transfer_in, transfer_out, adjustment, wastage, opening
            $t->decimal('qty', 12, 2); // positive in, negative out
            $t->decimal('rate', 12, 2)->default(0);
            $t->string('department')->nullable();
            $t->string('reference', 60)->nullable();
            $t->string('remarks')->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->index(['hotel_id', 'inventory_item_id', 'store_id']);
        });

        // ---------- Accounts ----------
        Schema::create('account_heads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('name', 100);
            $t->string('type', 20); // income, expense, asset, liability
            $t->timestamps();
        });

        Schema::create('vouchers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('voucher_no', 30);
            $t->string('type', 20); // payment (expense), receipt (income), journal
            $t->date('date');
            $t->foreignId('account_head_id')->constrained()->restrictOnDelete();
            $t->foreignId('payment_mode_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $t->string('party_name')->nullable();
            $t->decimal('amount', 14, 2);
            $t->string('reference', 60)->nullable();
            $t->string('narration', 500)->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['vouchers', 'account_heads', 'stock_movements', 'purchase_order_items', 'purchase_orders', 'suppliers', 'inventory_items', 'item_categories', 'stores', 'banquet_events', 'banquet_halls', 'pos_order_items', 'pos_orders', 'menu_items', 'menu_categories', 'pos_tables', 'outlets', 'attendances', 'employees', 'departments'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};

