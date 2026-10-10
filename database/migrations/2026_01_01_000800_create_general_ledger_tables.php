<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 (v1.6): double-entry general ledger.
 * account_heads becomes the chart of accounts; journals are immutable (changes are reversed, never edited).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_heads', function (Blueprint $t) {
            $t->string('code', 12)->nullable()->after('hotel_id');
            $t->string('group_name', 60)->nullable()->after('type');
            $t->string('system_key', 30)->nullable()->after('group_name');
            $t->boolean('is_active')->default(true)->after('system_key');
            $t->unique(['hotel_id', 'code']);
            $t->unique(['hotel_id', 'system_key']);
        });

        Schema::table('payment_modes', function (Blueprint $t) {
            $t->foreignId('account_head_id')->nullable()->after('type')->constrained('account_heads')->nullOnDelete();
        });

        Schema::create('gl_journals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('journal_no', 30);
            $t->date('date');
            $t->string('source_type', 20);           // folio_tx, pos, voucher, po, stock, manual
            $t->string('source_key', 40)->nullable(); // source id, or Y-m-d for daily stock journals
            $t->string('reference', 80)->nullable();
            $t->string('narration', 255)->nullable();
            $t->decimal('total', 14, 2)->default(0);
            $t->char('hash', 32)->nullable();
            $t->foreignId('reversal_of_id')->nullable()->constrained('gl_journals')->nullOnDelete();
            $t->foreignId('reversed_by_id')->nullable()->constrained('gl_journals')->nullOnDelete();
            $t->boolean('is_manual')->default(false);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'journal_no']);
            $t->index(['hotel_id', 'source_type', 'source_key']);
            $t->index(['hotel_id', 'date']);
        });

        Schema::create('gl_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('gl_journal_id')->constrained('gl_journals')->cascadeOnDelete();
            $t->foreignId('account_head_id')->constrained('account_heads')->restrictOnDelete();
            $t->date('date');
            $t->decimal('debit', 14, 2)->default(0);
            $t->decimal('credit', 14, 2)->default(0);
            $t->string('memo', 150)->nullable();
            $t->index(['hotel_id', 'account_head_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gl_lines');
        Schema::dropIfExists('gl_journals');
        if (Schema::hasColumn('payment_modes', 'account_head_id')) {
            Schema::table('payment_modes', fn (Blueprint $t) => $t->dropConstrainedForeignId('account_head_id'));
        }
        if (! Schema::hasIndex('account_heads', 'account_heads_hotel_fk_idx')) {
            Schema::table('account_heads', fn (Blueprint $t) => $t->index('hotel_id', 'account_heads_hotel_fk_idx')); // keeps the hotel_id FK indexed
        }
        foreach (['account_heads_hotel_id_code_unique', 'account_heads_hotel_id_system_key_unique'] as $idx) {
            if (Schema::hasIndex('account_heads', $idx)) {
                Schema::table('account_heads', fn (Blueprint $t) => $t->dropIndex($idx));
            }
        }
        Schema::table('account_heads', function (Blueprint $t) {
            $t->dropColumn(['code', 'group_name', 'system_key', 'is_active']);
        });
    }
};

