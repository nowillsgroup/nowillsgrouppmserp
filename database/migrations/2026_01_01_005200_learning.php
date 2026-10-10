<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v6.0 Learning & development: a versioned SOP library with read-and-sign acknowledgements by the staff it applies to,
 * a training course catalogue with renewal periods, a training matrix (which roles / departments must hold which
 * course, and by how long after joining), scheduled training sessions with attendance and scores, and each employee's
 * training records and certificates (passing a food-hygiene course renews their v5.0 food-safety certificate).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sop_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('code', 30);                                            // SOP-FO-001
            $t->string('title', 150);
            $t->foreignId('department_id')->nullable()->constrained()->nullOnDelete();   // null = hotel-wide
            $t->string('category', 20)->default('operations');
            $t->string('summary', 255)->nullable();
            $t->longText('body')->nullable();                                  // Markdown (the draft being edited)
            $t->unsignedSmallInteger('version')->default(0);                   // last published version (0 = never)
            $t->string('status', 10)->default('draft');                        // draft | published | retired
            $t->boolean('has_draft')->default(true);                           // body differs from the published version
            $t->boolean('requires_ack')->default(true);
            $t->json('ack_departments')->nullable();                           // who must sign: null = the SOP's department (all staff if hotel-wide)
            $t->unsignedSmallInteger('review_months')->default(12);
            $t->date('effective_date')->nullable();
            $t->date('review_due')->nullable();
            $t->date('last_reviewed')->nullable();
            $t->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'code']);
        });

        Schema::create('sop_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('sop_document_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('version');
            $t->string('title', 150);
            $t->longText('body');
            $t->string('change_note', 255)->nullable();
            $t->boolean('reack')->default(true);                               // staff must sign this version again
            $t->date('effective_date');
            $t->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
            $t->unique(['sop_document_id', 'version']);
        });

        Schema::create('sop_acknowledgements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('sop_document_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('version');
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->string('via', 10)->default('self');                            // self (read & signed online) | sheet (paper sign-off recorded) | training
            $t->dateTime('acknowledged_at');
            $t->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['sop_document_id', 'version', 'employee_id'], 'sop_ack_unique');
        });

        Schema::create('training_courses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('code', 20);
            $t->string('name', 150);
            $t->string('category', 20)->default('skills');
            $t->string('delivery', 12)->default('classroom');                  // classroom | on_job | online | external
            $t->text('description')->nullable();
            $t->decimal('duration_hours', 5, 1)->default(1);
            $t->unsignedSmallInteger('validity_months')->nullable();           // null = never expires
            $t->unsignedTinyInteger('pass_mark')->nullable();                  // % – null = attendance only
            $t->string('provider', 150)->nullable();                           // external trainer / body
            $t->decimal('cost_per_head', 12, 2)->default(0);
            $t->foreignId('sop_document_id')->nullable()->constrained()->nullOnDelete();  // passing counts as signing this SOP
            $t->string('fs_type', 10)->nullable();                             // hygiene = renews the food-safety certificate
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['hotel_id', 'code']);
        });

        Schema::create('training_requirements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('training_course_id')->constrained()->cascadeOnDelete();
            $t->foreignId('department_id')->nullable()->constrained()->nullOnDelete();   // null + no designation = everyone
            $t->string('designation', 80)->nullable();                         // job title (exact, case-insensitive)
            $t->unsignedSmallInteger('due_days')->default(30);                 // new starters: due this many days after joining
            $t->timestamps();
        });

        Schema::create('training_sessions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('number', 20);
            $t->foreignId('training_course_id')->constrained()->cascadeOnDelete();
            $t->date('date');
            $t->string('start_time', 5)->default('09:00');
            $t->string('end_time', 5)->nullable();
            $t->string('venue', 120)->nullable();
            $t->foreignId('trainer_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('trainer_name', 120)->nullable();                       // external trainer
            $t->unsignedSmallInteger('capacity')->nullable();
            $t->decimal('cost', 12, 2)->default(0);                            // total (external fee, materials)
            $t->string('status', 10)->default('scheduled');                    // scheduled | completed | cancelled
            $t->string('notes', 255)->nullable();
            $t->string('cancel_reason', 190)->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['hotel_id', 'date']);
        });

        Schema::create('training_records', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('training_course_id')->constrained()->cascadeOnDelete();
            $t->foreignId('training_session_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->string('status', 10)->default('enrolled');                     // enrolled | passed | failed | no_show | exempt
            $t->string('source', 10)->default('session');                      // session | external | exempt
            $t->decimal('score', 5, 1)->nullable();
            $t->date('completed_on')->nullable();
            $t->date('expires_on')->nullable();
            $t->string('certificate_no', 40)->nullable();
            $t->string('provider', 150)->nullable();
            $t->decimal('cost', 12, 2)->default(0);
            $t->string('notes', 255)->nullable();
            $t->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['employee_id', 'training_course_id']);
        });
    }

    public function down(): void
    {
        foreach (['training_records', 'training_sessions', 'training_requirements', 'training_courses', 'sop_acknowledgements', 'sop_versions', 'sop_documents'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};

