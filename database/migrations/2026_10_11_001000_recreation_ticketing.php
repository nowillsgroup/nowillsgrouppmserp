<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v7.38 Recreation ticketing (POS-style outlet for Wellness & Recreation).
 *
 * A full ticketing outlet for the recreation department: ticket types (catalog),
 * POS sales with split-tender payments, and issued tickets that ride on the
 * existing club_day_passes table so membership, check-in and day-pass flows are
 * untouched — members keep their memberships, walk-ins buy tickets at the outlet.
 */
return new class extends Migration
{
    public function up(): void
    {
                if (Schema::hasTable('club_ticket_types')) {
            return;
        }

        Schema::create('club_ticket_types', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('code', 16);
            $t->string('name', 80);
            $t->string('category', 12)->default('access');            // access | rental | service
            $t->string('pax_type', 8)->default('adult');              // adult | child (ticket pax bookkeeping)
            $t->decimal('price', 10, 2);
            $t->decimal('tax_rate', 7, 4)->nullable();                // null = standard club tax
            $t->boolean('issues_pass')->default(true);                // access tickets issue a day-pass credential
            $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
            $t->unique(['hotel_id', 'code']);
        });

                if (Schema::hasTable('club_ticket_sales')) {
            return;
        }

        Schema::create('club_ticket_sales', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('sale_no', 20);
            $t->dateTime('sold_at');
            $t->string('guest_name', 120)->nullable();
            $t->string('phone', 30)->nullable();
            $t->foreignId('host_member_id')->nullable()->constrained('club_members')->nullOnDelete();
            $t->decimal('subtotal', 10, 2)->default(0);
            $t->decimal('tax', 10, 2)->default(0);
            $t->decimal('total', 10, 2)->default(0);
            $t->decimal('paid', 10, 2)->default(0);
            $t->decimal('change_due', 10, 2)->default(0);
            $t->string('status', 10)->default('paid');                // paid | void
            $t->foreignId('reservation_room_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('folio_transaction_id')->nullable()->constrained()->nullOnDelete();
            $t->string('reference', 80)->nullable();
            $t->string('notes', 255)->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('cancelled_at')->nullable();
            $t->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('cancel_reason', 255)->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'sale_no']);
            $t->index(['hotel_id', 'sold_at']);
        });

                if (Schema::hasTable('club_ticket_sale_items')) {
            return;
        }

        Schema::create('club_ticket_sale_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sale_id')->constrained('club_ticket_sales')->cascadeOnDelete();
            $t->foreignId('ticket_type_id')->nullable()->constrained('club_ticket_types')->nullOnDelete();
            $t->string('description', 120);
            $t->unsignedSmallInteger('qty')->default(1);
            $t->decimal('unit_price', 10, 2)->default(0);
            $t->decimal('line_total', 10, 2)->default(0);             // net
            $t->decimal('tax_rate', 7, 4)->default(0);
            $t->decimal('tax', 10, 2)->default(0);
            $t->unsignedSmallInteger('passes')->default(0);           // day-pass credentials issued
            $t->timestamps();
        });

                if (Schema::hasTable('club_ticket_payments')) {
            return;
        }

        Schema::create('club_ticket_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sale_id')->constrained('club_ticket_sales')->cascadeOnDelete();
            $t->foreignId('payment_mode_id')->constrained()->cascadeOnDelete();
            $t->decimal('amount', 10, 2);
            $t->string('reference', 80)->nullable();
            $t->timestamps();
        });

        // Issued tickets ride on the existing day-pass table — membership,
        // check-in and passes pages keep working unchanged.
        Schema::table('club_day_passes', function (Blueprint $t) {
            $t->foreignId('sale_id')->nullable()->after('user_id')->constrained('club_ticket_sales')->nullOnDelete();
            $t->foreignId('ticket_type_id')->nullable()->after('sale_id')->constrained('club_ticket_types')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('club_day_passes', function (Blueprint $t) {
            $t->dropConstrainedForeignId('ticket_type_id');
            $t->dropConstrainedForeignId('sale_id');
        });
        Schema::dropIfExists('club_ticket_payments');
        Schema::dropIfExists('club_ticket_sale_items');
        Schema::dropIfExists('club_ticket_sales');
        Schema::dropIfExists('club_ticket_types');
    }
};
