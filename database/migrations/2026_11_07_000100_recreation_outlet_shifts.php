<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreation outlet as a cash-handling point of sale.
 *
 * A recreation ticket sale takes money at a counter, so it needs the same two things every
 * other outlet has: the shift it was rung up on, and the person who took each tender. The
 * department shift table (already used by Food & Beverage service shifts) is reused with
 * department = "recreation"; the drawer figures it now needs are added here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('department_shift_sessions')) {
            Schema::table('department_shift_sessions', function (Blueprint $t) {
                if (! Schema::hasColumn('department_shift_sessions', 'opening_float')) {
                    $t->decimal('opening_float', 10, 2)->nullable();
                }
                if (! Schema::hasColumn('department_shift_sessions', 'expected_cash')) {
                    $t->decimal('expected_cash', 10, 2)->nullable();
                }
                if (! Schema::hasColumn('department_shift_sessions', 'counted_cash')) {
                    $t->decimal('counted_cash', 10, 2)->nullable();
                }
                if (! Schema::hasColumn('department_shift_sessions', 'variance')) {
                    $t->decimal('variance', 10, 2)->nullable();
                }
                if (! Schema::hasColumn('department_shift_sessions', 'counted_denominations')) {
                    $t->json('counted_denominations')->nullable();
                }
            });
        }

        if (Schema::hasTable('club_ticket_sales') && ! Schema::hasColumn('club_ticket_sales', 'department_shift_session_id')) {
            Schema::table('club_ticket_sales', function (Blueprint $t) {
                $t->foreignId('department_shift_session_id')->nullable()->constrained('department_shift_sessions')->nullOnDelete();
                $t->index(['hotel_id', 'department_shift_session_id'], 'club_ticket_sale_shift_idx');
            });
        }

        if (Schema::hasTable('club_ticket_payments')) {
            Schema::table('club_ticket_payments', function (Blueprint $t) {
                if (! Schema::hasColumn('club_ticket_payments', 'received_by')) {
                    $t->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('club_ticket_payments', 'received_at')) {
                    $t->dateTime('received_at')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('club_ticket_payments')) {
            Schema::table('club_ticket_payments', function (Blueprint $t) {
                if (Schema::hasColumn('club_ticket_payments', 'received_at')) {
                    $t->dropColumn('received_at');
                }
                if (Schema::hasColumn('club_ticket_payments', 'received_by')) {
                    $t->dropConstrainedForeignId('received_by');
                }
            });
        }

        if (Schema::hasTable('club_ticket_sales') && Schema::hasColumn('club_ticket_sales', 'department_shift_session_id')) {
            Schema::table('club_ticket_sales', function (Blueprint $t) {
                $t->dropIndex('club_ticket_sale_shift_idx');
                $t->dropConstrainedForeignId('department_shift_session_id');
            });
        }

        if (Schema::hasTable('department_shift_sessions')) {
            Schema::table('department_shift_sessions', function (Blueprint $t) {
                foreach (['opening_float', 'expected_cash', 'counted_cash', 'variance', 'counted_denominations'] as $column) {
                    if (Schema::hasColumn('department_shift_sessions', $column)) {
                        $t->dropColumn($column);
                    }
                }
            });
        }
    }
};
