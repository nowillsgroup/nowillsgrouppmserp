<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v5.9 Concierge, activities & excursions desk: third-party activity vendors (tour operators, guides, attractions, event
 * ticketing) and the activities they run, scheduled departures with capacity, guest bookings sold by the hotel as agent
 * (charged to the room folio – the gross is owed to the vendor, only the commission is hotel revenue – or paid to the
 * vendor, who owes the hotel its commission), vendor settlements, and the local guide shown on the guest portal.
 */
return new class extends Migration
{
    public function up(): void
    {
                if (Schema::hasTable('activity_vendors')) {
            return;
        }

        Schema::create('activity_vendors', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('name', 150);
            $t->string('kind', 20)->default('tour_operator');
            $t->string('contact_name', 100)->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('email', 150)->nullable();
            $t->string('payout_details', 255)->nullable();                     // bank / MoMo for settlements
            $t->string('licence_no', 60)->nullable();                          // Ghana Tourism Authority licence
            $t->date('licence_expires')->nullable();
            $t->decimal('commission_pct', 5, 2)->default(15);
            $t->string('collection', 8)->default('folio');                     // folio = hotel collects | vendor = guest pays the vendor
            $t->string('notes', 255)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

                if (Schema::hasTable('activities')) {
            return;
        }

        Schema::create('activities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('activity_vendor_id')->constrained()->cascadeOnDelete();
            $t->string('code', 20);
            $t->string('name', 150);
            $t->string('category', 20)->default('tour');
            $t->text('description')->nullable();
            $t->string('highlights', 255)->nullable();
            $t->string('bring', 255)->nullable();                              // what to bring / wear
            $t->unsignedSmallInteger('duration_min')->default(240);
            $t->string('meeting_point', 150)->nullable();
            $t->boolean('pickup_included')->default(false);
            $t->string('pricing', 8)->default('person');                       // person | group (flat per private booking)
            $t->decimal('price_adult', 12, 2)->default(0);
            $t->decimal('price_child', 12, 2)->default(0);
            $t->unsignedTinyInteger('child_max_age')->default(11);
            $t->decimal('commission_pct', 5, 2)->nullable();                   // null = vendor's
            $t->string('collection', 8)->nullable();                           // null = vendor's
            $t->unsignedSmallInteger('min_pax')->default(1);
            $t->unsignedSmallInteger('capacity')->nullable();                  // per departure (group: max pax per booking)
            $t->string('schedule', 10)->default('scheduled');                  // scheduled | request
            $t->json('weekdays')->nullable();                                  // ISO 1..7
            $t->json('times')->nullable();                                     // ["09:00","14:00"]
            $t->boolean('instant')->default(false);                            // the hotel holds seats – confirmed at once
            $t->unsignedSmallInteger('cutoff_hours')->default(12);
            $t->unsignedSmallInteger('free_cancel_hours')->default(24);
            $t->decimal('cancel_fee_pct', 5, 2)->default(50);
            $t->boolean('portal_visible')->default(true);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['hotel_id', 'code']);
        });

                if (Schema::hasTable('activity_departures')) {
            return;
        }

        Schema::create('activity_departures', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $t->date('date');
            $t->char('time', 5);
            $t->unsignedSmallInteger('capacity')->nullable();
            $t->string('status', 10)->default('open');                         // open | closed | cancelled
            $t->string('guide', 100)->nullable();
            $t->string('notes', 255)->nullable();
            $t->string('cancel_reason', 190)->nullable();
            $t->timestamps();
            $t->unique(['activity_id', 'date', 'time']);
        });

                if (Schema::hasTable('activity_settlements')) {
            return;
        }

        Schema::create('activity_settlements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('number', 20);
            $t->foreignId('activity_vendor_id')->constrained()->cascadeOnDelete();
            $t->date('date');
            $t->date('up_to');                                                  // bookings on or before
            $t->unsignedSmallInteger('bookings')->default(0);
            $t->decimal('payable', 14, 2)->default(0);                          // owed to the vendor (hotel-collected, net of commission)
            $t->decimal('receivable', 14, 2)->default(0);                       // commission owed by the vendor (vendor-collected)
            $t->decimal('net', 14, 2)->default(0);                              // + hotel pays, − vendor pays the hotel
            $t->foreignId('account_head_id')->nullable()->constrained()->nullOnDelete();
            $t->string('reference', 80)->nullable();
            $t->foreignId('gl_journal_id')->nullable()->constrained()->nullOnDelete();
            $t->string('status', 10)->default('posted');                        // posted | void
            $t->string('void_reason', 190)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'number']);
        });

                if (Schema::hasTable('activity_bookings')) {
            return;
        }

        Schema::create('activity_bookings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('number', 20);
            $t->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $t->foreignId('activity_vendor_id')->constrained()->cascadeOnDelete();
            $t->foreignId('activity_departure_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('reservation_room_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $t->string('guest_name', 150);
            $t->string('room_no', 12)->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('email', 150)->nullable();
            $t->date('date');
            $t->char('time', 5);
            $t->unsignedSmallInteger('adults')->default(1);
            $t->unsignedSmallInteger('children')->default(0);
            $t->string('pickup_point', 150)->nullable();
            $t->char('pickup_time', 5)->nullable();
            $t->string('notes', 500)->nullable();
            $t->string('source', 8)->default('desk');                          // desk | portal | phone
            $t->decimal('price_adult', 12, 2)->default(0);
            $t->decimal('price_child', 12, 2)->default(0);
            $t->decimal('gross', 12, 2)->default(0);                            // what the guest pays
            $t->decimal('commission_pct', 5, 2)->default(0);
            $t->decimal('commission', 12, 2)->default(0);                       // hotel revenue
            $t->decimal('vendor_net', 12, 2)->default(0);                       // owed to the vendor
            $t->string('collection', 8)->default('folio');                      // folio | vendor
            $t->foreignId('folio_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('folio_transaction_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('cancel_fee', 12, 2)->default(0);
            $t->string('status', 10)->default('requested');                    // requested | waitlisted | confirmed | completed | no_show | cancelled
            $t->string('vendor_ref', 60)->nullable();
            $t->timestamp('confirmed_at')->nullable();
            $t->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('completed_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->string('cancel_reason', 190)->nullable();
            $t->date('recognised_on')->nullable();                              // commission recognised (journal date)
            $t->foreignId('gl_journal_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('activity_settlement_id')->nullable()->constrained()->nullOnDelete();
            $t->char('voucher_token', 40)->nullable();
            $t->timestamp('guest_notified_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'number']);
            $t->index(['hotel_id', 'date', 'status']);
        });

                if (Schema::hasTable('concierge_places')) {
            return;
        }

        Schema::create('concierge_places', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('name', 150);
            $t->string('category', 20)->default('attraction');
            $t->string('area', 100)->nullable();
            $t->string('address', 190)->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('website', 190)->nullable();
            $t->decimal('distance_km', 6, 1)->nullable();
            $t->string('hours', 120)->nullable();
            $t->unsignedTinyInteger('price_level')->nullable();                 // 1..4
            $t->string('description', 500)->nullable();
            $t->string('tip', 255)->nullable();                                  // concierge's tip
            $t->boolean('portal_visible')->default(true);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('concierge_places');
        Schema::dropIfExists('activity_bookings');
        Schema::dropIfExists('activity_settlements');
        Schema::dropIfExists('activity_departures');
        Schema::dropIfExists('activities');
        Schema::dropIfExists('activity_vendors');
    }
};

