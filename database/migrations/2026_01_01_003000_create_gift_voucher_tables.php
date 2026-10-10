<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 29 (v3.8): gift vouchers & stored-value cards.
 *  • Voucher types (open-amount gift cards, fixed-value / single-use experience vouchers, reloadable stored-value cards)
 *    with validity, value limits and where they can be spent (room folio, restaurant / bar POS).
 *  • Vouchers (GV-XXXX-XXXX) sold at the desk, issued free as promotions / service recovery, or in bulk campaigns;
 *    e-mailed to the recipient with a public voucher page and QR code.
 *  • A transaction ledger per voucher: issue, reload, redeem, reversal, expiry (breakage) and cancellation – the running
 *    balance is the hotel's liability, posted to the general ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gift_voucher_types', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('name', 80);
            $t->string('kind', 12)->default('value');          // value (spend in parts) | single (one redemption) | stored (reloadable card)
            $t->decimal('face_value', 12, 2)->nullable();       // null = open amount chosen at sale
            $t->decimal('min_value', 12, 2)->default(0);
            $t->decimal('max_value', 12, 2)->default(0);        // 0 = hotel limit
            $t->unsignedSmallInteger('validity_months')->default(12);
            $t->json('usable_on')->nullable();                  // ['folio','pos']
            $t->string('color', 7)->default('#0f766e');
            $t->string('terms', 1000)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('gift_vouchers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('code', 14)->unique();                   // GV-XXXX-XXXX, unique across properties (public page)
            $t->foreignId('gift_voucher_type_id')->constrained()->restrictOnDelete();
            $t->string('source', 10)->default('sold');         // sold | promo (campaign / complimentary)
            $t->string('campaign', 60)->nullable();
            $t->decimal('face_value', 12, 2);
            $t->decimal('balance', 12, 2);
            $t->string('status', 12)->default('active');       // active | redeemed | expired | suspended | cancelled
            $t->date('issued_on');
            $t->date('expires_on');
            $t->string('purchaser_name', 100)->nullable();
            $t->string('purchaser_email', 150)->nullable();
            $t->string('purchaser_phone', 30)->nullable();
            $t->string('recipient_name', 100)->nullable();
            $t->string('recipient_email', 150)->nullable();
            $t->string('message', 300)->nullable();
            $t->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('payment_mode_id')->nullable()->constrained()->nullOnDelete();
            $t->string('payment_ref', 80)->nullable();
            $t->string('status_note', 200)->nullable();
            $t->timestamp('emailed_at')->nullable();
            $t->timestamp('reminded_at')->nullable();
            $t->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['hotel_id', 'status', 'expires_on']);
        });

        Schema::create('gift_voucher_transactions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('gift_voucher_id')->constrained()->cascadeOnDelete();
            $t->string('type', 10);                             // issue | reload | redeem | reversal | expire | cancel
            $t->decimal('amount', 12, 2);                       // always positive – the type says which way
            $t->decimal('balance_after', 12, 2);
            $t->date('business_date');
            $t->foreignId('payment_mode_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('folio_transaction_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('pos_order_id')->nullable()->constrained()->nullOnDelete();
            $t->string('outlet', 60)->nullable();               // where it was spent (Front office / outlet name)
            $t->string('reference', 80)->nullable();
            $t->string('note', 200)->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->index(['hotel_id', 'business_date']);
            $t->index(['gift_voucher_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_voucher_transactions');
        Schema::dropIfExists('gift_vouchers');
        Schema::dropIfExists('gift_voucher_types');
    }
};

