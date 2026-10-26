<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 30 (v3.9): Engineering – utilities & energy management and the licences & statutory compliance register.
 *  • Utility meters (grid electricity, DG, solar, water, LPG, diesel, PNG) with sub-meters, CT multipliers, roll-over,
 *    tariff and an anomaly threshold; daily readings compute consumption, cost and flag abnormal usage.
 *  • Utility bills are reconciled against metered units and can be booked as an AP expense bill (GL + budget actuals).
 *  • Compliance items: trade licences, permits, certificates, insurance policies, statutory inspections and AMCs –
 *    expiry / next-due tracking with a renewal lead time, renewal & inspection history and documents.
 */
return new class extends Migration
{
    public function up(): void
    {
                if (Schema::hasTable('utility_meters')) {
            return;
        }

        Schema::create('utility_meters', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('code', 20);
            $t->string('name', 80);
            $t->string('utility', 12);                          // electricity | dg | solar | water | lpg | diesel | png
            $t->string('unit', 8);                              // kWh | m3 | kg | L | SCM
            $t->string('meter_no', 40)->nullable();
            $t->string('area', 30)->nullable();                 // cost centre / area served
            $t->foreignId('parent_id')->nullable()->constrained('utility_meters')->nullOnDelete();   // sub-meter of
            $t->decimal('multiplier', 10, 3)->default(1);       // CT / PT factor
            $t->unsignedTinyInteger('digits')->default(7);      // register rolls over at 10^digits
            $t->decimal('rate', 10, 4)->default(0);             // cost per unit
            $t->boolean('is_main')->default(true);              // counts toward the hotel's total (false for sub-meters)
            $t->unsignedSmallInteger('alert_pct')->default(30); // flag a day that deviates this much from the recent average
            $t->boolean('is_active')->default(true);
            $t->string('notes', 500)->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'code']);
        });

                if (Schema::hasTable('meter_readings')) {
            return;
        }

        Schema::create('meter_readings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('utility_meter_id')->constrained()->cascadeOnDelete();
            $t->date('reading_date');
            $t->decimal('reading', 14, 3);
            $t->decimal('consumption', 14, 3)->default(0);
            $t->unsignedSmallInteger('days')->default(1);       // days since the previous reading
            $t->decimal('cost', 12, 2)->default(0);
            $t->boolean('is_estimated')->default(false);
            $t->boolean('meter_reset')->default(false);         // meter replaced / reset – counts from zero
            $t->boolean('rolled_over')->default(false);
            $t->string('anomaly', 8)->nullable();               // high | low | zero
            $t->string('anomaly_note', 190)->nullable();
            $t->timestamp('anomaly_ack_at')->nullable();
            $t->string('note', 190)->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->unique(['utility_meter_id', 'reading_date']);
            $t->index(['hotel_id', 'reading_date']);
        });

                if (Schema::hasTable('utility_bills')) {
            return;
        }

        Schema::create('utility_bills', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('utility_meter_id')->constrained()->restrictOnDelete();
            $t->string('bill_no', 40);
            $t->date('bill_date');
            $t->date('period_from');
            $t->date('period_to');
            $t->decimal('units_billed', 14, 3)->default(0);
            $t->decimal('energy_charges', 12, 2)->default(0);
            $t->decimal('fixed_charges', 12, 2)->default(0);    // demand / fixed / meter rent
            $t->decimal('taxes', 12, 2)->default(0);            // electricity duty, cess
            $t->decimal('total', 12, 2)->default(0);
            $t->decimal('metered_units', 14, 3)->default(0);    // snapshot of metered consumption for the period
            $t->date('due_date')->nullable();
            $t->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('supplier_invoice_id')->nullable()->constrained()->nullOnDelete();
            $t->string('notes', 500)->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->unique(['utility_meter_id', 'bill_no']);
        });

                if (Schema::hasTable('compliance_items')) {
            return;
        }

        Schema::create('compliance_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('name', 120);
            $t->string('category', 12);                         // licence | certificate | insurance | inspection | contract
            $t->string('authority', 120)->nullable();
            $t->string('reference_no', 80)->nullable();
            $t->string('department', 30)->nullable();
            $t->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $t->date('issued_on')->nullable();
            $t->date('expires_on')->nullable();                 // valid until / next due; null = no expiry
            $t->unsignedSmallInteger('frequency_months')->nullable();   // recurring inspections
            $t->unsignedSmallInteger('lead_days')->default(60);
            $t->decimal('fee', 12, 2)->default(0);              // typical renewal fee / premium
            $t->boolean('is_critical')->default(false);         // operating without it risks closure / penalty
            $t->string('status', 12)->default('active');        // active | renewing | archived
            $t->date('applied_on')->nullable();                 // renewal application date
            $t->string('application_ref', 80)->nullable();
            $t->string('document_path')->nullable();
            $t->string('document_name', 190)->nullable();
            $t->date('alerted_for')->nullable();                // expiry date for which the due-soon alert was sent
            $t->date('lapsed_alerted_for')->nullable();
            $t->string('notes', 1000)->nullable();
            $t->timestamps();
            $t->index(['hotel_id', 'expires_on']);
        });

                if (Schema::hasTable('compliance_events')) {
            return;
        }

        Schema::create('compliance_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('compliance_item_id')->constrained()->cascadeOnDelete();
            $t->string('type', 12);                             // created | applied | renewed | inspected | document | archived | restored | note
            $t->date('event_date');
            $t->date('new_expiry')->nullable();
            $t->string('result', 8)->nullable();                // inspections: pass | fail
            $t->decimal('fee', 12, 2)->default(0);
            $t->string('reference', 80)->nullable();
            $t->string('note', 500)->nullable();
            $t->string('document_path')->nullable();
            $t->string('document_name', 190)->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_events');
        Schema::dropIfExists('compliance_items');
        Schema::dropIfExists('utility_bills');
        Schema::dropIfExists('meter_readings');
        Schema::dropIfExists('utility_meters');
    }
};

