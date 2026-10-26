<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v7.1 IPTV & guest welcome: in-room TVs and lobby screens (paired by code, heartbeat), the channel line-up with its
 * programme guide, personalised welcome screens, hotel information pages, messages / broadcasts / emergency alerts with
 * read receipts, pay movies posted to the folio, remote commands, per-stay TV sessions and usage analytics.
 */
return new class extends Migration
{
    public function up(): void
    {
                if (Schema::hasTable('tv_devices')) {
            return;
        }

        Schema::create('tv_devices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('name', 80);
            $t->string('kind', 10)->default('room');          // room, signage
            $t->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $t->string('location', 80)->nullable();            // signage / public screens: Lobby, Restaurant entrance …
            $t->string('platform', 12)->default('other');      // samsung, lg, android, philips, stb, browser, other
            $t->string('model', 60)->nullable();
            $t->string('mac', 17)->nullable();
            $t->string('serial', 60)->nullable();
            $t->string('token', 40)->unique();                 // secret in the TV's start URL
            $t->string('status', 10)->default('active');       // pending (awaiting pairing), active, disabled
            $t->string('pairing_code', 6)->nullable();
            $t->timestamp('pairing_expires_at')->nullable();
            $t->timestamp('paired_at')->nullable();
            $t->foreignId('paired_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('last_seen_at')->nullable();
            $t->string('last_ip', 45)->nullable();
            $t->string('user_agent', 190)->nullable();
            $t->string('notes', 255)->nullable();
            $t->timestamps();
            $t->index(['hotel_id', 'status']);
            $t->index(['hotel_id', 'room_id']);
            $t->index(['pairing_code', 'status']);
        });

                if (Schema::hasTable('tv_channels')) {
            return;
        }

        Schema::create('tv_channels', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('number');
            $t->string('name', 80);
            $t->string('category', 14)->default('entertainment');
            $t->string('language', 30)->nullable();
            $t->string('stream_url', 255);
            $t->string('epg_id', 80)->nullable();              // XMLTV channel id
            $t->boolean('is_radio')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['hotel_id', 'number']);
        });

                if (Schema::hasTable('tv_programmes')) {
            return;
        }

        Schema::create('tv_programmes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('tv_channel_id')->constrained()->cascadeOnDelete();
            $t->dateTime('starts_at');
            $t->dateTime('ends_at');
            $t->string('title', 150);
            $t->string('description', 500)->nullable();
            $t->string('genre', 60)->nullable();
            $t->index(['tv_channel_id', 'starts_at']);
            $t->index(['hotel_id', 'ends_at']);
        });

                if (Schema::hasTable('tv_welcome_templates')) {
            return;
        }

        Schema::create('tv_welcome_templates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('name', 80);
            $t->string('language', 5)->default('en');
            $t->string('segment', 12)->default('all');         // all, vip, repeat, corporate, group, long_stay, birthday
            $t->unsignedSmallInteger('priority')->default(0);
            $t->string('headline', 120);
            $t->text('body')->nullable();
            $t->string('theme', 12)->default('gold');          // background preset
            $t->date('valid_from')->nullable();
            $t->date('valid_to')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->index(['hotel_id', 'language', 'is_active']);
        });

                if (Schema::hasTable('tv_pages')) {
            return;
        }

        Schema::create('tv_pages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('title', 80);
            $t->string('category', 12)->default('info');       // dining, wellness, facilities, services, explore, offers, safety, info
            $t->string('icon', 16)->default('book');
            $t->string('language', 5)->default('en');
            $t->text('body');
            $t->unsignedSmallInteger('sort')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

                if (Schema::hasTable('tv_messages')) {
            return;
        }

        Schema::create('tv_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('number', 20);
            $t->string('kind', 10);                            // room, broadcast, emergency
            $t->string('target', 10);                          // room, floor, all, vip, group, signage
            $t->unsignedBigInteger('target_id')->nullable();    // room_id / floor_id / reservation_id
            $t->foreignId('reservation_room_id')->nullable()->constrained()->nullOnDelete();
            $t->string('title', 80);
            $t->string('body', 500);
            $t->string('priority', 6)->default('normal');      // normal, high
            $t->boolean('requires_ack')->default(false);
            $t->dateTime('starts_at');
            $t->dateTime('ends_at')->nullable();
            $t->string('status', 10)->default('active');       // active, cancelled, cleared (emergency)
            $t->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('closed_at')->nullable();
            $t->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('close_note', 190)->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'number']);
            $t->index(['hotel_id', 'status', 'starts_at']);
        });

                if (Schema::hasTable('tv_message_reads')) {
            return;
        }

        Schema::create('tv_message_reads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('tv_message_id')->constrained()->cascadeOnDelete();
            $t->foreignId('tv_device_id')->constrained()->cascadeOnDelete();
            $t->foreignId('reservation_room_id')->nullable()->constrained()->nullOnDelete();
            $t->dateTime('read_at');
            $t->unique(['tv_message_id', 'tv_device_id']);
        });

                if (Schema::hasTable('tv_movies')) {
            return;
        }

        Schema::create('tv_movies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('title', 120);
            $t->string('genre', 20);
            $t->string('rating', 6)->default('PG');            // G, PG, PG-13, 16, 18
            $t->unsignedSmallInteger('duration_min')->default(100);
            $t->unsignedSmallInteger('year')->nullable();
            $t->string('language', 30)->nullable();
            $t->string('synopsis', 500)->nullable();
            $t->decimal('price', 10, 2);                       // before VAT / levies
            $t->unsignedSmallInteger('rental_hours')->default(24);
            $t->string('stream_url', 255)->nullable();
            $t->boolean('is_adult')->default(false);
            $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
        });

                if (Schema::hasTable('tv_purchases')) {
            return;
        }

        Schema::create('tv_purchases', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('number', 20);
            $t->foreignId('tv_device_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('reservation_room_id')->constrained()->cascadeOnDelete();
            $t->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('tv_movie_id')->nullable()->constrained()->nullOnDelete();
            $t->string('title', 120);
            $t->decimal('amount', 10, 2);
            $t->decimal('tax', 10, 2)->default(0);
            $t->decimal('total', 10, 2);
            $t->dateTime('starts_at');
            $t->dateTime('expires_at');
            $t->string('status', 10)->default('active');       // active, refunded (expiry is derived from expires_at)
            $t->foreignId('folio_transaction_id')->nullable()->constrained()->nullOnDelete();
            $t->string('refund_reason', 190)->nullable();
            $t->foreignId('refunded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('refunded_at')->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'number']);
            $t->index(['hotel_id', 'starts_at']);
        });

                if (Schema::hasTable('tv_sessions')) {
            return;
        }

        Schema::create('tv_sessions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('tv_device_id')->constrained()->cascadeOnDelete();
            $t->foreignId('reservation_room_id')->constrained()->cascadeOnDelete();
            $t->foreignId('tv_welcome_template_id')->nullable()->constrained()->nullOnDelete();
            $t->string('language', 5)->nullable();
            $t->dateTime('started_at');
            $t->timestamp('welcomed_at')->nullable();
            $t->timestamp('ended_at')->nullable();
            $t->string('end_reason', 12)->nullable();          // checkout, room_move, reset, vacant
            $t->unsignedTinyInteger('pin_failures')->default(0);
            $t->timestamp('pin_locked_until')->nullable();
            $t->timestamps();
            $t->index(['tv_device_id', 'ended_at']);
            $t->index(['reservation_room_id']);
        });

                if (Schema::hasTable('tv_commands')) {
            return;
        }

        Schema::create('tv_commands', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('tv_device_id')->constrained()->cascadeOnDelete();
            $t->string('command', 12);                         // reload, reset, message, volume, power_off, power_on, tune
            $t->json('payload')->nullable();
            $t->string('status', 8)->default('queued');        // queued, sent, done, failed, expired
            $t->string('result', 190)->nullable();
            $t->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('done_at')->nullable();
            $t->timestamps();
            $t->index(['tv_device_id', 'status']);
        });

                if (Schema::hasTable('tv_events')) {
            return;
        }

        Schema::create('tv_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('tv_device_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('reservation_room_id')->nullable()->constrained()->nullOnDelete();
            $t->string('event', 12);
            $t->string('target', 120)->nullable();
            $t->timestamp('created_at')->nullable();
            $t->index(['hotel_id', 'created_at']);
            $t->index(['hotel_id', 'event']);
        });

        Schema::table('reservation_rooms', function (Blueprint $t) {
            $t->string('tv_pin', 4)->nullable();               // 4-digit PIN for TV purchases / the bill, given at check-in
        });
        Schema::table('guests', function (Blueprint $t) {
            $t->string('language', 5)->nullable();             // preferred language (TV / communications); remembered across stays
        });
    }

    public function down(): void
    {
        Schema::table('guests', fn (Blueprint $t) => $t->dropColumn('language'));
        Schema::table('reservation_rooms', fn (Blueprint $t) => $t->dropColumn('tv_pin'));
        foreach (['tv_events', 'tv_commands', 'tv_sessions', 'tv_purchases', 'tv_movies', 'tv_message_reads', 'tv_messages', 'tv_pages', 'tv_welcome_templates', 'tv_programmes', 'tv_channels', 'tv_devices'] as $tb) {
            Schema::dropIfExists($tb);
        }
    }
};

