<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.4 store requisitions (indents): departments ask a store for stock, the head of department approves,
 * the store issues (in part or in full) and the department acknowledges receipt. Issues are either expensed
 * to the department's cost account or transferred to the department's own sub-store. Departments get a
 * head, a cost account, a default sub-store and a monthly stores budget; sub-stores get par levels.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $t) {
            $t->foreignId('hod_user_id')->nullable()->after('name')->constrained('users')->nullOnDelete();
            $t->foreignId('expense_head_id')->nullable()->after('hod_user_id')->constrained('account_heads')->nullOnDelete();
            $t->foreignId('default_store_id')->nullable()->after('expense_head_id')->constrained('stores')->nullOnDelete();
            $t->decimal('monthly_budget', 14, 2)->nullable()->after('default_store_id');
        });

                if (Schema::hasTable('requisitions')) {
            return;
        }

        Schema::create('requisitions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('req_no', 20);
            $t->foreignId('department_id')->constrained()->restrictOnDelete();
            $t->foreignId('from_store_id')->constrained('stores')->restrictOnDelete();
            $t->foreignId('to_store_id')->nullable()->constrained('stores')->restrictOnDelete(); // department sub-store → transfer
            $t->date('req_date');
            $t->date('required_by')->nullable();
            $t->string('priority', 8)->default('normal'); // normal | urgent
            // draft → submitted → approved → partial → issued | closed (short) ; rejected ; cancelled
            $t->string('status', 12)->default('draft');
            $t->string('purpose', 255)->nullable();
            $t->decimal('est_value', 14, 2)->default(0); // approved (or requested) qty × last cost
            $t->foreignId('raised_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('submitted_at')->nullable();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->boolean('over_budget')->default(false);
            $t->string('approval_note', 255)->nullable();
            $t->string('rejected_reason', 255)->nullable();
            $t->string('closed_reason', 255)->nullable();
            $t->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'req_no']);
            $t->index(['hotel_id', 'status']);
        });

                if (Schema::hasTable('requisition_lines')) {
            return;
        }

        Schema::create('requisition_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('requisition_id')->constrained()->cascadeOnDelete();
            $t->foreignId('inventory_item_id')->constrained()->restrictOnDelete();
            $t->decimal('qty_requested', 12, 2);
            $t->decimal('qty_approved', 12, 2)->nullable();
            $t->decimal('qty_issued', 12, 2)->default(0);
            $t->string('remarks', 190)->nullable();
            $t->timestamps();
        });

                if (Schema::hasTable('store_issues')) {
            return;
        }

        Schema::create('store_issues', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('issue_no', 20);
            $t->foreignId('requisition_id')->constrained()->restrictOnDelete();
            $t->foreignId('department_id')->constrained()->restrictOnDelete();
            $t->foreignId('from_store_id')->constrained('stores')->restrictOnDelete();
            $t->foreignId('to_store_id')->nullable()->constrained('stores')->restrictOnDelete();
            $t->date('date');
            $t->decimal('value', 14, 2)->default(0);
            $t->string('notes', 255)->nullable();
            $t->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('received_at')->nullable();
            $t->string('receipt_note', 255)->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'issue_no']);
        });

                if (Schema::hasTable('store_issue_lines')) {
            return;
        }

        Schema::create('store_issue_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_issue_id')->constrained()->cascadeOnDelete();
            $t->foreignId('requisition_line_id')->constrained()->cascadeOnDelete();
            $t->foreignId('inventory_item_id')->constrained()->restrictOnDelete();
            $t->decimal('qty', 12, 2);
            $t->decimal('rate', 12, 2)->default(0);
            $t->decimal('amount', 14, 2)->default(0);
            $t->timestamps();
        });

                if (Schema::hasTable('store_pars')) {
            return;
        }

        Schema::create('store_pars', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('store_id')->constrained()->cascadeOnDelete();
            $t->foreignId('inventory_item_id')->constrained()->cascadeOnDelete();
            $t->decimal('par_qty', 12, 2);
            $t->timestamps();
            $t->unique(['store_id', 'inventory_item_id']);
        });

        Schema::table('stock_movements', function (Blueprint $t) {
            $t->foreignId('department_id')->nullable()->after('department')->constrained()->nullOnDelete();
            $t->foreignId('store_issue_id')->nullable()->after('department_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $t) {
            $t->dropConstrainedForeignId('store_issue_id');
            $t->dropConstrainedForeignId('department_id');
        });
        Schema::dropIfExists('store_pars');
        Schema::dropIfExists('store_issue_lines');
        Schema::dropIfExists('store_issues');
        Schema::dropIfExists('requisition_lines');
        Schema::dropIfExists('requisitions');
        Schema::table('departments', function (Blueprint $t) {
            $t->dropConstrainedForeignId('default_store_id');
            $t->dropConstrainedForeignId('expense_head_id');
            $t->dropConstrainedForeignId('hod_user_id');
            $t->dropColumn('monthly_budget');
        });
    }
};

