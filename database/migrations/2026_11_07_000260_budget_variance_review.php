<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v8.2 – the variance explanation lifecycle.
 *
 * A flagged variance is not a note on a page: it has an owner, a deadline and a state, and a month cannot be signed
 * off while one is still unexplained.
 *
 *  · `budget_comments.assigned_to`  the person who must explain it (NULL = whoever is preparing the budget)
 *  · `budget_comments.due_on`       the deadline the screen prints (month end + the days in the setting)
 *  · `budget_comments.status`       explained | accepted (existing rows are explanations, so 'explained')
 *  · `budget_comments.resolved_at`  when it was accepted, and by whom
 *  · `budget_signoffs`              one row per version per month: who signed the month off, when, and why
 *
 * The sign-off is per version and per month, because two versions of the same year (whole hotel and a cost centre, or
 * a budget and its re-forecast) are reviewed separately and must not vouch for each other.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('budget_comments')) {
            Schema::table('budget_comments', function (Blueprint $t) {
                if (! Schema::hasColumn('budget_comments', 'assigned_to')) {
                    $t->foreignId('assigned_to')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('budget_comments', 'due_on')) {
                    $t->date('due_on')->nullable()->after('assigned_to');
                }
                if (! Schema::hasColumn('budget_comments', 'status')) {
                    $t->string('status', 12)->default('explained')->after('due_on');
                }
                if (! Schema::hasColumn('budget_comments', 'resolved_at')) {
                    $t->timestamp('resolved_at')->nullable()->after('status');
                }
                if (! Schema::hasColumn('budget_comments', 'resolved_by')) {
                    $t->foreignId('resolved_by')->nullable()->after('resolved_at')->constrained('users')->nullOnDelete();
                }
            });
        }

        if (! Schema::hasTable('budget_signoffs')) {
            Schema::create('budget_signoffs', function (Blueprint $t) {
                $t->id();
                $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
                $t->foreignId('budget_id')->constrained()->cascadeOnDelete();
                $t->char('period', 7);                                  // YYYY-MM, a fiscal month of the version
                $t->unsignedSmallInteger('flagged')->default(0);        // how many variances were flagged when signed
                $t->unsignedSmallInteger('explained')->default(0);      // …and how many had an explanation
                $t->foreignId('signed_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('signed_at')->nullable();
                $t->string('note', 255)->nullable();
                $t->timestamps();
                $t->unique(['budget_id', 'period']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_signoffs');

        if (Schema::hasTable('budget_comments')) {
            Schema::table('budget_comments', function (Blueprint $t) {
                foreach (['resolved_by' => 'resolved_by', 'resolved_at' => 'resolved_at', 'status' => 'status', 'due_on' => 'due_on', 'assigned_to' => 'assigned_to'] as $col => $_) {
                    if (Schema::hasColumn('budget_comments', $col)) {
                        $col === 'resolved_by' || $col === 'assigned_to'
                            ? $t->dropConstrainedForeignId($col)
                            : $t->dropColumn($col);
                    }
                }
            });
        }
    }
};
