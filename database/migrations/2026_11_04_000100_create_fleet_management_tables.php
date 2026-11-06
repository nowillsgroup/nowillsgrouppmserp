<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Provider-neutral fleet tracking, maintenance planning, and work-order history. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vehicle_trackers')) {
            Schema::create('vehicle_trackers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
                $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
                $table->string('provider', 40)->default('generic');
                $table->string('device_uid', 120);
                $table->string('name', 100)->nullable();
                $table->char('token_hash', 64);
                $table->timestamp('last_seen_at')->nullable();
                $table->dateTime('last_position_at')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->unique(['hotel_id', 'provider', 'device_uid']);
                $table->index(['hotel_id', 'is_active', 'last_seen_at']);
                $table->index(['vehicle_id', 'is_active']);
            });
        }

        if (! Schema::hasTable('vehicle_positions')) {
            Schema::create('vehicle_positions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
                $table->foreignId('vehicle_tracker_id')->constrained('vehicle_trackers')->cascadeOnDelete();
                $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
                $table->string('event_id', 120)->nullable();
                $table->decimal('latitude', 10, 7);
                $table->decimal('longitude', 10, 7);
                $table->decimal('speed_kph', 7, 2)->nullable();
                $table->decimal('heading', 6, 2)->nullable();
                $table->boolean('ignition')->nullable();
                $table->unsignedInteger('odometer_km')->nullable();
                $table->dateTime('recorded_at');
                $table->timestamp('received_at');
                $table->timestamps();
                $table->unique(['vehicle_tracker_id', 'event_id']);
                $table->index(['hotel_id', 'vehicle_id', 'recorded_at']);
                $table->index(['vehicle_tracker_id', 'recorded_at']);
            });
        }

        if (! Schema::hasTable('vehicle_maintenance_plans')) {
            Schema::create('vehicle_maintenance_plans', function (Blueprint $table) {
                $table->id();
                $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
                $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
                $table->string('name', 120);
                $table->string('category', 24)->default('preventive');
                $table->unsignedSmallInteger('interval_days')->nullable();
                $table->unsignedInteger('interval_km')->nullable();
                $table->date('last_completed_date')->nullable();
                $table->unsignedInteger('last_completed_odometer')->nullable();
                $table->date('due_date')->nullable();
                $table->unsignedInteger('due_odometer')->nullable();
                $table->boolean('is_active')->default(true);
                $table->string('notes', 500)->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['hotel_id', 'is_active', 'due_date']);
                $table->index(['vehicle_id', 'is_active', 'due_odometer'], 'vmp_vehicle_active_odometer_index');
            });
        }

        if (! Schema::hasTable('vehicle_maintenance_records')) {
            Schema::create('vehicle_maintenance_records', function (Blueprint $table) {
                $table->id();
                $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
                $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
                $table->foreignId('plan_id')->nullable()->constrained('vehicle_maintenance_plans')->nullOnDelete();
                $table->string('category', 24)->default('repair');
                $table->string('status', 16)->default('scheduled');
                $table->string('priority', 12)->default('normal');
                $table->string('title', 140);
                $table->text('description')->nullable();
                $table->date('due_date')->nullable();
                $table->unsignedInteger('due_odometer')->nullable();
                $table->dateTime('started_at')->nullable();
                $table->dateTime('completed_at')->nullable();
                $table->unsignedInteger('odometer')->nullable();
                $table->string('vendor', 120)->nullable();
                $table->decimal('estimated_cost', 12, 2)->default(0);
                $table->decimal('actual_cost', 12, 2)->default(0);
                $table->string('invoice_ref', 80)->nullable();
                $table->string('notes', 500)->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['hotel_id', 'status', 'due_date']);
                $table->index(['vehicle_id', 'status', 'due_odometer']);
            });
        }

        if (Schema::hasTable('vehicle_expenses') && ! Schema::hasColumn('vehicle_expenses', 'maintenance_record_id')) {
            Schema::table('vehicle_expenses', function (Blueprint $table) {
                $table->foreignId('maintenance_record_id')->nullable()->after('vehicle_id')
                    ->constrained('vehicle_maintenance_records')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('vehicle_expenses') && Schema::hasColumn('vehicle_expenses', 'maintenance_record_id')) {
            Schema::table('vehicle_expenses', function (Blueprint $table) {
                $table->dropConstrainedForeignId('maintenance_record_id');
            });
        }
        Schema::dropIfExists('vehicle_maintenance_records');
        Schema::dropIfExists('vehicle_maintenance_plans');
        Schema::dropIfExists('vehicle_positions');
        Schema::dropIfExists('vehicle_trackers');
    }
};
