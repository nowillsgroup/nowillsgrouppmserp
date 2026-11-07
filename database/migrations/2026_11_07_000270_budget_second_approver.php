<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v8.3 – approval thresholds.
 *
 * Four eyes are not enough for every plan: above the value in Budget settings a version needs two approvers, one after
 * the other, and the second signature is stored separately so the trail can say who gave which.
 *
 * `approved_by` / `approved_at` keep their meaning — the first signature (and the only one when the version is at or
 * below the threshold). A version that needs two stays in `submitted` after the first signature, so nothing that
 * filters on "approved" can act on half an approval.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('budgets')) {
            Schema::table('budgets', function (Blueprint $t) {
                if (! Schema::hasColumn('budgets', 'second_approved_by')) {
                    $t->foreignId('second_approved_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('budgets', 'second_approved_at')) {
                    $t->timestamp('second_approved_at')->nullable()->after('second_approved_by');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('budgets')) {
            Schema::table('budgets', function (Blueprint $t) {
                if (Schema::hasColumn('budgets', 'second_approved_at')) {
                    $t->dropColumn('second_approved_at');
                }
                if (Schema::hasColumn('budgets', 'second_approved_by')) {
                    $t->dropConstrainedForeignId('second_approved_by');
                }
            });
        }
    }
};
