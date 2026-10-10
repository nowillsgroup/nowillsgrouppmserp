<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.1 – online payments: payment links (deposit / balance / booking-engine deposit) paid through a
 * hosted gateway checkout, the webhook/event log, gateway refunds and gateway payouts (settlements).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('online_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('pay_no', 20);
            $t->string('token', 40)->unique();
            $t->string('purpose', 12); // deposit | balance | booking
            $t->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('folio_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $t->string('payer_name', 150);
            $t->string('payer_email', 150)->nullable();
            $t->string('payer_phone', 30)->nullable();
            $t->string('description', 190);
            $t->decimal('amount', 12, 2);
            $t->string('currency', 3);
            $t->string('status', 12)->default('pending'); // pending | paid | review | expired | cancelled
            $t->string('provider', 12);
            $t->string('provider_ref', 120)->nullable();      // our reference / session / link id at the gateway
            $t->string('provider_payment_id', 120)->nullable(); // gateway transaction / payment-intent / payment id
            $t->text('checkout_url')->nullable();
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->decimal('amount_received', 12, 2)->nullable();
            $t->decimal('fee', 12, 2)->nullable();
            $t->string('channel', 30)->nullable(); // card, mobile_money, upi, bank …
            $t->decimal('refunded_amount', 12, 2)->default(0);
            $t->foreignId('folio_transaction_id')->nullable()->constrained('folio_transactions')->nullOnDelete();
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->string('last_error', 255)->nullable();
            $t->string('cancel_reason', 190)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'pay_no']);
            $t->index(['hotel_id', 'status']);
            $t->index(['provider', 'provider_ref']);
        });

        Schema::create('online_payment_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('online_payment_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('provider', 12);
            $t->string('source', 10); // webhook | return | api | staff | system
            $t->string('event', 60);
            $t->string('event_id', 120)->nullable();
            $t->boolean('signature_ok')->nullable();
            $t->string('result', 255)->nullable();
            $t->json('payload')->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['hotel_id', 'provider', 'event_id']);
        });

        Schema::create('online_refunds', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('online_payment_id')->constrained()->cascadeOnDelete();
            $t->decimal('amount', 12, 2);
            $t->string('reason', 190);
            $t->string('status', 12); // processed | pending
            $t->string('provider_ref', 120)->nullable();
            $t->foreignId('folio_transaction_id')->nullable()->constrained('folio_transactions')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('gateway_payouts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('payout_no', 20);
            $t->string('provider', 12);
            $t->date('date');
            $t->decimal('gross', 12, 2);
            $t->decimal('fees', 12, 2)->default(0);
            $t->foreignId('payment_mode_id')->constrained(); // where the money landed (bank)
            $t->string('reference', 120)->nullable();
            $t->string('notes', 255)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'payout_no']);
            $t->index(['hotel_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_payouts');
        Schema::dropIfExists('online_refunds');
        Schema::dropIfExists('online_payment_events');
        Schema::dropIfExists('online_payments');
    }
};

