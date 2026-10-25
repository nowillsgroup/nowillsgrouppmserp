<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** v4.1 – upselling & pre-arrival offers: offer catalogue, orders (upgrades / add-ons / early check-in / late check-out), presentation funnel. */
return new class extends Migration
{
    public function up(): void
    {
                if (Schema::hasTable('upsell_offers')) {
            return;
        }

        Schema::create('upsell_offers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('code', 20);
            $t->string('name', 80);
            $t->string('kind', 20);                          // upgrade | addon | early_checkin | late_checkout
            $t->text('description')->nullable();             // guest-facing copy
            $t->string('icon', 20)->default('star');
            $t->string('category', 30)->default('misc');     // folio charge category
            $t->boolean('apply_tax')->default(true);
            $t->string('pricing', 20)->default('per_stay');  // per_stay | per_night | per_person | per_person_night | rate_difference
            $t->decimal('price', 12, 2)->default(0);
            $t->decimal('diff_pct', 5, 2)->nullable();       // rate_difference: % of (target rate − booked rate) per night
            $t->decimal('min_price', 12, 2)->nullable();     // rate_difference: floor per night
            $t->foreignId('to_room_type_id')->nullable()->constrained('room_types')->nullOnDelete();
            $t->json('room_type_ids')->nullable();           // eligible booked room types (null = any; upgrades: any cheaper type)
            $t->json('source_ids')->nullable();              // eligible business sources (null = any)
            $t->json('channels');                            // pre_arrival, desk, portal
            $t->time('service_time')->nullable();            // early check-in from / late check-out until
            $t->unsignedSmallInteger('daily_cap')->nullable();
            $t->unsignedTinyInteger('min_nights')->default(1);
            $t->unsignedSmallInteger('lead_hours')->default(0); // guest self-service closes this many hours before arrival
            $t->boolean('auto_confirm')->default(true);
            $t->string('incentive_type', 10)->default('none'); // none | pct | fixed
            $t->decimal('incentive_value', 10, 2)->default(0);
            $t->date('valid_from')->nullable();
            $t->date('valid_to')->nullable();
            $t->unsignedSmallInteger('sort')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['hotel_id', 'code']);
        });

                if (Schema::hasTable('upsell_orders')) {
            return;
        }

        Schema::create('upsell_orders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('order_no', 20);
            $t->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $t->foreignId('reservation_room_id')->constrained()->cascadeOnDelete();
            $t->foreignId('upsell_offer_id')->constrained()->restrictOnDelete();
            $t->string('kind', 20);
            $t->string('channel', 15);                       // pre_arrival | desk | portal
            $t->string('status', 15)->default('requested');  // requested | confirmed | declined | cancelled
            $t->unsignedSmallInteger('quantity')->default(1);
            $t->unsignedSmallInteger('nights')->default(1);
            $t->decimal('unit_price', 12, 2);
            $t->decimal('amount', 12, 2);                    // booked value, before tax
            $t->foreignId('from_room_type_id')->nullable()->constrained('room_types')->nullOnDelete();
            $t->foreignId('to_room_type_id')->nullable()->constrained('room_types')->nullOnDelete();
            $t->date('service_date');
            $t->foreignId('stay_fixed_charge_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('sold_by')->nullable()->constrained('users')->nullOnDelete();
            $t->decimal('incentive_amount', 10, 2)->default(0);
            $t->string('guest_note', 255)->nullable();
            $t->string('staff_note', 255)->nullable();       // decline / cancel reason
            $t->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('confirmed_at')->nullable();
            $t->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'order_no']);
            $t->index(['hotel_id', 'status', 'service_date']);
            $t->index(['upsell_offer_id', 'service_date']);
        });

                if (Schema::hasTable('upsell_presentations')) {
            return;
        }

        Schema::create('upsell_presentations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $t->string('channel', 15);
            $t->json('offer_ids')->nullable();
            $t->foreignId('email_log_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('opened_at')->nullable();
            $t->unsignedInteger('open_count')->default(0);
            $t->timestamp('converted_at')->nullable();
            $t->timestamps();
            $t->unique(['reservation_id', 'channel']);
            $t->index(['hotel_id', 'created_at']);
        });

        Schema::table('reservations', function (Blueprint $t) {
            $t->char('offer_token', 40)->nullable()->unique();
        });
        Schema::table('reservation_rooms', function (Blueprint $t) {
            $t->time('early_checkin_from')->nullable();
            $t->time('late_checkout_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reservation_rooms', fn (Blueprint $t) => $t->dropColumn(['early_checkin_from', 'late_checkout_until']));
        Schema::table('reservations', function (Blueprint $t) {
            $t->dropUnique(['offer_token']);
            $t->dropColumn('offer_token');
        });
        Schema::dropIfExists('upsell_presentations');
        Schema::dropIfExists('upsell_orders');
        Schema::dropIfExists('upsell_offers');
    }
};

