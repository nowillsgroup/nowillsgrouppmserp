<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v4.7→4.8 Procurement II: supplier price lists, requests for quotation (RFQ) with a tokenised supplier quote page,
 * comparative statements and split awards, a value-based PO approval matrix, partial goods receipts (GRN) with
 * accept/reject quantities and short-closing, feeding a supplier scorecard.
 */
return new class extends Migration
{
    public function up(): void
    {
                if (Schema::hasTable('supplier_prices')) {
            return;
        }

        Schema::create('supplier_prices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $t->foreignId('inventory_item_id')->constrained()->cascadeOnDelete();
            $t->decimal('rate', 12, 2);
            $t->decimal('min_qty', 12, 2)->default(0);
            $t->unsignedSmallInteger('lead_days')->nullable();
            $t->date('valid_from');
            $t->date('valid_to')->nullable();
            $t->string('source', 10)->default('manual');       // manual | rfq
            $t->unsignedBigInteger('rfq_id')->nullable();
            $t->string('notes', 255)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['hotel_id', 'inventory_item_id', 'supplier_id']);
        });

                if (Schema::hasTable('rfqs')) {
            return;
        }

        Schema::create('rfqs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('rfq_no', 20);
            $t->string('title', 150);
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->date('rfq_date');
            $t->date('respond_by');                              // quotation deadline (end of day)
            $t->date('needed_by')->nullable();                   // required delivery date
            $t->string('status', 12)->default('draft');          // draft, sent, closed, awarded, cancelled
            $t->text('terms')->nullable();                       // printed on the RFQ / shown to suppliers
            $t->string('notes', 500)->nullable();                // internal
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->foreignId('awarded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('awarded_at')->nullable();
            $t->string('award_note', 500)->nullable();
            $t->string('cancel_reason', 255)->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'rfq_no']);
            $t->index(['hotel_id', 'status']);
        });

                if (Schema::hasTable('rfq_lines')) {
            return;
        }

        Schema::create('rfq_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('rfq_id')->constrained()->cascadeOnDelete();
            $t->foreignId('inventory_item_id')->constrained()->restrictOnDelete();
            $t->decimal('qty', 12, 2);
            $t->string('specification', 255)->nullable();
            $t->unsignedBigInteger('awarded_quote_line_id')->nullable();
            $t->string('award_reason', 255)->nullable();        // required when not awarded to the lowest price
            $t->timestamps();
        });

                if (Schema::hasTable('rfq_suppliers')) {
            return;
        }

        Schema::create('rfq_suppliers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('rfq_id')->constrained()->cascadeOnDelete();
            $t->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $t->string('token', 40)->unique();                   // private supplier quote link /quote/{token}
            $t->string('email_status', 10)->nullable();          // sent | failed | null (not e-mailed)
            $t->timestamp('invited_at')->nullable();
            $t->timestamp('viewed_at')->nullable();
            $t->timestamp('responded_at')->nullable();
            $t->timestamp('declined_at')->nullable();
            $t->string('decline_reason', 255)->nullable();
            $t->timestamps();
            $t->unique(['rfq_id', 'supplier_id']);
        });

                if (Schema::hasTable('rfq_quotes')) {
            return;
        }

        Schema::create('rfq_quotes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('rfq_id')->constrained()->cascadeOnDelete();
            $t->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $t->string('quote_ref', 60)->nullable();
            $t->date('quote_date');
            $t->date('valid_until')->nullable();
            $t->unsignedSmallInteger('delivery_days')->nullable();
            $t->unsignedSmallInteger('payment_terms_days')->nullable();
            $t->string('notes', 1000)->nullable();
            $t->string('source', 10)->default('manual');         // portal | manual (entered by the buyer from a paper / e-mail quote)
            $t->decimal('subtotal', 14, 2)->default(0);
            $t->decimal('tax', 14, 2)->default(0);
            $t->decimal('total', 14, 2)->default(0);
            $t->unsignedSmallInteger('revision')->default(1);
            $t->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['rfq_id', 'supplier_id']);
        });

                if (Schema::hasTable('rfq_quote_lines')) {
            return;
        }

        Schema::create('rfq_quote_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('rfq_quote_id')->constrained()->cascadeOnDelete();
            $t->foreignId('rfq_line_id')->constrained()->cascadeOnDelete();
            $t->decimal('rate', 12, 2);
            $t->decimal('tax_rate', 5, 2)->default(0);
            $t->decimal('qty_offered', 12, 2)->nullable();       // null = full quantity
            $t->string('remarks', 255)->nullable();             // brand, pack size, substitution …
            $t->timestamps();
            $t->unique(['rfq_quote_id', 'rfq_line_id']);
        });

        Schema::table('purchase_orders', function (Blueprint $t) {
            $t->foreignId('rfq_id')->nullable()->after('store_id')->constrained('rfqs')->nullOnDelete();
            $t->unsignedTinyInteger('approval_level')->nullable()->after('status');
            $t->foreignId('approved_by')->nullable()->after('approval_level')->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable()->after('approved_by');
            $t->string('close_reason', 255)->nullable()->after('received_at');
            $t->timestamp('closed_at')->nullable()->after('close_reason');
            $t->foreignId('closed_by')->nullable()->after('closed_at')->constrained('users')->nullOnDelete();
        });

        Schema::table('purchase_order_items', function (Blueprint $t) {
            $t->decimal('received_qty', 12, 2)->default(0)->after('amount');
            $t->decimal('rejected_qty', 12, 2)->default(0)->after('received_qty');
            $t->decimal('list_rate', 12, 2)->nullable()->after('rejected_qty'); // agreed price-list rate when the PO was raised
            $t->unsignedBigInteger('rfq_quote_line_id')->nullable()->after('list_rate');
        });
        // POs received in full before v4.8 (single-step receipt): the whole ordered quantity came in.
        // Use the query builder instead of MySQL's UPDATE ... JOIN so fresh SQLite previews and tests
        // apply the same backfill as MySQL/MariaDB.
        \Illuminate\Support\Facades\DB::table('purchase_order_items')
            ->whereIn('purchase_order_id', function ($query) {
                $query->select('id')->from('purchase_orders')->whereIn('status', ['received']);
            })
            ->update(['received_qty' => \Illuminate\Support\Facades\DB::raw('qty')]);
        \Illuminate\Support\Facades\DB::statement('UPDATE purchase_orders SET approved_at = updated_at WHERE status IN (\'approved\', \'received\') AND approved_at IS NULL');

                if (Schema::hasTable('goods_receipts')) {
            return;
        }

        Schema::create('goods_receipts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('grn_no', 20);
            $t->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $t->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->date('date');
            $t->string('delivery_note', 60)->nullable();
            $t->string('invoice_no', 60)->nullable();
            $t->string('vehicle_no', 30)->nullable();
            $t->decimal('subtotal', 14, 2)->default(0);          // accepted quantity × PO rate
            $t->decimal('tax', 14, 2)->default(0);
            $t->decimal('total', 14, 2)->default(0);
            $t->decimal('rejected_value', 14, 2)->default(0);
            $t->string('notes', 500)->nullable();
            $t->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'grn_no']);
            $t->index(['hotel_id', 'date']);
        });

                if (Schema::hasTable('goods_receipt_lines')) {
            return;
        }

        Schema::create('goods_receipt_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('goods_receipt_id')->constrained()->cascadeOnDelete();
            $t->foreignId('purchase_order_item_id')->constrained()->cascadeOnDelete();
            $t->foreignId('inventory_item_id')->constrained()->restrictOnDelete();
            $t->decimal('qty_delivered', 12, 2);
            $t->decimal('qty_accepted', 12, 2);
            $t->decimal('qty_rejected', 12, 2)->default(0);
            $t->string('reject_reason', 120)->nullable();
            $t->decimal('rate', 12, 2);
            $t->decimal('tax_rate', 5, 2)->default(0);
            $t->decimal('amount', 14, 2);                         // accepted × rate
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_lines');
        Schema::dropIfExists('goods_receipts');
        Schema::table('purchase_order_items', fn (Blueprint $t) => $t->dropColumn(['received_qty', 'rejected_qty', 'list_rate', 'rfq_quote_line_id']));
        Schema::table('purchase_orders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('rfq_id');
            $t->dropConstrainedForeignId('approved_by');
            $t->dropConstrainedForeignId('closed_by');
            $t->dropColumn(['approval_level', 'approved_at', 'close_reason', 'closed_at']);
        });
        Schema::dropIfExists('rfq_quote_lines');
        Schema::dropIfExists('rfq_quotes');
        Schema::dropIfExists('rfq_suppliers');
        Schema::dropIfExists('rfq_lines');
        Schema::dropIfExists('rfqs');
        Schema::dropIfExists('supplier_prices');
    }
};

