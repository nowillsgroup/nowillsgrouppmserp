<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.9 guest data privacy & compliance (India DPDP Act 2023 / GDPR):
 *  - data-principal (subject) requests: access, correction, erasure, consent withdrawal, grievance – with statutory
 *    deadline, identity verification, extension and a timeline; public request form with e-mail verification;
 *  - erasure = anonymisation, honouring legal holds (tax invoices, police / Form-C register) – the guest profile is
 *    "restricted" (only the legally required fields kept, no processing) until the hold expires, then fully erased;
 *  - retention policies run nightly (ID scans & e-signatures, message contents, inactive profiles, expired holds);
 *  - PII access log (reveals of masked identifiers, document views, data exports);
 *  - personal-data breach register with the notification deadline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guests', function (Blueprint $t) {
            $t->string('privacy_status', 12)->nullable()->after('stats_at');   // null | restricted | erased
            $t->date('legal_hold_until')->nullable()->after('privacy_status');
            $t->timestamp('erased_at')->nullable()->after('legal_hold_until');
            $t->string('privacy_note', 255)->nullable()->after('erased_at');
            $t->index(['hotel_id', 'privacy_status']);
        });

                if (Schema::hasTable('privacy_requests')) {
            return;
        }

        Schema::create('privacy_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('request_no', 20);
            $t->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $t->string('type', 12);                        // access | correction | erasure | consent | grievance
            $t->string('status', 12)->default('open');     // unverified | open | completed | rejected
            $t->string('channel', 12)->default('email');   // web | email | in_person | phone | letter
            $t->string('requester_name', 120);
            $t->string('requester_email', 150)->nullable();
            $t->string('requester_phone', 30)->nullable();
            $t->string('relationship', 12)->default('self');   // self | nominee | authorised (agent / guardian)
            $t->string('booking_ref', 40)->nullable();
            $t->text('details')->nullable();
            $t->dateTime('received_at');
            $t->date('due_on');
            $t->boolean('extended')->default(false);
            $t->string('extension_reason', 255)->nullable();
            $t->string('verify_token', 64)->nullable()->unique();   // sha256 of the e-mailed token (public form)
            $t->timestamp('verify_expires_at')->nullable();
            $t->timestamp('email_verified_at')->nullable();
            $t->timestamp('verified_at')->nullable();                 // identity verified by staff
            $t->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('verification_method', 20)->nullable();       // id_document | known_contact | email_link | in_person | booking_details
            $t->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('completed_at')->nullable();
            $t->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('outcome')->nullable();
            $t->string('rejection_reason', 255)->nullable();
            $t->unsignedInteger('exports')->default(0);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'request_no']);
            $t->index(['hotel_id', 'status', 'due_on']);
        });

                if (Schema::hasTable('privacy_request_events')) {
            return;
        }

        Schema::create('privacy_request_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('privacy_request_id')->constrained()->cascadeOnDelete();
            $t->string('action', 20);
            $t->text('note')->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
        });

                if (Schema::hasTable('pii_access_logs')) {
            return;
        }

        Schema::create('pii_access_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action', 12);                      // reveal | document | export | signature
            $t->string('detail', 190)->nullable();
            $t->string('reason', 190)->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['hotel_id', 'created_at']);
            $t->index(['hotel_id', 'guest_id']);
        });

                if (Schema::hasTable('privacy_retention_runs')) {
            return;
        }

        Schema::create('privacy_retention_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('trigger', 10)->default('schedule');   // schedule | manual
            $t->json('counts');
            $t->unsignedInteger('duration_ms')->default(0);
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
        });

                if (Schema::hasTable('data_breaches')) {
            return;
        }

        Schema::create('data_breaches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('ref', 20);
            $t->string('title', 150);
            $t->text('description');
            $t->dateTime('detected_at');
            $t->timestamp('occurred_at')->nullable();
            $t->string('severity', 10)->default('medium');   // low | medium | high | critical
            $t->string('categories', 255)->nullable();       // csv of data categories
            $t->unsignedInteger('affected_count')->default(0);
            $t->boolean('notifiable')->default(true);
            $t->string('not_notifiable_reason', 255)->nullable();
            $t->string('status', 12)->default('open');       // open | contained | notified | closed
            $t->timestamp('contained_at')->nullable();
            $t->text('containment')->nullable();
            $t->timestamp('authority_notified_at')->nullable();
            $t->string('authority_ref', 80)->nullable();
            $t->timestamp('subjects_notified_at')->nullable();
            $t->string('subjects_notice', 255)->nullable();
            $t->text('root_cause')->nullable();
            $t->text('lessons')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_breaches');
        Schema::dropIfExists('privacy_retention_runs');
        Schema::dropIfExists('pii_access_logs');
        Schema::dropIfExists('privacy_request_events');
        Schema::dropIfExists('privacy_requests');
        Schema::table('guests', function (Blueprint $t) {
            $t->dropIndex(['hotel_id', 'privacy_status']);
            $t->dropColumn(['privacy_status', 'legal_hold_until', 'erased_at', 'privacy_note']);
        });
    }
};

