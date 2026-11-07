<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily briefings — the sign-off.
 *
 * Each head of department reads the pack built from their own areas and signs it, so the
 * General Manager can see at a glance who has looked at their numbers today and who has
 * not. The headline figures are stored with the signature: if a figure is later corrected,
 * the pack the head actually signed stays on record.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('briefing_signoffs')) {
            return;
        }

        Schema::create('briefing_signoffs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->date('business_date');
            $t->string('department_key', 40);
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('note', 500)->nullable();
            $t->json('headline')->nullable();
            $t->timestamp('signed_at')->nullable();
            $t->timestamps();
            $t->unique(['hotel_id', 'business_date', 'department_key'], 'briefing_signoff_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('briefing_signoffs');
    }
};
