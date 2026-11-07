<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v8.5 – a capital project says which asset account it will be capitalised to.
 *
 * The capital plan (budgets, kind = capital) is held by capital asset account; a capex project is held by the owner and
 * approved with a budget. Without a link between the two, an approved project is a promise nobody's plan has heard of:
 * the plan shows only what the ledger has already debited, so a hotel can approve GH₵400,000 of work, see a plan that
 * still looks unspent, and approve more. This column is that link, and the plan's screen now prints committed money
 * beside spent money because of it.
 *
 * Nullable on purpose: a project may be recorded before anyone decides the account, and an existing project must not be
 * guessed at — it counts as committed once somebody says which head it belongs to.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('capex_projects')) {
            return;
        }
        Schema::table('capex_projects', function (Blueprint $t) {
            if (! Schema::hasColumn('capex_projects', 'capital_head_id')) {
                $t->foreignId('capital_head_id')->nullable()->after('budget')->constrained('account_heads')->nullOnDelete();
                $t->index(['hotel_id', 'capital_head_id'], 'capex_project_head_idx');
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('capex_projects')) {
            Schema::table('capex_projects', function (Blueprint $t) {
                if (Schema::hasColumn('capex_projects', 'capital_head_id')) {
                    $t->dropIndex('capex_project_head_idx');
                    $t->dropConstrainedForeignId('capital_head_id');
                }
            });
        }
    }
};
