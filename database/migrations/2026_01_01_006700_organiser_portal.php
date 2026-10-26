<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v7.5 Event organiser portal (B2B extranet for MICE & banquet clients): organiser logins per company or per contact
 * e-mail, change / cancellation / new-event quote requests the hotel answers, an access & activity audit, guest lists
 * per event, a published banquet event order (BEO) the organiser signs online, and online payment of event deposits and
 * balances straight onto the event folio.
 */
return new class extends Migration
{
    public function up(): void
    {
                if (Schema::hasTable('organiser_users')) {
            return;
        }

        Schema::create('organiser_users', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete(); // null: a private client (wedding, party …)
            $t->foreignId('banquet_event_id')->nullable()->constrained()->nullOnDelete(); // direct link: a planner invited for one event
            $t->foreignId('invited_by')->nullable()->constrained('organiser_users')->nullOnDelete();
            $t->string('name', 120);
            $t->string('email', 190);
            $t->string('phone', 30)->nullable();
            $t->string('role', 10)->default('planner');      // admin (users + everything), planner (events / BEO / changes / guest lists), finance (money / documents only)
            $t->string('password')->nullable();               // null until the invitation is accepted
            $t->boolean('is_active')->default(true);
            $t->string('invite_token', 64)->nullable();       // sha256 of the emailed token
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

                if (Schema::hasTable('organiser_requests')) {
            return;
        }

        Schema::create('organiser_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('number', 20);
            $t->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('organiser_user_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('banquet_event_id')->nullable()->constrained()->nullOnDelete(); // null for a new-event quote
            $t->string('type', 12);                           // change (to an event), cancel (give the event up), quote (price a new event)
            $t->string('status', 10)->default('pending');     // pending, approved, declined, done, withdrawn
            $t->text('details')->nullable();                  // what the organiser asks for
            $t->json('payload')->nullable();                  // quoted fee / requested date-pax-package changes
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->string('decision_note', 500)->nullable();
            $t->timestamps();
            $t->index(['hotel_id', 'status', 'type']);
        });

                if (Schema::hasTable('organiser_audit')) {
            return;
        }

        Schema::create('organiser_audit', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('organiser_user_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('banquet_event_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action', 30);                         // login, login_failed, locked, logout, beo, guarantee, request, payment, guest_list, user, password …
            $t->string('detail', 300)->nullable();
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 190)->nullable();
            $t->timestamp('created_at')->nullable();
            $t->index(['hotel_id', 'created_at']);
            $t->index(['organiser_user_id', 'action']);
        });

                if (Schema::hasTable('organiser_attendees')) {
            return;
        }

        Schema::create('organiser_attendees', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('banquet_event_id')->constrained()->cascadeOnDelete();
            $t->string('name', 120);
            $t->string('email', 190)->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('dietary', 120)->nullable();            // vegetarian, no pork, allergies …
            $t->string('tag', 12)->default('delegate');       // delegate, vip, speaker, companion, crew
            $t->string('notes', 200)->nullable();
            $t->foreignId('added_by')->nullable()->constrained('organiser_users')->nullOnDelete();
            $t->timestamps();
            $t->index(['banquet_event_id', 'tag']);
        });

        Schema::table('banquet_events', function (Blueprint $t) {
            $t->boolean('portal_enabled')->default(false);
            $t->timestamp('beo_published_at')->nullable();
            $t->foreignId('beo_published_by')->nullable()->constrained('users')->nullOnDelete();
            $t->json('beo_snapshot')->nullable();             // the BEO the organiser signs: header, functions, money, terms
            $t->timestamp('beo_approved_at')->nullable();
            $t->string('beo_sign_name', 120)->nullable();
        });

        Schema::table('online_payments', function (Blueprint $t) {
            $t->foreignId('banquet_event_id')->nullable()->constrained()->nullOnDelete(); // purpose banquet: deposit / balance onto the event folio
        });
    }

    public function down(): void
    {
        Schema::table('online_payments', fn (Blueprint $t) => $t->dropConstrainedForeignId('banquet_event_id'));
        Schema::table('banquet_events', function (Blueprint $t) {
            $t->dropConstrainedForeignId('beo_published_by');
            $t->dropColumn(['portal_enabled', 'beo_published_at', 'beo_snapshot', 'beo_approved_at', 'beo_sign_name']);
        });
        Schema::dropIfExists('organiser_attendees');
        Schema::dropIfExists('organiser_audit');
        Schema::dropIfExists('organiser_requests');
        Schema::dropIfExists('organiser_users');
    }
};

