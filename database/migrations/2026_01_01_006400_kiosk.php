<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v7.2 Self-service kiosk: lobby kiosks (paired by code like the TVs) where guests check themselves in – find the booking,
 * confirm their details, photograph the ID, sign the registration card, pay by QR (mobile money / card), pick a room and
 * encode their own key – check out and settle the bill, or book a walk-in room. Every guest visit is a kiosk session with
 * a step-by-step trail; anything the kiosk cannot finish is handed to the front desk as an assistance request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kiosks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('name', 80);
            $t->string('location', 80)->nullable();              // Main lobby, Airport desk …
            $t->string('token', 40)->unique();                   // secret in the kiosk's start URL
            $t->string('status', 10)->default('active');         // pending (awaiting pairing), active, disabled
            $t->string('pairing_code', 6)->nullable();
            $t->timestamp('pairing_expires_at')->nullable();
            $t->timestamp('paired_at')->nullable();
            $t->foreignId('paired_by')->nullable()->constrained('users')->nullOnDelete();
            $t->boolean('allow_checkin')->default(true);
            $t->boolean('allow_checkout')->default(true);
            $t->boolean('allow_walkin')->default(true);
            $t->boolean('has_encoder')->default(true);           // a key-card encoder is attached
            $t->boolean('has_camera')->default(true);            // ID document camera / scanner
            $t->string('serial', 60)->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->string('last_ip', 45)->nullable();
            $t->string('user_agent', 190)->nullable();
            $t->string('notes', 255)->nullable();
            $t->timestamps();
            $t->index(['hotel_id', 'status']);
            $t->index(['pairing_code', 'status']);
        });

        Schema::create('kiosk_sessions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('kiosk_id')->constrained()->cascadeOnDelete();
            $t->string('number', 20);
            $t->string('token', 40)->unique();                    // the guest's visit – every step call carries it
            $t->string('kind', 10);                               // checkin, checkout, walkin
            $t->string('status', 10)->default('active');          // active, completed, assist, abandoned, cancelled
            $t->string('step', 12)->default('find');
            $t->string('lookup', 10)->nullable();                 // reference, qr, room
            $t->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('reservation_room_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('online_payment_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('amount_due', 12, 2)->default(0);
            $t->decimal('amount_paid', 12, 2)->default(0);
            $t->boolean('details_ok')->default(false);
            $t->boolean('id_captured')->default(false);
            $t->boolean('signed')->default(false);
            $t->unsignedTinyInteger('keys_issued')->default(0);
            $t->unsignedTinyInteger('rating')->nullable();
            $t->unsignedTinyInteger('failed_lookups')->default(0);
            $t->string('assist_reason', 20)->nullable();
            $t->string('assist_note', 255)->nullable();
            $t->timestamp('assist_at')->nullable();
            $t->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('resolved_at')->nullable();
            $t->string('resolution', 255)->nullable();
            $t->json('trail')->nullable();                        // [[time, step, note], …]
            $t->dateTime('started_at');
            $t->timestamp('last_activity_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'number']);
            $t->index(['hotel_id', 'status']);
            $t->index(['hotel_id', 'started_at']);
            $t->index(['reservation_room_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kiosk_sessions');
        Schema::dropIfExists('kiosks');
    }
};

