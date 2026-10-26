<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * POS payment ledger: one bill may be paid with several tenders and may remain
 * partially paid. The legacy pos_orders.payment_mode_id is retained as the
 * primary/legacy mode for compatibility and reporting.
 */
return new class extends Migration
{
    public function up(): void
    {
                if (Schema::hasTable('pos_payments')) {
            return;
        }

        Schema::create('pos_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('pos_order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('payment_mode_id')->nullable()->constrained()->nullOnDelete();
            $t->string('kind', 12)->default('payment'); // payment, refund (refunds are added by the POS reversal slice)
            $t->decimal('amount', 14, 2);                 // amount allocated to the bill
            $t->decimal('tendered_amount', 14, 2)->nullable();
            $t->decimal('change_amount', 14, 2)->default(0);
            $t->string('reference', 80)->nullable();
            $t->string('cheque_bank_name', 100)->nullable();
            $t->string('cheque_number', 30)->nullable();
            $t->string('cheque_bank_branch', 100)->nullable();
            $t->string('card_authorisation', 80)->nullable();
            $t->string('mobile_network', 30)->nullable();
            $t->string('mobile_phone', 30)->nullable();
            $t->string('notes', 190)->nullable();
            $t->date('business_date');
            $t->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('received_at')->nullable();
            $t->timestamps();
            $t->index(['hotel_id', 'pos_order_id', 'kind']);
            $t->index(['hotel_id', 'business_date', 'payment_mode_id']);
        });

        // Preserve historical direct POS settlements in the new ledger. This is
        // additive and idempotent because the table is created only once.
        DB::table('pos_orders')
            ->where('status', 'settled')
            ->where('settlement', 'payment')
            ->where('total', '>', 0)
            ->whereNotNull('payment_mode_id')
            ->orderBy('id')
            ->chunkById(500, function ($orders): void {
                $now = now();
                $rows = $orders->map(fn ($order) => [
                    'hotel_id' => $order->hotel_id,
                    'pos_order_id' => $order->id,
                    'payment_mode_id' => $order->payment_mode_id,
                    'kind' => 'payment',
                    'amount' => $order->total,
                    'tendered_amount' => null,
                    'change_amount' => 0,
                    'reference' => 'Legacy POS settlement',
                    'business_date' => $order->business_date,
                    'received_by' => $order->settled_by ?: $order->user_id,
                    'received_at' => $order->settled_at ?: $order->updated_at,
                    'created_at' => $order->settled_at ?: $order->updated_at ?: $now,
                    'updated_at' => $order->settled_at ?: $order->updated_at ?: $now,
                ])->all();
                if ($rows) {
                    DB::table('pos_payments')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_payments');
    }
};
