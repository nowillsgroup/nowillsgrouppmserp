<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** v7.40 Car Rental — first-class self-drive rental: fleet + bookings. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_vehicles', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('hotel_id')->index();
            $t->string('reg_no', 40);          // plate / registration
            $t->string('make', 60);
            $t->string('model', 60);
            $t->string('class', 40)->default('economy'); // economy | standard | suv | luxury | van
            $t->unsignedTinyInteger('seats')->default(5);
            $t->string('transmission', 20)->default('automatic');
            $t->string('fuel', 20)->default('petrol');
            $t->unsignedInteger('odometer')->default(0);
            $t->decimal('daily_rate', 10, 2);
            $t->decimal('weekly_rate', 10, 2)->nullable();  // null = 7 x daily
            $t->decimal('deposit', 10, 2)->default(0);
            $t->decimal('fuel_out', 4, 1)->default(100);    // % full at pickup baseline
            $t->string('status', 20)->default('available'); // available | rented | maintenance | retired
            $t->string('notes', 500)->nullable();
            $t->timestamps();
        });

        Schema::create('car_rentals', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('hotel_id')->index();
            $t->string('reference', 30)->unique();
            $t->unsignedBigInteger('rental_vehicle_id')->index();
            $t->unsignedBigInteger('guest_id')->nullable()->index();
            $t->unsignedBigInteger('reservation_room_id')->nullable()->index(); // in-house stay to bill
            $t->string('customer_name', 120);
            $t->string('customer_phone', 40)->nullable();
            $t->string('license_no', 60)->nullable();
            $t->timestamp('pickup_at');
            $t->timestamp('return_due_at');
            $t->timestamp('returned_at')->nullable();
            $t->unsignedInteger('pickup_mileage')->nullable();
            $t->unsignedInteger('return_mileage')->nullable();
            $t->decimal('fuel_out', 4, 1)->nullable();
            $t->decimal('fuel_in', 4, 1)->nullable();
            $t->decimal('daily_rate', 10, 2);
            $t->decimal('extras', 10, 2)->default(0);     // quoted extras (GPS, child seat …)
            $t->decimal('adjustments', 10, 2)->default(0); // fuel / mileage / late at return
            $t->decimal('total', 10, 2)->default(0);
            $t->decimal('deposit', 10, 2)->default(0);
            $t->string('status', 20)->default('reserved')->index(); // reserved | picked_up | returned | cancelled | no_show
            $t->text('notes')->nullable();
            $t->unsignedBigInteger('created_by');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('car_rentals');
        Schema::dropIfExists('rental_vehicles');
    }
};
