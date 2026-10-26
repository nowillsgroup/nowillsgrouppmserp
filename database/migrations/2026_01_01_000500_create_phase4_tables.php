<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 (v1.3): fixed charges, group room blocks, e-registration card & ID documents, two-factor auth.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Recurring / one-off charges attached to a stay, posted by the night audit
                if (Schema::hasTable('stay_fixed_charges')) {
            return;
        }

        Schema::create('stay_fixed_charges', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('reservation_room_id')->constrained()->cascadeOnDelete();
            $t->string('description', 120);
            $t->string('category', 30);
            $t->decimal('amount', 12, 2);
            $t->boolean('apply_tax')->default(true);
            $t->string('frequency', 10)->default('daily'); // daily | once
            $t->date('start_date');
            $t->date('end_date')->nullable();              // null = until departure
            $t->date('last_posted_date')->nullable();
            $t->unsignedInteger('times_posted')->default(0);
            $t->boolean('is_active')->default(true);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['hotel_id', 'is_active']);
        });

        // Group allotments: rooms held per type for a date range until a cut-off date
                if (Schema::hasTable('room_blocks')) {
            return;
        }

        Schema::create('room_blocks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('block_no', 30);
            $t->string('name', 150);
            $t->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('business_source_id')->nullable()->constrained()->nullOnDelete();
            $t->string('contact_name', 120)->nullable();
            $t->string('contact_phone', 30)->nullable();
            $t->string('contact_email', 150)->nullable();
            $t->date('arrival_date');
            $t->date('departure_date');
            $t->date('cutoff_date');
            $t->decimal('rate', 12, 2)->nullable();      // group rate; null = rate calendar
            $t->string('billing_instruction', 20)->default('guest');
            $t->string('status', 20)->default('tentative'); // tentative, definite, released, cancelled
            $t->text('notes')->nullable();
            $t->timestamp('released_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'block_no']);
            $t->index(['hotel_id', 'status', 'arrival_date']);
        });
                if (Schema::hasTable('room_block_lines')) {
            return;
        }

        Schema::create('room_block_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('room_block_id')->constrained()->cascadeOnDelete();
            $t->foreignId('room_type_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('rooms');
            $t->unique(['room_block_id', 'room_type_id']);
        });
        Schema::table('reservations', function (Blueprint $t) {
            $t->foreignId('room_block_id')->nullable()->after('ota_uid')->constrained()->nullOnDelete();
        });

        // Electronic registration card + scanned ID documents
        Schema::table('reservation_rooms', function (Blueprint $t) {
            $t->string('grc_signature_path')->nullable();
            $t->timestamp('grc_signed_at')->nullable();
        });
                if (Schema::hasTable('guest_documents')) {
            return;
        }

        Schema::create('guest_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('guest_id')->constrained()->cascadeOnDelete();
            $t->foreignId('reservation_room_id')->nullable()->constrained()->nullOnDelete();
            $t->string('type', 20); // id_front, id_back, visa, photo, other
            $t->string('path');
            $t->string('original_name', 190)->nullable();
            $t->string('mime', 60);
            $t->unsignedInteger('size');
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        // TOTP two-factor authentication
        Schema::table('users', function (Blueprint $t) {
            $t->text('two_factor_secret')->nullable();
            $t->text('two_factor_recovery_codes')->nullable();
            $t->timestamp('two_factor_confirmed_at')->nullable();
            $t->unsignedBigInteger('two_factor_last_step')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'two_factor_last_step']));
        Schema::dropIfExists('guest_documents');
        Schema::table('reservation_rooms', fn (Blueprint $t) => $t->dropColumn(['grc_signature_path', 'grc_signed_at']));
        Schema::table('reservations', fn (Blueprint $t) => $t->dropConstrainedForeignId('room_block_id'));
        Schema::dropIfExists('room_block_lines');
        Schema::dropIfExists('room_blocks');
        Schema::dropIfExists('stay_fixed_charges');
    }
};

