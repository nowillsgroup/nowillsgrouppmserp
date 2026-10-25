<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Non-operational, append-only retention for historical supplier bills/payments.
 * It deliberately does not create AP subledger or GL records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_paid_history_archives', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('batch_id')->nullable()->constrained('data_migration_batches')->nullOnDelete();
            $t->string('external_id', 100);
            $t->string('supplier_external_id', 100);
            $t->string('supplier_name_snapshot', 255);
            $t->string('record_type', 24);
            $t->string('document_ref', 120);
            $t->date('cutover_date');
            $t->date('document_date');
            $t->char('currency', 3);
            $t->decimal('amount', 14, 2);
            $t->string('bill_external_id', 100)->nullable();
            $t->string('payment_external_id', 100)->nullable();
            $t->string('credit_external_id', 100)->nullable();
            $t->text('remarks')->nullable();
            $t->json('source_payload')->nullable();
            $t->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'external_id'], 'spha_hotel_external_unique');
            $t->index(['hotel_id', 'record_type', 'document_date'], 'spha_hotel_type_date_idx');
            $t->index(['hotel_id', 'supplier_external_id', 'document_date'], 'spha_hotel_supplier_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_paid_history_archives');
    }
};
