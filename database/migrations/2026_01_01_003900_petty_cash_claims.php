<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** v4.6→4.7: petty cash (imprest floats, vouchers, IOUs, replenishment, cash counts) and staff expense claims. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('petty_floats', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('code', 20);
            $t->string('name', 80);
            $t->foreignId('account_head_id')->constrained('account_heads')->restrictOnDelete();
            $t->foreignId('custodian_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('imprest_amount', 12, 2);
            $t->decimal('voucher_limit', 12, 2)->default(500);
            $t->boolean('is_active')->default(true);
            $t->string('notes', 255)->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'code']);
        });

        Schema::create('petty_replenishments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('petty_float_id')->constrained()->cascadeOnDelete();
            $t->string('repl_no', 20);
            $t->date('date');
            $t->decimal('amount', 12, 2);
            $t->string('status', 12)->default('requested'); // requested, approved, paid, rejected
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->foreignId('payment_mode_id')->nullable()->constrained()->nullOnDelete();
            $t->date('paid_on')->nullable();
            $t->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('reference', 80)->nullable();
            $t->string('reject_reason', 255)->nullable();
            $t->string('notes', 255)->nullable();
            $t->timestamps();
            $t->index(['hotel_id', 'status']);
        });

        Schema::create('petty_txns', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('petty_float_id')->constrained()->cascadeOnDelete();
            $t->string('txn_no', 20);
            $t->string('type', 12); // topup, expense, advance, settle, variance, claim_pay
            $t->date('date');
            $t->string('payee', 120)->nullable();
            $t->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $t->string('description', 255)->nullable();
            $t->foreignId('expense_head_id')->nullable()->constrained('account_heads')->nullOnDelete();
            $t->decimal('amount', 12, 2);                 // gross; for a settlement: the IOU cleared
            $t->decimal('spent', 12, 2)->default(0);      // settlement: receipts brought back
            $t->decimal('vat_amount', 12, 2)->default(0); // input VAT + NHIL + GETFund on a VAT invoice
            $t->decimal('effect', 12, 2)->default(0);     // signed effect on the cash in the tin
            $t->string('status', 10)->default('posted');  // pending, posted, rejected, void
            $t->string('receipt_path')->nullable();
            $t->string('receipt_ref', 60)->nullable();
            $t->string('no_receipt_reason', 255)->nullable();
            $t->foreignId('advance_id')->nullable()->constrained('petty_txns')->nullOnDelete();
            $t->date('due_date')->nullable();
            $t->timestamp('settled_at')->nullable();
            $t->foreignId('payment_mode_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('petty_replenishment_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedBigInteger('expense_claim_id')->nullable();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->string('reject_reason', 255)->nullable();
            $t->string('void_reason', 255)->nullable();
            $t->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('voided_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['petty_float_id', 'date']);
            $t->index(['hotel_id', 'status']);
        });

        Schema::create('petty_counts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('petty_float_id')->constrained()->cascadeOnDelete();
            $t->string('count_no', 20);
            $t->date('date');
            $t->json('denominations');
            $t->decimal('counted', 12, 2);
            $t->decimal('book', 12, 2);
            $t->decimal('variance', 12, 2);
            $t->string('status', 10)->default('agreed'); // agreed, pending, posted, rejected
            $t->string('note', 255)->nullable();
            $t->foreignId('counted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('witness_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->foreignId('variance_txn_id')->nullable()->constrained('petty_txns')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('expense_claims', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('claim_no', 20);
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->string('title', 150);
            $t->string('status', 10)->default('draft'); // draft, submitted, approved, rejected, paid
            $t->decimal('total', 12, 2)->default(0);
            $t->decimal('vat_total', 12, 2)->default(0);
            $t->timestamp('submitted_at')->nullable();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->date('approved_on')->nullable();
            $t->string('reject_reason', 255)->nullable();
            $t->string('pay_method', 10)->nullable(); // mode, petty
            $t->foreignId('payment_mode_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('petty_txn_id')->nullable()->constrained('petty_txns')->nullOnDelete();
            $t->date('paid_on')->nullable();
            $t->string('pay_reference', 80)->nullable();
            $t->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('notes', 500)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['hotel_id', 'status']);
            $t->index(['hotel_id', 'employee_id']);
        });

        Schema::create('expense_claim_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('expense_claim_id')->constrained()->cascadeOnDelete();
            $t->date('date');
            $t->string('category', 20);
            $t->foreignId('expense_head_id')->constrained('account_heads')->restrictOnDelete();
            $t->string('description', 255);
            $t->decimal('km', 8, 1)->nullable();
            $t->decimal('amount', 12, 2);
            $t->decimal('vat_amount', 12, 2)->default(0);
            $t->string('receipt_path')->nullable();
            $t->string('receipt_ref', 60)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_claim_lines');
        Schema::dropIfExists('expense_claims');
        Schema::dropIfExists('petty_counts');
        Schema::dropIfExists('petty_txns');
        Schema::dropIfExists('petty_replenishments');
        Schema::dropIfExists('petty_floats');
    }
};

