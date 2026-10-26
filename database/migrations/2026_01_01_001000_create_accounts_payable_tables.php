<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9 (v1.8): accounts payable – supplier bills (matched to goods receipts or direct expenses),
 * debit notes, supplier payments with allocation, ageing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $t) {
            $t->unsignedSmallInteger('payment_terms_days')->default(30)->after('tax_number');
            $t->foreignId('expense_head_id')->nullable()->after('payment_terms_days')->constrained('account_heads')->nullOnDelete();
            $t->string('bank_details', 255)->nullable()->after('expense_head_id');
        });

                if (Schema::hasTable('supplier_invoices')) {
            return;
        }

        Schema::create('supplier_invoices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('doc_no', 20);                         // BILL-00001 / DN-00001
            $t->string('type', 12)->default('invoice');       // invoice | debit_note
            $t->string('kind', 10)->default('expense');       // goods (against a goods receipt) | expense (direct)
            $t->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $t->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('against_invoice_id')->nullable()->constrained('supplier_invoices')->nullOnDelete(); // debit note → bill
            $t->string('supplier_ref', 60)->nullable();       // supplier's invoice / credit-note number
            $t->date('doc_date');
            $t->date('due_date')->nullable();
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->decimal('tax', 12, 2)->default(0);
            $t->decimal('total', 12, 2)->default(0);
            $t->decimal('grn_total', 12, 2)->nullable();      // goods receipt value at match time
            $t->decimal('allocated', 12, 2)->default(0);     // paid / applied so far
            $t->string('status', 12)->default('open');        // on_hold, open, partial, paid, cancelled
            $t->string('notes', 500)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->string('cancel_reason', 255)->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'doc_no']);
            $t->index(['hotel_id', 'supplier_id', 'status']);
            $t->index(['hotel_id', 'doc_date']);
        });

                if (Schema::hasTable('supplier_invoice_lines')) {
            return;
        }

        Schema::create('supplier_invoice_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('supplier_invoice_id')->constrained()->cascadeOnDelete();
            $t->foreignId('account_head_id')->nullable()->constrained()->nullOnDelete();
            $t->string('description', 200);
            $t->decimal('amount', 12, 2);
            $t->decimal('tax_rate', 5, 2)->default(0);
            $t->decimal('tax_amount', 12, 2)->default(0);
            $t->timestamps();
        });

                if (Schema::hasTable('supplier_payments')) {
            return;
        }

        Schema::create('supplier_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('payment_no', 20);
            $t->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $t->date('date');
            $t->foreignId('payment_mode_id')->constrained()->restrictOnDelete();
            $t->decimal('amount', 12, 2);
            $t->decimal('allocated', 12, 2)->default(0);
            $t->string('reference', 60)->nullable();
            $t->string('notes', 500)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('cancelled_at')->nullable();
            $t->string('cancel_reason', 255)->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'payment_no']);
            $t->index(['hotel_id', 'supplier_id', 'date']);
        });

        // Payment (or debit note) applied to a bill
                if (Schema::hasTable('ap_allocations')) {
            return;
        }

        Schema::create('ap_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('supplier_invoice_id')->constrained()->cascadeOnDelete();
            $t->foreignId('supplier_payment_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('debit_note_id')->nullable()->constrained('supplier_invoices')->cascadeOnDelete();
            $t->decimal('amount', 12, 2);
            $t->date('date');
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ap_allocations');
        Schema::dropIfExists('supplier_payments');
        Schema::dropIfExists('supplier_invoice_lines');
        Schema::dropIfExists('supplier_invoices');
        Schema::table('suppliers', function (Blueprint $t) {
            $t->dropConstrainedForeignId('expense_head_id');
            $t->dropColumn(['payment_terms_days', 'bank_details']);
        });
    }
};

