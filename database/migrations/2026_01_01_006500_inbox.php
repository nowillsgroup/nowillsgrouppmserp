<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v7.3 Guest messaging inbox – one two-way conversation per guest and channel (guest-portal chat, WhatsApp, SMS, e-mail),
 * shared by the desk: assignment, departments, internal notes, quick replies, keyword routing & auto-replies,
 * response-time targets and broadcasts to in-house guests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_rules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('name', 80);
            $t->string('keywords', 400);                          // comma-separated words / phrases, matched on whole words
            $t->string('department', 20)->nullable();             // route to this team
            $t->boolean('urgent')->default(false);                // raise priority
            $t->string('request_category', 20)->nullable();       // raise a guest request for an in-house guest …
            $t->string('request_item', 60)->nullable();           // … with this item
            $t->text('reply')->nullable();                        // automatic answer (template with {guest}, {room}, {wifi} …)
            $t->unsignedSmallInteger('sort')->default(10);
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('hits')->default(0);
            $t->timestamp('last_hit_at')->nullable();
            $t->timestamps();
            $t->index(['hotel_id', 'is_active', 'sort']);
        });

        Schema::create('inbox_quick_replies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('shortcut', 30);                           // typed as /wifi in the composer
            $t->string('title', 80);
            $t->text('body');
            $t->string('department', 20)->nullable();
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('uses')->default(0);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'shortcut']);
        });

        Schema::create('inbox_broadcasts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('number', 20);
            $t->string('title', 120);
            $t->text('body');
            $t->string('audience', 20);                           // inhouse, arrivals, departures, floor
            $t->foreignId('floor_id')->nullable()->constrained()->nullOnDelete();
            $t->string('channel', 12);                            // portal, whatsapp, sms, best
            $t->unsignedInteger('recipients')->default(0);
            $t->unsignedInteger('sent')->default(0);
            $t->unsignedInteger('failed')->default(0);
            $t->unsignedInteger('skipped')->default(0);
            $t->json('skipped_reasons')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['hotel_id', 'created_at']);
        });

        Schema::create('conversations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('number', 20);
            $t->string('channel', 12);                            // portal, whatsapp, sms, email
            $t->string('handle', 190);                            // +233… / e-mail / stay:<id>
            $t->string('contact_name', 120)->nullable();
            $t->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('reservation_room_id')->nullable()->constrained()->nullOnDelete();
            $t->string('status', 12)->default('open');            // open, pending (waiting for the guest), snoozed, closed
            $t->string('department', 20)->default('front_desk');
            $t->string('priority', 8)->default('normal');         // normal, high
            $t->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->string('subject', 190)->nullable();
            $t->unsignedSmallInteger('unread')->default(0);
            $t->timestamp('awaiting_since')->nullable();          // oldest guest message nobody has answered yet (response-time clock)
            $t->timestamp('last_message_at')->nullable();
            $t->timestamp('last_inbound_at')->nullable();
            $t->timestamp('opened_at')->nullable();               // start of the current cycle
            $t->timestamp('snoozed_until')->nullable();
            $t->timestamp('after_hours_sent_at')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->boolean('csat_asked')->default(false);
            $t->unsignedTinyInteger('csat')->nullable();          // 1–5, the guest's rating of the conversation
            $t->timestamp('opted_out_at')->nullable();            // replied STOP – no broadcasts on this number
            $t->timestamps();
            $t->index(['hotel_id', 'status', 'last_message_at']);
            $t->index(['hotel_id', 'channel', 'handle']);
            $t->index(['reservation_room_id']);
        });

        Schema::create('conversation_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $t->string('direction', 8);                           // in, out, note (staff only), system
            $t->text('body');
            $t->string('channel', 12);
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('status', 12)->default('received');        // received, sent, delivered, read, failed
            $t->string('provider_id', 100)->nullable();
            $t->string('error', 300)->nullable();
            $t->boolean('auto')->default(false);                  // automatic reply (rule / after hours)
            $t->foreignId('rule_id')->nullable()->constrained('inbox_rules')->nullOnDelete();
            $t->foreignId('quick_reply_id')->nullable()->constrained('inbox_quick_replies')->nullOnDelete();
            $t->foreignId('broadcast_id')->nullable()->constrained('inbox_broadcasts')->nullOnDelete();
            $t->unsignedInteger('response_sec')->nullable();      // first staff answer after the guest wrote
            $t->timestamp('read_at')->nullable();                 // guest has seen it (portal)
            $t->timestamps();
            $t->index(['conversation_id', 'id']);
            $t->index(['hotel_id', 'created_at']);
            $t->index(['provider_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('inbox_broadcasts');
        Schema::dropIfExists('inbox_quick_replies');
        Schema::dropIfExists('inbox_rules');
    }
};

