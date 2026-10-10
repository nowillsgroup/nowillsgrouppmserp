<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** v3.0 – GST compliance: e-invoicing (IRN), credit notes, cancelled documents, GSTR-1 periods. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('folios', function (Blueprint $t) {
            $t->date('invoice_date')->nullable()->after('invoice_no');
            $t->foreignId('original_folio_id')->nullable()->after('company_id')->constrained('folios')->nullOnDelete(); // credit note → invoice
            $t->string('note_reason', 30)->nullable();
            $t->string('note_remarks', 255)->nullable();
            $t->string('supply_type', 3)->nullable();          // B2B / B2C
            $t->string('buyer_gstin', 15)->nullable();
            $t->string('buyer_name', 150)->nullable();
            $t->string('buyer_address', 255)->nullable();
            $t->char('buyer_state_code', 2)->nullable();
            $t->char('pos_state_code', 2)->nullable();          // place of supply
            $t->string('irn', 64)->nullable();
            $t->string('irn_status', 12)->nullable();           // pending / generated / failed / cancelled
            $t->string('irn_ack_no', 20)->nullable();
            $t->dateTime('irn_ack_at')->nullable();
            $t->text('irn_qr')->nullable();
            $t->string('irn_error', 500)->nullable();
            $t->boolean('irn_retryable')->default(true);
            $t->unsignedTinyInteger('irn_attempts')->default(0);
            $t->dateTime('irn_next_try_at')->nullable();
            $t->index(['hotel_id', 'invoice_date']);
            $t->index(['hotel_id', 'irn_status']);
        });

        Schema::create('gst_cancelled_docs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('folio_id')->nullable()->constrained('folios')->nullOnDelete();
            $t->string('doc_type', 12);                          // invoice / credit_note
            $t->string('doc_no', 30);
            $t->date('doc_date');
            $t->string('buyer_gstin', 15)->nullable();
            $t->decimal('value', 14, 2)->default(0);
            $t->string('irn', 64)->nullable();
            $t->string('reason_code', 2);
            $t->string('remarks', 255);
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['hotel_id', 'doc_date']);
        });

        Schema::create('einvoice_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('folio_id')->nullable()->constrained('folios')->nullOnDelete();
            $t->string('doc_no', 30)->nullable();
            $t->string('action', 10);                            // generate / cancel
            $t->string('driver', 10);                            // sandbox / gsp
            $t->boolean('ok');
            $t->string('error_code', 20)->nullable();
            $t->string('message', 500)->nullable();
            $t->longText('request')->nullable();
            $t->longText('response')->nullable();
            $t->unsignedInteger('duration_ms')->default(0);
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at')->nullable();
            $t->index(['hotel_id', 'folio_id']);
        });

        Schema::create('gst_periods', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->char('period', 7);                               // YYYY-MM
            $t->string('arn', 20);
            $t->date('filed_on');
            $t->json('summary')->nullable();
            $t->foreignId('filed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gst_periods');
        Schema::dropIfExists('einvoice_logs');
        Schema::dropIfExists('gst_cancelled_docs');
        Schema::table('folios', function (Blueprint $t) {
            $t->dropForeign(['original_folio_id']);
            $t->dropIndex(['hotel_id', 'invoice_date']);
            $t->dropIndex(['hotel_id', 'irn_status']);
            $t->dropColumn(['invoice_date', 'original_folio_id', 'note_reason', 'note_remarks', 'supply_type', 'buyer_gstin', 'buyer_name', 'buyer_address',
                'buyer_state_code', 'pos_state_code', 'irn', 'irn_status', 'irn_ack_no', 'irn_ack_at', 'irn_qr', 'irn_error', 'irn_retryable', 'irn_attempts', 'irn_next_try_at']);
        });
    }
};

