<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 (v1.7): guest mobile portal – tokenised stay link, service requests, portal feedback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservation_rooms', function (Blueprint $t) {
            $t->char('portal_token', 40)->nullable()->unique()->after('grc_signed_at');
            $t->timestamp('portal_seen_at')->nullable()->after('portal_token');
        });

                if (Schema::hasTable('guest_requests')) {
            return;
        }

        Schema::create('guest_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('request_no', 20);
            $t->foreignId('reservation_room_id')->constrained('reservation_rooms')->cascadeOnDelete();
            $t->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $t->string('category', 20);           // housekeeping, amenity, maintenance, wakeup, transport, late_checkout, checkout, front_desk
            $t->string('item', 120);
            $t->unsignedSmallInteger('quantity')->default(1);
            $t->string('notes', 500)->nullable();
            $t->dateTime('preferred_at')->nullable();
            $t->string('status', 20)->default('new'); // new, acknowledged, in_progress, done, declined, cancelled
            $t->string('priority', 10)->default('normal');
            $t->string('source', 10)->default('portal'); // portal, staff
            $t->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->string('linked_type', 30)->nullable(); // MaintenanceRequest, HousekeepingTask
            $t->unsignedBigInteger('linked_id')->nullable();
            $t->string('staff_note', 255)->nullable();
            $t->unsignedTinyInteger('rating')->nullable();
            $t->timestamp('acknowledged_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['hotel_id', 'request_no']);
            $t->index(['hotel_id', 'status', 'created_at']);
            $t->index(['linked_type', 'linked_id']);
        });

        Schema::table('guest_feedback', function (Blueprint $t) {
            $t->foreignId('reservation_room_id')->nullable()->after('reservation_id')->constrained('reservation_rooms')->nullOnDelete();
            $t->string('source', 12)->default('web')->after('comments');
        });
    }

    public function down(): void
    {
        Schema::table('guest_feedback', function (Blueprint $t) {
            $t->dropConstrainedForeignId('reservation_room_id');
            $t->dropColumn('source');
        });
        Schema::dropIfExists('guest_requests');
        Schema::table('reservation_rooms', function (Blueprint $t) {
            $t->dropUnique(['portal_token']);
            $t->dropColumn(['portal_token', 'portal_seen_at']);
        });
    }
};

