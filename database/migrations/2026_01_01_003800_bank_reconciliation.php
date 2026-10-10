<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v4.6 Bank & Mobile Money reconciliation.
 *
 *  - bank_statements: one statement per account (1020 bank, 1040 MoMo clearing, 1030 card settlements …) and period,
 *    opening / closing balance, imported from CSV or typed in, draft → reconciled (locked)
 *  - bank_statement_lines: signed amounts (+ money in, − money out) with the match state
 *  - bank_matches: statement line ↔ general-ledger line(s) (a GL line clears once; many GL lines may clear one
 *    statement line, e.g. a day's card or MoMo collections settled as one credit)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('statement_no', 20);
            $t->foreignId('account_head_id')->constrained('account_heads')->restrictOnDelete();
            $t->date('period_from');
            $t->date('period_to');
            $t->decimal('opening_balance', 14, 2);
            $t->decimal('closing_balance', 14, 2);
            $t->string('source', 10)->default('manual');            // csv | manual
            $t->string('file_name', 190)->nullable();
            $t->string('status', 12)->default('draft');             // draft | reconciled
            $t->boolean('needs_review')->default(false);            // a cleared book entry changed after reconciliation
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('reconciled_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reconciled_at')->nullable();
            $t->string('reopen_reason', 255)->nullable();
            $t->string('notes', 500)->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'statement_no']);
            $t->index(['account_head_id', 'period_to']);
        });

        Schema::create('bank_statement_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('bank_statement_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('line_no')->default(0);
            $t->date('date');
            $t->string('description', 255)->nullable();
            $t->string('reference', 80)->nullable();
            $t->decimal('amount', 14, 2);                            // + money in, − money out
            $t->decimal('balance', 14, 2)->nullable();               // running balance from the statement, if given
            $t->char('hash', 32)->nullable();                        // duplicate-import guard
            $t->string('status', 10)->default('unmatched');          // unmatched | matched | ignored
            $t->string('match_type', 12)->nullable();                // reference | amount | group | manual | entry
            $t->string('ignore_reason', 255)->nullable();
            $t->string('entry_kind', 20)->nullable();                // bank_charges | momo_fees | interest | other (booked from the statement)
            $t->foreignId('entry_account_id')->nullable()->constrained('account_heads')->nullOnDelete();
            $t->string('entry_memo', 150)->nullable();
            $t->foreignId('matched_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('matched_at')->nullable();
            $t->string('note', 255)->nullable();
            $t->timestamps();
            $t->index(['bank_statement_id', 'status']);
            $t->index(['hotel_id', 'hash']);
        });

        Schema::create('bank_matches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('bank_statement_line_id')->constrained()->cascadeOnDelete();
            $t->foreignId('gl_line_id')->constrained('gl_lines')->cascadeOnDelete();
            $t->timestamps();
            $t->unique('gl_line_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_matches');
        Schema::dropIfExists('bank_statement_lines');
        Schema::dropIfExists('bank_statements');
    }
};

