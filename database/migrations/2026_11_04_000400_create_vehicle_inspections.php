<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v7.61 – daily pre-trip inspection: what the driver or desk checked before the car left the yard,
 * and the defects it raised. A defect can spawn a work order (vehicle_maintenance_records), which
 * is why this table points at it rather than duplicating the workshop fields.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vehicle_inspections')) {
            return;
        }
        Schema::create('vehicle_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->dateTime('inspected_at');
            $table->unsignedInteger('odometer')->nullable();
            $table->string('inspector', 100)->nullable();
            $table->json('checks')->nullable();              // ['brakes', 'lights', …] confirmed OK
            $table->string('result', 12)->default('pass');    // pass | minor | major
            $table->string('defects', 1000)->nullable();
            $table->foreignId('work_order_id')->nullable()->constrained('vehicle_maintenance_records')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['hotel_id', 'vehicle_id', 'inspected_at']);
            $table->index(['hotel_id', 'result', 'inspected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_inspections');
    }
};
