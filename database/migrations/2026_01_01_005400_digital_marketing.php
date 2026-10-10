<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v6.2 Digital marketing: social / listing / ad channels with monthly KPI snapshots and listing-health audits,
 * a social content calendar with four-eyes approval, paid-media campaigns with daily spend (manual or CSV import),
 * UTM-tagged tracked links with click logging and booking attribution, and influencer collaborations.
 * Guest e-mail/SMS campaigns, segments, journeys, promo codes and online reviews stay in CRM / Feedback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dm_channels', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('name', 100);
            $t->string('platform', 20);                                        // facebook | instagram | … | google_business | tripadvisor | google_ads | meta_ads …
            $t->string('handle', 100)->nullable();
            $t->string('url', 255)->nullable();
            $t->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('followers')->default(0);                      // latest snapshot
            $t->decimal('rating', 4, 2)->nullable();                           // listings: star / score rating
            $t->unsignedInteger('reviews')->nullable();
            $t->json('health')->nullable();                                    // listings: checklist item => bool
            $t->dateTime('audited_at')->nullable();
            $t->foreignId('audited_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'name']);
        });
        Schema::create('dm_channel_stats', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('channel_id')->constrained('dm_channels')->cascadeOnDelete();
            $t->date('month');                                                 // first day of the month
            $t->unsignedInteger('followers')->default(0);
            $t->unsignedInteger('reach')->default(0);
            $t->unsignedInteger('impressions')->default(0);
            $t->unsignedInteger('engagements')->default(0);
            $t->unsignedInteger('clicks')->default(0);                         // website / profile clicks
            $t->timestamps();
            $t->unique(['channel_id', 'month']);
        });
        Schema::create('dm_campaigns', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('number', 20);                                          // DMC-00001
            $t->string('name', 150);
            $t->string('objective', 12);                                       // awareness | traffic | engagement | leads | bookings
            $t->foreignId('channel_id')->nullable()->constrained('dm_channels')->nullOnDelete();
            $t->string('utm_campaign', 60);
            $t->foreignId('promo_code_id')->nullable()->constrained()->nullOnDelete();
            $t->date('start_date');
            $t->date('end_date')->nullable();
            $t->decimal('budget', 14, 2)->default(0);
            $t->decimal('target_roas', 6, 2)->nullable();
            $t->string('status', 10)->default('draft');                        // draft | active | paused | ended
            $t->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'utm_campaign']);
            $t->index(['hotel_id', 'status']);
        });
        Schema::create('dm_ad_stats', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('campaign_id')->constrained('dm_campaigns')->cascadeOnDelete();
            $t->date('date');
            $t->unsignedInteger('impressions')->default(0);
            $t->unsignedInteger('clicks')->default(0);
            $t->decimal('spend', 12, 2)->default(0);
            $t->unsignedInteger('conversions')->default(0);
            $t->decimal('conversion_value', 14, 2)->default(0);                // as reported by the ad platform
            $t->string('source', 6)->default('manual');                        // manual | csv
            $t->timestamps();
            $t->unique(['campaign_id', 'date']);
            $t->index(['hotel_id', 'date']);
        });
        Schema::create('dm_links', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('code', 40)->unique();                                  // /go/{code} – global so the public redirect needs no tenant
            $t->string('name', 120);
            $t->string('destination', 500);
            $t->string('utm_source', 60);
            $t->string('utm_medium', 60);
            $t->string('utm_campaign', 60);
            $t->string('utm_content', 60)->nullable();
            $t->foreignId('campaign_id')->nullable()->constrained('dm_campaigns')->nullOnDelete();
            $t->unsignedInteger('clicks')->default(0);
            $t->dateTime('last_click_at')->nullable();
            $t->boolean('is_active')->default(true);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        Schema::create('dm_link_clicks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('link_id')->constrained('dm_links')->cascadeOnDelete();
            $t->dateTime('clicked_at');
            $t->string('device', 8);                                           // mobile | tablet | desktop
            $t->string('referrer_host', 100)->nullable();
            $t->string('ip_hash', 64)->nullable();                             // keyed hash – no raw IP is stored
            $t->index(['link_id', 'clicked_at']);
            $t->index(['hotel_id', 'clicked_at']);
        });
        Schema::create('dm_posts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('title', 150);
            $t->text('caption');
            $t->json('channels');                                              // dm_channel ids
            $t->foreignId('campaign_id')->nullable()->constrained('dm_campaigns')->nullOnDelete();
            $t->foreignId('link_id')->nullable()->constrained('dm_links')->nullOnDelete();
            $t->string('media_url', 500)->nullable();
            $t->string('pillar', 12)->nullable();                              // content pillar
            $t->dateTime('scheduled_at');
            $t->string('status', 10)->default('draft');                        // draft | review | approved | rejected | published | cancelled
            $t->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('submitted_at')->nullable();
            $t->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('reviewed_at')->nullable();
            $t->string('review_note', 500)->nullable();
            $t->dateTime('published_at')->nullable();
            $t->string('post_url', 500)->nullable();
            $t->unsignedInteger('reach')->default(0);
            $t->unsignedInteger('engagements')->default(0);
            $t->unsignedInteger('clicks')->default(0);
            $t->timestamps();
            $t->index(['hotel_id', 'scheduled_at']);
            $t->index(['hotel_id', 'status']);
        });
        Schema::create('dm_influencers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('number', 20);                                          // INF-00001
            $t->string('name', 120);
            $t->string('platform', 20);
            $t->string('handle', 100);
            $t->unsignedInteger('followers')->default(0);
            $t->string('niche', 60)->nullable();
            $t->string('email', 150)->nullable();
            $t->string('phone', 30)->nullable();
            $t->date('stay_from')->nullable();
            $t->date('stay_to')->nullable();
            $t->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $t->text('deliverables')->nullable();
            $t->date('due_date')->nullable();                                  // content due
            $t->decimal('fee', 12, 2)->default(0);                             // cash fee
            $t->decimal('comp_value', 12, 2)->default(0);                      // value of the complimentary stay / F&B
            $t->string('status', 10)->default('proposed');                     // proposed | agreed | hosted | delivered | cancelled
            $t->text('content_urls')->nullable();                              // one per line
            $t->unsignedInteger('reach')->default(0);
            $t->unsignedInteger('engagements')->default(0);
            $t->unsignedTinyInteger('rating')->nullable();                     // 1–5 post-collaboration rating
            $t->foreignId('link_id')->nullable()->constrained('dm_links')->nullOnDelete();
            $t->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $t->text('notes')->nullable();
            $t->string('cancel_reason', 255)->nullable();
            $t->timestamps();
            $t->index(['hotel_id', 'status']);
        });
        Schema::table('reservations', function (Blueprint $t) {
            $t->foreignId('dm_link_id')->nullable()->constrained('dm_links')->nullOnDelete(); // v6.2 booked after a tracked-link click
        });
    }

    public function down(): void
    {
        Schema::table('reservations', fn (Blueprint $t) => $t->dropConstrainedForeignId('dm_link_id'));
        foreach (['dm_influencers', 'dm_posts', 'dm_link_clicks', 'dm_links', 'dm_ad_stats', 'dm_campaigns', 'dm_channel_stats', 'dm_channels'] as $x) {
            Schema::dropIfExists($x);
        }
    }
};

