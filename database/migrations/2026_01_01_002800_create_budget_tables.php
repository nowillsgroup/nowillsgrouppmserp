<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 27 (v3.6): budgeting & forecasting. A budget (or re-forecast) is a version of the year's P&L by GL account and
 * fiscal month, plus rooms statistics; it goes draft → submitted → approved (four eyes) and one approved budget and one
 * approved forecast per fiscal year are "active" for budget-vs-actual and spend control.
 */
return new class extends Migration
{
    public function up(): void
    {
                if (Schema::hasTable('budgets')) {
            return;
        }

        Schema::create('budgets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('budget_no', 20);
            $t->unsignedSmallInteger('fiscal_year');          // year the fiscal year starts in (2026 = FY 2026-27 when it starts in April)
            $t->string('name', 80);
            $t->string('kind', 10)->default('budget');       // budget | forecast
            $t->string('status', 12)->default('draft');      // draft | submitted | approved
            $t->boolean('is_active')->default(false);        // the approved version used for reporting & control
            $t->foreignId('parent_id')->nullable()->constrained('budgets')->nullOnDelete(); // forecast → the budget it re-forecasts / copy source
            $t->date('actuals_through')->nullable();          // forecast: months up to here hold actuals (locked)
            $t->string('method', 12)->default('blank');      // blank | last_year | run_rate | copy
            $t->decimal('growth_income', 6, 2)->default(0);
            $t->decimal('growth_expense', 6, 2)->default(0);
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('submitted_at')->nullable();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->string('return_note', 255)->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'budget_no']);
            $t->index(['hotel_id', 'fiscal_year', 'kind']);
        });

                if (Schema::hasTable('budget_lines')) {
            return;
        }

        Schema::create('budget_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $t->foreignId('account_head_id')->constrained()->restrictOnDelete();
            for ($m = 1; $m <= 12; $m++) {
                $t->decimal('m'.$m, 14, 2)->default(0);      // fiscal month 1..12
            }
            $t->string('note', 190)->nullable();
            $t->timestamps();
            $t->unique(['budget_id', 'account_head_id']);
        });

                if (Schema::hasTable('budget_stats')) {
            return;
        }

        Schema::create('budget_stats', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $t->unsignedTinyInteger('month');                // fiscal month 1..12
            $t->unsignedInteger('rooms_available')->default(0);
            $t->decimal('occupancy', 5, 2)->default(0);      // %
            $t->decimal('adr', 12, 2)->default(0);
            $t->timestamps();
            $t->unique(['budget_id', 'month']);
        });

                if (Schema::hasTable('budget_comments')) {
            return;
        }

        Schema::create('budget_comments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('budget_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('account_head_id')->nullable()->constrained()->nullOnDelete(); // null = a general note on the month
            $t->char('period', 7);                            // Y-m
            $t->decimal('actual', 14, 2)->nullable();
            $t->decimal('budget', 14, 2)->nullable();
            $t->text('body');
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->index(['hotel_id', 'period']);
        });

                if (Schema::hasTable('budget_events')) {
            return;
        }

        Schema::create('budget_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $t->string('action', 20);
            $t->string('note', 255)->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['budget_events', 'budget_comments', 'budget_stats', 'budget_lines', 'budgets'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};

