<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v5.6 Financial close: monthly accounting periods with a close checklist (automatic checks against every
 * sub-ledger + manual tasks), four-eyes close / reopen that drives the GL lock date, accrual / prepayment /
 * deferred-income schedules, recurring journals, and the year-end closing entry to retained earnings.
 */
return new class extends Migration
{
    public function up(): void
    {
        // closing entries (year-end) are flagged so P&L-type totals can leave them out
        Schema::table('gl_journals', function (Blueprint $t) {
            $t->boolean('is_closing')->default(false)->after('is_manual');
        });
        Schema::table('gl_lines', function (Blueprint $t) {
            $t->boolean('closing')->default(false)->after('memo');
        });

                if (Schema::hasTable('close_periods')) {
            return;
        }

        Schema::create('close_periods', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->char('period', 7);                                   // YYYY-MM
            $t->unsignedSmallInteger('fiscal_year');
            $t->unsignedTinyInteger('fiscal_month');                  // 1..12 within the fiscal year
            $t->string('status', 12)->default('open');                // open | review | closed
            $t->timestamp('checked_at')->nullable();
            $t->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('prepared_at')->nullable();
            $t->string('prepare_note', 255)->nullable();
            $t->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('closed_at')->nullable();
            $t->string('close_note', 255)->nullable();
            $t->date('lock_before')->nullable();                      // GL lock date before this period closed
            $t->string('reject_reason', 255)->nullable();
            $t->foreignId('reopen_requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reopen_requested_at')->nullable();
            $t->string('reopen_reason', 255)->nullable();
            $t->unsignedSmallInteger('reopen_count')->default(0);
            $t->timestamps();
            $t->unique(['hotel_id', 'period']);
        });

                if (Schema::hasTable('close_tasks')) {
            return;
        }

        Schema::create('close_tasks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('close_period_id')->constrained()->cascadeOnDelete();
            $t->string('key', 30);                                    // automatic check key, or manual_<n>
            $t->string('title', 150);
            $t->string('area', 30);
            $t->string('kind', 8)->default('auto');                   // auto | manual
            $t->string('status', 10)->default('pending');             // pending | pass | warn | fail | na | done | waived
            $t->string('result', 500)->nullable();
            $t->string('note', 255)->nullable();                      // completion note or waiver reason
            $t->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('done_at')->nullable();
            $t->timestamp('checked_at')->nullable();
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
            $t->unique(['close_period_id', 'key']);
        });

                if (Schema::hasTable('close_events')) {
            return;
        }

        Schema::create('close_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('close_period_id')->constrained()->cascadeOnDelete();
            $t->string('action', 20);
            $t->string('note', 255)->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('created_at')->nullable();
        });

                if (Schema::hasTable('accruals')) {
            return;
        }

        Schema::create('accruals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('number', 20);
            $t->string('type', 12);                                   // accrual | prepayment | deferral
            $t->string('description', 150);
            $t->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('pl_account_id')->constrained('account_heads')->restrictOnDelete();      // expense (income for deferrals)
            $t->foreignId('balance_account_id')->constrained('account_heads')->restrictOnDelete(); // accrued exp. / prepayments / deferred income
            $t->decimal('amount', 14, 2);
            $t->char('start_period', 7);
            $t->unsignedSmallInteger('months')->default(1);
            $t->string('reference', 80)->nullable();
            $t->string('notes', 255)->nullable();
            $t->string('status', 12)->default('pending');             // pending | active | completed | cancelled | rejected
            $t->decimal('posted', 14, 2)->default(0);
            $t->char('last_period', 7)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->string('decision_note', 255)->nullable();
            $t->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'number']);
            $t->index(['hotel_id', 'status']);
        });

                if (Schema::hasTable('recurring_journals')) {
            return;
        }

        Schema::create('recurring_journals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('code', 20);
            $t->string('name', 120);
            $t->string('frequency', 10)->default('monthly');          // monthly | quarterly | yearly
            $t->char('start_period', 7);
            $t->char('end_period', 7)->nullable();
            $t->boolean('auto_reverse')->default(false);              // reverse on the 1st of the next month
            $t->json('lines');                                        // [[account_head_id, debit, credit, memo], …]
            $t->decimal('total', 14, 2)->default(0);
            $t->string('status', 10)->default('pending');             // pending | active | paused | ended | rejected
            $t->char('last_period', 7)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->string('decision_note', 255)->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'code']);
        });

                if (Schema::hasTable('period_postings')) {
            return;
        }

        Schema::create('period_postings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('source', 10);                                 // accrual | recurring
            $t->unsignedBigInteger('source_id');
            $t->char('period', 7);
            $t->string('kind', 8)->default('post');                   // post | reverse
            $t->date('date');
            $t->decimal('amount', 14, 2);
            $t->foreignId('gl_journal_id')->nullable()->constrained('gl_journals')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'source', 'source_id', 'period', 'kind'], 'period_postings_unique');
            $t->index(['hotel_id', 'period']);
        });

                if (Schema::hasTable('fiscal_year_closes')) {
            return;
        }

        Schema::create('fiscal_year_closes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('fiscal_year');
            $t->date('closing_date');
            $t->decimal('income', 14, 2)->default(0);
            $t->decimal('expense', 14, 2)->default(0);
            $t->decimal('net', 14, 2)->default(0);
            $t->foreignId('gl_journal_id')->nullable()->constrained('gl_journals')->nullOnDelete();
            $t->string('status', 10)->default('closed');              // closed | reopened
            $t->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('closed_at')->nullable();
            $t->timestamp('reopened_at')->nullable();
            $t->timestamps();
            $t->index(['hotel_id', 'fiscal_year']);
        });
    }

    public function down(): void
    {
        foreach (['fiscal_year_closes', 'period_postings', 'recurring_journals', 'accruals', 'close_events', 'close_tasks', 'close_periods'] as $tb) {
            Schema::dropIfExists($tb);
        }
        Schema::table('gl_lines', fn (Blueprint $t) => $t->dropColumn('closing'));
        Schema::table('gl_journals', fn (Blueprint $t) => $t->dropColumn('is_closing'));
    }
};

