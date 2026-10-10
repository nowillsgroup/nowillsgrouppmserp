<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v6.3 Business development: the competitive set (competitor profiles, rate shops against our own BAR, monthly
 * STR-style benchmarks → MPI / ARI / RGI), strategic partnerships with production tracking, and new-business
 * initiatives through a stage gate with a business case (payback / NPV / ROI), milestones and monthly results.
 * Account-level selling (accounts, contacts, opportunities, diary) stays in Sales (v6.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bd_competitors', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('name', 120);
            $t->unsignedTinyInteger('stars')->nullable();
            $t->unsignedInteger('rooms')->nullable();
            $t->string('positioning', 20)->nullable();                  // luxury | upscale | midscale | economy | apartments
            $t->string('location', 120)->nullable();
            $t->decimal('distance_km', 5, 1)->nullable();
            $t->string('website', 255)->nullable();
            $t->text('strengths')->nullable();
            $t->text('weaknesses')->nullable();
            $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
            $t->unique(['hotel_id', 'name']);
        });
        Schema::create('bd_rate_shops', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->date('stay_date');
            $t->string('channel', 20);                                   // brand_site | booking_com | expedia | phone
            $t->decimal('own_rate', 12, 2)->nullable();
            $t->boolean('own_sold_out')->default(false);
            $t->date('shopped_on');
            $t->foreignId('shopped_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('note', 255)->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'stay_date', 'channel']);
        });
        Schema::create('bd_rate_shop_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('shop_id')->constrained('bd_rate_shops')->cascadeOnDelete();
            $t->foreignId('competitor_id')->constrained('bd_competitors')->cascadeOnDelete();
            $t->decimal('rate', 12, 2)->nullable();
            $t->boolean('sold_out')->default(false);
            $t->unique(['shop_id', 'competitor_id']);
        });
        Schema::create('bd_benchmarks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->date('month');                                           // first day of the month
            $t->decimal('comp_occupancy', 5, 2);
            $t->decimal('comp_adr', 12, 2);
            $t->decimal('own_occupancy', 5, 2)->nullable();              // "My Property" line of the STR report – overrides the PMS figures when given
            $t->decimal('own_adr', 12, 2)->nullable();
            $t->string('source', 20)->default('manual');                 // str | manual | estimate
            $t->string('note', 255)->nullable();
            $t->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'month']);
        });
        Schema::create('bd_partners', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('number', 20);
            $t->string('name', 150);
            $t->string('type', 20);                                      // airline | tour_operator | embassy | corporate | …
            $t->string('agreement', 20);                                 // referral | commission | co_marketing | barter | preferred | mou
            $t->string('status', 12)->default('prospect');               // prospect | negotiating | active | expired | terminated
            $t->string('contact_name', 100)->nullable();
            $t->string('email', 150)->nullable();
            $t->string('phone', 30)->nullable();
            $t->text('terms')->nullable();
            $t->decimal('commission_pct', 5, 2)->nullable();
            $t->foreignId('business_source_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('promo_code_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->date('review_date')->nullable();
            $t->unsignedInteger('target_nights')->nullable();            // per year
            $t->decimal('target_revenue', 14, 2)->nullable();
            $t->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('end_reason', 255)->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'number']);
            $t->index(['hotel_id', 'status']);
        });
        Schema::create('bd_initiatives', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('number', 20);
            $t->string('name', 150);
            $t->string('category', 20);                                  // new_segment | new_market | revenue_stream | product | partnership | expansion
            $t->string('target_market', 120)->nullable();
            $t->text('description')->nullable();
            $t->string('stage', 16)->default('idea');                    // idea | assessment | business_case | approved | implementation | live | closed | rejected
            $t->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('partner_id')->nullable()->constrained('bd_partners')->nullOnDelete();
            $t->decimal('investment', 14, 2)->default(0);
            $t->decimal('annual_revenue', 14, 2)->default(0);
            $t->decimal('annual_cost', 14, 2)->default(0);
            $t->unsignedTinyInteger('horizon_years')->default(3);
            $t->date('launch_date')->nullable();                         // target
            $t->date('live_date')->nullable();                           // actual
            $t->dateTime('submitted_at')->nullable();
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('decided_at')->nullable();
            $t->string('decision_note', 500)->nullable();
            $t->string('close_reason', 255)->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'number']);
            $t->index(['hotel_id', 'stage']);
        });
        Schema::create('bd_milestones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('initiative_id')->constrained('bd_initiatives')->cascadeOnDelete();
            $t->string('title', 150);
            $t->date('due_date');
            $t->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('done_at')->nullable();
            $t->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        Schema::create('bd_results', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('initiative_id')->constrained('bd_initiatives')->cascadeOnDelete();
            $t->date('month');
            $t->decimal('revenue', 14, 2)->default(0);
            $t->decimal('cost', 14, 2)->default(0);
            $t->string('note', 255)->nullable();
            $t->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['initiative_id', 'month']);
        });
    }

    public function down(): void
    {
        foreach (['bd_results', 'bd_milestones', 'bd_initiatives', 'bd_partners', 'bd_benchmarks', 'bd_rate_shop_lines', 'bd_rate_shops', 'bd_competitors'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};

