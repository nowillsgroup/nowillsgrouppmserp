<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3: cashier shifts, POS recipes & stock deduction, guest e-mail log, iCal channel manager.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashier_shifts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->date('business_date');
            $t->dateTime('opened_at');
            $t->timestamp('closed_at')->nullable();
            $t->decimal('opening_float', 12, 2)->default(0);
            $t->decimal('expected_cash', 12, 2)->nullable();   // float + cash received − cash refunded (snapshot at close)
            $t->decimal('counted_cash', 12, 2)->nullable();
            $t->decimal('variance', 12, 2)->nullable();
            $t->json('summary')->nullable();                   // totals by payment mode at close
            $t->string('notes', 500)->nullable();
            $t->string('status', 10)->default('open');        // open, closed
            $t->timestamps();
            $t->index(['hotel_id', 'user_id', 'status']);
        });

        Schema::table('pos_orders', function (Blueprint $t) {
            $t->foreignId('settled_by')->nullable()->after('settled_at')->constrained('users')->nullOnDelete();
        });

        Schema::create('menu_item_recipes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('menu_item_id')->constrained()->cascadeOnDelete();
            $t->foreignId('inventory_item_id')->constrained()->cascadeOnDelete();
            $t->decimal('qty', 12, 3);                          // stock units consumed per 1 portion
            $t->timestamps();
            $t->unique(['menu_item_id', 'inventory_item_id']);
        });
        Schema::table('outlets', function (Blueprint $t) {
            $t->foreignId('store_id')->nullable()->after('service_charge_pct')->constrained()->nullOnDelete(); // store consumed by recipes
        });
        Schema::table('stock_movements', function (Blueprint $t) {
            $t->decimal('qty', 12, 3)->change();
        });

        Schema::create('email_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('to_email', 190);
            $t->string('subject');
            $t->string('template', 40);                         // reservation_confirmation, invoice, custom
            $t->string('related_type', 60)->nullable();
            $t->unsignedBigInteger('related_id')->nullable();
            $t->string('status', 10);                           // sent, failed
            $t->string('error', 500)->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->index(['hotel_id', 'created_at']);
        });

        Schema::create('ota_channels', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('name', 80);                             // e.g. "Booking.com – Deluxe"
            $t->foreignId('room_type_id')->constrained()->cascadeOnDelete();
            $t->foreignId('business_source_id')->nullable()->constrained()->nullOnDelete();
            $t->string('export_token', 40)->unique();          // public iCal feed of our availability for this type
            $t->string('import_url', 500)->nullable();          // OTA iCal feed of bookings made there
            $t->timestamp('last_sync_at')->nullable();
            $t->string('last_sync_status', 255)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::table('reservations', function (Blueprint $t) {
            $t->foreignId('ota_channel_id')->nullable()->after('business_source_id')->constrained()->nullOnDelete();
            $t->string('ota_uid', 190)->nullable()->after('ota_channel_id');   // iCal UID for idempotent sync
            $t->index(['ota_channel_id', 'ota_uid']);
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $t) {
            $t->dropIndex(['ota_channel_id', 'ota_uid']);
            $t->dropConstrainedForeignId('ota_channel_id');
            $t->dropColumn('ota_uid');
        });
        Schema::dropIfExists('ota_channels');
        Schema::dropIfExists('email_logs');
        Schema::table('outlets', fn (Blueprint $t) => $t->dropConstrainedForeignId('store_id'));
        Schema::dropIfExists('menu_item_recipes');
        Schema::table('pos_orders', fn (Blueprint $t) => $t->dropConstrainedForeignId('settled_by'));
        Schema::dropIfExists('cashier_shifts');
    }
};

