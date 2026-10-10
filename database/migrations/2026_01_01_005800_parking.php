<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v6.6 Parking & valet: car parks / zones with a capacity; a vehicle register (plate, make, colour, banned flag) linked
 * to guests; parking tickets for in-house guests (self-park or valet, charged per night to the room folio), visitors
 * (hourly tariff with free minutes, a daily cap, outlet validation and a lost-ticket fee, paid at the exit) and staff
 * (permit holders); valet key tags, intake condition check and a retrieval flow (requested → ready → handed over)
 * with a QR link on the ticket so the guest can call for the car; staff / tenant / contractor permits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parking_zones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('code', 10);
            $t->string('name', 80);
            $t->string('type', 12)->default('mixed');                       // guest | valet | visitor | staff | mixed
            $t->unsignedSmallInteger('capacity')->default(0);
            $t->boolean('is_covered')->default(false);
            $t->unsignedSmallInteger('ev_points')->default(0);
            $t->string('notes', 255)->nullable();
            $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
            $t->unique(['hotel_id', 'code']);
        });

        Schema::create('parking_vehicles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('plate', 16);                                         // normalised: upper case, letters & digits only
            $t->string('plate_display', 20);                                 // as written: GR 1234-21
            $t->string('make', 40)->nullable();
            $t->string('model', 40)->nullable();
            $t->string('colour', 30)->nullable();
            $t->string('type', 12)->default('car');                         // car | suv | pickup | van | bus | motorbike
            $t->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $t->string('owner_name', 120)->nullable();
            $t->string('phone', 30)->nullable();
            $t->boolean('is_banned')->default(false);
            $t->string('ban_reason', 255)->nullable();
            $t->string('notes', 255)->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'plate']);
        });

        Schema::create('parking_permits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('number', 20);
            $t->foreignId('parking_vehicle_id')->constrained()->cascadeOnDelete();
            $t->string('holder_name', 120);
            $t->string('holder_type', 12)->default('staff');                // staff | tenant | contractor | member | other
            $t->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('parking_zone_id')->nullable()->constrained()->nullOnDelete();
            $t->date('valid_from');
            $t->date('valid_to');
            $t->decimal('fee', 12, 2)->default(0);                           // recorded only – billed through leases / payroll
            $t->string('status', 10)->default('active');                    // active | revoked
            $t->string('revoke_reason', 190)->nullable();
            $t->string('notes', 255)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'number']);
        });

        Schema::create('parking_sessions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('number', 20);
            $t->foreignId('parking_zone_id')->constrained()->cascadeOnDelete();
            $t->foreignId('parking_vehicle_id')->constrained()->cascadeOnDelete();
            $t->string('plate', 20);                                         // display plate at entry
            $t->string('kind', 10)->default('visitor');                     // guest | visitor | staff
            $t->boolean('is_valet')->default(false);
            $t->foreignId('reservation_room_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('parking_permit_id')->nullable()->constrained()->nullOnDelete();
            $t->string('guest_name', 120)->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('bay', 20)->nullable();
            $t->string('key_tag', 20)->nullable();
            $t->json('damage')->nullable();                                  // panels marked at the valet intake check
            $t->string('condition_notes', 500)->nullable();
            $t->string('fuel', 10)->nullable();                             // E | 1/4 | 1/2 | 3/4 | F
            $t->string('valuables', 200)->nullable();
            $t->dateTime('entered_at');
            $t->timestamp('exited_at')->nullable();
            $t->string('status', 12)->default('parked');                    // parked | requested | ready | out | cancelled
            $t->foreignId('parked_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('requested_at')->nullable();
            $t->string('requested_via', 10)->nullable();                    // desk | phone | qr
            $t->timestamp('ready_at')->nullable();
            $t->foreignId('runner_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('exited_by')->nullable()->constrained('users')->nullOnDelete();
            $t->unsignedTinyInteger('validation_hours')->default(0);
            $t->string('validation_ref', 60)->nullable();
            $t->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->boolean('lost_ticket')->default(false);
            $t->decimal('fee', 12, 2)->default(0);                           // charged at the exit (visitor tariff / unposted nights / lost ticket)
            $t->decimal('fee_waived', 12, 2)->default(0);
            $t->string('waive_reason', 190)->nullable();
            $t->foreignId('folio_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('payment_mode_id')->nullable()->constrained()->nullOnDelete();
            $t->char('token', 40)->nullable()->unique();
            $t->string('cancel_reason', 190)->nullable();
            $t->string('notes', 255)->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'number']);
            $t->index(['hotel_id', 'status']);
            $t->index(['hotel_id', 'entered_at']);
        });

        Schema::create('parking_charges', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('parking_session_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 8);                                           // night | exit | lost
            $t->date('night')->nullable();
            $t->date('business_date');
            $t->decimal('amount', 12, 2);
            $t->foreignId('folio_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('folio_transaction_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->unique(['parking_session_id', 'kind', 'night']);
        });
    }

    public function down(): void
    {
        foreach (['parking_charges', 'parking_sessions', 'parking_permits', 'parking_vehicles', 'parking_zones'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};

