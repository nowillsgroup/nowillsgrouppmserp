<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v6.9 Talent management:
 *  - recruitment: approved vacancies (establishment check), applicants from the public careers page / referrals / agencies,
 *    interviews with panel scorecards, offers (above-range salary needs approval) and hire into the employee register;
 *  - new starters: onboarding checklist, the written statement of particulars within two months (Labour Act 2003,
 *    Act 651 s. 13) and probation reviews;
 *  - performance appraisals: cycles, weighted goals + competencies, self-assessment, manager review, HR sign-off;
 *  - discipline & grievances: query (show cause), reply, hearing, sanction with warning expiry, dismissal only for
 *    proven misconduct (s. 62);
 *  - exits: statutory notice (s. 17), final-pay date (s. 18), clearance, exit interview, turnover.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $t) {
            $t->string('employment_type', 12)->default('permanent')->after('designation'); // permanent | fixed_term | weekly | casual
            $t->date('contract_end')->nullable()->after('leaving_date');
            $t->date('probation_end')->nullable()->after('contract_end');
            $t->date('confirmed_on')->nullable()->after('probation_end');
            $t->date('statement_issued_on')->nullable()->after('confirmed_on');   // Act 651 s. 13 written statement of particulars
            $t->boolean('has_disability')->default(false)->after('statement_issued_on'); // s. 51: at least one month's notice
            $t->foreignId('manager_user_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
        });
        Schema::table('departments', function (Blueprint $t) {
            $t->unsignedSmallInteger('establishment')->nullable();                // approved headcount
        });

        Schema::create('talent_vacancies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('number', 20);
            $t->foreignId('department_id')->constrained()->cascadeOnDelete();
            $t->string('title', 80);
            $t->unsignedTinyInteger('headcount')->default(1);
            $t->unsignedTinyInteger('filled')->default(0);
            $t->string('employment_type', 12)->default('permanent');
            $t->unsignedTinyInteger('contract_months')->nullable();
            $t->decimal('salary_min', 12, 2);
            $t->decimal('salary_max', 12, 2);
            $t->string('reason', 12)->default('new');                          // new | replacement | seasonal
            $t->foreignId('replaces_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $t->text('justification')->nullable();
            $t->text('description')->nullable();
            $t->text('requirements')->nullable();
            $t->date('closing_on')->nullable();
            $t->boolean('is_public')->default(true);
            $t->string('status', 12)->default('draft');                        // draft | pending | open | on_hold | filled | cancelled
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->string('approval_note', 255)->nullable();
            $t->date('filled_on')->nullable();
            $t->string('closed_reason', 255)->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'number']);
            $t->index(['hotel_id', 'status']);
        });

        Schema::create('talent_applicants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('number', 20);
            $t->foreignId('vacancy_id')->constrained('talent_vacancies')->cascadeOnDelete();
            $t->string('name', 120);
            $t->string('email', 150)->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('source', 12)->default('careers');                      // careers | referral | agency | walk_in | job_board | internal
            $t->foreignId('referred_by_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $t->string('current_employer', 120)->nullable();
            $t->decimal('experience_years', 4, 1)->nullable();
            $t->decimal('expected_salary', 12, 2)->nullable();
            $t->string('cv_path', 255)->nullable();
            $t->string('cv_name', 150)->nullable();
            $t->string('cv_mime', 100)->nullable();
            $t->text('cover_note')->nullable();
            $t->string('stage', 12)->default('applied');                       // applied | screening | interview | offer | hired | rejected | withdrawn
            $t->unsignedTinyInteger('screen_rating')->nullable();
            $t->string('reject_reason', 255)->nullable();
            $t->timestamp('stage_changed_at')->nullable();
            $t->timestamp('consent_at')->nullable();                            // Data Protection Act 2012 (Act 843) consent
            $t->timestamp('anonymised_at')->nullable();
            $t->foreignId('hired_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'number']);
            $t->index(['hotel_id', 'vacancy_id', 'stage']);
        });

        Schema::create('talent_interviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('applicant_id')->constrained('talent_applicants')->cascadeOnDelete();
            $t->unsignedTinyInteger('round')->default(1);
            $t->string('kind', 12)->default('in_person');                      // phone | video | in_person | trade_test
            $t->dateTime('scheduled_at');
            $t->string('location', 120)->nullable();
            $t->json('panel');                                                  // user ids
            $t->string('status', 10)->default('scheduled');                    // scheduled | done | no_show | cancelled
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('talent_scorecards', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('interview_id')->constrained('talent_interviews')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->json('scores');
            $t->decimal('average', 3, 2);
            $t->string('recommendation', 8);                                   // hire | hold | reject
            $t->text('comments')->nullable();
            $t->timestamps();
            $t->unique(['interview_id', 'user_id']);
        });

        Schema::create('talent_offers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('number', 20);
            $t->foreignId('applicant_id')->constrained('talent_applicants')->cascadeOnDelete();
            $t->foreignId('vacancy_id')->constrained('talent_vacancies')->cascadeOnDelete();
            $t->decimal('salary', 12, 2);
            $t->date('start_date');
            $t->unsignedTinyInteger('probation_months')->default(6);
            $t->string('employment_type', 12);
            $t->unsignedTinyInteger('contract_months')->nullable();
            $t->string('status', 12)->default('issued');                       // pending | issued | accepted | declined | withdrawn
            $t->boolean('above_range')->default(false);
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->date('respond_by')->nullable();
            $t->timestamp('responded_at')->nullable();
            $t->string('decline_reason', 255)->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'number']);
        });

        Schema::create('talent_tasks', function (Blueprint $t) {                // onboarding checklist per new starter
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->string('item', 20);
            $t->date('due_on');
            $t->timestamp('done_at')->nullable();
            $t->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('note', 190)->nullable();
            $t->timestamps();
            $t->unique(['employee_id', 'item']);
        });

        Schema::create('talent_probations', function (Blueprint $t) {         // probation review history
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->date('due_on');
            $t->date('reviewed_on');
            $t->string('outcome', 10);                                         // confirm | extend | terminate
            $t->unsignedTinyInteger('extend_months')->nullable();
            $t->unsignedTinyInteger('rating')->nullable();
            $t->text('comments')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('talent_cycles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('name', 80);
            $t->date('period_from');
            $t->date('period_to');
            $t->date('self_due');
            $t->date('review_due');
            $t->string('status', 8)->default('active');                        // active | closed
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
        });

        Schema::create('talent_appraisals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('cycle_id')->constrained('talent_cycles')->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $t->json('goals')->nullable();                                      // [{title, weight, self, mgr}]
            $t->json('competencies')->nullable();                               // {key: {self, mgr}}
            $t->text('self_comments')->nullable();
            $t->text('mgr_comments')->nullable();
            $t->text('development')->nullable();
            $t->decimal('goals_score', 3, 2)->nullable();
            $t->decimal('comp_score', 3, 2)->nullable();
            $t->decimal('overall', 3, 2)->nullable();
            $t->unsignedTinyInteger('band')->nullable();                        // 1–5
            $t->boolean('pip')->default(false);
            $t->string('status', 8)->default('self');                          // self | review | hr | signed
            $t->timestamp('self_at')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->foreignId('signed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('signed_at')->nullable();
            $t->timestamp('acknowledged_at')->nullable();
            $t->string('employee_comment', 255)->nullable();
            $t->timestamps();
            $t->unique(['cycle_id', 'employee_id']);
        });

        Schema::create('talent_cases', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('number', 20);
            $t->string('kind', 10);                                            // discipline | grievance
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->string('category', 20);
            $t->string('severity', 6)->nullable();                             // minor | major | gross (discipline)
            $t->date('incident_on');
            $t->text('description');
            $t->boolean('confidential')->default(false);
            $t->string('status', 16)->default('open');                         // open | query | hearing | decided | investigating | resolved | closed | withdrawn
            $t->date('query_issued_on')->nullable();
            $t->date('reply_due')->nullable();
            $t->text('reply')->nullable();
            $t->date('replied_on')->nullable();
            $t->dateTime('hearing_at')->nullable();
            $t->string('panel', 255)->nullable();
            $t->string('representative', 120)->nullable();
            $t->text('hearing_notes')->nullable();
            $t->boolean('hearing_held')->default(false);
            $t->string('finding', 12)->nullable();                             // proven | not_proven
            $t->string('outcome', 12)->nullable();                             // none | counselling | verbal | written | final | suspension | dismissal
            $t->unsignedTinyInteger('suspension_days')->nullable();
            $t->boolean('suspension_paid')->default(false);
            $t->date('outcome_on')->nullable();
            $t->date('expires_on')->nullable();
            $t->text('resolution')->nullable();
            $t->foreignId('raised_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'number']);
            $t->index(['hotel_id', 'employee_id']);
        });

        Schema::create('talent_exits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('number', 20);
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->string('type', 14);
            $t->date('notice_on');
            $t->unsignedSmallInteger('notice_days');
            $t->string('notice_basis', 120);
            $t->date('last_day');
            $t->boolean('pilon')->default(false);                              // pay in lieu of notice (s. 18(4))
            $t->decimal('pilon_amount', 12, 2)->nullable();
            $t->date('final_pay_due');
            $t->foreignId('case_id')->nullable()->constrained('talent_cases')->nullOnDelete();
            $t->text('reason')->nullable();
            $t->date('clo_notified_on')->nullable();                            // redundancy: Chief Labour Officer (s. 65)
            $t->boolean('fair_confirmed')->default(false);                     // s. 63 reasons excluded
            $t->boolean('regretted')->default(false);
            $t->boolean('rehire')->default(true);
            $t->string('status', 10)->default('notice');                       // notice | completed | withdrawn
            $t->json('clearance')->nullable();
            $t->json('interview')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'number']);
        });
    }

    public function down(): void
    {
        foreach (['talent_exits', 'talent_cases', 'talent_appraisals', 'talent_cycles', 'talent_probations', 'talent_tasks', 'talent_offers', 'talent_scorecards',
            'talent_interviews', 'talent_applicants', 'talent_vacancies'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('departments', fn (Blueprint $t) => $t->dropColumn('establishment'));
        Schema::table('employees', function (Blueprint $t) {
            $t->dropConstrainedForeignId('manager_user_id');
            $t->dropColumn(['employment_type', 'contract_end', 'probation_end', 'confirmed_on', 'statement_issued_on', 'has_disability']);
        });
    }
};

