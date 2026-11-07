<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v8.1 – cost centres.
 *
 * Departments become the cost-centre dimension of the ledger and of budgeting:
 *
 *  · `departments.code`            a short code the ledger and the reports print next to the name
 *  · `departments.is_cost_centre`  which departments take part in cost-centre reporting (a department that is only
 *                                 an approval route, e.g. "Stores", can be left out of the analysis)
 *  · `gl_journals.department_id`   the header cost centre – every line that does not name its own inherits it
 *  · `gl_lines.department_id`      the line cost centre – what a departmental P&L is actually built from
 *  · `budgets.department_id`       a budget version that belongs to one department; NULL is the whole hotel
 *
 * Additive only. Existing rows are left alone: postings made before this migration carry no cost centre and are
 * reported as "Unallocated" rather than being guessed at. The budgets table keeps its per-account unique key, because the
 * department lives on the version, not on the line – so this migration does not touch an existing index.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('departments')) {
            Schema::table('departments', function (Blueprint $t) {
                if (! Schema::hasColumn('departments', 'code')) {
                    $t->string('code', 16)->nullable()->after('name');
                }
                if (! Schema::hasColumn('departments', 'is_cost_centre')) {
                    $t->boolean('is_cost_centre')->default(true)->after('code');
                }
            });
        }

        if (Schema::hasTable('gl_journals') && ! Schema::hasColumn('gl_journals', 'department_id')) {
            Schema::table('gl_journals', function (Blueprint $t) {
                $t->foreignId('department_id')->nullable()->after('source_key')->constrained()->nullOnDelete();
                $t->index(['hotel_id', 'department_id', 'date'], 'gl_journals_cost_centre_idx');
            });
        }

        if (Schema::hasTable('gl_lines') && ! Schema::hasColumn('gl_lines', 'department_id')) {
            Schema::table('gl_lines', function (Blueprint $t) {
                $t->foreignId('department_id')->nullable()->after('account_head_id')->constrained()->nullOnDelete();
                $t->index(['hotel_id', 'department_id', 'date'], 'gl_lines_cost_centre_idx');
            });
        }

        if (Schema::hasTable('budgets') && ! Schema::hasColumn('budgets', 'department_id')) {
            Schema::table('budgets', function (Blueprint $t) {
                $t->foreignId('department_id')->nullable()->after('kind')->constrained()->nullOnDelete();
                $t->index(['hotel_id', 'department_id', 'fiscal_year'], 'budgets_cost_centre_idx');
            });
        }
    }

    public function down(): void
    {
        foreach (['gl_lines' => 'gl_lines_cost_centre_idx', 'gl_journals' => 'gl_journals_cost_centre_idx'] as $table => $index) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'department_id')) {
                Schema::table($table, function (Blueprint $t) use ($index) {
                    $t->dropIndex($index);
                    $t->dropConstrainedForeignId('department_id');
                });
            }
        }

        if (Schema::hasTable('budgets') && Schema::hasColumn('budgets', 'department_id')) {
            Schema::table('budgets', function (Blueprint $t) {
                $t->dropIndex('budgets_cost_centre_idx');
                $t->dropConstrainedForeignId('department_id');
            });
        }

        Schema::table('departments', function (Blueprint $t) {
            foreach (['code', 'is_cost_centre'] as $c) {
                if (Schema::hasColumn('departments', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
