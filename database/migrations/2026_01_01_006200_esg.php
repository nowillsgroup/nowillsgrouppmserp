<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v7.0 Sustainability & ESG: waste, refrigerants, outsourced laundry, targets, green initiatives,
 * community investment, certification checklist, governance policy register, guest green-stay choice,
 * plus people / supplier attributes that feed the social and local-sourcing indicators.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('esg_waste_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('number', 20);
            $t->date('date');
            $t->string('stream', 16);          // general, food, paper_card, plastic, glass, metal, garden, e_waste, hazardous, cooking_oil
            $t->string('disposal', 14);        // landfill, recycling, composting, anaerobic, incineration, donation, rendering
            $t->decimal('weight_kg', 10, 2);
            $t->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $t->string('contractor', 120)->nullable();
            $t->string('manifest_no', 60)->nullable();
            $t->decimal('cost', 12, 2)->default(0);      // haulage / gate fee paid
            $t->decimal('income', 12, 2)->default(0);    // sale of recyclables / used oil
            $t->string('notes', 255)->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('cancelled_at')->nullable();
            $t->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('cancel_reason', 190)->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'number']);
            $t->index(['hotel_id', 'date']);
        });

        Schema::create('esg_refrigerant_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('number', 20);
            $t->date('date');
            $t->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $t->string('equipment', 120);
            $t->string('gas', 10);                        // R22, R410A, R32, R134a, R404A, R407C, R290, R600a, R717
            $t->decimal('system_charge_kg', 8, 2)->nullable();
            $t->decimal('kg_added', 8, 3)->default(0);
            $t->decimal('kg_recovered', 8, 3)->default(0);
            $t->string('reason', 14);                     // leak_repair, top_up, install, service, decommission
            $t->string('technician', 120)->nullable();
            $t->string('certificate_no', 60)->nullable();
            $t->foreignId('maintenance_request_id')->nullable()->constrained()->nullOnDelete();
            $t->string('notes', 255)->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'number']);
            $t->index(['hotel_id', 'date']);
        });

        Schema::create('esg_laundry_months', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->date('month');                            // first day of the month
            $t->decimal('kg', 10, 1);
            $t->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('kwh', 12, 1)->nullable();         // supplier-reported energy, when given
            $t->string('notes', 255)->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'month']);
        });

        Schema::create('esg_targets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('metric', 24);
            $t->unsignedSmallInteger('baseline_year');
            $t->decimal('baseline_value', 14, 3);
            $t->unsignedSmallInteger('target_year');
            $t->decimal('target_value', 14, 3);
            $t->string('notes', 255)->nullable();
            $t->boolean('is_active')->default(true);
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('esg_initiatives', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('number', 20);
            $t->string('title', 150);
            $t->string('category', 12);                   // energy, water, waste, carbon, procurement, social, governance
            $t->text('description')->nullable();
            $t->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('status', 12)->default('idea');    // idea, approved, in_progress, done, rejected, cancelled
            $t->decimal('capex', 12, 2)->default(0);
            $t->decimal('saving_ghs', 12, 2)->default(0);  // per year
            $t->decimal('saving_kwh', 12, 1)->default(0);
            $t->decimal('saving_m3', 12, 1)->default(0);
            $t->decimal('saving_waste_kg', 12, 1)->default(0);
            $t->decimal('saving_co2_kg', 12, 1)->default(0);
            $t->date('start_on')->nullable();
            $t->date('due_on')->nullable();
            $t->date('completed_on')->nullable();
            $t->string('outcome', 500)->nullable();
            $t->foreignId('raised_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->string('decision_note', 255)->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'number']);
        });

        Schema::create('esg_community', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('number', 20);
            $t->date('date');
            $t->string('kind', 12);                       // donation, in_kind, volunteering, sponsorship, training, food
            $t->string('beneficiary', 150);
            $t->string('description', 500)->nullable();
            $t->decimal('amount', 12, 2)->default(0);      // cash or value in kind
            $t->decimal('hours', 8, 1)->default(0);        // staff volunteering hours
            $t->unsignedSmallInteger('volunteers')->default(0);
            $t->unsignedInteger('people_reached')->default(0);
            $t->string('evidence_path', 255)->nullable();
            $t->string('evidence_name', 190)->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'number']);
            $t->index(['hotel_id', 'date']);
        });

        Schema::create('esg_checklist_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('code', 12);
            $t->string('section', 16);
            $t->string('requirement', 255);
            $t->string('guidance', 500)->nullable();
            $t->boolean('is_mandatory')->default(false);
            $t->string('status', 8)->default('not');      // met, partial, not, na
            $t->string('evidence', 500)->nullable();
            $t->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->date('reviewed_on')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
            $t->unique(['hotel_id', 'code']);
        });

        Schema::create('esg_policies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('code', 20);
            $t->string('title', 150);
            $t->string('pillar', 12);                     // environment, social, governance
            $t->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('version', 10)->default('1.0');
            $t->date('approved_on')->nullable();
            $t->string('approved_by_name', 120)->nullable();
            $t->unsignedSmallInteger('review_months')->default(12);
            $t->date('next_review_on')->nullable();
            $t->string('status', 10)->default('draft');   // draft, active, retired
            $t->string('summary', 500)->nullable();
            $t->string('file_path', 255)->nullable();
            $t->string('file_name', 190)->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'code']);
        });

        Schema::create('esg_green_stays', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('reservation_room_id')->constrained()->cascadeOnDelete();
            $t->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $t->date('from_date');                        // first day the stay-over service is skipped
            $t->date('ended_on')->nullable();
            $t->string('status', 8)->default('active');   // active, ended
            $t->string('source', 8)->default('desk');     // desk, portal
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->index(['hotel_id', 'status']);
        });

        Schema::create('esg_green_nights', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('esg_green_stay_id')->constrained()->cascadeOnDelete();
            $t->foreignId('reservation_room_id')->constrained()->cascadeOnDelete();
            $t->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $t->date('business_date');
            $t->unsignedInteger('points')->default(0);
            $t->decimal('kwh', 8, 2)->default(0);
            $t->decimal('litres', 8, 1)->default(0);
            $t->decimal('co2_kg', 8, 2)->default(0);
            $t->timestamps();
            $t->unique(['hotel_id', 'business_date', 'reservation_room_id'], 'esg_green_night_unique');
        });

        Schema::table('suppliers', function (Blueprint $t) {
            $t->boolean('is_local')->default(false)->after('is_active');   // Ghana-based / sourced locally
        });
        Schema::table('employees', function (Blueprint $t) {
            $t->string('gender', 1)->nullable()->after('name');            // F, M, X (not stated)
            $t->string('nationality', 40)->nullable()->after('gender');
        });
    }

    public function down(): void
    {
        Schema::table('employees', fn (Blueprint $t) => $t->dropColumn(['gender', 'nationality']));
        Schema::table('suppliers', fn (Blueprint $t) => $t->dropColumn('is_local'));
        foreach (['esg_green_nights', 'esg_green_stays', 'esg_policies', 'esg_checklist_items', 'esg_community', 'esg_initiatives', 'esg_targets', 'esg_laundry_months', 'esg_refrigerant_logs', 'esg_waste_logs'] as $tb) {
            Schema::dropIfExists($tb);
        }
    }
};

