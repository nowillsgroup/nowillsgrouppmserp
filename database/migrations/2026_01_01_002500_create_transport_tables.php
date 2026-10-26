<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v3.3 – guest transport & fleet desk: the hotel's vehicles (with compliance papers), drivers (licence, optional
 * login for the phone screen), a tariff card, trips (airport / station pick-ups, drops and local transfers:
 * requested → confirmed → assigned → en route → on board → completed) with busy windows for clash checks,
 * billing to the room folio / direct payment / complimentary, and vehicle running expenses (fuel, service, tolls).
 */
return new class extends Migration
{
    public function up(): void
    {
                if (Schema::hasTable('vehicles')) {
            return;
        }

        Schema::create('vehicles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('code', 20);                       // short call-sign on the dispatch board, e.g. INNOVA-1
            $t->string('name', 80);                       // make & model
            $t->string('reg_no', 20);
            $t->string('type', 20)->default('sedan');     // sedan | suv | van | minibus | coach | luxury | buggy
            $t->unsignedTinyInteger('seats')->default(4); // passengers (driver excluded)
            $t->unsignedTinyInteger('luggage')->default(2);
            $t->string('fuel', 12)->default('diesel');
            $t->string('color', 30)->nullable();
            $t->unsignedInteger('odometer')->default(0);  // last known reading (km)
            $t->string('status', 12)->default('available'); // available | maintenance
            $t->date('insurance_until')->nullable();
            $t->date('permit_until')->nullable();         // tourist / contract-carriage permit
            $t->date('fitness_until')->nullable();
            $t->date('puc_until')->nullable();            // pollution-under-control certificate
            $t->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $t->string('notes', 500)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['hotel_id', 'code']);
            $t->unique(['hotel_id', 'reg_no']);
        });

                if (Schema::hasTable('drivers')) {
            return;
        }

        Schema::create('drivers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('name', 100);
            $t->string('phone', 30);
            $t->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // login → "My trips" phone screen
            $t->string('licence_no', 40)->nullable();
            $t->date('licence_until')->nullable();
            $t->string('languages', 100)->nullable();
            $t->string('notes', 500)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['hotel_id', 'phone']);
        });

                if (Schema::hasTable('transport_tariffs')) {
            return;
        }

        Schema::create('transport_tariffs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('name', 80);
            $t->string('kind', 12)->default('airport');   // airport | railway | local | outstation | tour
            $t->string('vehicle_type', 20)->nullable();   // null = any vehicle
            $t->decimal('flat_rate', 12, 2)->default(0);  // one way, before tax
            $t->unsignedSmallInteger('included_km')->default(0);
            $t->decimal('per_km', 10, 2)->default(0);     // beyond the included km
            $t->decimal('wait_per_hour', 10, 2)->default(0);
            $t->unsignedSmallInteger('duration_min')->default(120); // total vehicle time (there & back) for clash checks
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['hotel_id', 'name']);
        });

                if (Schema::hasTable('transport_trips')) {
            return;
        }

        Schema::create('transport_trips', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('trip_no', 20);
            $t->string('type', 10)->default('pickup');    // pickup | drop | transfer
            $t->string('status', 12)->default('confirmed'); // requested | confirmed | assigned | en_route | onboard | completed | cancelled | no_show
            $t->string('source', 12)->default('staff');   // staff | reservation | portal | request
            $t->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('reservation_room_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('guest_request_id')->nullable()->constrained()->nullOnDelete();
            $t->string('passenger_name', 150);
            $t->string('phone', 30)->nullable();
            $t->unsignedTinyInteger('pax')->default(1);
            $t->unsignedTinyInteger('bags')->default(0);
            $t->string('flight_no', 20)->nullable();      // flight / train number
            $t->string('pickup_point', 190);
            $t->string('dropoff_point', 190);
            $t->dateTime('scheduled_at');                 // pickup: flight arrival / meet time · drop & transfer: leaves the hotel
            $t->unsignedSmallInteger('duration_min')->default(120);
            $t->dateTime('busy_from');                    // vehicle & driver blocked from … (pickups leave early)
            $t->dateTime('busy_to');                      // … until back at the hotel
            $t->foreignId('transport_tariff_id')->nullable()->constrained()->nullOnDelete();
            $t->string('vehicle_type', 20)->nullable();   // requested type
            $t->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('driver_id')->nullable()->constrained()->nullOnDelete();
            $t->string('billing', 14)->default('room');   // room | direct | complimentary
            $t->decimal('amount', 12, 2)->default(0);     // quoted fare, before tax
            $t->decimal('extra_amount', 12, 2)->default(0); // extra km / waiting, set at completion
            $t->string('extra_note', 150)->nullable();
            $t->decimal('total', 12, 2)->default(0);
            $t->unsignedInteger('odo_start')->nullable();
            $t->unsignedInteger('odo_end')->nullable();
            $t->timestamp('assigned_at')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('onboard_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->string('cancel_reason', 190)->nullable();
            $t->foreignId('folio_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('folio_transaction_id')->nullable()->constrained('folio_transactions')->nullOnDelete();
            $t->foreignId('payment_mode_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('guest_notified_at')->nullable();
            $t->timestamp('driver_notified_at')->nullable();
            $t->timestamp('reminder_sent_at')->nullable();
            $t->string('notes', 500)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'trip_no']);
            $t->index(['hotel_id', 'scheduled_at']);
            $t->index(['hotel_id', 'status']);
            $t->index(['vehicle_id', 'busy_from']);
            $t->index(['driver_id', 'busy_from']);
        });

                if (Schema::hasTable('vehicle_expenses')) {
            return;
        }

        Schema::create('vehicle_expenses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $t->foreignId('driver_id')->nullable()->constrained()->nullOnDelete();
            $t->date('date');
            $t->string('kind', 10)->default('fuel');      // fuel | service | repair | toll | parking | other
            $t->decimal('litres', 8, 2)->nullable();
            $t->decimal('amount', 12, 2);
            $t->unsignedInteger('odometer')->nullable();
            $t->string('vendor', 80)->nullable();
            $t->string('notes', 255)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['hotel_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_expenses');
        Schema::dropIfExists('transport_trips');
        Schema::dropIfExists('transport_tariffs');
        Schema::dropIfExists('drivers');
        Schema::dropIfExists('vehicles');
    }
};

