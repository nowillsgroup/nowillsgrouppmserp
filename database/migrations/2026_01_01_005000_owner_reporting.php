<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v5.8 Owner reporting & USALI management accounts: USALI department / category mapping of income and expense accounts
 * (and of HR departments for the payroll split), the hotel management agreement (base fee, incentive fee over the owner's
 * priority, FF&E reserve schedule), monthly management-fee accruals and payments, the FF&E reserve ledger, owner-approved
 * capital expenditure projects, and the monthly owner pack with the owner's acknowledgement / queries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_heads', function (Blueprint $t) {
            $t->string('usali_dept', 12)->nullable()->after('cf_class');   // null = automatic
            $t->string('usali_cat', 8)->nullable()->after('usali_dept');   // revenue | cos | labor | other
        });
        Schema::table('departments', function (Blueprint $t) {
            $t->string('usali_dept', 12)->nullable()->after('name');       // where this department's payroll is reported
        });

                if (Schema::hasTable('management_agreements')) {
            return;
        }

        Schema::create('management_agreements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('operator_name', 150);
            $t->string('owner_name', 150);
            $t->string('contract_ref', 60)->nullable();
            $t->date('effective_from');
            $t->date('ends_on')->nullable();
            $t->decimal('base_fee_pct', 5, 2)->default(3);                  // % of total operating revenue
            $t->decimal('incentive_pct', 5, 2)->default(8);                 // % of adjusted GOP above the owner's priority
            $t->string('incentive_basis', 6)->default('agop');              // agop = GOP − base fee − FF&E reserve | gop
            $t->decimal('owner_priority', 14, 2)->default(0);               // annual return to the owner before any incentive fee
            $t->json('ffe_schedule')->nullable();                           // FF&E reserve % of revenue by contract year, e.g. [2,3,4]
            $t->foreignId('reserve_account_id')->nullable()->constrained('treasury_accounts')->nullOnDelete();
            $t->unsignedTinyInteger('pay_days')->default(15);               // fees payable N days after month end
            $t->string('status', 10)->default('active');                    // active | ended
            $t->string('notes', 255)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

                if (Schema::hasTable('owner_fees')) {
            return;
        }

        Schema::create('owner_fees', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('management_agreement_id')->constrained()->cascadeOnDelete();
            $t->char('period', 7);                                          // Y-m
            $t->date('date');                                               // month end – the accrual date
            $t->decimal('total_revenue', 14, 2)->default(0);
            $t->decimal('gop', 14, 2)->default(0);
            $t->decimal('base_fee', 14, 2)->default(0);
            $t->decimal('ffe_pct', 5, 2)->default(0);
            $t->decimal('ffe_amount', 14, 2)->default(0);
            $t->decimal('agop', 14, 2)->default(0);
            $t->decimal('agop_ytd', 14, 2)->default(0);
            $t->decimal('priority_ytd', 14, 2)->default(0);
            $t->decimal('incentive_ytd', 14, 2)->default(0);                // incentive earned for the fee year to date
            $t->decimal('incentive_prior', 14, 2)->default(0);              // already accrued in earlier months
            $t->decimal('incentive_fee', 14, 2)->default(0);                // this month (negative = true-up)
            $t->decimal('total_fee', 14, 2)->default(0);
            $t->string('status', 10)->default('accrued');                   // accrued | paid | reversed
            $t->foreignId('gl_journal_id')->nullable()->constrained('gl_journals')->nullOnDelete();
            $t->foreignId('accrued_by')->nullable()->constrained('users')->nullOnDelete();
            $t->date('paid_on')->nullable();
            $t->foreignId('paid_account_id')->nullable()->constrained('account_heads')->nullOnDelete();
            $t->string('paid_ref', 60)->nullable();
            $t->foreignId('paid_journal_id')->nullable()->constrained('gl_journals')->nullOnDelete();
            $t->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('reversal_note', 255)->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'period']);
        });

                if (Schema::hasTable('capex_projects')) {
            return;
        }

        Schema::create('capex_projects', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('number', 20);
            $t->string('title', 150);
            $t->string('category', 10)->default('ffe');                     // ffe | building | it | vehicle | other
            $t->string('funding', 10)->default('reserve');                  // reserve | owner | operating
            $t->string('usali_dept', 12)->nullable();
            $t->decimal('budget', 14, 2);
            $t->text('justification')->nullable();
            $t->date('needed_by')->nullable();
            $t->string('status', 10)->default('draft');                     // draft | submitted | approved | rejected | completed | cancelled
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('submitted_at')->nullable();
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->string('decision_note', 255)->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'number']);
        });

        Schema::table('assets', function (Blueprint $t) {
            $t->foreignId('capex_project_id')->nullable()->after('supplier_invoice_id')->constrained('capex_projects')->nullOnDelete();
        });

                if (Schema::hasTable('ffe_entries')) {
            return;
        }

        Schema::create('ffe_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->date('date');
            $t->string('type', 12);                                         // opening | contribution | interest | drawdown | adjustment
            $t->decimal('amount', 14, 2);                                   // signed: + into the reserve, − out of it
            $t->foreignId('owner_fee_id')->nullable()->constrained('owner_fees')->cascadeOnDelete();
            $t->foreignId('capex_project_id')->nullable()->constrained('capex_projects')->nullOnDelete();
            $t->foreignId('asset_id')->nullable()->constrained('assets')->cascadeOnDelete();
            $t->string('memo', 255)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['hotel_id', 'date']);
        });

                if (Schema::hasTable('owner_statements')) {
            return;
        }

        Schema::create('owner_statements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->char('period', 7);
            $t->string('status', 12)->default('draft');                     // draft | issued | queried | acknowledged
            $t->text('commentary')->nullable();                             // general manager's commentary
            $t->longText('snapshot')->nullable();                           // figures frozen when issued (json)
            $t->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('issued_at')->nullable();
            $t->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('acknowledged_at')->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'period']);
        });

                if (Schema::hasTable('owner_queries')) {
            return;
        }

        Schema::create('owner_queries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('owner_statement_id')->constrained()->cascadeOnDelete();
            $t->foreignId('raised_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('body');
            $t->text('reply')->nullable();
            $t->foreignId('replied_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('replied_at')->nullable();
            $t->string('status', 10)->default('open');                      // open | answered | closed
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_queries');
        Schema::dropIfExists('owner_statements');
        Schema::dropIfExists('ffe_entries');
        Schema::table('assets', function (Blueprint $t) {
            $t->dropConstrainedForeignId('capex_project_id');
        });
        Schema::dropIfExists('capex_projects');
        Schema::dropIfExists('owner_fees');
        Schema::dropIfExists('management_agreements');
        Schema::table('departments', fn (Blueprint $t) => $t->dropColumn('usali_dept'));
        Schema::table('account_heads', fn (Blueprint $t) => $t->dropColumn(['usali_dept', 'usali_cat']));
    }
};

