<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v5.0→5.1 Health Club & Memberships: membership plans (term, joining fee, facilities, off-peak hours, guest passes,
 * member discounts), memberships with one or more card holders, a members' account (subscription invoices deferred
 * and recognised over the term, joining fees, guest passes, credit notes, receipts), the check-in desk and access log
 * (members, in-house residents, day passes, members' guests) with capacity per facility, and the class timetable
 * with bookings, waitlist and attendance. POS bills can carry a member for the F&B discount.
 */
return new class extends Migration
{
    public function up(): void
    {
                if (Schema::hasTable('club_plans')) {
            return;
        }

        Schema::create('club_plans', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('code', 12);
            $t->string('name', 80);
            $t->string('category', 12)->default('individual');          // individual | couple | family | corporate | student | senior
            $t->unsignedTinyInteger('term_months')->default(1);          // 1 | 3 | 6 | 12
            $t->decimal('price', 12, 2);                                  // per term, tax-exclusive
            $t->decimal('joining_fee', 10, 2)->default(0);
            $t->unsignedTinyInteger('max_persons')->default(1);
            $t->json('facilities');
            $t->time('access_from')->nullable();                          // off-peak plans
            $t->time('access_to')->nullable();
            $t->unsignedTinyInteger('guest_passes')->default(0);         // free guest visits per calendar month
            $t->decimal('fnb_discount_pct', 5, 2)->default(0);
            $t->decimal('spa_discount_pct', 5, 2)->default(0);
            $t->unsignedSmallInteger('freeze_days_max')->default(30);     // per term
            $t->string('description', 255)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['hotel_id', 'code']);
        });

                if (Schema::hasTable('club_memberships')) {
            return;
        }

        Schema::create('club_memberships', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('membership_no', 20);
            $t->foreignId('club_plan_id')->constrained()->restrictOnDelete();
            $t->foreignId('company_id')->nullable()->constrained()->nullOnDelete();   // corporate payer
            $t->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();     // linked guest profile
            $t->string('holder_name', 120);
            $t->string('phone', 30)->nullable();
            $t->string('email', 120)->nullable();
            $t->string('status', 10)->default('active');                 // active | frozen | expired | cancelled
            $t->date('start_date');
            $t->date('end_date');                                         // current term end (paid-through when settled)
            $t->boolean('auto_renew')->default(true);
            $t->date('frozen_from')->nullable();
            $t->date('frozen_to')->nullable();
            $t->unsignedSmallInteger('frozen_days')->default(0);          // used in the current term
            $t->string('freeze_reason', 160)->nullable();
            $t->string('cancel_mode', 10)->nullable();                    // term_end | immediate
            $t->date('cancelled_on')->nullable();
            $t->string('cancel_reason', 255)->nullable();
            $t->string('source', 12)->default('walk_in');                 // walk_in | corporate | guest | referral
            $t->string('notes', 500)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'membership_no']);
            $t->index(['hotel_id', 'status', 'end_date']);
        });

                if (Schema::hasTable('club_members')) {
            return;
        }

        Schema::create('club_members', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('club_membership_id')->constrained()->cascadeOnDelete();
            $t->string('name', 120);
            $t->string('relation', 10)->default('primary');              // primary | spouse | child | dependant | employee
            $t->char('gender', 1)->nullable();
            $t->date('dob')->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('card_no', 20);                                    // printed + QR on the member card
            $t->string('photo_path', 160)->nullable();
            $t->timestamp('health_declared_at')->nullable();              // PAR-Q / waiver signed
            $t->string('emergency_contact', 120)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['hotel_id', 'card_no']);
        });

                if (Schema::hasTable('club_charges')) {
            return;
        }

        Schema::create('club_charges', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('charge_no', 20);
            $t->foreignId('club_membership_id')->constrained()->cascadeOnDelete();
            $t->date('date');
            $t->string('type', 12);                                       // subscription | joining | guest_pass | credit | other
            $t->string('description', 160);
            $t->date('period_start')->nullable();                         // subscription service period
            $t->date('period_end')->nullable();
            $t->decimal('amount', 12, 2);                                  // net (negative on a credit note)
            $t->decimal('tax_rate', 7, 4)->default(0);
            $t->decimal('tax', 12, 2)->default(0);
            $t->decimal('total', 12, 2);
            $t->decimal('paid', 12, 2)->default(0);
            $t->date('due_date');
            $t->foreignId('credit_of_id')->nullable()->constrained('club_charges')->nullOnDelete();
            $t->timestamp('cancelled_at')->nullable();
            $t->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('cancel_reason', 255)->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'charge_no']);
            $t->index(['hotel_id', 'date']);
        });

                if (Schema::hasTable('club_payments')) {
            return;
        }

        Schema::create('club_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('receipt_no', 20);
            $t->foreignId('club_membership_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 8)->default('payment');                    // payment | refund
            $t->date('date');
            $t->foreignId('payment_mode_id')->constrained()->restrictOnDelete();
            $t->decimal('amount', 12, 2);
            $t->string('reference', 80)->nullable();
            $t->json('allocations')->nullable();                          // [{charge_id, amount}]
            $t->timestamp('voided_at')->nullable();
            $t->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('void_reason', 255)->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'receipt_no']);
        });

                if (Schema::hasTable('club_day_passes')) {
            return;
        }

        Schema::create('club_day_passes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('pass_no', 20);
            $t->date('date');
            $t->string('kind', 10)->default('day');                        // day | guest (a member's guest)
            $t->string('name', 120);
            $t->string('phone', 30)->nullable();
            $t->unsignedTinyInteger('adults')->default(1);
            $t->unsignedTinyInteger('children')->default(0);
            $t->foreignId('host_member_id')->nullable()->constrained('club_members')->nullOnDelete();
            $t->decimal('amount', 10, 2);                                  // net
            $t->decimal('tax_rate', 7, 4)->default(0);
            $t->decimal('tax', 10, 2)->default(0);
            $t->decimal('total', 10, 2);
            $t->foreignId('payment_mode_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('reservation_room_id')->nullable()->constrained()->nullOnDelete();   // charged to a room
            $t->foreignId('folio_transaction_id')->nullable()->constrained()->nullOnDelete();
            $t->string('reference', 80)->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('cancel_reason', 255)->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'pass_no']);
            $t->index(['hotel_id', 'date']);
        });

                if (Schema::hasTable('club_visits')) {
            return;
        }

        Schema::create('club_visits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('visitor_type', 10);                                // member | resident | day_pass | guest
            $t->foreignId('club_member_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('club_day_pass_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('reservation_room_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('host_member_id')->nullable()->constrained('club_members')->nullOnDelete();
            $t->foreignId('club_charge_id')->nullable()->constrained()->nullOnDelete();   // guest pass billed to the member
            $t->string('name', 120);
            $t->string('facility', 12);
            $t->unsignedTinyInteger('pax')->default(1);
            $t->dateTime('checked_in_at');
            $t->timestamp('checked_out_at')->nullable();
            $t->boolean('denied')->default(false);
            $t->string('deny_reason', 200)->nullable();
            $t->string('override_reason', 200)->nullable();
            $t->foreignId('override_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->index(['hotel_id', 'checked_in_at']);
        });

                if (Schema::hasTable('club_classes')) {
            return;
        }

        Schema::create('club_classes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('name', 80);
            $t->string('facility', 12);
            $t->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();   // in-house instructor
            $t->string('instructor', 80)->nullable();                     // or an external one
            $t->unsignedTinyInteger('weekday');                            // 1 = Monday … 7 = Sunday
            $t->time('start_time');
            $t->unsignedSmallInteger('duration_min')->default(60);
            $t->unsignedSmallInteger('capacity')->default(12);
            $t->boolean('residents')->default(true);                       // hotel guests may book
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

                if (Schema::hasTable('club_bookings')) {
            return;
        }

        Schema::create('club_bookings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('club_class_id')->constrained()->cascadeOnDelete();
            $t->date('date');
            $t->foreignId('club_member_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('reservation_room_id')->nullable()->constrained()->nullOnDelete();
            $t->string('name', 120);
            $t->string('status', 10)->default('booked');                   // booked | waitlist | attended | no_show | cancelled
            $t->unsignedSmallInteger('position')->nullable();               // waitlist order
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->index(['hotel_id', 'club_class_id', 'date']);
        });

        Schema::table('pos_orders', function (Blueprint $t) {
            $t->foreignId('club_member_id')->nullable()->constrained()->nullOnDelete(); // member discount on the bill
        });
    }

    public function down(): void
    {
        Schema::table('pos_orders', fn (Blueprint $t) => $t->dropConstrainedForeignId('club_member_id'));
        foreach (['club_bookings', 'club_classes', 'club_visits', 'club_day_passes', 'club_payments', 'club_charges', 'club_members', 'club_memberships', 'club_plans'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};

