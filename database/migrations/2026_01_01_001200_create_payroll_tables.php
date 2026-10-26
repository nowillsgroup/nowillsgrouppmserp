<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.0 HR & payroll: leave types & requests, pay components (earnings / deductions / employer contributions),
 * employee pay setup, payroll runs with payslips & lines, and salary advances recovered through payroll.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $t) {
            $t->string('bank_name', 80)->nullable()->after('salary');
            $t->string('bank_account', 40)->nullable()->after('bank_name');
            $t->string('bank_ifsc', 20)->nullable()->after('bank_account');
            $t->string('tax_id', 30)->nullable()->after('bank_ifsc');            // PAN / TIN
            $t->string('social_security_no', 30)->nullable()->after('tax_id');   // UAN / SSNIT
            $t->foreignId('payment_mode_id')->nullable()->after('social_security_no')->constrained()->nullOnDelete();
            $t->boolean('ot_eligible')->default(true)->after('payment_mode_id');
            $t->date('leaving_date')->nullable()->after('joining_date');
        });

                if (Schema::hasTable('leave_types')) {
            return;
        }

        Schema::create('leave_types', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('code', 10);
            $t->string('name', 60);
            $t->decimal('days_per_year', 5, 1)->default(0);
            $t->boolean('is_paid')->default(true);
            $t->decimal('carry_forward_max', 5, 1)->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['hotel_id', 'code']);
        });

                if (Schema::hasTable('leave_requests')) {
            return;
        }

        Schema::create('leave_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $t->date('date_from');
            $t->date('date_to');
            $t->boolean('half_day')->default(false);
            $t->decimal('days', 5, 1);
            $t->string('reason')->nullable();
            $t->string('status', 10)->default('pending'); // pending | approved | rejected | cancelled
            $t->string('decision_note')->nullable();
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
            $t->index(['hotel_id', 'status']);
            $t->index(['employee_id', 'date_from']);
        });

        Schema::table('attendances', function (Blueprint $t) {
            $t->foreignId('leave_request_id')->nullable()->after('status')->constrained()->nullOnDelete();
        });

                if (Schema::hasTable('pay_components')) {
            return;
        }

        Schema::create('pay_components', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('code', 12);
            $t->string('name', 60);
            $t->string('kind', 10);                         // earning | deduction | employer
            $t->string('calc', 15)->default('fixed');       // fixed | percent_basic | percent_gross | slab | tax | system
            $t->decimal('value', 12, 2)->default(0);        // amount or %
            $t->decimal('cap_base', 12, 2)->nullable();     // % applies to at most this base (e.g. PF on basic up to 15,000)
            $t->decimal('gross_ceiling', 12, 2)->nullable(); // applies only while gross ≤ this (e.g. ESI)
            $t->json('slabs')->nullable();                  // [[upto|null, amount], …] on gross (e.g. professional tax)
            $t->boolean('prorate')->default(true);          // fixed earnings scale with paid days
            $t->boolean('taxable')->default(true);          // earning counts towards income tax
            $t->boolean('is_default')->default(true);       // applies to every employee unless excluded
            $t->foreignId('expense_head_id')->nullable()->constrained('account_heads')->nullOnDelete();
            $t->foreignId('liability_head_id')->nullable()->constrained('account_heads')->nullOnDelete();
            $t->unsignedSmallInteger('sort_order')->default(100);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['hotel_id', 'code']);
        });

                if (Schema::hasTable('employee_pay_components')) {
            return;
        }

        Schema::create('employee_pay_components', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->foreignId('pay_component_id')->constrained()->cascadeOnDelete();
            $t->decimal('value', 12, 2)->nullable();        // null = component default
            $t->boolean('is_excluded')->default(false);
            $t->timestamps();
            $t->unique(['employee_id', 'pay_component_id']);
        });

                if (Schema::hasTable('staff_advances')) {
            return;
        }

        Schema::create('staff_advances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('advance_no', 20);
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->date('date');
            $t->decimal('amount', 12, 2);
            $t->decimal('installment', 12, 2);
            $t->foreignId('payment_mode_id')->nullable()->constrained()->nullOnDelete();
            $t->string('reason')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamps();
            $t->index(['hotel_id', 'employee_id']);
        });

                if (Schema::hasTable('payroll_runs')) {
            return;
        }

        Schema::create('payroll_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('run_no', 20);
            $t->date('period_start');
            $t->date('period_end');                         // accrual date in the ledger
            $t->string('status', 10)->default('draft');     // draft | approved | paid
            $t->decimal('gross', 14, 2)->default(0);
            $t->decimal('deductions', 14, 2)->default(0);
            $t->decimal('net', 14, 2)->default(0);
            $t->decimal('employer_cost', 14, 2)->default(0);
            $t->unsignedSmallInteger('employees')->default(0);
            $t->string('notes')->nullable();
            $t->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('generated_at')->nullable();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->date('paid_date')->nullable();
            $t->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'period_start']);
        });

                if (Schema::hasTable('payslips')) {
            return;
        }

        Schema::create('payslips', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->unsignedTinyInteger('days_in_period');
            $t->decimal('employed_days', 5, 1);
            $t->decimal('present_days', 5, 1)->default(0);
            $t->decimal('paid_leave_days', 5, 1)->default(0);
            $t->decimal('lop_days', 5, 1)->default(0);
            $t->decimal('paid_days', 5, 1);
            $t->decimal('ot_hours', 6, 2)->default(0);
            $t->decimal('basic', 12, 2)->default(0);        // monthly basic at generation
            $t->decimal('gross', 12, 2)->default(0);
            $t->decimal('deductions', 12, 2)->default(0);
            $t->decimal('net', 12, 2)->default(0);
            $t->decimal('employer_cost', 12, 2)->default(0);
            $t->foreignId('payment_mode_id')->nullable()->constrained()->nullOnDelete();
            $t->string('bank_account', 40)->nullable();
            $t->timestamps();
            $t->unique(['payroll_run_id', 'employee_id']);
        });

                if (Schema::hasTable('payslip_lines')) {
            return;
        }

        Schema::create('payslip_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('payslip_id')->constrained()->cascadeOnDelete();
            $t->foreignId('pay_component_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('staff_advance_id')->nullable()->constrained()->nullOnDelete();
            $t->string('code', 12);
            $t->string('name', 80);
            $t->string('kind', 10);                         // earning | deduction | employer
            $t->decimal('amount', 12, 2);
            $t->boolean('taxable')->default(true);
            $t->boolean('is_manual')->default(false);       // kept when the run is recalculated
            $t->foreignId('expense_head_id')->nullable()->constrained('account_heads')->nullOnDelete();
            $t->foreignId('liability_head_id')->nullable()->constrained('account_heads')->nullOnDelete();
            $t->unsignedSmallInteger('sort_order')->default(100);
            $t->timestamps();
            $t->index(['payslip_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslip_lines');
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('staff_advances');
        Schema::dropIfExists('employee_pay_components');
        Schema::dropIfExists('pay_components');
        Schema::table('attendances', function (Blueprint $t) {
            $t->dropConstrainedForeignId('leave_request_id');
        });
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('leave_types');
        Schema::table('employees', function (Blueprint $t) {
            $t->dropConstrainedForeignId('payment_mode_id');
            $t->dropColumn(['bank_name', 'bank_account', 'bank_ifsc', 'tax_id', 'social_security_no', 'ot_eligible', 'leaving_date']);
        });
    }
};

