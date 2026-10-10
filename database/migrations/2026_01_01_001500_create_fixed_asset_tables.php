<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.3 fixed assets & preventive maintenance: asset categories with depreciation rules, the asset register
 * (acquisition, location, warranty / AMC, disposal), monthly depreciation runs, and preventive-maintenance
 * schedules that raise maintenance tickets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_categories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('code', 12);
            $t->string('name', 80);
            $t->string('method', 4)->default('slm'); // slm = straight line | wdv = written-down value (reducing balance)
            $t->unsignedSmallInteger('life_months')->default(120); // slm
            $t->decimal('wdv_rate', 5, 2)->default(0);             // wdv: % per year
            $t->decimal('residual_pct', 5, 2)->default(5);          // salvage value as % of cost
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['hotel_id', 'code']);
        });

        Schema::create('assets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('asset_no', 20);
            $t->string('name', 150);
            $t->foreignId('asset_category_id')->constrained()->restrictOnDelete();
            $t->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $t->string('location', 120)->nullable();
            $t->string('department', 60)->nullable();
            $t->string('make', 80)->nullable();
            $t->string('model', 80)->nullable();
            $t->string('serial_no', 80)->nullable();
            $t->foreignId('custodian_id')->nullable()->constrained('employees')->nullOnDelete();
            // acquisition: opening = brought in with the opening balances (no journal); direct = paid from cash/bank;
            // bill = capitalised from a supplier bill whose line is coded to fixed assets (the bill posts it)
            $t->string('acquired_via', 8)->default('direct');
            $t->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('supplier_invoice_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('payment_mode_id')->nullable()->constrained()->nullOnDelete();
            $t->string('reference', 60)->nullable();
            $t->date('purchase_date');
            $t->date('dep_start');                       // first month depreciated (in service / go-live)
            $t->decimal('cost', 14, 2);
            $t->decimal('residual_value', 14, 2)->default(0);
            $t->decimal('opening_acc_dep', 14, 2)->default(0); // accumulated depreciation brought in (opening assets)
            $t->string('method', 4);
            $t->unsignedSmallInteger('life_months')->default(0);
            $t->decimal('wdv_rate', 5, 2)->default(0);
            $t->date('dep_through')->nullable();         // last month-end depreciated
            $t->date('warranty_until')->nullable();
            $t->string('amc_vendor', 120)->nullable();
            $t->date('amc_until')->nullable();
            $t->string('status', 10)->default('active'); // active | in_repair | disposed
            $t->date('disposed_date')->nullable();
            $t->string('disposal_type', 10)->nullable(); // sold | scrapped | lost
            $t->decimal('disposal_proceeds', 14, 2)->default(0);
            $t->foreignId('disposal_mode_id')->nullable()->constrained('payment_modes')->nullOnDelete();
            $t->decimal('disposal_nbv', 14, 2)->nullable(); // book value at disposal (frozen)
            $t->string('disposal_note', 255)->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'asset_no']);
            $t->index(['hotel_id', 'status']);
        });

        Schema::create('asset_dep_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('run_no', 20);
            $t->date('period_end');
            $t->string('status', 10)->default('posted'); // posted | reversed
            $t->decimal('total', 14, 2)->default(0);
            $t->unsignedInteger('assets')->default(0);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reversed_at')->nullable();
            $t->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('reverse_reason', 190)->nullable();
            $t->timestamps();
            $t->index(['hotel_id', 'period_end']);
        });

        Schema::create('asset_dep_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('asset_dep_run_id')->constrained()->cascadeOnDelete();
            $t->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $t->decimal('opening_nbv', 14, 2);
            $t->decimal('amount', 14, 2);
            $t->unsignedTinyInteger('days')->default(0); // days depreciated in the month (pro-rata first month)
            $t->unique(['asset_dep_run_id', 'asset_id']);
        });

        Schema::create('pm_schedules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('title', 150);
            $t->foreignId('asset_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $t->string('location', 120)->nullable();
            $t->string('category', 12)->default('general'); // maintenance ticket category
            $t->string('priority', 8)->default('normal');
            $t->text('checklist')->nullable();              // one task per line, copied into the ticket
            $t->string('every_unit', 6)->default('month');  // day | week | month
            $t->unsignedSmallInteger('every')->default(1);
            $t->date('next_due');
            $t->unsignedSmallInteger('lead_days')->default(3); // raise the ticket this many days before it's due
            $t->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $t->string('vendor', 120)->nullable();
            $t->date('last_done')->nullable();
            $t->boolean('is_active')->default(true);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['hotel_id', 'is_active', 'next_due']);
        });

        Schema::table('maintenance_requests', function (Blueprint $t) {
            $t->foreignId('asset_id')->nullable()->after('room_id')->constrained()->nullOnDelete();
            $t->foreignId('pm_schedule_id')->nullable()->after('asset_id')->constrained()->nullOnDelete();
            $t->date('due_date')->nullable()->after('priority');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_requests', function (Blueprint $t) {
            $t->dropConstrainedForeignId('pm_schedule_id');
            $t->dropConstrainedForeignId('asset_id');
            $t->dropColumn('due_date');
        });
        foreach (['pm_schedules', 'asset_dep_lines', 'asset_dep_runs', 'assets', 'asset_categories'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};

