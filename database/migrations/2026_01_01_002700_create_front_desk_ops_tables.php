<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v3.5 – front desk operations: the shift log book (hand-over notes with read acknowledgement, follow-ups and
 * comments), guest messages & parcels held at the desk, wake-up calls (daily repeats, attempts, escalation), traces
 * (dated reminders against a booking or a department) and a full lost & found lifecycle (numbered items, valuables,
 * guest loss reports with matching, ID-checked returns, courier returns and witnessed disposal after retention).
 */
return new class extends Migration
{
    public function up(): void
    {
                if (Schema::hasTable('log_entries')) {
            return;
        }

        Schema::create('log_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->date('business_date');
            $t->string('shift', 10);                                   // morning | evening | night
            $t->string('department', 20)->default('front_office');     // front_office | housekeeping | fnb | engineering | security | management | all
            $t->string('category', 20)->default('info');               // info | handover | guest | complaint | incident | vip | maintenance | security | cash
            $t->string('priority', 8)->default('normal');              // normal | high | urgent
            $t->string('title', 150);
            $t->text('body')->nullable();
            $t->foreignId('reservation_room_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $t->string('status', 10)->default('note');                 // note (no action) | open | resolved
            $t->dateTime('follow_up_at')->nullable();
            $t->boolean('pinned')->default(false);
            $t->date('pinned_until')->nullable();
            $t->text('resolution')->nullable();
            $t->dateTime('resolved_at')->nullable();
            $t->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('source', 12)->default('manual');               // manual | system
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['hotel_id', 'business_date']);
            $t->index(['hotel_id', 'status']);
        });

                if (Schema::hasTable('log_entry_reads')) {
            return;
        }

        Schema::create('log_entry_reads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('log_entry_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->dateTime('read_at');
            $t->unique(['log_entry_id', 'user_id']);
        });

                if (Schema::hasTable('log_entry_comments')) {
            return;
        }

        Schema::create('log_entry_comments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('log_entry_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->text('body');
            $t->timestamps();
        });

                if (Schema::hasTable('guest_messages')) {
            return;
        }

        Schema::create('guest_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('message_no', 20);
            $t->string('kind', 8)->default('message');                 // message | parcel
            $t->foreignId('reservation_room_id')->constrained()->cascadeOnDelete();
            $t->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $t->string('channel', 10)->default('phone');               // phone | visitor | email | internal | courier
            $t->string('from_name', 120);
            $t->string('from_phone', 40)->nullable();
            $t->string('from_company', 120)->nullable();
            $t->text('body')->nullable();
            $t->string('courier', 60)->nullable();
            $t->string('tracking_no', 60)->nullable();
            $t->unsignedTinyInteger('pieces')->default(1);
            $t->string('storage_location', 60)->nullable();
            $t->boolean('urgent')->default(false);
            $t->string('status', 10)->default('pending');              // pending | notified | delivered | returned | cancelled
            $t->dateTime('notified_at')->nullable();
            $t->string('notified_via', 10)->nullable();                // sms | whatsapp | email | call | portal
            $t->dateTime('portal_read_at')->nullable();
            $t->dateTime('delivered_at')->nullable();
            $t->foreignId('delivered_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('delivery_note', 190)->nullable();
            $t->string('collected_by', 120)->nullable();               // parcel collected by (guest / someone on their behalf)
            $t->string('close_reason', 190)->nullable();
            $t->foreignId('taken_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'message_no']);
            $t->index(['hotel_id', 'status']);
        });

                if (Schema::hasTable('wakeup_calls')) {
            return;
        }

        Schema::create('wakeup_calls', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('reservation_room_id')->constrained()->cascadeOnDelete();
            $t->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $t->dateTime('call_at');
            $t->boolean('daily')->default(false);                      // repeat every day until check-out
            $t->string('status', 10)->default('scheduled');            // scheduled | done | no_answer | cancelled
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->dateTime('last_attempt_at')->nullable();
            $t->dateTime('completed_at')->nullable();
            $t->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('note', 190)->nullable();                       // instructions ("with coffee", "call twice")
            $t->string('outcome_note', 190)->nullable();
            $t->string('source', 8)->default('desk');                  // desk | phone | portal
            $t->foreignId('guest_request_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('parent_id')->nullable()->constrained('wakeup_calls')->nullOnDelete();
            $t->dateTime('escalated_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['hotel_id', 'status', 'call_at']);
        });

                if (Schema::hasTable('wakeup_attempts')) {
            return;
        }

        Schema::create('wakeup_attempts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('wakeup_call_id')->constrained()->cascadeOnDelete();
            $t->string('outcome', 10);                                 // answered | no_answer | busy
            $t->string('note', 190)->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->dateTime('attempted_at');
        });

                if (Schema::hasTable('traces')) {
            return;
        }

        Schema::create('traces', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->date('trace_date');
            $t->time('due_time')->nullable();
            $t->string('department', 20)->default('front_office');
            $t->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('reservation_room_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $t->string('text', 255);
            $t->string('status', 10)->default('open');                 // open | done | cancelled
            $t->date('carried_from')->nullable();                      // original date when rolled forward by the night audit
            $t->dateTime('done_at')->nullable();
            $t->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('done_note', 190)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['hotel_id', 'trace_date', 'status']);
        });

        Schema::table('lost_found_items', function (Blueprint $t) {
            $t->string('item_no', 20)->nullable()->after('hotel_id');
            $t->string('category', 20)->default('other')->after('item_name');   // electronics | jewellery | documents | cash | clothing | bags | keys | perishable | other
            $t->boolean('valuable')->default(false)->after('category');
            $t->decimal('est_value', 12, 2)->nullable()->after('valuable');
            $t->string('storage_location', 60)->nullable()->after('location');
            $t->foreignId('found_by_user_id')->nullable()->after('found_by')->constrained('users')->nullOnDelete();
            $t->foreignId('reservation_room_id')->nullable()->after('room_id')->constrained()->nullOnDelete(); // last stay in the room
            $t->foreignId('guest_id')->nullable()->after('reservation_room_id')->constrained()->nullOnDelete(); // probable owner
            $t->date('retain_until')->nullable()->after('found_date');
            // return
            $t->string('claimant_phone', 40)->nullable()->after('returned_to');
            $t->string('claimant_id_type', 20)->nullable()->after('claimant_phone');
            $t->string('claimant_id_no', 40)->nullable()->after('claimant_id_type');
            $t->string('return_method', 10)->nullable()->after('claimant_id_no');  // in_person | courier | police
            $t->string('courier', 60)->nullable()->after('return_method');
            $t->string('tracking_no', 60)->nullable()->after('courier');
            $t->decimal('courier_charge', 10, 2)->default(0)->after('tracking_no');
            $t->foreignId('released_by')->nullable()->after('returned_date')->constrained('users')->nullOnDelete();
            // disposal
            $t->string('disposal_method', 12)->nullable();                        // donated | destroyed | police | finder | staff_auction
            $t->date('disposed_date')->nullable();
            $t->foreignId('disposed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('disposal_witness_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('disposal_note', 190)->nullable();
            $t->foreignId('lost_report_id')->nullable();
            $t->index(['hotel_id', 'status']);
        });

                if (Schema::hasTable('lost_reports')) {
            return;
        }

        Schema::create('lost_reports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('report_no', 20);
            $t->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('reservation_room_id')->nullable()->constrained()->nullOnDelete();
            $t->string('name', 120);
            $t->string('phone', 40)->nullable();
            $t->string('email', 150)->nullable();
            $t->string('item', 150);
            $t->string('category', 20)->default('other');
            $t->text('description')->nullable();
            $t->date('lost_date')->nullable();
            $t->string('where_lost', 120)->nullable();
            $t->string('status', 10)->default('open');                 // open | matched | closed
            $t->foreignId('lost_found_item_id')->nullable()->constrained('lost_found_items')->nullOnDelete();
            $t->string('close_note', 190)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'report_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lost_reports');
        Schema::table('lost_found_items', function (Blueprint $t) {
            foreach (['found_by_user_id', 'reservation_room_id', 'guest_id', 'released_by', 'disposed_by', 'disposal_witness_id'] as $fk) {
                $t->dropForeign([$fk]);
            }
            $t->index('hotel_id', 'lost_found_items_hotel_id_foreign');   // the FK needs its own index back first
            $t->dropIndex(['hotel_id', 'status']);
            $t->dropColumn(['found_by_user_id', 'reservation_room_id', 'guest_id', 'released_by', 'disposed_by', 'disposal_witness_id','item_no', 'category', 'valuable', 'est_value', 'storage_location', 'retain_until', 'claimant_phone', 'claimant_id_type', 'claimant_id_no',
                'return_method', 'courier', 'tracking_no', 'courier_charge', 'disposal_method', 'disposed_date', 'disposal_note', 'lost_report_id']);
        });
        foreach (['traces', 'wakeup_attempts', 'wakeup_calls', 'guest_messages', 'log_entry_comments', 'log_entry_reads', 'log_entries'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};

