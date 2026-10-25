<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** v7.55 – Robita smart door locks: device registry, door mapping, access events. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('robita_devices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hotel_id')->index();
            $table->string('device_id', 64)->unique();          // Robita cloud device ID
            $table->string('serial', 64)->unique();              // physical device serial
            $table->string('name', 80);                          // e.g. "Room 101 door"
            $table->string('model', 40)->default('Robita S1');   // device model
            $table->unsignedBigInteger('room_id')->nullable()->index();
            $table->string('floor', 40)->nullable();
            $table->string('location', 80)->nullable();          // e.g. "Main entrance", "Lobby"
            $table->string('device_type', 20)->default('door_lock'); // door_lock | common_area | gate
            $table->string('status', 20)->default('provisioning'); // provisioning|online|offline|decommissioned
            $table->string('lock_state', 20)->nullable();         // locked|unlocked|unknown
            $table->unsignedTinyInteger('battery_pct')->nullable();
            $table->string('signal', 20)->nullable();             // excellent|good|fair|poor
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->unsignedInteger('event_count')->default(0);
            $table->unsignedInteger('unlock_count')->default(0);
            $table->string('api_key', 128)->nullable();            // per-device API key (encrypted at rest via app key)
            $table->string('firmware', 20)->nullable();
            $table->string('notes', 200)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['hotel_id', 'status']);
            $table->index(['room_id', 'status']);
            $table->index('last_seen_at');
        });

        Schema::create('robita_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hotel_id')->index();
            $table->unsignedBigInteger('robita_device_id')->index();
            $table->unsignedBigInteger('room_id')->nullable()->index();
            $table->unsignedBigInteger('reservation_room_id')->nullable()->index();
            $table->unsignedBigInteger('key_card_id')->nullable()->index();
            $table->string('event_type', 30);                      // unlock|lock|alarm|low_battery|tamper|forced_entry|remote_unlock|key_issued|key_cancelled
            $table->string('method', 20)->nullable();              // card|fingerprint|password|remote|mobile|master_key|auto
            $table->string('user_name', 80)->nullable();            // who opened it (from device)
            $table->string('user_id_ext', 64)->nullable();          // external user ID from Robita cloud
            $table->string('card_serial', 64)->nullable();
            $table->string('card_no', 30)->nullable();
            $table->text('payload', 65535)->nullable();
            $table->boolean('is_alarm')->default(false);
            $table->string('alarm_type', 40)->nullable();           // low_battery|tamper|forced_entry|door_ajar|timeout
            $table->timestamp('event_at');                          // time on the device
            $table->timestamp('received_at')->nullable();
            $table->string('source', 20)->default('webhook');       // webhook|poll|manual
            $table->timestamps();

            $table->index(['hotel_id', 'event_type']);
            $table->index(['robita_device_id', 'event_at']);
            $table->index('event_at');
        });

        Schema::create('robita_credentials', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hotel_id')->index();
            $table->unsignedBigInteger('robita_device_id')->index();
            $table->string('credential_type', 20);                  // card|password|fingerprint
            $table->string('credential_id', 128);                   // Robita cloud credential ID
            $table->string('credential_name', 80);                  // e.g. "Sarah Mensah – Room 101"
            $table->unsignedBigInteger('reservation_room_id')->nullable()->index();
            $table->unsignedBigInteger('key_card_id')->nullable()->index();
            $table->string('card_serial', 64)->nullable();
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->string('status', 20)->default('active');        // active|expired|cancelled
            $table->timestamps();

            $table->index(['hotel_id', 'status']);
            $table->index(['robita_device_id', 'status']);
            $table->index('reservation_room_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('robita_credentials');
        Schema::dropIfExists('robita_events');
        Schema::dropIfExists('robita_devices');
    }
};
