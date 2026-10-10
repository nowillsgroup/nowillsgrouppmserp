<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v5.3 Income Audit & Revenue Integrity.
 *
 * - audit_days: one row per closed business date – checks run, figures snapshot, auditor sign-off, controller close, reopen.
 * - audit_findings: exceptions raised by the automated checks (rate variance, comps, missing tariffs, discounts, voids,
 *   cash variances, open bills, unsettled departures, E-VAT, room-count discrepancies, figures changed after the audit),
 *   each explained / corrected / escalated / accepted by the auditor or auto-cleared.
 * - revenue_authorisations: comps, house use, below-expected rates and discounts approved in advance (four-eyes, limits).
 * - room_counts: the housekeeping room-count sheet (blind count) compared with front-office status → sleepers and
 *   unregistered occupancy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_days', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->date('business_date');
            $t->string('status', 10)->default('open');                  // open | signed | closed
            $t->timestamp('checked_at')->nullable();
            $t->json('figures')->nullable();                             // reconciliation snapshot at the last run
            $t->unsignedSmallInteger('findings_total')->default(0);
            $t->unsignedSmallInteger('findings_open')->default(0);
            $t->foreignId('signed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('signed_at')->nullable();
            $t->string('sign_note', 500)->nullable();
            $t->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('closed_at')->nullable();
            $t->string('close_note', 500)->nullable();
            $t->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reopened_at')->nullable();
            $t->string('reopen_reason', 255)->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'business_date']);
        });

        Schema::create('revenue_authorisations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('auth_no', 20);
            $t->string('kind', 12);                                     // comp | house_use | rate | discount
            $t->foreignId('reservation_room_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('folio_id')->nullable()->constrained()->nullOnDelete();
            $t->date('date_from')->nullable();
            $t->date('date_to')->nullable();
            $t->decimal('amount', 12, 2)->default(0);                    // approved rate (rate) or maximum discount (discount)
            $t->decimal('previous_rate', 12, 2)->nullable();
            $t->string('reason', 255);
            $t->string('status', 10)->default('pending');               // pending | approved | declined | cancelled
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->string('decision_note', 255)->nullable();
            $t->foreignId('folio_transaction_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('applied_at')->nullable();                     // stay rate changed (comp / house use / rate)
            $t->timestamp('restored_at')->nullable();                    // previous rate put back after date_to
            $t->timestamps();
            $t->unique(['hotel_id', 'auth_no']);
            $t->index(['hotel_id', 'status']);
        });

        Schema::create('room_counts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->date('business_date');
            $t->foreignId('room_id')->constrained()->cascadeOnDelete();
            $t->string('hk_status', 10);                                // occupied | vacant | ooo
            $t->boolean('luggage')->default(false);
            $t->string('fo_status', 12);                                // front-office status when counted
            $t->string('result', 14)->default('ok');                    // ok | sleeper | unregistered | ooo_mismatch
            $t->string('resolution', 20)->nullable();
            $t->string('note', 255)->nullable();
            $t->foreignId('counted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'business_date', 'room_id']);
        });

        Schema::create('audit_findings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('audit_day_id')->constrained()->cascadeOnDelete();
            $t->string('check', 20);
            $t->string('severity', 6);                                  // high | medium | low
            $t->string('fingerprint', 120);
            $t->string('ref_type', 20)->nullable();
            $t->unsignedBigInteger('ref_id')->nullable();
            $t->string('ref_label', 80)->nullable();
            $t->string('room_no', 20)->nullable();
            $t->string('detail', 500);
            $t->decimal('expected', 14, 2)->nullable();
            $t->decimal('actual', 14, 2)->nullable();
            $t->decimal('amount', 14, 2)->default(0);                    // revenue at risk / difference
            $t->string('status', 10)->default('open');                  // open | explained | corrected | escalated | accepted | cleared
            $t->string('resolution', 500)->nullable();
            $t->foreignId('revenue_authorisation_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
            $t->unique(['audit_day_id', 'fingerprint']);
            $t->index(['hotel_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_findings');
        Schema::dropIfExists('room_counts');
        Schema::dropIfExists('revenue_authorisations');
        Schema::dropIfExists('audit_days');
    }
};

