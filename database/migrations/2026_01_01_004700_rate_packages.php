<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v5.5 Rate packages, meal plans & inclusions: a package catalogue (breakfast, dinner, airport drop, spa credit …),
 * packages included in rate plans, add-on packages sold to a stay, the nightly entitlement ledger and the
 * redemption register kept at the restaurant door.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('code', 12);
            $t->string('name', 80);
            $t->string('description', 255)->nullable();
            $t->string('category', 30)->default('fnb');                 // folio charge category / revenue department
            $t->foreignId('outlet_id')->nullable()->constrained()->nullOnDelete(); // where it is redeemed
            $t->string('meal', 10)->nullable();                         // breakfast | lunch | dinner (drives the meal-plan code)
            $t->string('calc', 12)->default('per_person');              // per_person | per_adult | per_room
            $t->decimal('price_adult', 12, 2)->default(0);              // per adult, or per room for per_room
            $t->decimal('price_child', 12, 2)->default(0);
            $t->string('rhythm', 12)->default('every_night');           // every_night | arrival | departure | once
            $t->string('service_day', 10)->default('same_day');         // same_day | next_day (breakfast is served the morning after the night)
            $t->boolean('is_allowance')->default(true);                 // redeemed at an outlet – unused value is breakage
            $t->string('redeem_mode', 8)->default('covers');            // covers | amount
            $t->decimal('overage_price', 12, 2)->default(0);            // walk-in price per extra cover
            $t->boolean('apply_tax')->default(true);                    // when sold as an add-on
            $t->boolean('is_sellable')->default(true);                  // can be added to a stay
            $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
            $t->unique(['hotel_id', 'code']);
        });

        Schema::create('rate_plan_packages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('rate_plan_id')->constrained()->cascadeOnDelete();
            $t->foreignId('package_id')->constrained()->cascadeOnDelete();
            $t->timestamps();
            $t->unique(['rate_plan_id', 'package_id']);
        });

        Schema::table('stay_fixed_charges', function (Blueprint $t) {
            $t->foreignId('package_id')->nullable()->after('reservation_room_id')->constrained()->nullOnDelete();
        });

        Schema::create('package_entitlements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('reservation_room_id')->constrained()->cascadeOnDelete();
            $t->foreignId('package_id')->constrained()->cascadeOnDelete();
            $t->foreignId('stay_fixed_charge_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('folio_transaction_id')->nullable()->constrained()->nullOnDelete(); // the room night / add-on charge it came from
            $t->string('source', 10);                                   // included | addon
            $t->date('night_date');                                     // stay night that earned it
            $t->date('service_date');                                   // day it can be used
            $t->unsignedTinyInteger('adults')->default(0);
            $t->unsignedTinyInteger('children')->default(0);
            $t->unsignedSmallInteger('qty')->default(1);                // covers (1 for per-room)
            $t->decimal('amount', 12, 2)->default(0);                   // value allocated
            $t->boolean('is_allowance')->default(true);
            $t->unsignedSmallInteger('consumed_qty')->default(0);
            $t->decimal('consumed_amount', 12, 2)->default(0);
            $t->date('consumed_date')->nullable();
            $t->string('status', 10)->default('open');                  // open | partial | consumed | expired | cancelled
            $t->decimal('breakage_amount', 12, 2)->default(0);
            $t->date('expired_date')->nullable();
            $t->timestamps();
            $t->unique(['reservation_room_id', 'package_id', 'night_date', 'source'], 'pkg_ent_unique');
            $t->index(['hotel_id', 'service_date', 'status']);
            $t->index(['hotel_id', 'night_date']);
        });

        Schema::create('package_redemptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('redemption_no', 20);
            $t->foreignId('package_entitlement_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('reservation_room_id')->constrained()->cascadeOnDelete();
            $t->foreignId('package_id')->constrained()->cascadeOnDelete();
            $t->foreignId('outlet_id')->nullable()->constrained()->nullOnDelete();
            $t->date('business_date');
            $t->unsignedSmallInteger('covers')->default(0);             // covers served against the entitlement
            $t->decimal('amount', 12, 2)->default(0);                   // allowance value consumed
            $t->unsignedSmallInteger('extra_covers')->default(0);       // beyond the entitlement
            $t->decimal('extra_amount', 12, 2)->default(0);
            $t->string('overage_mode', 8)->default('folio');            // folio | paid | waived
            $t->foreignId('folio_transaction_id')->nullable()->constrained()->nullOnDelete();
            $t->string('pos_ref', 40)->nullable();
            $t->string('notes', 255)->nullable();
            $t->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('voided_at')->nullable();
            $t->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('void_reason', 255)->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'redemption_no']);
            $t->index(['hotel_id', 'business_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_redemptions');
        Schema::dropIfExists('package_entitlements');
        Schema::table('stay_fixed_charges', function (Blueprint $t) {
            $t->dropConstrainedForeignId('package_id');
        });
        Schema::dropIfExists('rate_plan_packages');
        Schema::dropIfExists('packages');
    }
};

