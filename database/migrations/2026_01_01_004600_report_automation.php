<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** v5.4 Report automation: saved report views, e-mailed report schedules with a delivery log, KPI threshold alerts. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_views', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();      // owner
            $t->string('report_key', 40);
            $t->string('name', 120);
            $t->string('range', 20)->default('mtd');                         // preset or 'custom'
            $t->date('date_from')->nullable();                               // custom range
            $t->date('date_to')->nullable();
            $t->json('columns')->nullable();                                 // subset of column keys, null = all
            $t->string('filter', 100)->nullable();                           // rows containing this text
            $t->boolean('is_shared')->default(false);                        // visible to everyone who can see the report
            $t->boolean('is_pinned')->default(false);                        // on the owner's dashboard
            $t->timestamp('last_opened_at')->nullable();
            $t->unsignedInteger('opens')->default(0);
            $t->timestamps();
            $t->index(['hotel_id', 'user_id']);
        });

        Schema::create('report_schedules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();      // owner – the report runs with this user's permissions
            $t->string('name', 120);
            $t->foreignId('report_view_id')->nullable()->constrained()->nullOnDelete();
            $t->string('report_key', 40);
            $t->string('range', 20)->default('yesterday');
            $t->json('columns')->nullable();
            $t->string('frequency', 12);                                     // daily, weekly, monthly, after_audit
            $t->unsignedTinyInteger('weekday')->nullable();                  // 1 = Monday … 7 = Sunday
            $t->unsignedTinyInteger('month_day')->nullable();                // 1–28, 0 = last day of the month
            $t->string('send_time', 5)->default('07:00');
            $t->string('format', 6)->default('both');                        // html, csv, both
            $t->json('recipient_users')->nullable();
            $t->text('recipient_emails')->nullable();
            $t->string('message', 500)->nullable();
            $t->boolean('skip_empty')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamp('next_run_at')->nullable();
            $t->timestamp('last_run_at')->nullable();
            $t->string('last_status', 10)->nullable();                       // sent, partial, failed, skipped
            $t->string('last_error', 500)->nullable();
            $t->unsignedTinyInteger('fail_count')->default(0);               // consecutive failures – paused at the limit
            $t->unsignedInteger('runs')->default(0);
            $t->timestamps();
            $t->index(['hotel_id', 'is_active', 'next_run_at']);
        });

        Schema::create('report_deliveries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('report_schedule_id')->nullable()->constrained()->nullOnDelete();
            $t->string('report_key', 40);
            $t->string('title', 150);
            $t->date('period_from')->nullable();
            $t->date('period_to')->nullable();
            $t->string('trigger', 12);                                       // schedule, after_audit, manual
            $t->string('status', 10);                                        // sent, partial, failed, skipped
            $t->unsignedInteger('rows')->default(0);
            $t->unsignedSmallInteger('recipients')->default(0);
            $t->unsignedSmallInteger('delivered')->default(0);
            $t->text('sent_to')->nullable();
            $t->string('error', 500)->nullable();
            $t->unsignedInteger('duration_ms')->default(0);
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();   // who pressed "send now"
            $t->timestamps();
            $t->index(['hotel_id', 'created_at']);
        });

        Schema::create('kpi_alerts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('name', 120);
            $t->string('metric', 30);
            $t->string('period', 6)->default('day');                         // day, mtd (flash metrics); ignored for "now" metrics
            $t->string('operator', 6);                                       // below, above
            $t->decimal('threshold', 14, 2);
            $t->string('severity', 10)->default('warning');                  // info, warning, critical
            $t->json('channels')->nullable();                                // email, sms (in-app always)
            $t->json('recipient_users')->nullable();
            $t->text('recipient_emails')->nullable();
            $t->string('recipient_phones', 255)->nullable();
            $t->boolean('is_active')->default(true);
            $t->decimal('last_value', 14, 2)->nullable();
            $t->date('last_date')->nullable();
            $t->timestamp('last_evaluated_at')->nullable();
            $t->timestamp('last_triggered_at')->nullable();
            $t->unsignedInteger('triggers')->default(0);
            $t->timestamps();
        });

        Schema::create('kpi_alert_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('kpi_alert_id')->constrained()->cascadeOnDelete();
            $t->date('business_date');
            $t->decimal('value', 14, 2);
            $t->decimal('threshold', 14, 2);
            $t->string('severity', 10);
            $t->string('message', 300);
            $t->unsignedSmallInteger('notified')->default(0);
            $t->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('acknowledged_at')->nullable();
            $t->string('ack_note', 255)->nullable();
            $t->timestamps();
            $t->unique(['kpi_alert_id', 'business_date']);                   // once per alert per business date
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_alert_events');
        Schema::dropIfExists('kpi_alerts');
        Schema::dropIfExists('report_deliveries');
        Schema::dropIfExists('report_schedules');
        Schema::dropIfExists('report_views');
    }
};

