<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v3.1 guest feedback & online reputation management:
 *  - configurable surveys (NPS, department ratings, yes/no, choice, free text);
 *  - post-stay survey invitations (scheduled at check-out, e-mail / SMS, one reminder, expiry, per-guest cooldown),
 *    reached through an unguessable link (only the SHA-256 of the token is stored);
 *  - every response (survey, guest portal, public form) is analysed: NPS group, 0-100 score, sentiment and
 *    department mentions (aspects) – poor scores open a service-recovery case with an SLA;
 *  - service-recovery cases with owner, timeline, guest contact, resolution, goodwill loyalty points and outcome;
 *  - online reviews (TripAdvisor, Google, Booking.com, …) imported or keyed in, normalised to 5, analysed,
 *    answered from templates with optional manager approval.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surveys', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('name', 120);
            $t->text('intro')->nullable();
            $t->text('thank_you')->nullable();
            $t->boolean('is_active')->default(true);
            $t->boolean('is_default')->default(false);           // the post-stay survey sent at check-out
            $t->string('channel', 8)->default('email');          // email | sms | both
            $t->unsignedSmallInteger('send_delay_hours')->default(4);
            $t->unsignedSmallInteger('reminder_hours')->nullable()->default(48);
            $t->unsignedSmallInteger('expiry_days')->default(14);
            $t->timestamps();
        });

        Schema::create('survey_questions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('survey_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('sort')->default(0);
            $t->string('type', 8);                               // nps | rating | yesno | choice | text
            $t->string('label', 255);
            $t->string('department', 16)->nullable();            // FeedbackService::DEPTS
            $t->json('options')->nullable();                     // choice answers
            $t->boolean('required')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('survey_invitations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('survey_id')->constrained()->cascadeOnDelete();
            $t->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('reservation_room_id')->nullable()->constrained('reservation_rooms')->nullOnDelete();
            $t->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $t->string('channel', 8)->default('email');
            $t->string('to_email', 150)->nullable();
            $t->string('to_phone', 30)->nullable();
            $t->string('nonce', 32);                              // token = HMAC(app key, id|nonce); link can be re-sent without storing it
            $t->string('token_hash', 64)->nullable()->unique();  // sha256(token), set when first sent
            $t->string('status', 10)->default('scheduled');      // scheduled | sent | opened | completed | expired | skipped | failed
            $t->string('skip_reason', 120)->nullable();
            $t->string('error', 255)->nullable();
            $t->timestamp('send_at')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('reminded_at')->nullable();
            $t->timestamp('opened_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['survey_id', 'reservation_id']);          // one invitation per stay and survey
            $t->index(['hotel_id', 'status', 'send_at']);
            $t->index(['hotel_id', 'guest_id']);
        });

        Schema::table('guest_feedback', function (Blueprint $t) {
            $t->foreignId('survey_id')->nullable()->after('reservation_room_id')->constrained()->nullOnDelete();
            $t->foreignId('invitation_id')->nullable()->after('survey_id')->constrained('survey_invitations')->nullOnDelete();
            $t->foreignId('guest_id')->nullable()->after('invitation_id')->constrained()->nullOnDelete();
            $t->unsignedTinyInteger('nps')->nullable()->after('rating_cleanliness');
            $t->string('nps_group', 10)->nullable()->after('nps');        // promoter | passive | detractor
            $t->unsignedTinyInteger('score')->nullable()->after('nps_group'); // 0-100
            $t->string('sentiment', 8)->nullable()->after('score');       // positive | neutral | negative
            $t->json('aspects')->nullable()->after('sentiment');          // department => positive | negative | mixed
            $t->boolean('in_house')->default(false)->after('aspects');
            $t->boolean('consent_publish')->default(false)->after('in_house');
            $t->string('status', 10)->default('new')->after('source');    // new | reviewed
            $t->foreignId('reviewed_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $t->string('review_click_site', 16)->nullable()->after('reviewed_at');
            $t->timestamp('review_click_at')->nullable()->after('review_click_site');
            $t->index(['hotel_id', 'created_at']);
            $t->index(['hotel_id', 'guest_id']);
        });

        Schema::create('feedback_answers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('feedback_id')->constrained('guest_feedback')->cascadeOnDelete();
            $t->foreignId('question_id')->constrained('survey_questions')->cascadeOnDelete();
            $t->smallInteger('value_int')->nullable();
            $t->text('value_text')->nullable();
            $t->index(['question_id', 'value_int']);
        });

        Schema::create('online_reviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('source', 16);                            // tripadvisor | google | booking | expedia | makemytrip | agoda | other
            $t->string('external_id', 80)->nullable();
            $t->string('dedupe_hash', 64);
            $t->string('reviewer_name', 120);
            $t->string('reviewer_location', 120)->nullable();
            $t->date('review_date');
            $t->decimal('rating', 4, 1);
            $t->unsignedTinyInteger('rating_scale')->default(5); // 5 | 10
            $t->decimal('rating5', 3, 1);                        // normalised to 5
            $t->string('title', 200)->nullable();
            $t->text('body');
            $t->string('url', 500)->nullable();
            $t->string('trip_type', 16)->nullable();             // business | couple | family | friends | solo
            $t->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $t->string('sentiment', 8)->nullable();
            $t->json('aspects')->nullable();
            $t->string('status', 10)->default('new');            // new | draft | pending | approved | posted | ignored
            $t->text('response_text')->nullable();
            $t->foreignId('drafted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('drafted_at')->nullable();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->string('return_note', 255)->nullable();
            $t->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('posted_at')->nullable();
            $t->string('ignore_reason', 160)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'source', 'dedupe_hash']);
            $t->index(['hotel_id', 'status', 'review_date']);
        });

        Schema::create('feedback_cases', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('case_no', 20);
            $t->string('source', 8)->default('feedback');         // feedback | review | manual
            $t->foreignId('feedback_id')->nullable()->unique()->constrained('guest_feedback')->nullOnDelete();
            $t->foreignId('review_id')->nullable()->unique()->constrained('online_reviews')->nullOnDelete();
            $t->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $t->string('department', 16);
            $t->string('severity', 8);                           // low | medium | high
            $t->boolean('in_house')->default(false);
            $t->string('title', 160);
            $t->text('details')->nullable();
            $t->string('status', 12)->default('open');           // open | in_progress | resolved | closed
            $t->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('due_at');
            $t->timestamp('first_response_at')->nullable();
            $t->timestamp('escalated_at')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('resolution')->nullable();
            $t->string('recovery_action', 16)->nullable();       // FeedbackCase::ACTIONS
            $t->unsignedInteger('goodwill_points')->default(0);
            $t->timestamp('closed_at')->nullable();
            $t->string('outcome', 16)->nullable();                // recovered | not_recovered | no_reply
            $t->timestamps();
            $t->unique(['hotel_id', 'case_no']);
            $t->index(['hotel_id', 'status', 'due_at']);
        });

        Schema::create('feedback_case_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('case_id')->constrained('feedback_cases')->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('type', 12);                              // opened | assigned | started | note | contacted | resolved | closed | reopened | escalated | updated
            $t->text('note')->nullable();
            $t->timestamp('created_at')->nullable();
        });

        Schema::create('review_templates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('name', 80);
            $t->string('sentiment', 8)->default('any');          // positive | neutral | negative | any
            $t->text('body');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_templates');
        Schema::dropIfExists('feedback_case_events');
        Schema::dropIfExists('feedback_cases');
        Schema::dropIfExists('online_reviews');
        Schema::dropIfExists('feedback_answers');
        Schema::table('guest_feedback', function (Blueprint $t) {
            $t->dropIndex(['hotel_id', 'created_at']);
            $t->dropIndex(['hotel_id', 'guest_id']);
            $t->dropConstrainedForeignId('survey_id');
            $t->dropConstrainedForeignId('invitation_id');
            $t->dropConstrainedForeignId('guest_id');
            $t->dropConstrainedForeignId('reviewed_by');
            $t->dropColumn(['nps', 'nps_group', 'score', 'sentiment', 'aspects', 'in_house', 'consent_publish', 'status', 'reviewed_at', 'review_click_site', 'review_click_at']);
        });
        Schema::dropIfExists('survey_invitations');
        Schema::dropIfExists('survey_questions');
        Schema::dropIfExists('surveys');
    }
};

