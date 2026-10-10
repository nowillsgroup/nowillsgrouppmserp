<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v4.3 – Ghana localisation (GRA / GTA / SSNIT):
 *  - base currency Ghana cedi (GHS, GH₵), timezone Africa/Accra, country Ghana
 *  - tax components carry a statutory code (VAT, NHIL, GETFUND, TL) and the collecting authority (GRA / GTA)
 *  - GRA E-VAT stamp fields on invoices (SDC internal data + signature; SDC id / receipt no. / time / QR reuse the irn_* columns)
 *  - pay components can be a PAYE relief (SSNIT employee contribution is deducted before PAYE)
 *
 * Data conversion (tax rows, account names, pay structure, settings) is done per hotel by
 * App\Services\GhanaTaxSetup::apply(), called from provisioning and `php artisan pms:upgrade`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotels', function (Blueprint $t) {
            $t->string('currency', 10)->default('GHS')->change();
            $t->string('currency_symbol', 5)->default('GH₵')->change();
            $t->string('timezone', 50)->default('Africa/Accra')->change();
        });
        DB::table('hotels')->where('currency', 'INR')->update(['currency' => 'GHS', 'currency_symbol' => 'GH₵']);
        DB::table('hotels')->where('timezone', 'Asia/Kolkata')->update(['timezone' => 'Africa/Accra']);
        DB::table('hotels')->where(fn ($q) => $q->whereNull('country')->orWhereIn(DB::raw('LOWER(country)'), ['india', 'in', '']))->update(['country' => 'Ghana']);

        Schema::table('taxes', function (Blueprint $t) {
            $t->string('code', 12)->nullable()->after('name');         // VAT | NHIL | GETFUND | TL (Tourism Levy)
            $t->string('authority', 10)->nullable()->after('code');    // GRA | GTA
        });
        Schema::table('folios', function (Blueprint $t) {
            $t->string('evat_intdata', 120)->nullable()->after('irn_qr');       // ysdcintdata – SDC internal data
            $t->string('evat_signature', 190)->nullable()->after('evat_intdata'); // ysdcregsig – SDC signature
        });
        Schema::table('gst_periods', function (Blueprint $t) {
            $t->string('arn', 40)->change();                                     // GRA acknowledgement / reference no.
        });
        Schema::table('pay_components', function (Blueprint $t) {
            $t->boolean('tax_relief')->default(false)->after('taxable');      // deduction reduces PAYE chargeable income (SSNIT 5.5%)
        });
    }

    public function down(): void
    {
        Schema::table('pay_components', fn (Blueprint $t) => $t->dropColumn('tax_relief'));
        Schema::table('folios', fn (Blueprint $t) => $t->dropColumn(['evat_intdata', 'evat_signature']));
        Schema::table('taxes', fn (Blueprint $t) => $t->dropColumn(['code', 'authority']));
    }
};

