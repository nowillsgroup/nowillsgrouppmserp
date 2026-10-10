<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable reservation version history. Additive only: existing reservation
 * and activity-log data is left untouched and every new row is tenant-scoped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_amendments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('version');
            $t->string('event', 40); // created, amended, confirmed, cancelled, no_show, assigned
            $t->string('source', 30)->default('front_desk'); // front_desk, booking_engine, channel, api, system
            $t->string('reason', 255)->nullable();
            $t->json('before_state')->nullable();
            $t->json('after_state')->nullable();
            $t->json('changes')->nullable();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();

            $t->unique(['hotel_id', 'reservation_id', 'version']);
            $t->index(['hotel_id', 'reservation_id', 'created_at']);
            $t->index(['hotel_id', 'event', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_amendments');
    }
};
