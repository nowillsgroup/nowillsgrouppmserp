<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** v6.1 Sales & marketing – the marketing plan: calendar of trade shows / roadshows / fam trips…, budget by line, collateral library. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('number', 20);                                          // MKT-00001
            $t->string('name', 150);
            $t->string('type', 20);                                            // trade_show | roadshow | sales_blitz | fam_trip | sponsorship | advertising | pr | hosted_event | direct_mail | other
            $t->string('segment', 20)->nullable();
            $t->string('market', 20)->nullable();
            $t->string('location', 150)->nullable();
            $t->date('start_date');
            $t->date('end_date');
            $t->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('status', 10)->default('planned');                      // planned | confirmed | done | cancelled
            $t->decimal('budget', 14, 2)->default(0);
            $t->decimal('actual_cost', 14, 2)->default(0);
            $t->unsignedInteger('target_leads')->default(0);
            $t->unsignedInteger('leads')->default(0);
            $t->text('objectives')->nullable();
            $t->text('results')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'number']);
            $t->index(['hotel_id', 'start_date']);
        });
        Schema::create('marketing_budgets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('year');
            $t->unsignedTinyInteger('month');
            $t->string('line', 20);                                            // an event type, or digital / entertainment
            $t->decimal('amount', 14, 2)->default(0);
            $t->timestamps();
            $t->unique(['hotel_id', 'year', 'month', 'line']);
        });
        Schema::create('marketing_collateral', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('title', 150);
            $t->string('kind', 20);                                            // brochure | rate_card | fact_sheet | presentation | menu | photo | video | template | other
            $t->string('language', 10)->default('en');
            $t->string('version', 20)->nullable();
            $t->date('valid_until')->nullable();
            $t->string('file_path', 255)->nullable();
            $t->string('file_name', 190)->nullable();
            $t->unsignedInteger('file_size')->nullable();
            $t->string('url', 255)->nullable();
            $t->string('notes', 500)->nullable();
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('downloads')->default(0);
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        Schema::table('sales_opportunities', function (Blueprint $t) {
            $t->foreignId('marketing_event_id')->nullable()->after('source')->constrained()->nullOnDelete(); // lead source attribution → marketing ROI
        });
    }

    public function down(): void
    {
        Schema::table('sales_opportunities', fn (Blueprint $t) => $t->dropConstrainedForeignId('marketing_event_id'));
        Schema::dropIfExists('marketing_collateral');
        Schema::dropIfExists('marketing_budgets');
        Schema::dropIfExists('marketing_events');
    }
};

