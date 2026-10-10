<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v5.1→5.2 Serviced apartments & long-stay leases: lettable units (optionally a hotel room, which is then blocked from
 * nightly sale while leased), lease agreements with tax class (serviced / residential / commercial), billing cycle,
 * escalation, security deposit and utilities, a tenant account (rent, service charge, utility recharges, late fees,
 * damages, credit notes, receipts with withholding tax, deposits held / applied / refunded), meter readings and
 * move-in / move-out inspections.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lease_units', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('code', 20);
            $t->string('name', 100);
            $t->string('type', 12)->default('1bed');                       // studio | 1bed | 2bed | 3bed | penthouse | office | shop
            $t->foreignId('room_id')->nullable()->constrained()->nullOnDelete(); // a hotel room let long-term
            $t->string('block', 40)->nullable();
            $t->string('floor', 10)->nullable();
            $t->unsignedTinyInteger('bedrooms')->default(1);
            $t->decimal('size_sqm', 8, 2)->nullable();
            $t->boolean('furnished')->default(true);
            $t->string('tax_class', 12)->default('serviced');              // serviced | residential | commercial
            $t->decimal('list_rent', 12, 2)->default(0);                    // per month, tax-exclusive
            $t->decimal('service_charge', 10, 2)->default(0);               // per month
            $t->string('elec_meter', 40)->nullable();
            $t->string('water_meter', 40)->nullable();
            $t->string('status', 12)->default('vacant');                    // vacant | reserved | leased | maintenance
            $t->string('amenities', 255)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['hotel_id', 'code']);
        });

        Schema::create('leases', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('lease_no', 20);
            $t->foreignId('lease_unit_id')->constrained()->cascadeOnDelete();
            $t->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $t->string('tenant_name', 120);
            $t->string('phone', 30)->nullable();
            $t->string('email', 120)->nullable();
            $t->string('id_type', 20)->nullable();                           // Ghana Card | Passport | Company reg.
            $t->string('id_no', 40)->nullable();
            $t->string('occupants', 255)->nullable();
            $t->string('status', 12)->default('draft');                     // draft | active | notice | ended | terminated | cancelled
            $t->string('tax_class', 12)->default('serviced');
            $t->date('start_date');
            $t->date('end_date');
            $t->unsignedSmallInteger('term_months');
            $t->decimal('rent', 12, 2);                                       // current monthly rent, tax-exclusive
            $t->decimal('service_charge', 10, 2)->default(0);                 // per month
            $t->unsignedTinyInteger('cycle_months')->default(1);             // 1 | 3 | 6 | 12 – months billed per invoice
            $t->date('billed_to')->nullable();                                // last day invoiced
            $t->decimal('deposit_amount', 12, 2)->default(0);                 // security deposit required
            $t->decimal('escalation_pct', 5, 2)->default(0);
            $t->unsignedTinyInteger('escalation_months')->default(12);
            $t->date('next_review_on')->nullable();
            $t->unsignedSmallInteger('notice_days')->default(30);
            $t->string('elec_mode', 10)->default('included');                // included | metered | prepaid
            $t->string('water_mode', 10)->default('included');
            $t->decimal('elec_tariff', 8, 4)->nullable();                    // GH₵ per kWh (null = setting)
            $t->decimal('water_tariff', 8, 4)->nullable();                   // GH₵ per m³
            $t->decimal('wht_pct', 5, 2)->default(0);                         // tenant withholds (company tenants)
            $t->string('inclusions', 255)->nullable();                        // housekeeping, Wi-Fi, parking…
            $t->boolean('auto_renew')->default(false);
            $t->date('moved_in_on')->nullable();
            $t->date('notice_on')->nullable();
            $t->string('notice_by', 10)->nullable();                          // tenant | landlord
            $t->date('move_out_on')->nullable();
            $t->string('end_reason', 255)->nullable();
            $t->timestamp('settled_at')->nullable();                          // move-out statement closed
            $t->string('signed_by', 120)->nullable();
            $t->timestamp('signed_at')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'lease_no']);
            $t->index(['hotel_id', 'status']);
        });

        Schema::create('lease_charges', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('charge_no', 20);
            $t->foreignId('lease_id')->constrained()->cascadeOnDelete();
            $t->date('date');
            $t->string('type', 14);                                           // rent | service_charge | electricity | water | late_fee | damage | other | credit
            $t->string('description', 160);
            $t->date('period_start')->nullable();
            $t->date('period_end')->nullable();
            $t->decimal('amount', 12, 2);                                      // net (negative on credit notes)
            $t->decimal('tax_rate', 6, 3)->default(0);
            $t->decimal('tax', 12, 2)->default(0);
            $t->decimal('total', 12, 2);
            $t->date('due_date');
            $t->decimal('paid', 12, 2)->default(0);
            $t->foreignId('credit_of_id')->nullable()->constrained('lease_charges')->nullOnDelete();
            $t->foreignId('late_fee_of_id')->nullable()->constrained('lease_charges')->nullOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('cancelled_at')->nullable();
            $t->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('cancel_reason', 255)->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'charge_no']);
            $t->index(['lease_id', 'type']);
        });

        Schema::create('lease_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('receipt_no', 20);
            $t->foreignId('lease_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 16)->default('payment');                      // payment | refund | deposit | deposit_refund | deposit_applied
            $t->date('date');
            $t->foreignId('payment_mode_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('amount', 12, 2);                                      // money received / paid out (applied: deposit used)
            $t->decimal('wht_amount', 12, 2)->default(0);                      // tax withheld by the tenant (settles the account too)
            $t->string('wht_cert_no', 40)->nullable();
            $t->string('reference', 80)->nullable();
            $t->string('note', 255)->nullable();
            $t->json('allocations')->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('voided_at')->nullable();
            $t->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('void_reason', 255)->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'receipt_no']);
        });

        Schema::create('lease_meter_readings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('lease_unit_id')->constrained()->cascadeOnDelete();
            $t->foreignId('lease_id')->nullable()->constrained()->nullOnDelete();
            $t->string('meter', 12);                                          // electricity | water
            $t->date('date');
            $t->decimal('reading', 12, 2);
            $t->decimal('previous', 12, 2)->nullable();
            $t->decimal('consumption', 12, 2)->default(0);
            $t->decimal('rate', 8, 4)->default(0);
            $t->string('kind', 10)->default('regular');                       // opening | regular | closing
            $t->foreignId('lease_charge_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->index(['lease_unit_id', 'meter', 'date']);
        });

        Schema::create('lease_inspections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('lease_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 10);                                           // move_in | move_out | periodic
            $t->date('date');
            $t->json('items');                                                 // [{area, item, condition, note, charge}]
            $t->decimal('damages', 12, 2)->default(0);
            $t->foreignId('lease_charge_id')->nullable()->constrained()->nullOnDelete();
            $t->string('tenant_ack', 120)->nullable();
            $t->string('notes', 500)->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['lease_inspections', 'lease_meter_readings', 'lease_payments', 'lease_charges', 'leases', 'lease_units'] as $tb) {
            Schema::dropIfExists($tb);
        }
    }
};

