<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v7.52 Per-property VAT registration — the record Act 1151 makes a hotel keep.
 *
 * Until now the only thing standing between a property and submitting tax invoices to the GRA Virtual Sales Data
 * Controller was the `gst_einvoice_enabled` switch, and `hotels` carried a TIN with no registration status beside it.
 * That matters more since the Value Added Tax Act, 2025 (Act 1151) took effect on 1 January 2026, because the
 * registration test now depends on WHAT a property supplies:
 *
 *   · suppliers of services — a hotel, a restaurant, a guest house — must register regardless of turnover. There is no
 *     threshold (GRA: "The 750,000 threshold is only applicable to the supply of goods. Supply of services — there is
 *     no threshold.").
 *   · suppliers of taxable goods register once annual taxable supplies reach GH₵750,000, raised from GH₵200,000.
 *
 * So a group with two properties can be in two different positions, and E-VAT is mandatory for every VAT-registered
 * business with no revenue threshold — an unregistered property must NOT submit, a registered one must.
 *
 *  vat_registered        is this property on the GRA VAT register
 *  vat_registered_at     since when (a return filed before this date is somebody else's problem)
 *  vat_supply_kind       services | goods | both — which side of Act 1151 the property falls on
 *  supports_e_invoicing  the property is able to e-invoice (a certified invoicing system / VSDC connection)
 *
 * Existing properties are backfilled as registered where they carry a TIN, because in Ghana the TIN is issued with the
 * VAT registration — so nothing that submits today stops submitting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotels', function (Blueprint $t) {
            $t->boolean('vat_registered')->default(false);
            $t->date('vat_registered_at')->nullable();
            $t->string('vat_supply_kind', 12)->default('services');
            $t->boolean('supports_e_invoicing')->default(true);
        });

        DB::table('hotels')->whereNotNull('tax_number')->where('tax_number', '!=', '')
            ->update(['vat_registered' => true]);
    }

    public function down(): void
    {
        Schema::table('hotels', function (Blueprint $t) {
            $t->dropColumn(['vat_registered', 'vat_registered_at', 'vat_supply_kind', 'supports_e_invoicing']);
        });
    }
};
