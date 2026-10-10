<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v4.8→4.9 Workforce: shift templates, weekly duty rosters (publish / copy / swap requests), a time clock
 * (self-service, shared kiosk with PIN, manager corrections) that feeds attendance on approval, and the
 * service charge & tips pool (tronc) distributed to staff by points × days worked through payroll.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_templates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('code', 8);
            $t->string('name', 60);
            $t->foreignId('department_id')->nullable()->constrained()->nullOnDelete(); // null = any department
            $t->time('start_time');
            $t->time('end_time');                                  // before start = ends next day
            $t->unsignedSmallInteger('break_minutes')->default(60);
            $t->string('color', 7)->default('#3b82f6');
            $t->unsignedSmallInteger('min_staff')->default(0);     // coverage target per department & day
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['hotel_id', 'code']);
        });

        Schema::create('roster_weeks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('department_id')->constrained()->cascadeOnDelete();
            $t->date('week_start');                                // Monday
            $t->timestamp('published_at')->nullable();
            $t->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('changed_at')->nullable();               // edited after publishing
            $t->timestamps();
            $t->unique(['hotel_id', 'department_id', 'week_start']);
        });

        Schema::create('roster_shifts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->date('date');
            $t->string('kind', 6)->default('shift');              // shift | off
            $t->foreignId('shift_template_id')->nullable()->constrained()->nullOnDelete();
            $t->time('start_time')->nullable();
            $t->time('end_time')->nullable();
            $t->unsignedSmallInteger('break_minutes')->default(0);
            $t->string('notes', 120)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['employee_id', 'date']);
            $t->index(['hotel_id', 'date']);
        });

        Schema::create('shift_swaps', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('requester_id')->constrained('employees')->cascadeOnDelete();
            $t->foreignId('roster_shift_id')->constrained()->cascadeOnDelete();       // the requester's shift
            $t->foreignId('colleague_id')->constrained('employees')->cascadeOnDelete();
            $t->foreignId('colleague_shift_id')->nullable()->constrained('roster_shifts')->nullOnDelete(); // null = hand over (colleague is off)
            $t->string('reason', 255)->nullable();
            $t->string('status', 16)->default('pending_peer');   // pending_peer, pending_manager, approved, declined, rejected, cancelled
            $t->timestamp('peer_at')->nullable();
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->string('decision_note', 255)->nullable();
            $t->timestamps();
            $t->index(['hotel_id', 'status']);
        });

        Schema::create('time_punches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->date('work_date');
            $t->foreignId('roster_shift_id')->nullable()->constrained()->nullOnDelete();
            $t->dateTime('clock_in');
            $t->dateTime('clock_out')->nullable();
            $t->string('in_source', 8)->default('self');          // self | kiosk | manager
            $t->string('out_source', 8)->nullable();
            $t->string('in_ip', 45)->nullable();
            $t->string('out_ip', 45)->nullable();
            $t->unsignedSmallInteger('late_minutes')->default(0);
            $t->unsignedSmallInteger('early_minutes')->default(0);
            $t->unsignedSmallInteger('worked_minutes')->nullable();
            $t->string('status', 10)->default('open');            // open | closed | approved
            $t->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('edit_reason', 255)->nullable();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
            $t->index(['hotel_id', 'work_date']);
            $t->index(['employee_id', 'status']);
        });

        Schema::table('employees', function (Blueprint $t) {
            $t->string('clock_pin')->nullable()->after('user_id');          // bcrypt hash of a 4–6 digit kiosk PIN
            $t->decimal('sc_points', 5, 2)->default(1)->after('clock_pin'); // service charge share points
            $t->boolean('sc_eligible')->default(true)->after('sc_points');
        });

        Schema::create('tip_collections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('tip_no', 20);
            $t->date('date');
            $t->string('source', 20);                             // card_tips, cash_box, momo_tips, banquet, other
            $t->foreignId('payment_mode_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('amount', 12, 2);
            $t->string('reference', 60)->nullable();
            $t->string('notes', 255)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('cancelled_at')->nullable();
            $t->string('cancel_reason', 255)->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'tip_no']);
            $t->index(['hotel_id', 'date']);
        });

        Schema::create('sc_distributions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('dist_no', 20);
            $t->date('period_start');
            $t->date('period_end');
            $t->decimal('collected', 12, 2)->default(0);          // service charge + tips credited to 2110 in the period
            $t->decimal('carried_in', 12, 2)->default(0);         // rounding left over from the previous distribution
            $t->decimal('retention_pct', 5, 2)->default(0);
            $t->decimal('retained', 12, 2)->default(0);           // house share → income
            $t->decimal('pool', 12, 2)->default(0);               // shared among staff
            $t->decimal('distributed', 12, 2)->default(0);
            $t->decimal('carry_out', 12, 2)->default(0);
            $t->string('basis', 12)->default('points_days');      // points_days | points | equal
            $t->string('status', 10)->default('draft');           // draft, approved, posted, cancelled
            $t->foreignId('payroll_run_id')->nullable()->constrained()->nullOnDelete();
            $t->string('notes', 255)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->date('approved_on')->nullable();
            $t->timestamp('posted_at')->nullable();
            $t->string('cancel_reason', 255)->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'dist_no']);
        });

        Schema::create('sc_distribution_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('sc_distribution_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('points', 5, 2)->default(1);
            $t->decimal('days', 5, 1)->default(0);
            $t->decimal('weight', 10, 2)->default(0);
            $t->decimal('amount', 12, 2)->default(0);
            $t->boolean('excluded')->default(false);
            $t->string('exclude_reason', 120)->nullable();
            $t->foreignId('payslip_line_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->unique(['sc_distribution_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sc_distribution_lines');
        Schema::dropIfExists('sc_distributions');
        Schema::dropIfExists('tip_collections');
        Schema::table('employees', fn (Blueprint $t) => $t->dropColumn(['clock_pin', 'sc_points', 'sc_eligible']));
        Schema::dropIfExists('time_punches');
        Schema::dropIfExists('shift_swaps');
        Schema::dropIfExists('roster_shifts');
        Schema::dropIfExists('roster_weeks');
        Schema::dropIfExists('shift_templates');
    }
};

