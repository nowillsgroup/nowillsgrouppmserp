<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.8 two-way channel manager (OTA connectivity). Sits alongside the v1.2 iCal feeds:
 *  - connections to OTAs / a channel hub with HMAC-signed JSON messages, commission, rate markup (parity), collect model;
 *  - room-type & rate-plan mappings to the channel's room / rate codes, per-channel inventory caps;
 *  - per-channel restrictions (stop-sell, min stay, closed-to-arrival);
 *  - ARI (availability / rates / inventory) push: dirty dates queue, per-connection watermark, retry with back-off,
 *    push log and the last state acknowledged by each channel (used for the parity view);
 *  - inbound reservations (new / modify / cancel) through a signed webhook, idempotent, with a needs-attention inbox.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_connections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('name', 80);
            $t->string('code', 20);                                   // BDC, EXP, AGO…
            $t->string('provider', 12)->default('hub');               // hub (HTTPS JSON endpoint), simulator (built-in test OTA)
            $t->foreignId('business_source_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('company_id')->nullable()->constrained()->nullOnDelete();  // OTA account: commission + channel-collect billing
            $t->string('property_code', 40)->nullable();              // our hotel id at the channel
            $t->string('endpoint_url', 500)->nullable();              // ARI push URL (hub provider)
            $t->text('api_key')->nullable();                          // encrypted – bearer token for our pushes
            $t->char('token', 40)->unique();                          // inbound webhook path token
            $t->text('secret');                                       // encrypted – shared HMAC secret (both directions)
            $t->decimal('commission_pct', 5, 2)->default(0);
            $t->decimal('rate_markup_pct', 6, 2)->default(0);         // channel price = PMS price × (1 + markup %)
            $t->string('rate_rounding', 6)->default('none');          // none, unit, ten
            $t->string('payment_model', 16)->default('hotel_collect'); // hotel_collect (guest pays at hotel), channel_collect (OTA prepaid, bill the OTA)
            $t->unsignedSmallInteger('push_days')->default(365);
            $t->boolean('is_active')->default(true);
            $t->unsignedBigInteger('pushed_upto_id')->default(0);     // watermark into channel_dirty
            $t->boolean('full_sync_due')->default(true);
            $t->timestamp('last_push_at')->nullable();
            $t->boolean('last_push_ok')->nullable();
            $t->string('last_push_status', 255)->nullable();
            $t->unsignedSmallInteger('fail_count')->default(0);
            $t->timestamp('next_retry_at')->nullable();
            $t->timestamp('last_booking_at')->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'code']);
        });

        Schema::create('channel_room_maps', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('channel_connection_id')->constrained()->cascadeOnDelete();
            $t->foreignId('room_type_id')->constrained()->cascadeOnDelete();
            $t->string('channel_room_code', 40);
            $t->unsignedSmallInteger('max_sell')->nullable();         // cap on rooms offered to this channel per night
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['channel_connection_id', 'room_type_id']);
            $t->unique(['channel_connection_id', 'channel_room_code']);
        });

        Schema::create('channel_rate_maps', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('channel_connection_id')->constrained()->cascadeOnDelete();
            $t->foreignId('room_type_id')->constrained()->cascadeOnDelete();
            $t->foreignId('rate_plan_id')->nullable()->constrained()->nullOnDelete(); // null = room only (best available rate)
            $t->string('channel_rate_code', 40);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['channel_connection_id', 'room_type_id', 'channel_rate_code'], 'chan_rate_code_unique');
        });

        Schema::create('channel_restrictions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('channel_connection_id')->constrained()->cascadeOnDelete();
            $t->foreignId('room_type_id')->nullable()->constrained()->cascadeOnDelete(); // null = every mapped room
            $t->date('date_from');
            $t->date('date_to');                                      // inclusive
            $t->boolean('stop_sell')->default(false);
            $t->unsignedTinyInteger('min_stay')->nullable();
            $t->boolean('closed_to_arrival')->default(false);
            $t->string('reason', 150)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['channel_connection_id', 'date_from']);
        });

        Schema::create('channel_dirty', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('room_type_id');
            $t->date('date');
            $t->timestamp('created_at')->nullable();
            $t->index(['hotel_id', 'id']);
        });

        Schema::create('channel_pushes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('channel_connection_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 8);                                    // delta, full
            $t->date('date_from')->nullable();
            $t->date('date_to')->nullable();
            $t->unsignedInteger('room_days')->default(0);
            $t->unsignedSmallInteger('requests')->default(0);
            $t->string('status', 10);                                 // ok, failed
            $t->unsignedSmallInteger('http_status')->nullable();
            $t->string('error', 500)->nullable();
            $t->unsignedInteger('duration_ms')->default(0);
            $t->unsignedSmallInteger('attempt')->default(1);
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->index(['channel_connection_id', 'id']);
        });

        Schema::create('channel_ari_states', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('channel_connection_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('room_type_id');
            $t->date('date');
            $t->smallInteger('available');
            $t->boolean('stop_sell')->default(false);
            $t->unsignedTinyInteger('min_stay')->default(1);
            $t->boolean('cta')->default(false);
            $t->json('rates')->nullable();                            // {rate_code: price}
            $t->timestamp('pushed_at')->nullable();
            $t->unique(['channel_connection_id', 'room_type_id', 'date'], 'chan_ari_unique');
        });

        Schema::create('channel_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('channel_connection_id')->constrained()->cascadeOnDelete();
            $t->string('channel_ref', 60);
            $t->string('event', 10);                                  // new, modify, cancel
            $t->char('payload_hash', 64);
            $t->json('payload');                                      // sanitised (card data stripped)
            $t->string('status', 12)->default('received');            // processed, attention, failed, dismissed
            $t->string('attention', 30)->nullable();                  // unmapped_room, overbooked, modify_inhouse, cancel_inhouse, cancelled_booking
            $t->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('amount', 12, 2)->nullable();
            $t->string('error', 500)->nullable();
            $t->json('warnings')->nullable();
            $t->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('resolved_at')->nullable();
            $t->string('resolution', 255)->nullable();
            $t->timestamps();
            $t->unique(['channel_connection_id', 'payload_hash']);
            $t->index(['channel_connection_id', 'channel_ref']);
            $t->index(['hotel_id', 'status']);
        });

        Schema::table('reservations', function (Blueprint $t) {
            $t->foreignId('channel_connection_id')->nullable()->after('ota_uid')->constrained()->nullOnDelete();
            $t->string('channel_payment', 16)->nullable()->after('channel_connection_id');     // hotel_collect, channel_collect
            $t->decimal('channel_amount', 12, 2)->nullable()->after('channel_payment');         // gross total sent by the OTA
            $t->decimal('channel_commission', 12, 2)->nullable()->after('channel_amount');
            $t->string('channel_attention', 30)->nullable()->after('channel_commission');       // overbooked (until resolved)
            $t->index(['channel_connection_id', 'ota_reference']);
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $t) {
            $t->dropIndex(['channel_connection_id', 'ota_reference']);
            $t->dropConstrainedForeignId('channel_connection_id');
            $t->dropColumn(['channel_payment', 'channel_amount', 'channel_commission', 'channel_attention']);
        });
        foreach (['channel_messages', 'channel_ari_states', 'channel_pushes', 'channel_dirty', 'channel_restrictions', 'channel_rate_maps', 'channel_room_maps', 'channel_connections'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};

