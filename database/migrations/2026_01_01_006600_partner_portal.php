<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v7.4 Corporate & travel-agent portal (B2B extranet): partner logins per company, approval / change requests the hotel
 * answers, an access & activity audit, portal terms on the company, the booker on the reservation and online payment of
 * a company's city-ledger balance.
 */
return new class extends Migration
{
    public function up(): void
    {
                if (Schema::hasTable('partner_users')) {
            return;
        }

        Schema::create('partner_users', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('name', 120);
            $t->string('email', 190);
            $t->string('phone', 30)->nullable();
            $t->string('role', 10)->default('booker');           // admin (users + everything), booker, finance (invoices / payments only)
            $t->string('password')->nullable();                   // null until the invitation is accepted
            $t->boolean('is_active')->default(true);
            $t->string('invite_token', 64)->nullable();           // sha256 of the emailed token
            $t->timestamp('invite_expires_at')->nullable();
            $t->string('reset_token', 64)->nullable();
            $t->timestamp('reset_expires_at')->nullable();
            $t->unsignedTinyInteger('failed_logins')->default(0);
            $t->timestamp('locked_until')->nullable();
            $t->timestamp('last_login_at')->nullable();
            $t->string('last_login_ip', 45)->nullable();
            $t->timestamp('password_changed_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'email']);
            $t->index(['company_id', 'is_active']);
        });

                if (Schema::hasTable('partner_requests')) {
            return;
        }

        Schema::create('partner_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('number', 20);
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('partner_user_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $t->string('type', 12);                               // approval (booking waiting for the hotel), amendment (change to a booking), group (quote for 6+ rooms)
            $t->string('status', 10)->default('pending');         // pending, approved, declined, done, withdrawn
            $t->string('reason', 300)->nullable();                // why the booking needs approval
            $t->text('details')->nullable();                      // what the partner asks for
            $t->json('payload')->nullable();                      // requested dates / rooms
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->string('decision_note', 500)->nullable();
            $t->timestamps();
            $t->index(['hotel_id', 'status', 'type']);
        });

                if (Schema::hasTable('partner_audit')) {
            return;
        }

        Schema::create('partner_audit', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('partner_user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action', 30);                             // login, login_failed, locked, logout, booking, cancel, request, payment, user, password …
            $t->string('detail', 300)->nullable();
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 190)->nullable();
            $t->timestamp('created_at')->nullable();
            $t->index(['hotel_id', 'created_at']);
            $t->index(['partner_user_id', 'action']);
        });

        Schema::table('companies', function (Blueprint $t) {
            $t->boolean('portal_enabled')->default(false);
            $t->decimal('portal_approval_over', 14, 2)->nullable(); // bookings worth more than this wait for the hotel
            $t->boolean('portal_guest_pay')->default(true);         // may book stays the guest pays at the hotel
            $t->unsignedSmallInteger('portal_max_rooms')->default(5);
        });

        Schema::table('reservations', function (Blueprint $t) {
            $t->foreignId('partner_user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('partner_ref', 60)->nullable();              // PO number / cost centre / agency file reference
        });

        Schema::table('online_payments', function (Blueprint $t) {
            $t->foreignId('company_id')->nullable()->constrained()->nullOnDelete(); // purpose city_ledger: settles the company account
        });
    }

    public function down(): void
    {
        Schema::table('online_payments', fn (Blueprint $t) => $t->dropConstrainedForeignId('company_id'));
        Schema::table('reservations', function (Blueprint $t) {
            $t->dropConstrainedForeignId('partner_user_id');
            $t->dropColumn('partner_ref');
        });
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn(['portal_enabled', 'portal_approval_over', 'portal_guest_pay', 'portal_max_rooms']));
        Schema::dropIfExists('partner_audit');
        Schema::dropIfExists('partner_requests');
        Schema::dropIfExists('partner_users');
    }
};

