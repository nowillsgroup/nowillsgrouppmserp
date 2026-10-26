<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v4.5 Ghana payroll statutory returns: monthly SSNIT Tier 1, Tier 2 and PAYE remittances recorded against the approved
 * payroll (each posts Dr the liability / Cr the bank); a remitted month locks its payroll run.
 */
return new class extends Migration
{
    public function up(): void
    {
                if (Schema::hasTable('statutory_remittances')) {
            return;
        }

        Schema::create('statutory_remittances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('remit_no', 20);
            $t->char('period', 7);                                   // YYYY-MM of the payroll month
            $t->string('kind', 10);                                  // ssnit | tier2 | paye
            $t->foreignId('payroll_run_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('amount', 14, 2);
            $t->decimal('employee_share', 14, 2)->default(0);
            $t->decimal('employer_share', 14, 2)->default(0);
            $t->unsignedSmallInteger('employees')->default(0);
            $t->date('paid_on');
            $t->foreignId('payment_mode_id')->nullable()->constrained()->nullOnDelete();
            $t->string('reference', 60);
            $t->string('payee', 120)->nullable();
            $t->string('notes', 255)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('cancelled_at')->nullable();
            $t->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('cancel_reason', 255)->nullable();
            $t->timestamps();
            $t->index(['hotel_id', 'period', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statutory_remittances');
    }
};

