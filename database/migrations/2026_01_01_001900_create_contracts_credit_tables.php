<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.7 corporate & travel-agent contracts and credit control: negotiated rate agreements per company (net rates by
 * room type and season, or a percentage off the public rate), blackout dates, allotments with release days, last-room
 * availability, a corporate booking-engine access code, production targets; company credit terms (limit, credit days,
 * direct-billing approval, hold / release) with an audit log of holds, releases, limit changes and overrides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->unsignedSmallInteger('credit_days')->default(30);         // payment terms
            $t->boolean('direct_billing')->default(true);                  // approved to receive city-ledger bills
            $t->string('credit_status', 10)->default('open');              // open, hold
            $t->string('credit_hold_reason', 200)->nullable();
            $t->boolean('credit_hold_auto')->default(false);                // placed by the overdue review (auto-released when paid)
            $t->timestamp('credit_hold_at')->nullable();
            $t->timestamp('last_statement_at')->nullable();
            $t->foreignId('account_manager_id')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('rate_contracts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('code', 30);
            $t->string('name', 150);
            $t->date('valid_from');
            $t->date('valid_to');
            $t->string('status', 12)->default('draft');   // draft, active, suspended (expired = active past valid_to)
            $t->string('pricing', 10)->default('net');     // net (rate grid), discount (% off public rate)
            $t->decimal('discount_pct', 5, 2)->default(0); // discount basis; also the fallback for room types without a net rate
            $t->string('meal_plan', 10)->default('EP');
            $t->string('inclusions', 255)->nullable();
            $t->boolean('lra')->default(false);            // last-room availability: ignores stop-sell / minimum stay
            $t->string('access_code', 20)->nullable();      // booking-engine corporate code
            $t->unsignedSmallInteger('cancel_days')->default(1); // free cancellation until n days before arrival
            $t->decimal('commission_pct', 5, 2)->nullable(); // agents: overrides the company's %
            $t->unsignedInteger('room_night_target')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('renewed_from_id')->nullable()->constrained('rate_contracts')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'code']);
            $t->unique(['hotel_id', 'access_code']);
            $t->index(['hotel_id', 'company_id', 'status']);
        });

        Schema::create('rate_contract_rates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('rate_contract_id')->constrained()->cascadeOnDelete();
            $t->foreignId('room_type_id')->constrained()->cascadeOnDelete();
            $t->date('season_from')->nullable();              // null = whole contract period
            $t->date('season_to')->nullable();
            $t->string('season_name', 40)->nullable();
            $t->decimal('rate', 12, 2);                       // single occupancy
            $t->decimal('double_rate', 12, 2)->nullable();    // 2+ adults (null = same as single)
            $t->decimal('extra_adult_rate', 12, 2)->nullable();
            $t->timestamps();
        });

        Schema::create('rate_contract_blackouts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('rate_contract_id')->constrained()->cascadeOnDelete();
            $t->date('date_from');
            $t->date('date_to');
            $t->string('reason', 100)->nullable();
            $t->timestamps();
        });

        Schema::create('contract_allotments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('rate_contract_id')->constrained()->cascadeOnDelete();
            $t->foreignId('room_type_id')->constrained()->cascadeOnDelete();
            $t->date('date_from');
            $t->date('date_to');                              // last night
            $t->unsignedSmallInteger('rooms');
            $t->unsignedSmallInteger('release_days')->default(7);
            $t->timestamps();
        });

        Schema::create('credit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('action', 20);                         // hold, release, terms, override, statement, refused
            $t->decimal('amount', 14, 2)->nullable();
            $t->string('detail', 255);
            $t->foreignId('folio_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->index(['hotel_id', 'company_id', 'created_at']);
        });

        Schema::table('reservations', function (Blueprint $t) {
            $t->foreignId('rate_contract_id')->nullable()->after('company_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reservations', fn (Blueprint $t) => $t->dropConstrainedForeignId('rate_contract_id'));
        Schema::dropIfExists('credit_logs');
        Schema::dropIfExists('contract_allotments');
        Schema::dropIfExists('rate_contract_blackouts');
        Schema::dropIfExists('rate_contract_rates');
        Schema::dropIfExists('rate_contracts');
        Schema::table('companies', function (Blueprint $t) {
            $t->dropConstrainedForeignId('account_manager_id');
            $t->dropColumn(['credit_days', 'direct_billing', 'credit_status', 'credit_hold_reason', 'credit_hold_auto', 'credit_hold_at', 'last_statement_at']);
        });
    }
};

