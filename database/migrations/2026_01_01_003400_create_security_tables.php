<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v4.2 – Security & Gate Management:
 *  - visitor register (guests' visitors, staff / event visitors, portal pre-registration, badges, overstays, ID retention)
 *  - watch list (banned / flagged persons)
 *  - material gate passes (returnable / non-returnable, approval, gate out, partial returns, overdue returns)
 *  - security incident register with follow-up notes
 */
return new class extends Migration
{
    public function up(): void
    {
                if (Schema::hasTable('security_watchlist')) {
            return;
        }

        Schema::create('security_watchlist', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('name', 120);
            $t->string('phone', 30)->nullable();
            $t->string('phone_key', 15)->nullable();          // last 10 digits – what matching uses
            $t->string('id_type', 30)->nullable();
            $t->string('id_last4', 4)->nullable();
            $t->string('id_hash', 64)->nullable();            // sha256 of the normalised ID number – the number itself is never stored
            $t->string('level', 10)->default('block');        // block = refuse entry (manager override only), alert = let in but warn
            $t->string('reason', 255);
            $t->date('valid_until')->nullable();              // null = indefinite
            $t->boolean('is_active')->default(true);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['hotel_id', 'phone_key']);
            $t->index(['hotel_id', 'id_hash']);
        });

                if (Schema::hasTable('visitors')) {
            return;
        }

        Schema::create('visitors', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('pass_no', 20);
            $t->string('name', 120);
            $t->string('phone', 30)->nullable();
            $t->string('company', 120)->nullable();
            $t->string('id_type', 30)->nullable();
            $t->string('id_last4', 4)->nullable();
            $t->string('id_hash', 64)->nullable();
            $t->string('purpose', 20)->default('guest_visit');     // guest_visit, meeting, delivery, contractor, interview, event, other
            $t->string('host_type', 10)->default('guest');         // guest, staff, event
            $t->foreignId('reservation_room_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();   // the host guest
            $t->foreignId('host_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('banquet_event_id')->nullable()->constrained()->nullOnDelete();
            $t->string('host_name', 150)->nullable();              // snapshot shown on the badge / register
            $t->unsignedSmallInteger('persons')->default(1);
            $t->string('vehicle_no', 20)->nullable();
            $t->string('items_declared', 255)->nullable();         // laptop, tools… checked at exit
            $t->string('status', 20)->default('in');               // expected, awaiting, in, out, denied, cancelled, no_show
            $t->string('source', 10)->default('desk');             // desk, portal
            $t->dateTime('expected_at')->nullable();
            $t->dateTime('valid_until')->nullable();
            $t->dateTime('checked_in_at')->nullable();
            $t->dateTime('checked_out_at')->nullable();
            $t->foreignId('checked_in_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('checked_out_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('approved_via', 20)->nullable();            // phone, host, pre_registered, override
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('deny_reason', 255)->nullable();
            $t->foreignId('watchlist_id')->nullable()->constrained('security_watchlist')->nullOnDelete();
            $t->string('override_reason', 255)->nullable();
            $t->string('pre_token', 40)->nullable()->unique();     // guest-shared visitor pass link
            $t->string('notes', 500)->nullable();
            $t->dateTime('overstay_alerted_at')->nullable();
            $t->dateTime('anonymised_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'pass_no']);
            $t->index(['hotel_id', 'status']);
            $t->index(['hotel_id', 'checked_in_at']);
        });

                if (Schema::hasTable('gate_passes')) {
            return;
        }

        Schema::create('gate_passes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('pass_no', 20);
            $t->string('type', 15)->default('returnable');         // returnable, non_returnable
            $t->string('purpose', 20)->default('repair');          // repair, vendor_return, laundry, hire_out, transfer, scrap_sale, event, other
            $t->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $t->string('party_name', 150);                         // vendor / receiver
            $t->string('party_phone', 30)->nullable();
            $t->string('carrier_name', 120)->nullable();           // person physically taking it out
            $t->string('vehicle_no', 20)->nullable();
            $t->date('expected_return_date')->nullable();
            $t->string('reason', 500)->nullable();
            $t->string('status', 20)->default('pending');          // pending, approved, rejected, out, partial, returned, closed, cancelled
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('approved_at')->nullable();
            $t->string('reject_reason', 255)->nullable();
            $t->dateTime('out_at')->nullable();
            $t->foreignId('out_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('closed_at')->nullable();
            $t->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('close_note', 255)->nullable();
            $t->date('overdue_alerted_on')->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'pass_no']);
            $t->index(['hotel_id', 'status']);
        });

                if (Schema::hasTable('gate_pass_items')) {
            return;
        }

        Schema::create('gate_pass_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('gate_pass_id')->constrained()->cascadeOnDelete();
            $t->string('description', 200);
            $t->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('quantity', 10, 2);
            $t->string('unit', 15)->default('nos');
            $t->decimal('est_value', 12, 2)->nullable();
            $t->decimal('returned_qty', 10, 2)->default(0);
            $t->timestamps();
        });

                if (Schema::hasTable('gate_pass_returns')) {
            return;
        }

        Schema::create('gate_pass_returns', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('gate_pass_id')->constrained()->cascadeOnDelete();
            $t->dateTime('returned_at');
            $t->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('carrier_name', 120)->nullable();
            $t->string('note', 255)->nullable();
            $t->json('lines');                                      // [{item_id, qty}]
            $t->timestamps();
        });

                if (Schema::hasTable('security_incidents')) {
            return;
        }

        Schema::create('security_incidents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('incident_no', 20);
            $t->string('type', 20);                                 // theft, trespass, disturbance, fire_alarm, medical, accident, damage, harassment, suspicious, other
            $t->string('severity', 10)->default('medium');          // low, medium, high, critical
            $t->dateTime('occurred_at');
            $t->string('location', 150);
            $t->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('reservation_room_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $t->string('title', 200);
            $t->text('description');
            $t->text('persons_involved')->nullable();
            $t->text('action_taken')->nullable();
            $t->boolean('injuries')->default(false);
            $t->decimal('loss_amount', 12, 2)->nullable();
            $t->boolean('police_informed')->default(false);
            $t->string('police_station', 120)->nullable();
            $t->string('police_ref', 60)->nullable();               // FIR / DD / complaint number
            $t->string('status', 15)->default('open');              // open, investigating, closed
            $t->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->text('resolution')->nullable();
            $t->dateTime('closed_at')->nullable();
            $t->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('log_entry_id')->nullable()->constrained('log_entries')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'incident_no']);
            $t->index(['hotel_id', 'status']);
            $t->index(['hotel_id', 'occurred_at']);
        });

                if (Schema::hasTable('security_incident_updates')) {
            return;
        }

        Schema::create('security_incident_updates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('security_incident_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->text('note');
            $t->string('status_to', 15)->nullable();
            $t->timestamps();
        });

        Schema::table('reservation_rooms', function (Blueprint $t) {
            // allow = send visitors up after the desk's usual check; call = always call me first; none = no visitors, do not confirm I'm staying here
            $t->string('visitor_policy', 10)->default('allow')->after('portal_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('reservation_rooms', fn (Blueprint $t) => $t->dropColumn('visitor_policy'));
        foreach (['security_incident_updates', 'security_incidents', 'gate_pass_returns', 'gate_pass_items', 'gate_passes', 'visitors', 'security_watchlist'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};

