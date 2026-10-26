<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v3.2 restaurant table reservations, waitlist & live floor plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_tables', function (Blueprint $t) {
            $t->string('shape', 10)->default('square')->after('section');          // square, round, rect, booth
            $t->unsignedTinyInteger('pos_x')->nullable()->after('shape');           // floor plan position, % of width
            $t->unsignedTinyInteger('pos_y')->nullable()->after('pos_x');           // % of height
            $t->unsignedTinyInteger('min_capacity')->default(1)->after('capacity'); // don't give a 6-top to a couple
            $t->boolean('combinable')->default(true)->after('min_capacity');        // may be joined with a neighbour in its section
            $t->boolean('reservable')->default(true)->after('combinable');          // offered to online / auto-assignment
            $t->boolean('needs_clearing')->default(false)->after('is_active');      // bill settled, table not yet reset
            $t->string('blocked_reason', 120)->nullable()->after('needs_clearing'); // out of service (broken, private use…)
        });

        Schema::table('outlets', function (Blueprint $t) {
            $t->boolean('tb_enabled')->default(false)->after('is_active');   // table reservations & floor plan
            $t->boolean('tb_online')->default(false)->after('tb_enabled');   // bookable on the public page
            $t->json('tb_settings')->nullable()->after('tb_online');
        });

                if (Schema::hasTable('outlet_service_periods')) {
            return;
        }

        Schema::create('outlet_service_periods', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $t->string('name', 40);                       // Breakfast, Lunch, Dinner
            $t->string('days', 7)->default('1234567');    // ISO weekdays open (1 = Monday)
            $t->time('start_time');                       // first seating
            $t->time('end_time');                         // last seating
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

                if (Schema::hasTable('table_reservations')) {
            return;
        }

        Schema::create('table_reservations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $t->string('booking_no', 20);
            $t->string('type', 10)->default('booking');   // booking, walkin, waitlist
            $t->string('status', 12)->default('confirmed'); // booked, confirmed, seated, completed, cancelled, no_show | waiting, notified, left
            $t->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('reservation_room_id')->nullable()->constrained('reservation_rooms')->nullOnDelete(); // in-house guest
            $t->string('name', 120);
            $t->string('phone', 30)->nullable();
            $t->string('email', 150)->nullable();
            $t->unsignedTinyInteger('party_size');
            $t->date('date');
            $t->dateTime('start_at');
            $t->dateTime('end_at');
            $t->unsignedSmallInteger('duration');         // minutes
            $t->string('source', 12)->default('phone');   // phone, walkin, online, email, staff, concierge
            $t->string('occasion', 20)->nullable();
            $t->json('tags')->nullable();                 // high_chair, wheelchair, window, quiet, vegetarian, allergy
            $t->string('section_pref', 40)->nullable();
            $t->text('notes')->nullable();
            $t->unsignedSmallInteger('quoted_minutes')->nullable(); // waitlist quote
            $t->char('token_hash', 64)->nullable()->unique();       // guest manage link (sha256 of the token)
            $t->foreignId('pos_order_id')->nullable()->constrained('pos_orders')->nullOnDelete();
            $t->timestamp('confirmed_at')->nullable();
            $t->timestamp('reminder_sent_at')->nullable();
            $t->timestamp('notified_at')->nullable();
            $t->timestamp('seated_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->string('cancel_reason', 200)->nullable();
            $t->timestamp('no_show_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['hotel_id', 'outlet_id', 'date', 'status']);
            $t->index(['hotel_id', 'phone']);
            $t->unique(['hotel_id', 'booking_no']);
        });

                if (Schema::hasTable('table_reservation_tables')) {
            return;
        }

        Schema::create('table_reservation_tables', function (Blueprint $t) {
            $t->foreignId('table_reservation_id')->constrained()->cascadeOnDelete();
            $t->foreignId('pos_table_id')->constrained()->cascadeOnDelete();
            $t->primary(['table_reservation_id', 'pos_table_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('table_reservation_tables');
        Schema::dropIfExists('table_reservations');
        Schema::dropIfExists('outlet_service_periods');
        Schema::table('outlets', fn (Blueprint $t) => $t->dropColumn(['tb_enabled', 'tb_online', 'tb_settings']));
        Schema::table('pos_tables', fn (Blueprint $t) => $t->dropColumn(['shape', 'pos_x', 'pos_y', 'min_capacity', 'combinable', 'reservable', 'needs_clearing', 'blocked_reason']));
    }
};

