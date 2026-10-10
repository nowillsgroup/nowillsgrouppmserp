<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v6.4 Central reservations & lost business: the reservations enquiry log (every call / e-mail / WhatsApp that asks for
 * rooms, whether it books or not) with denials (we could not offer – sold out, restrictions) and regrets (we offered, the
 * guest declined – price, location …), follow-up contacts, and referrals to sister hotels of the group. Turnaways feed
 * the unconstrained-demand view for revenue management. Group-wide availability search and booking into any property
 * of the group are services on top of the existing reservations tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cro_enquiries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();   // the property the caller asked for
            $t->string('number', 20);
            $t->string('channel', 20)->default('phone');                    // phone | email | whatsapp | walk_in | web_chat | travel_agent | social | referral
            $t->string('caller_name', 120);
            $t->string('phone', 30)->nullable();
            $t->string('email', 255)->nullable();
            $t->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $t->date('arrival_date');
            $t->date('departure_date');
            $t->unsignedSmallInteger('rooms')->default(1);
            $t->unsignedSmallInteger('adults')->default(1);
            $t->unsignedSmallInteger('children')->default(0);
            $t->foreignId('room_type_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('quoted_rate', 12, 2)->nullable();                  // average per room-night offered
            $t->decimal('quoted_total', 14, 2)->nullable();                 // rate × nights × rooms (the value at stake)
            $t->string('status', 12)->default('open');                      // open | booked | referred | lost
            $t->string('loss_type', 10)->nullable();                        // denial | regret (also set on referrals: why we could not keep it)
            $t->string('loss_reason', 20)->nullable();
            $t->foreignId('competitor_id')->nullable()->constrained('bd_competitors')->nullOnDelete();
            $t->string('loss_note', 255)->nullable();
            $t->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('booked_value', 14, 2)->nullable();                 // room revenue of the booking made (here or at the sister hotel)
            $t->unsignedBigInteger('referred_hotel_id')->nullable();        // referral given → sister hotel
            $t->unsignedBigInteger('referred_reservation_id')->nullable();  // reservation id in the sister hotel (cross-tenant, no FK)
            $t->string('referred_reservation_no', 30)->nullable();
            $t->unsignedBigInteger('referred_from_hotel_id')->nullable();   // referral received ← sister hotel
            $t->unsignedBigInteger('origin_enquiry_id')->nullable();        // the enquiry at the referring hotel
            $t->unsignedSmallInteger('duration_sec')->nullable();           // call handling time
            $t->dateTime('follow_up_at')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('taken_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('closed_at')->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'number']);
            $t->index(['hotel_id', 'status']);
            $t->index(['hotel_id', 'arrival_date']);
            $t->index(['hotel_id', 'created_at']);
            $t->index('referred_hotel_id');
        });

        Schema::create('cro_contacts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('cro_enquiry_id')->constrained()->cascadeOnDelete();
            $t->string('type', 12)->default('call_out');                    // call_in | call_out | email | whatsapp | sms | note
            $t->string('note', 500);
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->index(['hotel_id', 'cro_enquiry_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cro_contacts');
        Schema::dropIfExists('cro_enquiries');
    }
};

