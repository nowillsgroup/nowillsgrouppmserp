<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Geofences around the yard, guest zones and restricted areas, with entry / exit history per vehicle. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fleet_geofences')) {
            Schema::create('fleet_geofences', function (Blueprint $table) {
                $table->id();
                $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
                $table->string('name', 80);
                $table->string('kind', 16)->default('yard');      // yard | zone | restricted
                $table->decimal('latitude', 10, 7);
                $table->decimal('longitude', 10, 7);
                $table->unsignedInteger('radius_m')->default(300);
                $table->string('alert_on', 8)->default('both');    // enter | exit | both | none
                $table->boolean('is_active')->default(true);
                $table->string('notes', 255)->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['hotel_id', 'name']);
                $table->index(['hotel_id', 'is_active']);
            });
        }

        if (! Schema::hasTable('fleet_geofence_states')) {
            Schema::create('fleet_geofence_states', function (Blueprint $table) {
                $table->id();
                $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
                $table->foreignId('geofence_id')->constrained('fleet_geofences')->cascadeOnDelete();
                $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
                $table->boolean('inside')->default(false);
                $table->timestamp('changed_at')->nullable();
                $table->timestamps();
                $table->unique(['geofence_id', 'vehicle_id']);
            });
        }

        if (! Schema::hasTable('fleet_geofence_events')) {
            Schema::create('fleet_geofence_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
                $table->foreignId('geofence_id')->constrained('fleet_geofences')->cascadeOnDelete();
                $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
                $table->foreignId('position_id')->nullable()->constrained('vehicle_positions')->nullOnDelete();
                $table->string('type', 8);                        // enter | exit
                $table->dateTime('occurred_at');
                $table->timestamp('acknowledged_at')->nullable();
                $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['hotel_id', 'occurred_at']);
                $table->index(['geofence_id', 'occurred_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_geofence_events');
        Schema::dropIfExists('fleet_geofence_states');
        Schema::dropIfExists('fleet_geofences');
    }
};
