<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 28 (v3.7): hotel system interfaces.
 *  • PABX call accounting – extensions, carrier tariffs (longest-prefix rating), call detail records (SMDR) imported or
 *    pushed by the PBX, rated and posted to the guest folio, with a review queue for calls that could not be posted.
 *  • Door-lock key cards – guest cards encoded per stay (new key / duplicate), lost / returned / expired life-cycle,
 *    staff master keys with a sign-out register.
 *  • An interface command log (PMS → PBX / lock system) with a demo driver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_extensions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('extension', 10);
            $t->string('type', 10)->default('room');          // room | admin | public
            $t->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $t->string('department', 40)->nullable();        // admin extensions: whose cost centre
            $t->string('label', 60)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['hotel_id', 'extension']);
        });

        Schema::create('call_tariffs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('name', 60);
            $t->string('prefix', 15)->default('');           // longest matching prefix wins; '' = default (local)
            $t->string('call_class', 10)->default('local');  // local | std | mobile | isd | premium | free
            $t->unsignedSmallInteger('pulse_secs')->default(60);
            $t->decimal('cost_per_pulse', 10, 2)->default(0); // what the carrier charges the hotel
            $t->decimal('markup_pct', 6, 2)->default(0);     // guest charge = cost × (1 + markup %)
            $t->decimal('min_charge', 10, 2)->default(0);
            $t->unsignedSmallInteger('free_secs')->default(0); // calls up to this many seconds are not charged
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['hotel_id', 'prefix']);
        });

        Schema::create('call_records', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('extension', 10);
            $t->foreignId('phone_extension_id')->nullable()->constrained()->nullOnDelete();
            $t->string('dialled', 30);
            $t->dateTime('started_at');
            $t->unsignedInteger('duration_secs')->default(0);
            $t->string('trunk', 10)->nullable();
            $t->string('call_class', 10)->nullable();        // local | std | mobile | isd | premium | free | internal
            $t->foreignId('call_tariff_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedInteger('pulses')->default(0);
            $t->decimal('cost', 10, 2)->default(0);
            $t->decimal('charge', 10, 2)->default(0);
            $t->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('reservation_room_id')->nullable()->constrained()->nullOnDelete();
            $t->string('status', 10)->default('pending');    // posted | pending | free | admin | waived
            $t->string('note', 200)->nullable();             // why it is pending / waived
            $t->foreignId('folio_transaction_id')->nullable()->constrained()->nullOnDelete();
            $t->string('source', 10)->default('import');     // import | push | manual
            $t->string('batch', 20)->nullable();
            $t->char('raw_hash', 40);
            $t->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('handled_at')->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'raw_hash']);
            $t->index(['hotel_id', 'started_at']);
            $t->index(['hotel_id', 'status']);
        });

        Schema::create('key_cards', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('card_no', 20);
            $t->string('serial', 30);                         // card UID written by the encoder
            $t->string('type', 12)->default('guest');         // guest | duplicate | replacement
            $t->foreignId('reservation_room_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $t->string('holder', 80)->nullable();
            $t->dateTime('valid_from');
            $t->dateTime('valid_until');
            $t->json('areas')->nullable();                    // common areas the card opens (pool, gym …)
            $t->string('status', 10)->default('active');      // active | returned | lost | expired | cancelled
            $t->string('status_note', 150)->nullable();
            $t->foreignId('fee_transaction_id')->nullable()->constrained('folio_transactions')->nullOnDelete();
            $t->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'card_no']);
            $t->index(['hotel_id', 'status']);
            $t->index(['hotel_id', 'serial']);
        });

        Schema::create('master_keys', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('key_no', 20);
            $t->string('name', 80);
            $t->string('level', 12)->default('section');      // grand | floor | section | emergency
            $t->string('scope', 120)->nullable();             // which doors it opens
            $t->string('department', 40)->nullable();
            $t->string('status', 10)->default('available');   // available | out | lost | retired
            $t->timestamps();
            $t->unique(['hotel_id', 'key_no']);
        });

        Schema::create('key_loans', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('master_key_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // who took it
            $t->string('holder', 80);
            $t->string('purpose', 150)->nullable();
            $t->dateTime('out_at');
            $t->dateTime('due_at');
            $t->dateTime('in_at')->nullable();
            $t->string('status', 10)->default('out');         // out | returned | lost
            $t->string('note', 150)->nullable();
            $t->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['hotel_id', 'status']);
        });

        Schema::create('interface_commands', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('system', 6);                           // pbx | lock
            $t->string('command', 20);                         // pbx: checkin, checkout, move, cos, name – lock: issue, duplicate, cancel, extend, read
            $t->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('reservation_room_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('key_card_id')->nullable()->constrained()->nullOnDelete();
            $t->json('payload')->nullable();
            $t->string('status', 8)->default('queued');        // queued | done | failed
            $t->string('response', 200)->nullable();
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->index(['hotel_id', 'system', 'created_at']);
        });

        Schema::table('reservation_rooms', function (Blueprint $t) {
            $t->string('phone_cos', 10)->default('full');      // v3.7 class of service: full | national | local | barred
        });
    }

    public function down(): void
    {
        Schema::table('reservation_rooms', fn (Blueprint $t) => $t->dropColumn('phone_cos'));
        foreach (['interface_commands', 'key_loans', 'master_keys', 'key_cards', 'call_records', 'call_tariffs', 'phone_extensions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

