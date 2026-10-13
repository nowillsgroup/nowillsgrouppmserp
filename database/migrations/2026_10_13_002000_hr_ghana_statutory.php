<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v7.43 HR & Workforce — Ghana statutory payroll fields.
 *
 *  employees: tax residence (Act 896 s.2 – a non-resident's employment income is taxed at a flat 25 %) and the
 *             employee's monthly personal reliefs (GRA: marriage GH¢1,200 a year, child education GH¢600 a child,
 *             old age GH¢1,500, aged dependant GH¢1,000, education GH¢2,000 – entered as a monthly figure).
 *  payslips:  the chargeable income PAYE was actually computed on and the rule that produced it, plus the public
 *             holiday days and hours of the month (Labour Act ss. 22, 72, 77 – a public holiday is paid, does not
 *             consume annual leave and is worked at the holiday rate).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $t) {
            $t->boolean('tax_resident')->default(true)->after('tax_id');
            $t->decimal('tax_relief', 12, 2)->default(0)->after('tax_resident');
        });

        Schema::table('payslips', function (Blueprint $t) {
            $t->decimal('holiday_days', 4, 1)->default(0)->after('lop_days');
            $t->decimal('holiday_hours', 6, 2)->default(0)->after('ot_hours');
            $t->decimal('chargeable_income', 12, 2)->default(0)->after('employer_cost');
            $t->string('paye_basis', 120)->nullable()->after('chargeable_income');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $t) {
            $t->dropColumn(['tax_resident', 'tax_relief']);
        });

        Schema::table('payslips', function (Blueprint $t) {
            $t->dropColumn(['holiday_days', 'holiday_hours', 'chargeable_income', 'paye_basis']);
        });
    }
};
