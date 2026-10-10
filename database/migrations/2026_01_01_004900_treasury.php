<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v5.7 Treasury & cash management: a register of bank / Mobile Money / cash / petty / clearing accounts, fund transfers
 * (cash & cheque banking with deposit slips, card and MoMo settlement sweeps net of fees, bank-to-bank transfers) through
 * Cash in Transit with four-eyes approval, received cheques incl. post-dated (deposit, clear, bounce, return), cheque
 * books and issued cheques (void, stop, stale), a 13-week cash forecast with manual lines, and the IAS 7 cash-flow
 * classification of balance-sheet accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        // IAS 7 classification override for balance-sheet accounts (null = automatic from the account group)
        Schema::table('account_heads', function (Blueprint $t) {
            $t->string('cf_class', 12)->nullable()->after('group_name');   // cash | operating | noncash | investing | financing
        });

        Schema::create('treasury_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('account_head_id')->constrained('account_heads')->cascadeOnDelete();
            $t->string('kind', 10);                                    // bank | momo | cash | petty | clearing | forex | transit | cheques
            $t->string('name', 100);
            $t->string('bank_name', 100)->nullable();
            $t->string('branch', 100)->nullable();
            $t->string('account_no', 40)->nullable();
            $t->string('swift', 20)->nullable();
            $t->char('currency', 3)->default('GHS');
            $t->decimal('min_balance', 14, 2)->default(0);
            $t->decimal('overdraft_limit', 14, 2)->default(0);
            $t->decimal('fee_rate', 5, 2)->default(0);                  // settlement fee % (card MDR / MoMo merchant fee)
            $t->unsignedTinyInteger('settle_days')->default(0);         // clearing accounts: days until the acquirer pays out
            $t->boolean('in_position')->default(true);                  // counts as cash & cash equivalents
            $t->boolean('is_active')->default(true);
            $t->string('notes', 255)->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'account_head_id']);
        });

        Schema::create('treasury_transfers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('number', 20);
            $t->string('kind', 10);                                     // deposit | sweep | transfer | withdrawal
            $t->foreignId('from_account_id')->constrained('treasury_accounts');
            $t->foreignId('to_account_id')->constrained('treasury_accounts');
            $t->decimal('cash_amount', 14, 2)->default(0);              // from the source account
            $t->decimal('cheque_amount', 14, 2)->default(0);            // cheques banked with a deposit
            $t->decimal('amount', 14, 2);                               // total sent (gross)
            $t->decimal('fee', 14, 2)->default(0);
            $t->foreignId('fee_account_id')->nullable()->constrained('account_heads')->nullOnDelete();
            $t->boolean('via_transit')->default(true);
            $t->date('date');                                           // requested / value date
            $t->string('slip_no', 40)->nullable();                      // deposit slip / bank reference
            $t->json('cash_count')->nullable();                         // denomination => pieces
            $t->string('status', 12)->default('requested');             // requested | approved | in_transit | completed | cancelled | rejected
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->foreignId('dispatched_by')->nullable()->constrained('users')->nullOnDelete();
            $t->date('dispatched_on')->nullable();
            $t->foreignId('dispatch_journal_id')->nullable()->constrained('gl_journals')->nullOnDelete();
            $t->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->date('completed_on')->nullable();
            $t->decimal('received_amount', 14, 2)->nullable();          // credited by the bank (before the fee)
            $t->decimal('variance', 14, 2)->default(0);                 // short (−) / over (+) banking
            $t->string('variance_reason', 255)->nullable();
            $t->foreignId('complete_journal_id')->nullable()->constrained('gl_journals')->nullOnDelete();
            $t->string('notes', 255)->nullable();
            $t->string('reason', 255)->nullable();                      // reject / cancel / recall reason
            $t->timestamps();
            $t->unique(['hotel_id', 'number']);
            $t->index(['hotel_id', 'status']);
        });

        Schema::create('cheques_received', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('number', 20);
            $t->string('source', 8)->default('treasury');               // treasury | voucher | folio (captured from a cheque-mode payment)
            $t->string('cheque_no', 20)->nullable();
            $t->string('drawer', 150);
            $t->string('bank_name', 100)->nullable();
            $t->date('cheque_date');
            $t->date('received_on');
            $t->decimal('amount', 14, 2);
            $t->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('account_head_id')->nullable()->constrained('account_heads')->nullOnDelete();   // other receipts
            $t->foreignId('voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
            $t->foreignId('folio_transaction_id')->nullable()->constrained('folio_transactions')->nullOnDelete();
            $t->date('recognised_on')->nullable();                      // receipt posted (Dr Cheques in Hand)
            $t->string('status', 10)->default('in_hand');               // pdc | in_hand | deposited | cleared | bounced | returned | cancelled
            $t->foreignId('transfer_id')->nullable()->constrained('treasury_transfers')->nullOnDelete();
            $t->date('left_on')->nullable();                            // out of Cheques in Hand (deposit dispatched / returned)
            $t->date('cleared_on')->nullable();
            $t->date('bounced_on')->nullable();
            $t->string('bounce_reason', 150)->nullable();
            $t->decimal('bounce_fee', 10, 2)->default(0);
            $t->foreignId('bounce_voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
            $t->foreignId('bounce_journal_id')->nullable()->constrained('gl_journals')->nullOnDelete();
            $t->string('notes', 255)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'number']);
            $t->index(['hotel_id', 'status']);
        });

        Schema::create('cheque_books', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('treasury_account_id')->constrained('treasury_accounts')->cascadeOnDelete();
            $t->unsignedInteger('first_no');
            $t->unsignedInteger('last_no');
            $t->unsignedInteger('next_no');
            $t->unsignedTinyInteger('digits')->default(6);
            $t->date('received_on');
            $t->string('status', 10)->default('active');                // active | used | cancelled
            $t->timestamps();
        });

        Schema::create('cheques_issued', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('cheque_book_id')->constrained()->cascadeOnDelete();
            $t->string('cheque_no', 20);
            $t->string('payee', 150)->nullable();
            $t->decimal('amount', 14, 2)->default(0);
            $t->date('cheque_date');
            $t->foreignId('supplier_payment_id')->nullable()->constrained('supplier_payments')->nullOnDelete();
            $t->foreignId('account_head_id')->nullable()->constrained('account_heads')->nullOnDelete();   // standalone cheque: debit account
            $t->foreignId('gl_journal_id')->nullable()->constrained('gl_journals')->nullOnDelete();
            $t->string('memo', 255)->nullable();
            $t->string('status', 10)->default('issued');                // issued | cleared | void | stopped | stale
            $t->date('cleared_on')->nullable();
            $t->string('void_reason', 255)->nullable();
            $t->timestamp('voided_at')->nullable();
            $t->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['cheque_book_id', 'cheque_no']);
            $t->index(['hotel_id', 'status']);
        });

        Schema::create('cash_forecast_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('description', 150);
            $t->string('direction', 3);                                 // in | out
            $t->string('category', 30)->default('Other');
            $t->decimal('amount', 14, 2);
            $t->date('first_date');
            $t->string('frequency', 8)->default('once');                // once | weekly | monthly
            $t->date('until_date')->nullable();
            $t->boolean('is_active')->default(true);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['cash_forecast_lines', 'cheques_issued', 'cheque_books', 'cheques_received', 'treasury_transfers', 'treasury_accounts'] as $tb) {
            Schema::dropIfExists($tb);
        }
        Schema::table('account_heads', fn (Blueprint $t) => $t->dropColumn('cf_class'));
    }
};

