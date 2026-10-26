<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Front office: masters, rooms, guests, reservations, stays, folios, housekeeping.
 */
return new class extends Migration
{
    public function up(): void
    {
                if (Schema::hasTable('floors')) {
            return;
        }

        Schema::create('floors', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('name', 60);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
        });

                if (Schema::hasTable('room_types')) {
            return;
        }

        Schema::create('room_types', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('code', 20);
            $t->string('name', 80);
            $t->decimal('base_rate', 12, 2)->default(0);
            $t->decimal('extra_adult_rate', 12, 2)->default(0);
            $t->decimal('extra_child_rate', 12, 2)->default(0);
            $t->unsignedTinyInteger('base_occupancy')->default(2);
            $t->unsignedTinyInteger('max_adults')->default(2);
            $t->unsignedTinyInteger('max_children')->default(1);
            $t->string('amenities', 500)->nullable();
            $t->text('description')->nullable();
            $t->boolean('show_online')->default(true);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['hotel_id', 'code']);
        });

                if (Schema::hasTable('rooms')) {
            return;
        }

        Schema::create('rooms', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('room_type_id')->constrained()->restrictOnDelete();
            $t->foreignId('floor_id')->nullable()->constrained()->nullOnDelete();
            $t->string('room_no', 20);
            $t->string('status', 20)->default('vacant');     // vacant, occupied, out_of_order, out_of_service
            $t->string('hk_status', 20)->default('clean');   // clean, dirty, inspected, touch_up
            $t->string('extension', 10)->nullable();
            $t->boolean('is_smoking')->default(false);
            $t->string('notes')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['hotel_id', 'room_no']);
        });

                if (Schema::hasTable('rate_plans')) {
            return;
        }

        Schema::create('rate_plans', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('room_type_id')->constrained()->cascadeOnDelete();
            $t->string('code', 20);
            $t->string('name', 80);
            $t->string('meal_plan', 10)->default('EP'); // EP, CP, MAP, AP
            $t->decimal('rate', 12, 2);
            $t->decimal('extra_adult_rate', 12, 2)->default(0);
            $t->decimal('extra_child_rate', 12, 2)->default(0);
            $t->boolean('tax_inclusive')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

                if (Schema::hasTable('taxes')) {
            return;
        }

        Schema::create('taxes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('name', 60);
            $t->decimal('rate', 6, 2);
            $t->string('applies_to', 20)->default('room'); // room, fnb, service, all
            $t->decimal('slab_from', 12, 2)->default(0);   // tariff slab (e.g. GST 12% up to 7500)
            $t->decimal('slab_to', 12, 2)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

                if (Schema::hasTable('business_sources')) {
            return;
        }

        Schema::create('business_sources', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('name', 80);
            $t->string('type', 30)->default('direct'); // direct, walk_in, ota, corporate, travel_agent, booking_engine
            $t->decimal('commission_pct', 5, 2)->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

                if (Schema::hasTable('payment_modes')) {
            return;
        }

        Schema::create('payment_modes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('name', 60);
            $t->string('type', 20)->default('cash'); // cash, card, upi, bank, wallet, city_ledger
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

                if (Schema::hasTable('companies')) {
            return;
        }

        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('type', 20)->default('corporate'); // corporate, travel_agent, ota
            $t->string('contact_person')->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('email')->nullable();
            $t->string('address')->nullable();
            $t->string('tax_number', 40)->nullable();
            $t->decimal('credit_limit', 14, 2)->default(0);
            $t->decimal('discount_pct', 5, 2)->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

                if (Schema::hasTable('guests')) {
            return;
        }

        Schema::create('guests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $t->string('title', 10)->nullable();
            $t->string('first_name', 80);
            $t->string('last_name', 80)->nullable();
            $t->string('gender', 10)->nullable();
            $t->date('dob')->nullable();
            $t->date('anniversary')->nullable();
            $t->string('phone', 30)->nullable()->index();
            $t->string('email')->nullable();
            $t->string('nationality', 60)->nullable();
            $t->string('id_type', 30)->nullable();     // passport, aadhaar, driving_licence, voter_id, national_id
            $t->string('id_number', 60)->nullable();
            $t->string('passport_no', 40)->nullable();
            $t->date('visa_expiry')->nullable();
            $t->string('address')->nullable();
            $t->string('city', 80)->nullable();
            $t->string('state', 80)->nullable();
            $t->string('country', 80)->nullable();
            $t->string('pincode', 20)->nullable();
            $t->string('tax_number', 40)->nullable();
            $t->boolean('is_vip')->default(false);
            $t->boolean('is_blacklisted')->default(false);
            $t->string('preferences', 500)->nullable();
            $t->text('notes')->nullable();
            $t->unsignedInteger('loyalty_points')->default(0);
            $t->timestamps();
        });

                if (Schema::hasTable('reservations')) {
            return;
        }

        Schema::create('reservations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('reservation_no', 30);
            $t->foreignId('guest_id')->constrained()->restrictOnDelete();
            $t->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('business_source_id')->nullable()->constrained()->nullOnDelete();
            $t->string('booking_type', 20)->default('individual'); // individual, group
            $t->string('group_name')->nullable();
            $t->date('arrival_date');
            $t->date('departure_date');
            $t->time('eta')->nullable();
            $t->unsignedSmallInteger('adults')->default(1);
            $t->unsignedSmallInteger('children')->default(0);
            $t->string('status', 20)->default('confirmed'); // tentative, confirmed, checked_in, checked_out, cancelled, no_show
            $t->string('ota_reference', 60)->nullable();
            $t->string('purpose', 60)->nullable();
            $t->text('special_request')->nullable();
            $t->string('billing_instruction', 20)->default('guest'); // guest, company
            $t->decimal('discount_pct', 5, 2)->default(0);
            $t->string('cancel_reason')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'reservation_no']);
            $t->index(['hotel_id', 'arrival_date']);
        });

        // Each reserved room / stay line. Becomes a "stay" once checked-in.
                if (Schema::hasTable('reservation_rooms')) {
            return;
        }

        Schema::create('reservation_rooms', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $t->foreignId('room_type_id')->constrained()->restrictOnDelete();
            $t->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('rate_plan_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $t->date('arrival_date');
            $t->date('departure_date');
            $t->decimal('rate', 12, 2);
            $t->unsignedTinyInteger('adults')->default(1);
            $t->unsignedTinyInteger('children')->default(0);
            $t->decimal('extra_charge', 12, 2)->default(0);
            $t->string('status', 20)->default('reserved'); // reserved, checked_in, checked_out, cancelled, no_show
            $t->timestamp('checked_in_at')->nullable();
            $t->timestamp('checked_out_at')->nullable();
            $t->foreignId('checked_in_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('checked_out_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['hotel_id', 'status']);
        });

                if (Schema::hasTable('room_moves')) {
            return;
        }

        Schema::create('room_moves', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('reservation_room_id')->constrained()->cascadeOnDelete();
            $t->foreignId('from_room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $t->foreignId('to_room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $t->decimal('old_rate', 12, 2)->nullable();
            $t->decimal('new_rate', 12, 2)->nullable();
            $t->string('reason')->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
        });

                if (Schema::hasTable('folios')) {
            return;
        }

        Schema::create('folios', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('folio_no', 30);
            $t->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('reservation_room_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $t->string('type', 20)->default('guest'); // guest, master, walk_in (non-resident), banquet
            $t->string('status', 20)->default('open'); // open, closed
            $t->string('invoice_no', 30)->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'folio_no']);
        });

                if (Schema::hasTable('folio_transactions')) {
            return;
        }

        Schema::create('folio_transactions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('folio_id')->constrained()->cascadeOnDelete();
            $t->date('business_date');
            $t->string('type', 20);      // charge, payment, discount, refund, allowance
            $t->string('category', 30);  // room, extra_bed, fnb, laundry, telephone, minibar, transport, banquet, misc, advance, settlement
            $t->string('description');
            $t->decimal('amount', 14, 2);     // net amount (before tax for charges)
            $t->decimal('tax_amount', 14, 2)->default(0);
            $t->decimal('tax_rate', 6, 2)->default(0);
            $t->foreignId('payment_mode_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('company_id')->nullable()->constrained()->nullOnDelete(); // city ledger transfer
            $t->string('reference', 80)->nullable();
            $t->string('source_type', 60)->nullable();
            $t->unsignedBigInteger('source_id')->nullable();
            $t->boolean('is_void')->default(false);
            $t->string('void_reason')->nullable();
            $t->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['hotel_id', 'business_date']);
        });

                if (Schema::hasTable('housekeeping_tasks')) {
            return;
        }

        Schema::create('housekeeping_tasks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('employee_id')->nullable();
            $t->string('task_type', 30)->default('cleaning'); // cleaning, turndown, deep_clean, inspection, linen_change, public_area
            $t->string('area')->nullable();
            $t->string('priority', 10)->default('normal');
            $t->string('status', 20)->default('pending'); // pending, in_progress, completed
            $t->date('task_date');
            $t->string('notes')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
        });

                if (Schema::hasTable('maintenance_requests')) {
            return;
        }

        Schema::create('maintenance_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('ticket_no', 30);
            $t->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $t->string('location')->nullable();
            $t->string('category', 40)->default('general'); // electrical, plumbing, ac, carpentry, it, general
            $t->string('title');
            $t->text('description')->nullable();
            $t->string('priority', 10)->default('normal');
            $t->string('status', 20)->default('open'); // open, in_progress, resolved, closed
            $t->boolean('block_room')->default(false);
            $t->date('block_from')->nullable();
            $t->date('block_to')->nullable();
            $t->foreignId('employee_id')->nullable();
            $t->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $t->decimal('cost', 12, 2)->default(0);
            $t->text('resolution')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
        });

                if (Schema::hasTable('lost_found_items')) {
            return;
        }

        Schema::create('lost_found_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('item_name');
            $t->text('description')->nullable();
            $t->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $t->string('location')->nullable();
            $t->date('found_date');
            $t->string('found_by')->nullable();
            $t->string('status', 20)->default('stored'); // stored, returned, disposed
            $t->string('returned_to')->nullable();
            $t->date('returned_date')->nullable();
            $t->timestamps();
        });

                if (Schema::hasTable('guest_feedback')) {
            return;
        }

        Schema::create('guest_feedback', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $t->string('guest_name')->nullable();
            $t->string('contact')->nullable();
            $t->unsignedTinyInteger('rating_overall');
            $t->unsignedTinyInteger('rating_room')->nullable();
            $t->unsignedTinyInteger('rating_service')->nullable();
            $t->unsignedTinyInteger('rating_food')->nullable();
            $t->unsignedTinyInteger('rating_cleanliness')->nullable();
            $t->text('comments')->nullable();
            $t->timestamps();
        });

                if (Schema::hasTable('night_audits')) {
            return;
        }

        Schema::create('night_audits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->date('business_date');
            $t->unsignedInteger('rooms_total')->default(0);
            $t->unsignedInteger('rooms_occupied')->default(0);
            $t->unsignedInteger('arrivals')->default(0);
            $t->unsignedInteger('departures')->default(0);
            $t->unsignedInteger('no_shows')->default(0);
            $t->decimal('room_revenue', 14, 2)->default(0);
            $t->decimal('other_revenue', 14, 2)->default(0);
            $t->decimal('tax_collected', 14, 2)->default(0);
            $t->decimal('collections', 14, 2)->default(0);
            $t->decimal('occupancy_pct', 6, 2)->default(0);
            $t->decimal('adr', 12, 2)->default(0);
            $t->decimal('revpar', 12, 2)->default(0);
            $t->foreignId('run_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'business_date']);
        });
    }

    public function down(): void
    {
        foreach (['night_audits', 'guest_feedback', 'lost_found_items', 'maintenance_requests', 'housekeeping_tasks', 'folio_transactions', 'folios', 'room_moves', 'reservation_rooms', 'reservations', 'guests', 'companies', 'payment_modes', 'business_sources', 'taxes', 'rate_plans', 'rooms', 'room_types', 'floors'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};

