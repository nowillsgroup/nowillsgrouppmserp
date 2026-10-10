<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.2 – laundry & linen: the guest-laundry price list, laundry orders (collected → processing → ready → delivered,
 * posted to the folio or paid by walk-ins) and house-linen control: linen types with stock by location
 * (store / floor / soiled / at laundry), every movement, and laundry batches sent to an in-house or external laundry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laundry_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('name', 80);
            $t->string('category', 20)->default('gents'); // gents | ladies | kids | household
            $t->decimal('wash_rate', 10, 2)->nullable();    // wash & iron
            $t->decimal('dryclean_rate', 10, 2)->nullable();
            $t->decimal('press_rate', 10, 2)->nullable();   // press / iron only
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['hotel_id', 'name']);
        });

        Schema::create('laundry_orders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('order_no', 20);
            $t->string('type', 10)->default('guest'); // guest | walk_in
            $t->foreignId('reservation_room_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('guest_request_id')->nullable()->constrained()->nullOnDelete();
            $t->string('guest_name', 150);
            $t->string('phone', 30)->nullable();
            $t->string('speed', 10)->default('normal'); // normal | express
            $t->string('status', 12)->default('collected'); // collected | processing | ready | delivered | cancelled
            $t->string('bag_no', 30)->nullable();
            $t->unsignedSmallInteger('bag_pieces')->nullable(); // pieces counted at pick-up (before itemising)
            $t->unsignedSmallInteger('pieces')->default(0);
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->decimal('express_charge', 12, 2)->default(0);
            $t->decimal('total', 12, 2)->default(0); // before tax – tax is added when posted to the folio
            $t->timestamp('collected_at')->nullable();
            $t->timestamp('promised_at')->nullable();
            $t->timestamp('ready_at')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->foreignId('folio_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('folio_transaction_id')->nullable()->constrained('folio_transactions')->nullOnDelete();
            $t->foreignId('payment_mode_id')->nullable()->constrained()->nullOnDelete(); // walk-in paid at delivery
            $t->string('notes', 500)->nullable();
            $t->string('cancel_reason', 190)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('delivered_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'order_no']);
            $t->index(['hotel_id', 'status']);
        });

        Schema::create('laundry_order_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('laundry_order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('laundry_item_id')->nullable()->constrained()->nullOnDelete();
            $t->string('item_name', 80);
            $t->string('service', 10); // wash | dryclean | press
            $t->unsignedSmallInteger('qty');
            $t->decimal('rate', 10, 2);
            $t->decimal('amount', 12, 2);
            $t->string('remark', 150)->nullable(); // stain, missing button, damage noted at pick-up
        });

        Schema::create('linen_types', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('code', 12);
            $t->string('name', 80);
            $t->decimal('per_room', 5, 2)->default(0); // pieces in one room set (0 = not a room item, e.g. F&B linen)
            $t->unsignedInteger('par_override')->nullable(); // fixed par instead of rooms × per room × par levels
            $t->decimal('unit_cost', 10, 2)->default(0);
            $t->decimal('wash_rate', 10, 2)->default(0); // external laundry charge per piece
            $t->integer('qty_store')->default(0);   // clean, in linen room
            $t->integer('qty_floor')->default(0);   // issued to floors / rooms / outlets
            $t->integer('qty_soiled')->default(0);  // collected, waiting to go to the laundry
            $t->integer('qty_laundry')->default(0); // at the laundry
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['hotel_id', 'code']);
        });

        Schema::create('linen_batches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('batch_no', 20);
            $t->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete(); // null = in-house laundry
            $t->date('sent_date');
            $t->date('expected_date')->nullable();
            $t->date('closed_date')->nullable();
            $t->string('status', 10)->default('sent'); // sent | partial | closed
            $t->foreignId('supplier_invoice_id')->nullable()->constrained()->nullOnDelete();
            $t->string('notes', 255)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'batch_no']);
        });

        Schema::create('linen_batch_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('linen_batch_id')->constrained()->cascadeOnDelete();
            $t->foreignId('linen_type_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('qty_sent');
            $t->unsignedInteger('qty_returned')->default(0);
            $t->unsignedInteger('qty_damaged')->default(0); // came back unusable → discarded
            $t->unsignedInteger('qty_lost')->default(0);    // never came back (set when the batch is closed)
            $t->decimal('rate', 10, 2)->default(0);
            $t->unique(['linen_batch_id', 'linen_type_id']);
        });

        Schema::create('linen_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('linen_type_id')->constrained()->cascadeOnDelete();
            $t->date('date');
            $t->string('type', 12); // purchase | issue | collect | send | return | discard | lost | adjust
            $t->integer('qty');
            $t->string('from_loc', 8)->nullable(); // store | floor | soiled | laundry
            $t->string('to_loc', 8)->nullable();
            $t->foreignId('linen_batch_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('value', 12, 2)->default(0); // cost of pieces purchased / written off
            $t->string('reason', 190)->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('created_at')->nullable();
            $t->index(['hotel_id', 'date']);
        });
    }

    public function down(): void
    {
        foreach (['linen_movements', 'linen_batch_lines', 'linen_batches', 'linen_types', 'laundry_order_lines', 'laundry_orders', 'laundry_items'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};

