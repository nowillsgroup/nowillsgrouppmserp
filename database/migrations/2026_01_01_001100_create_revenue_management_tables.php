<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v1.9 revenue management: yield rules, price floors/ceilings, revenue-managed rate overrides and
 * nightly on-the-books snapshots (pickup & pace).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_types', function (Blueprint $t) {
            $t->decimal('min_rate', 12, 2)->nullable()->after('base_rate');   // floor – dynamic pricing never goes below
            $t->decimal('max_rate', 12, 2)->nullable()->after('min_rate');    // ceiling
        });

        Schema::table('rate_overrides', function (Blueprint $t) {
            $t->string('source', 10)->default('manual')->after('stop_sell'); // manual | rm (published by revenue management)
            $t->boolean('is_fixed')->default(false)->after('source');        // contract / event price – revenue management leaves it alone
            $t->index(['hotel_id', 'source', 'date_from']);
        });

                if (Schema::hasTable('yield_rules')) {
            return;
        }

        Schema::create('yield_rules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('name', 80);
            $t->foreignId('room_type_id')->nullable()->constrained()->cascadeOnDelete(); // null = every room type
            $t->string('basis', 5)->default('hotel');         // occupancy measured on: hotel | type
            $t->unsignedTinyInteger('occ_min')->nullable();   // % inclusive
            $t->unsignedTinyInteger('occ_max')->nullable();   // % inclusive
            $t->unsignedSmallInteger('lead_min')->nullable(); // days before arrival, inclusive
            $t->unsignedSmallInteger('lead_max')->nullable();
            $t->string('days', 20)->nullable();               // comma list of ISO weekdays 1=Mon … 7=Sun; null = all
            $t->date('date_from')->nullable();
            $t->date('date_to')->nullable();
            $t->string('adjust_type', 8)->default('percent'); // percent | amount
            $t->decimal('adjust_value', 10, 2)->default(0);   // signed
            $t->unsignedTinyInteger('min_stay')->nullable();  // restriction applied while the rule matches
            $t->unsignedSmallInteger('priority')->default(100);
            $t->boolean('is_active')->default(true);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['hotel_id', 'is_active']);
        });

                if (Schema::hasTable('rm_snapshots')) {
            return;
        }

        Schema::create('rm_snapshots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->date('snapshot_date'); // on-the-books position at the end of this business day
            $t->date('stay_date');
            $t->foreignId('room_type_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('rooms')->default(0);
            $t->decimal('revenue', 12, 2)->default(0);
            $t->unique(['hotel_id', 'snapshot_date', 'stay_date', 'room_type_id'], 'rm_snap_unique');
            $t->index(['hotel_id', 'stay_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rm_snapshots');
        Schema::dropIfExists('yield_rules');
        $plain = Schema::hasIndex('rate_overrides', ['hotel_id']);
        Schema::table('rate_overrides', function (Blueprint $t) use ($plain) {
            if (! $plain) {
                $t->index('hotel_id'); // the FK needs an index once the composite is gone
            }
            $t->dropIndex(['hotel_id', 'source', 'date_from']);
            $t->dropColumn(['source', 'is_fixed']);
        });
        Schema::table('room_types', fn (Blueprint $t) => $t->dropColumn(['min_rate', 'max_rate']));
    }
};

