<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v6.5 Quality assurance & brand standards: a library of weighted (optionally critical) standards by department and
 * area, linked to SOPs; audit checklists built from them (internal, department walk-throughs, mystery guest / LQA) with
 * a frequency and pass mark; scored audits with a snapshot of every item (pass / fail / n/a, note, repeat finding);
 * corrective actions raised from failures (owner, due date, root cause, engineering ticket) through done → verified.
 */
return new class extends Migration
{
    public function up(): void
    {
                if (Schema::hasTable('qa_standards')) {
            return;
        }

        Schema::create('qa_standards', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('code', 20);
            $t->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $t->string('area', 60);                                          // arrival, guest room, restaurant service, kitchen, life safety …
            $t->string('title', 200);
            $t->text('description')->nullable();                             // what "meets the standard" looks like
            $t->unsignedTinyInteger('weight')->default(1);                   // 1–5 points
            $t->boolean('is_critical')->default(false);                      // one failure fails the audit
            $t->foreignId('sop_document_id')->nullable()->constrained()->nullOnDelete();
            $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
            $t->unique(['hotel_id', 'code']);
        });

                if (Schema::hasTable('qa_templates')) {
            return;
        }

        Schema::create('qa_templates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('name', 120);
            $t->string('type', 20)->default('internal');                    // internal | walkthrough | mystery | brand
            $t->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedSmallInteger('frequency_days')->nullable();          // due again this many days after the last audit
            $t->decimal('pass_mark', 5, 2)->default(85);
            $t->string('description', 500)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

                if (Schema::hasTable('qa_template_items')) {
            return;
        }

        Schema::create('qa_template_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('qa_template_id')->constrained()->cascadeOnDelete();
            $t->foreignId('qa_standard_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('sort')->default(0);
            $t->unique(['qa_template_id', 'qa_standard_id']);
        });

                if (Schema::hasTable('qa_audits')) {
            return;
        }

        Schema::create('qa_audits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('number', 20);
            $t->foreignId('qa_template_id')->constrained()->cascadeOnDelete();
            $t->string('type', 20);
            $t->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $t->string('title', 150);
            $t->foreignId('auditor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('external_auditor', 120)->nullable();                 // mystery guest / brand auditor
            $t->date('scheduled_for');
            $t->string('status', 12)->default('scheduled');                  // scheduled | in_progress | completed | cancelled
            $t->dateTime('started_at')->nullable();
            $t->dateTime('completed_at')->nullable();
            $t->decimal('pass_mark', 5, 2)->default(85);
            $t->decimal('points_earned', 8, 2)->nullable();
            $t->decimal('points_possible', 8, 2)->nullable();
            $t->decimal('score_pct', 5, 2)->nullable();
            $t->unsignedSmallInteger('fails')->default(0);
            $t->unsignedSmallInteger('critical_fails')->default(0);
            $t->unsignedSmallInteger('repeat_fails')->default(0);
            $t->boolean('passed')->nullable();
            $t->text('summary')->nullable();
            $t->string('cancel_reason', 255)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('acknowledged_at')->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'number']);
            $t->index(['hotel_id', 'status']);
            $t->index(['hotel_id', 'completed_at']);
        });

                if (Schema::hasTable('qa_audit_items')) {
            return;
        }

        Schema::create('qa_audit_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('qa_audit_id')->constrained()->cascadeOnDelete();
            $t->foreignId('qa_standard_id')->nullable()->constrained()->nullOnDelete();
            $t->string('code', 20);
            $t->string('area', 60);
            $t->string('title', 200);
            $t->unsignedTinyInteger('weight')->default(1);
            $t->boolean('is_critical')->default(false);
            $t->string('result', 4)->nullable();                             // pass | fail | na
            $t->string('note', 500)->nullable();
            $t->boolean('is_repeat')->default(false);                        // also failed on the previous audit of this checklist
            $t->unsignedSmallInteger('sort')->default(0);
            $t->index(['hotel_id', 'qa_standard_id', 'result']);
        });

                if (Schema::hasTable('qa_actions')) {
            return;
        }

        Schema::create('qa_actions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('number', 20);
            $t->foreignId('qa_audit_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('qa_audit_item_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('qa_standard_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $t->string('title', 200);
            $t->string('priority', 10)->default('normal');                   // low | normal | high | critical
            $t->text('root_cause')->nullable();
            $t->text('action_plan')->nullable();
            $t->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $t->date('due_date');
            $t->string('status', 12)->default('open');                       // open | in_progress | done | verified | cancelled
            $t->foreignId('maintenance_request_id')->nullable()->constrained()->nullOnDelete();
            $t->text('completion_note')->nullable();
            $t->dateTime('completed_at')->nullable();
            $t->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('verified_at')->nullable();
            $t->string('verify_note', 255)->nullable();
            $t->unsignedTinyInteger('reopened')->default(0);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'number']);
            $t->index(['hotel_id', 'status', 'due_date']);
        });
    }

    public function down(): void
    {
        foreach (['qa_actions', 'qa_audit_items', 'qa_audits', 'qa_template_items', 'qa_templates', 'qa_standards'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};

