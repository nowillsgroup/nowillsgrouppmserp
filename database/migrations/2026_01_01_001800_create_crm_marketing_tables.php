<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.6 guest marketing & CRM: per-channel marketing consent with an audit trail, guest tags and value statistics,
 * rule-based segments, one-off campaigns, automated journeys (birthday, anniversary, pre-arrival, post-stay,
 * win-back), a delivery log per recipient (click tracking, unsubscribe, booking attribution) and booking-engine
 * promo codes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guests', function (Blueprint $t) {
            $t->boolean('email_opt_in')->default(false);
            $t->boolean('sms_opt_in')->default(false);
            $t->boolean('whatsapp_opt_in')->default(false);
            $t->timestamp('consent_at')->nullable();
            $t->string('consent_source', 30)->nullable();
            $t->string('tags', 255)->nullable();                 // comma separated, lower-case: "vip,corporate"
            $t->unsignedInteger('stays_count')->default(0);      // checked-out reservations
            $t->unsignedInteger('nights_count')->default(0);
            $t->decimal('total_revenue', 14, 2)->default(0);     // net charges on the guest's folios
            $t->date('first_stay_on')->nullable();
            $t->date('last_stay_on')->nullable();
            $t->timestamp('stats_at')->nullable();
        });

                if (Schema::hasTable('marketing_consent_logs')) {
            return;
        }

        Schema::create('marketing_consent_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('guest_id')->constrained()->cascadeOnDelete();
            $t->string('channel', 10);                 // email, sms, whatsapp
            $t->boolean('opted_in');
            $t->string('source', 30);                  // front_desk, booking_engine, precheckin, unsubscribe, import, seed
            $t->string('ip', 45)->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['hotel_id', 'guest_id']);
        });

                if (Schema::hasTable('marketing_segments')) {
            return;
        }

        Schema::create('marketing_segments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('name', 120);
            $t->string('description', 255)->nullable();
            $t->string('match', 3)->default('all');    // all | any
            $t->json('rules')->nullable();              // [{field, op, value}]
            $t->boolean('is_active')->default(true);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

                if (Schema::hasTable('promo_codes')) {
            return;
        }

        Schema::create('promo_codes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('code', 30);
            $t->string('name', 120);
            $t->string('discount_type', 10)->default('percent'); // percent | amount (per room-night)
            $t->decimal('value', 12, 2);
            $t->date('book_from')->nullable();          // booking window (business date the booking is made)
            $t->date('book_to')->nullable();
            $t->date('stay_from')->nullable();          // arrival must fall in the stay window
            $t->date('stay_to')->nullable();
            $t->unsignedSmallInteger('min_nights')->default(1);
            $t->unsignedInteger('max_uses')->nullable();
            $t->unsignedSmallInteger('per_guest_limit')->nullable();
            $t->json('room_type_ids')->nullable();      // null = all room types
            $t->boolean('is_active')->default(true);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'code']);
        });

                if (Schema::hasTable('marketing_campaigns')) {
            return;
        }

        Schema::create('marketing_campaigns', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('name', 150);
            $t->string('channel', 10);                  // email, sms, whatsapp
            $t->foreignId('segment_id')->nullable()->constrained('marketing_segments')->nullOnDelete(); // null = every opted-in guest
            $t->string('subject', 200)->nullable();
            $t->text('body');
            $t->string('cta_label', 60)->nullable();
            $t->foreignId('promo_code_id')->nullable()->constrained()->nullOnDelete();
            $t->string('status', 12)->default('draft'); // draft, scheduled, sending, sent, cancelled
            $t->timestamp('scheduled_at')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->unsignedInteger('recipients_count')->default(0);
            $t->unsignedInteger('sent_count')->default(0);
            $t->unsignedInteger('failed_count')->default(0);
            $t->unsignedInteger('skipped_count')->default(0);
            $t->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['hotel_id', 'status', 'scheduled_at']);
        });

                if (Schema::hasTable('marketing_journeys')) {
            return;
        }

        Schema::create('marketing_journeys', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('name', 150);
            $t->string('trigger', 20);                  // birthday, anniversary, pre_arrival, post_stay, win_back
            $t->smallInteger('offset_days')->default(0);
            $t->string('channel', 10);
            $t->foreignId('segment_id')->nullable()->constrained('marketing_segments')->nullOnDelete(); // optional extra filter
            $t->string('subject', 200)->nullable();
            $t->text('body');
            $t->string('cta_label', 60)->nullable();
            $t->foreignId('promo_code_id')->nullable()->constrained()->nullOnDelete();
            $t->boolean('is_active')->default(false);
            $t->timestamp('last_run_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

                if (Schema::hasTable('marketing_messages')) {
            return;
        }

        Schema::create('marketing_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('campaign_id')->nullable()->constrained('marketing_campaigns')->cascadeOnDelete();
            $t->foreignId('journey_id')->nullable()->constrained('marketing_journeys')->cascadeOnDelete();
            $t->foreignId('guest_id')->constrained()->cascadeOnDelete();
            $t->string('channel', 10);
            $t->string('to', 150)->nullable();
            $t->string('status', 10);                   // sent, failed, skipped
            $t->string('skip_reason', 60)->nullable();
            $t->string('occurrence', 40)->nullable();   // journeys: "2026" (birthday year), "R123" (reservation), date (win-back)
            $t->string('token', 40)->unique();          // click-tracking / unsubscribe links
            $t->foreignId('email_log_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('message_log_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('clicked_at')->nullable();
            $t->unsignedInteger('click_count')->default(0);
            $t->timestamp('unsubscribed_at')->nullable();
            $t->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete(); // first booking attributed
            $t->timestamps();
            $t->unique(['campaign_id', 'guest_id']);
            $t->unique(['journey_id', 'guest_id', 'occurrence']);
            $t->index(['hotel_id', 'guest_id', 'created_at']);
        });

        Schema::table('reservations', function (Blueprint $t) {
            $t->foreignId('promo_code_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('promo_discount', 12, 2)->default(0); // total discount over the stay
            $t->foreignId('marketing_message_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('campaign_id')->nullable()->constrained('marketing_campaigns')->nullOnDelete();
            $t->foreignId('journey_id')->nullable()->constrained('marketing_journeys')->nullOnDelete();
            $t->string('attribution', 12)->nullable();        // promo, click, influenced
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $t) {
            foreach (['promo_code_id', 'marketing_message_id', 'campaign_id', 'journey_id'] as $c) {
                $t->dropConstrainedForeignId($c);
            }
            $t->dropColumn(['promo_discount', 'attribution']);
        });
        Schema::dropIfExists('marketing_messages');
        Schema::dropIfExists('marketing_journeys');
        Schema::dropIfExists('marketing_campaigns');
        Schema::dropIfExists('promo_codes');
        Schema::dropIfExists('marketing_segments');
        Schema::dropIfExists('marketing_consent_logs');
        Schema::table('guests', function (Blueprint $t) {
            $t->dropColumn(['email_opt_in', 'sms_opt_in', 'whatsapp_opt_in', 'consent_at', 'consent_source', 'tags', 'stays_count', 'nights_count',
                'total_revenue', 'first_stay_on', 'last_stay_on', 'stats_at']);
        });
    }
};

