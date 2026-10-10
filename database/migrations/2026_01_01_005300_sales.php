<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v6.1 Sales & account management: the companies / agents master becomes the sales account list (tier, sales status,
 * industry, potential, account manager), with contacts, a sales-activity diary (calls, visits, site inspections,
 * meetings, entertainment, tasks), an opportunity pipeline (stage, probability, expected close, room nights and revenue,
 * won / lost with reasons, linked to the rate contract, room block or banquet event it became) and monthly sales targets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->string('tier', 10)->default('standard')->after('type');          // key | major | standard
            $t->string('sales_status', 10)->default('active')->after('tier');    // prospect | active | dormant | lost
            $t->string('industry', 30)->nullable()->after('sales_status');
            $t->string('city', 80)->nullable()->after('address');
            $t->string('website', 150)->nullable()->after('city');
            $t->unsignedInteger('potential_nights')->nullable()->after('website'); // estimated room nights a year
            $t->text('sales_notes')->nullable()->after('potential_nights');
        });

        Schema::create('sales_contacts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('name', 120);
            $t->string('title', 120)->nullable();                               // job title
            $t->string('email', 150)->nullable();
            $t->string('phone', 30)->nullable();
            $t->boolean('is_primary')->default(false);
            $t->boolean('decision_maker')->default(false);
            $t->date('birthday')->nullable();
            $t->string('notes', 500)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->index(['hotel_id', 'company_id']);
        });

        Schema::create('sales_opportunities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('number', 20);                                          // OPP-00001
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('sales_contact_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('name', 150);
            $t->string('type', 16);                                            // corporate | agency | group | mice | social | long_stay
            $t->string('source', 16)->default('prospecting');
            $t->string('stage', 12)->default('prospect');                      // prospect | qualified | proposal | negotiation | won | lost
            $t->unsignedTinyInteger('probability')->default(10);
            $t->date('expected_close');
            $t->date('arrival')->nullable();                                   // for groups / events: the dates; for agreements: validity
            $t->date('departure')->nullable();
            $t->unsignedInteger('room_nights')->default(0);
            $t->decimal('rooms_revenue', 14, 2)->default(0);
            $t->decimal('fb_revenue', 14, 2)->default(0);
            $t->decimal('other_revenue', 14, 2)->default(0);
            $t->string('competitors', 190)->nullable();
            $t->string('lost_reason', 16)->nullable();
            $t->string('lost_note', 255)->nullable();
            $t->date('closed_on')->nullable();
            $t->timestamp('stage_changed_at')->nullable();
            $t->foreignId('rate_contract_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('room_block_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('banquet_event_id')->nullable()->constrained()->nullOnDelete();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'number']);
            $t->index(['hotel_id', 'stage']);
        });

        Schema::create('sales_activities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('sales_contact_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('sales_opportunity_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('type', 16);                                            // call | visit | site_inspection | meeting | email | entertainment | task
            $t->string('subject', 190);
            $t->dateTime('due_at');
            $t->unsignedSmallInteger('duration_min')->nullable();
            $t->string('status', 10)->default('planned');                      // planned | done | cancelled
            $t->dateTime('done_at')->nullable();
            $t->text('outcome')->nullable();
            $t->decimal('cost', 12, 2)->default(0);                            // entertainment / travel spend
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['hotel_id', 'status', 'due_at']);
            $t->index(['hotel_id', 'company_id']);
        });

        Schema::create('sales_targets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->char('period', 7);                                             // 2026-10
            $t->unsignedInteger('room_nights')->default(0);
            $t->decimal('revenue', 14, 2)->default(0);
            $t->unsignedSmallInteger('activities')->default(0);
            $t->timestamps();
            $t->unique(['hotel_id', 'user_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_targets');
        Schema::dropIfExists('sales_activities');
        Schema::dropIfExists('sales_opportunities');
        Schema::dropIfExists('sales_contacts');
        Schema::table('companies', function (Blueprint $t) {
            $t->dropColumn(['tier', 'sales_status', 'industry', 'city', 'website', 'potential_nights', 'sales_notes']);
        });
    }
};

