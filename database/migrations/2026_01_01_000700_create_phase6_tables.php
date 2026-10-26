<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v1.5: mobile housekeeping (attendant login, credits, checklist, DND, inspection, minibar),
 * spa & activity appointments, SMS / WhatsApp message log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $t) {
            $t->foreignId('user_id')->nullable()->after('department_id')->constrained()->nullOnDelete();
        });

        Schema::table('housekeeping_tasks', function (Blueprint $t) {
            $t->unsignedTinyInteger('credits')->default(1)->after('priority');
            $t->json('checklist')->nullable()->after('notes');
            $t->timestamp('dnd_at')->nullable()->after('completed_at');
            $t->foreignId('completed_by')->nullable()->after('dnd_at')->constrained('users')->nullOnDelete();
            $t->string('inspection_status', 10)->nullable()->after('completed_by'); // passed, failed
            $t->foreignId('inspected_by')->nullable()->after('inspection_status')->constrained('users')->nullOnDelete();
            $t->timestamp('inspected_at')->nullable()->after('inspected_by');
            $t->string('inspection_notes')->nullable()->after('inspected_at');
            $t->unsignedTinyInteger('reclean_count')->default(0)->after('inspection_notes');
            $t->index(['hotel_id', 'task_date', 'employee_id']);
        });

                if (Schema::hasTable('minibar_items')) {
            return;
        }

        Schema::create('minibar_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('name', 80);
            $t->decimal('price', 10, 2);
            $t->foreignId('inventory_item_id')->nullable()->constrained()->nullOnDelete(); // optional stock deduction
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

                if (Schema::hasTable('spa_services')) {
            return;
        }

        Schema::create('spa_services', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('name', 120);
            $t->string('category', 40)->default('spa'); // spa, salon, fitness, activity, tour
            $t->unsignedSmallInteger('duration_min')->default(60);
            $t->decimal('price', 10, 2);
            $t->string('description', 500)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

                if (Schema::hasTable('spa_rooms')) {
            return;
        }

        Schema::create('spa_rooms', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('name', 60);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

                if (Schema::hasTable('spa_appointments')) {
            return;
        }

        Schema::create('spa_appointments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('appt_no', 30);
            $t->foreignId('spa_service_id')->constrained()->restrictOnDelete();
            $t->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();   // therapist / guide
            $t->foreignId('spa_room_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('reservation_room_id')->nullable()->constrained()->nullOnDelete();
            $t->string('customer_name', 120);
            $t->string('customer_phone', 30)->nullable();
            $t->date('date');
            $t->time('start_time');
            $t->time('end_time');
            $t->unsignedTinyInteger('pax')->default(1);
            $t->decimal('price', 10, 2);
            $t->decimal('discount', 10, 2)->default(0);
            $t->string('status', 20)->default('booked'); // booked, in_progress, completed, cancelled, no_show
            $t->string('settlement', 20)->nullable();     // room_post, payment, complimentary
            $t->foreignId('payment_mode_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('folio_id')->nullable()->constrained()->nullOnDelete();
            $t->string('notes', 500)->nullable();
            $t->string('cancel_reason')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('settled_at')->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'appt_no']);
            $t->index(['hotel_id', 'date', 'status']);
        });

                if (Schema::hasTable('message_logs')) {
            return;
        }

        Schema::create('message_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('channel', 10);            // sms, whatsapp
            $t->string('driver', 20);             // log, twilio, hubtel, http
            $t->string('to', 30);
            $t->string('template', 40);           // confirmation, pre_arrival, thank_you, custom, test
            $t->text('body');
            $t->string('status', 10);             // sent, failed
            $t->string('provider_id', 100)->nullable();
            $t->string('error', 500)->nullable();
            $t->string('related_type', 60)->nullable();
            $t->unsignedBigInteger('related_id')->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->index(['hotel_id', 'created_at']);
            $t->index(['related_type', 'related_id', 'template']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_logs');
        Schema::dropIfExists('spa_appointments');
        Schema::dropIfExists('spa_rooms');
        Schema::dropIfExists('spa_services');
        Schema::dropIfExists('minibar_items');
        if (! Schema::hasIndex('housekeeping_tasks', 'housekeeping_tasks_hotel_fk_idx')) {
            Schema::table('housekeeping_tasks', fn (Blueprint $t) => $t->index('hotel_id', 'housekeeping_tasks_hotel_fk_idx')); // keeps the hotel_id FK indexed
        }
        Schema::table('housekeeping_tasks', function (Blueprint $t) {
            $t->dropIndex(['hotel_id', 'task_date', 'employee_id']);
            $t->dropConstrainedForeignId('completed_by');
            $t->dropConstrainedForeignId('inspected_by');
            $t->dropColumn(['credits', 'checklist', 'dnd_at', 'inspection_status', 'inspected_at', 'inspection_notes', 'reclean_count']);
        });
        Schema::table('employees', fn (Blueprint $t) => $t->dropConstrainedForeignId('user_id'));
    }
};

