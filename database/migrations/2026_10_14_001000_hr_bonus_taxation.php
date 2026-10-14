<?php

use App\Services\PayrollService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v7.45 HR & Payroll — GRA taxation of bonuses / ex-gratia payments.
 *
 *  pay_components: BONUS (an earning a payslip can carry) and BONUS_TAX (the deduction the engine raises for it). Both
 *                  are seeded for an existing installation by PayrollService::ensureDefaults(), which is idempotent and
 *                  never touches a component that is already there.
 *  payslips:       paye_basis widened from 120 to 255 characters — the rule string now also records how the month's
 *                  bonus was taxed ("bonus GH₵1,800.00 at a final 5%, GH₵1,200.00 over the cap on the bands"), which
 *                  the payslip and the GRA return print verbatim.
 *
 *  The rule itself (Act 896 s. 114 and GRA's practice note on ex-gratia payments): a resident's bonus carries a final
 *  5% up to 15% of the year's basic salary and the excess is added to chargeable income; a non-resident's bonus and
 *  overtime carry a flat 20%; a casual worker's bonus is simply part of the gross that carries the final 5%.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $t) {
            $t->string('paye_basis', 255)->nullable()->change();
        });

        PayrollService::ensureDefaults();
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $t) {
            $t->string('paye_basis', 120)->nullable()->change();
        });
    }
};
