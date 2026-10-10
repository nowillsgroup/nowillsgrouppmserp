<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v6.7 Health, safety & fire:
 *  - fire & life-safety equipment register (extinguishers, hose reels, alarm panel, detectors, emergency lights, fire doors,
 *    first-aid kits, AED, LPG…) with GNFS tag, monthly visual checks and annual contractor service, defects → engineering;
 *  - fire drills per shift with evacuation time and roll call; fire wardens / first aiders per shift with training expiry;
 *  - risk assessments on a 5 × 5 matrix (initial and residual), acceptance of high residual risk, periodic review;
 *  - permits to work (hot work, work at height, confined space, electrical isolation, roof) with precautions, fire watch,
 *    detector isolation and close-out;
 *  - accident, injury, near-miss and dangerous-occurrence register with lost days, and the statutory notice to the
 *    Factories Inspectorate (Factories, Offices and Shops Act 1970, Act 328, ss. 10–11).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hse_equipment', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('code', 20);
            $t->string('type', 24);
            $t->string('location', 120);
            $t->string('floor', 10)->nullable();
            $t->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $t->string('capacity', 30)->nullable();                          // 9 kg, 30 m, 12-zone…
            $t->string('gnfs_tag', 40)->nullable();                          // GNFS equipment tag (AFCAS)
            $t->string('serial', 60)->nullable();
            $t->date('installed_on')->nullable();
            $t->date('expires_on')->nullable();                              // cylinder life / kit contents / battery
            $t->unsignedSmallInteger('check_days')->default(30);
            $t->unsignedSmallInteger('service_days')->default(365);         // 0 = no contractor service
            $t->date('last_checked_on')->nullable();
            $t->date('next_check_on')->nullable();
            $t->date('last_serviced_on')->nullable();
            $t->date('next_service_on')->nullable();
            $t->string('status', 16)->default('ok');                        // ok | defective | out_of_service | retired
            $t->foreignId('maintenance_request_id')->nullable()->constrained()->nullOnDelete();
            $t->string('notes', 255)->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'code']);
            $t->index(['hotel_id', 'status', 'next_check_on']);
        });

        Schema::create('hse_checks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('hse_equipment_id')->constrained('hse_equipment')->cascadeOnDelete();
            $t->string('kind', 8)->default('visual');                       // visual | service
            $t->date('checked_on');
            $t->string('result', 8);                                         // pass | fail
            $t->json('faults')->nullable();
            $t->string('notes', 255)->nullable();
            $t->string('contractor', 120)->nullable();
            $t->string('certificate_no', 60)->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('maintenance_request_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->index(['hotel_id', 'checked_on']);
        });

        Schema::create('hse_wardens', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->string('role', 16);                                          // chief | floor | first_aider | fire_team
            $t->string('area', 80)->nullable();
            $t->string('shift', 10)->default('morning');                    // morning | evening | night
            $t->date('trained_on')->nullable();
            $t->date('trained_until')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('hse_drills', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('number', 20);
            $t->string('type', 20)->default('fire_evacuation');
            $t->string('status', 12)->default('planned');                   // planned | done | cancelled
            $t->dateTime('scheduled_at');
            $t->string('shift', 10)->default('morning');
            $t->text('scenario')->nullable();
            $t->string('assembly_point', 80)->nullable();
            $t->boolean('announced')->default(true);
            $t->dateTime('alarm_at')->nullable();
            $t->dateTime('clear_at')->nullable();
            $t->unsignedInteger('evac_seconds')->nullable();
            $t->unsignedInteger('target_seconds')->default(240);
            $t->unsignedSmallInteger('staff_expected')->nullable();
            $t->unsignedSmallInteger('staff_accounted')->nullable();
            $t->unsignedSmallInteger('guests_evacuated')->nullable();
            $t->boolean('fire_service_attended')->default(false);
            $t->string('result', 8)->nullable();                             // pass | fail
            $t->text('observations')->nullable();
            $t->text('improvements')->nullable();
            $t->foreignId('led_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('cancel_reason', 190)->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'number']);
        });

        Schema::create('hse_drill_staff', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hse_drill_id')->constrained('hse_drills')->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->unique(['hse_drill_id', 'employee_id']);
        });

        Schema::create('hse_risks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('number', 20);
            $t->string('title', 150);
            $t->string('area', 80);
            $t->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $t->text('hazard');
            $t->string('who_at_risk', 120);
            $t->unsignedTinyInteger('likelihood');
            $t->unsignedTinyInteger('severity');
            $t->text('controls');
            $t->unsignedTinyInteger('residual_likelihood');
            $t->unsignedTinyInteger('residual_severity');
            $t->text('further_actions')->nullable();
            $t->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $t->date('review_on');
            $t->string('status', 12)->default('active');                    // active | pending (awaiting acceptance) | archived
            $t->foreignId('assessed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('accepted_at')->nullable();
            $t->date('last_reviewed_on')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'number']);
        });

        Schema::create('hse_permits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('number', 20);
            $t->string('type', 16);                                          // hot_work | height | confined | electrical | roof
            $t->string('status', 12)->default('requested');                 // requested | issued | closed | cancelled
            $t->string('location', 120);
            $t->string('work', 255);
            $t->string('contractor', 120)->nullable();
            $t->unsignedSmallInteger('workers')->default(1);
            $t->dateTime('starts_at');
            $t->dateTime('ends_at');
            $t->json('precautions')->nullable();
            $t->string('fire_watch', 80)->nullable();
            $t->string('detectors_isolated', 120)->nullable();
            $t->decimal('gas_o2', 4, 1)->nullable();
            $t->string('isolation_points', 255)->nullable();
            $t->foreignId('maintenance_request_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('issued_at')->nullable();
            $t->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('closed_at')->nullable();
            $t->string('close_note', 255)->nullable();
            $t->string('cancel_reason', 190)->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'number']);
            $t->index(['hotel_id', 'status', 'ends_at']);
        });

        Schema::create('hse_accidents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('number', 20);
            $t->string('kind', 12)->default('injury');                      // injury | near_miss | dangerous
            $t->dateTime('occurred_at');
            $t->string('location', 120);
            $t->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $t->string('person_type', 12)->nullable();                       // staff | contractor | guest | visitor
            $t->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $t->string('person_name', 120)->nullable();
            $t->string('injury_type', 20)->nullable();
            $t->string('body_part', 20)->nullable();
            $t->string('severity', 16)->default('near_miss');               // near_miss | first_aid | medical | lost_time | fatal
            $t->string('occurrence', 16)->nullable();                        // dangerous occurrence: fire | explosion | collapse | lift | machinery
            $t->text('description');
            $t->string('first_aid', 255)->nullable();
            $t->string('hospital', 120)->nullable();
            $t->unsignedSmallInteger('lost_days')->default(0);
            $t->date('returned_on')->nullable();
            $t->string('witnesses', 255)->nullable();
            $t->foreignId('security_incident_id')->nullable()->constrained()->nullOnDelete();
            $t->boolean('reportable')->default(false);
            $t->date('notified_on')->nullable();
            $t->string('notified_ref', 60)->nullable();
            $t->foreignId('notified_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('root_cause')->nullable();
            $t->text('actions')->nullable();
            $t->foreignId('maintenance_request_id')->nullable()->constrained()->nullOnDelete();
            $t->string('status', 14)->default('open');                      // open | investigating | closed
            $t->foreignId('investigated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('closed_at')->nullable();
            $t->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'number']);
            $t->index(['hotel_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        foreach (['hse_accidents', 'hse_permits', 'hse_risks', 'hse_drill_staff', 'hse_drills', 'hse_wardens', 'hse_checks', 'hse_equipment'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};

