<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('billing_reconciliations')) {
            return;
        }

        Schema::create('billing_reconciliations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('reconciliation_no', 30);
            $t->date('period_from');
            $t->date('period_to');
            $t->string('status', 16)->default('draft'); // draft, finalised
            $t->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('finalised_at')->nullable();
            $t->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reopened_at')->nullable();
            $t->text('reopen_reason')->nullable();
            $t->timestamp('refreshed_at')->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'reconciliation_no']);
            $t->unique(['hotel_id', 'period_from', 'period_to']);
            $t->index(['hotel_id', 'status', 'period_from']);
        });

        if (Schema::hasTable('billing_reconciliation_items')) {
            return;
        }

        Schema::create('billing_reconciliation_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('billing_reconciliation_id')->constrained('billing_reconciliations')->cascadeOnDelete();
            $t->foreignId('folio_id')->constrained('folios')->restrictOnDelete();
            $t->string('document_no', 30);
            $t->string('folio_no', 30);
            $t->string('document_type', 16); // invoice, credit_note
            $t->date('document_date');
            $t->string('original_invoice_no', 30)->nullable();
            $t->string('customer_name', 190)->nullable();
            $t->decimal('document_amount', 14, 2)->default(0);
            $t->decimal('cash_payments', 14, 2)->default(0);
            $t->decimal('cash_refunds', 14, 2)->default(0);
            $t->decimal('city_ledger_amount', 14, 2)->default(0);
            $t->decimal('settled_payments', 14, 2)->nullable();
            $t->decimal('settled_refunds', 14, 2)->nullable();
            $t->string('settlement_reference', 160)->nullable();
            $t->string('evidence_path', 255)->nullable();
            $t->string('evidence_name', 190)->nullable();
            $t->string('review_status', 20)->default('pending'); // pending, matched, exception, not_applicable
            $t->text('review_note')->nullable();
            $t->string('source_status', 20)->default('issued'); // issued, cancelled, not_issued, out_of_period, missing
            $t->string('source_hash', 64)->nullable();
            $t->timestamp('source_changed_at')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamp('snapshot_at')->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'billing_reconciliation_id', 'folio_id'], 'bcr_items_hotel_recon_folio');
            $t->index(['hotel_id', 'document_date', 'document_type']);
            $t->index(['hotel_id', 'review_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_reconciliation_items');
        Schema::dropIfExists('billing_reconciliations');
    }
};
