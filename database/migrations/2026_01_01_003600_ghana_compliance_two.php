<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v4.4 Ghana compliance II
 *  - GRA E-VAT stamping of restaurant / outlet bills (pos_orders.evat_*), optional customer TIN on the bill
 *  - withholding tax on supplier payments (Act 896) + withholding VAT, WHT credit certificates, monthly WHT return
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_orders', function (Blueprint $t) {
            $t->string('buyer_tin', 20)->nullable()->after('settled_by');
            $t->string('buyer_name', 120)->nullable()->after('buyer_tin');
            $t->string('evat_status', 12)->nullable()->after('buyer_name'); // pending | generated | failed
            $t->string('evat_sdc_id', 40)->nullable()->after('evat_status');
            $t->string('evat_rec_num', 40)->nullable()->after('evat_sdc_id');
            $t->timestamp('evat_at')->nullable()->after('evat_rec_num');
            $t->text('evat_qr')->nullable()->after('evat_at');
            $t->string('evat_intdata', 60)->nullable()->after('evat_qr');
            $t->string('evat_signature', 40)->nullable()->after('evat_intdata');
            $t->string('evat_error', 500)->nullable()->after('evat_signature');
            $t->unsignedTinyInteger('evat_attempts')->default(0)->after('evat_error');
            $t->boolean('evat_retryable')->default(true)->after('evat_attempts');
            $t->timestamp('evat_next_try_at')->nullable()->after('evat_retryable');
            $t->index(['hotel_id', 'evat_status']);
        });
        Schema::table('einvoice_logs', function (Blueprint $t) {
            $t->foreignId('pos_order_id')->nullable()->after('folio_id')->constrained('pos_orders')->nullOnDelete();
        });
        Schema::table('suppliers', function (Blueprint $t) {
            $t->string('wht_category', 12)->default('services')->after('tax_number'); // none|goods|works|services|rent_res|rent_com|nonresident
            $t->string('wht_exempt_ref', 60)->nullable()->after('wht_category');
            $t->date('wht_exempt_until')->nullable()->after('wht_exempt_ref');
        });
        Schema::table('supplier_payments', function (Blueprint $t) {
            $t->string('wht_category', 12)->nullable()->after('amount');
            $t->decimal('wht_rate', 5, 2)->default(0)->after('wht_category');
            $t->decimal('wht_base', 12, 2)->default(0)->after('wht_rate');      // VAT-exclusive value paid (counts towards the annual threshold)
            $t->decimal('wht_amount', 12, 2)->default(0)->after('wht_base');
            $t->decimal('wvat_base', 12, 2)->default(0)->after('wht_amount');
            $t->decimal('wvat_amount', 12, 2)->default(0)->after('wvat_base');
            $t->string('wht_note', 255)->nullable()->after('wvat_amount');       // why nothing was withheld (exemption, threshold, override)
            $t->string('wht_cert_no', 20)->nullable()->after('wht_note');
            $t->index(['hotel_id', 'date', 'wht_amount']);
        });
        Schema::create('wht_periods', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->char('period', 7);
            $t->string('reference', 40);
            $t->date('filed_on');
            $t->json('summary')->nullable();
            $t->foreignId('filed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wht_periods');
        Schema::table('supplier_payments', function (Blueprint $t) {
            $t->dropIndex(['hotel_id', 'date', 'wht_amount']);
            $t->dropColumn(['wht_category', 'wht_rate', 'wht_base', 'wht_amount', 'wvat_base', 'wvat_amount', 'wht_note', 'wht_cert_no']);
        });
        Schema::table('suppliers', fn (Blueprint $t) => $t->dropColumn(['wht_category', 'wht_exempt_ref', 'wht_exempt_until']));
        Schema::table('einvoice_logs', fn (Blueprint $t) => $t->dropConstrainedForeignId('pos_order_id'));
        Schema::table('pos_orders', function (Blueprint $t) {
            $t->dropIndex(['hotel_id', 'evat_status']);
            $t->dropColumn(['buyer_tin', 'buyer_name', 'evat_status', 'evat_sdc_id', 'evat_rec_num', 'evat_at', 'evat_qr', 'evat_intdata', 'evat_signature', 'evat_error', 'evat_attempts', 'evat_retryable', 'evat_next_try_at']);
        });
    }
};

