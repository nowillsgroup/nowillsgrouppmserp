<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v3.4 – foreign guests: the Form C register (FRRO, Registration of Foreigners Rules 1992 r.14 – every foreign national
 * staying for consideration is reported within 24 h of arrival, departure reported after check-out) and the forex desk
 * (purchase of foreign currency from in-house foreign guests under the RBI money-changing directions: daily buy rates,
 * encashment in rupees or credit to the bill, encashment certificates, currency on hand at average cost and surrender
 * to the bank / franchiser).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_c_records', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('form_no', 20);
            $t->foreignId('reservation_room_id')->constrained()->cascadeOnDelete();
            $t->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $t->foreignId('guest_id')->nullable()->constrained()->nullOnDelete(); // null = companion without a profile
            $t->boolean('is_companion')->default(false);
            $t->string('status', 12)->default('pending');        // pending | submitted | exempt
            // particulars (snapshot – what was reported)
            $t->string('surname', 80)->nullable();
            $t->string('given_name', 80)->nullable();
            $t->string('gender', 1)->nullable();                 // M | F | X
            $t->date('dob')->nullable();
            $t->string('nationality', 60)->nullable();
            $t->string('passport_no', 30)->nullable();
            $t->string('passport_place', 80)->nullable();
            $t->date('passport_issued_on')->nullable();
            $t->date('passport_expires_on')->nullable();
            $t->string('visa_no', 30)->nullable();
            $t->string('visa_type', 20)->nullable();
            $t->string('visa_place', 80)->nullable();
            $t->date('visa_issued_on')->nullable();
            $t->date('visa_expires_on')->nullable();
            $t->date('arrived_india_on')->nullable();
            $t->string('arrived_from', 120)->nullable();         // city, country
            $t->string('port_of_arrival', 80)->nullable();
            $t->string('next_destination', 120)->nullable();
            $t->string('purpose', 20)->nullable();
            $t->string('address_abroad', 255)->nullable();
            $t->string('phone', 30)->nullable();
            // stay & reporting
            $t->timestamp('checked_in_at')->nullable();
            $t->date('expected_checkout')->nullable();
            $t->timestamp('deadline_at')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('frro_ref', 40)->nullable();
            $t->string('late_reason', 255)->nullable();
            $t->timestamp('departed_at')->nullable();
            $t->timestamp('departure_submitted_at')->nullable();
            $t->foreignId('departure_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('departure_ref', 40)->nullable();
            $t->string('exempt_reason', 255)->nullable();
            $t->timestamp('alert_due_at')->nullable();
            $t->timestamp('alert_overdue_at')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'form_no']);
            $t->index(['hotel_id', 'status', 'deadline_at']);
        });

        Schema::table('reservations', function (Blueprint $t) {
            $t->json('formc_prefill')->nullable(); // Form C details the guest gave during online check-in
        });

        Schema::create('currencies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('code', 3);
            $t->string('name', 60);
            $t->string('symbol', 8)->nullable();
            $t->unsignedSmallInteger('unit')->default(1);   // rate is quoted per N units (JPY per 100)
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['hotel_id', 'code']);
        });

        Schema::create('forex_rates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('currency_id')->constrained()->cascadeOnDelete();
            $t->date('date');                               // business date the rate applies to
            $t->decimal('reference_rate', 12, 4);           // bank / RBI reference (INR per unit)
            $t->decimal('buy_rate', 12, 4);                 // what the hotel pays the guest
            $t->boolean('carried')->default(false);         // copied forward by the night audit, not reviewed
            $t->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'currency_id', 'date']);
        });

        Schema::create('forex_exchanges', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('exchange_no', 20);
            $t->date('business_date');
            $t->foreignId('reservation_room_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $t->string('guest_name', 160);
            $t->string('passport_no', 30)->nullable();
            $t->string('nationality', 60)->nullable();
            $t->foreignId('currency_id')->constrained();
            $t->string('instrument', 8)->default('notes'); // notes | tc
            $t->decimal('fc_amount', 14, 2);
            $t->decimal('rate', 12, 4);
            $t->unsignedSmallInteger('unit')->default(1);
            $t->decimal('inr_amount', 14, 2);               // gross rupee value
            $t->decimal('fee', 12, 2)->default(0);
            $t->decimal('gst_value', 12, 2)->default(0);    // value of supply – CGST Rules r.32(2)(b)
            $t->decimal('gst', 12, 2)->default(0);
            $t->decimal('net_amount', 14, 2);               // paid out in cash / credited to the bill
            $t->decimal('usd_equiv', 14, 2)->default(0);
            $t->string('payout', 8);                        // cash | folio
            $t->foreignId('folio_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('folio_transaction_id')->nullable()->constrained()->nullOnDelete();
            $t->boolean('certificate')->default(true);
            $t->unsignedSmallInteger('certificate_prints')->default(0);
            $t->string('notes', 255)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('voided_at')->nullable();
            $t->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('void_reason', 255)->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'exchange_no']);
            $t->index(['hotel_id', 'business_date']);
            $t->index(['hotel_id', 'passport_no']);
        });

        Schema::create('forex_surrenders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('surrender_no', 20);
            $t->date('date');
            $t->string('counterparty', 120);                // bank / authorised dealer / franchiser
            $t->string('reference', 60)->nullable();
            $t->foreignId('payment_mode_id')->nullable()->constrained()->nullOnDelete(); // where the rupees land
            $t->decimal('total_cost', 14, 2)->default(0);
            $t->decimal('total_realised', 14, 2)->default(0);
            $t->decimal('gain', 14, 2)->default(0);
            $t->string('notes', 255)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'surrender_no']);
        });

        Schema::create('forex_surrender_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('forex_surrender_id')->constrained()->cascadeOnDelete();
            $t->foreignId('currency_id')->constrained();
            $t->decimal('fc_amount', 14, 2);
            $t->decimal('cost_rate', 12, 4);                // average cost per unit at surrender
            $t->decimal('cost', 14, 2);
            $t->decimal('rate', 12, 4);                     // realised per unit
            $t->decimal('realised', 14, 2);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forex_surrender_lines');
        Schema::dropIfExists('forex_surrenders');
        Schema::dropIfExists('forex_exchanges');
        Schema::dropIfExists('forex_rates');
        Schema::dropIfExists('currencies');
        Schema::table('reservations', fn (Blueprint $t) => $t->dropColumn('formc_prefill'));
        Schema::dropIfExists('form_c_records');
    }
};

