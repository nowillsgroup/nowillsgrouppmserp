<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configuration — what a setting was before.
 *
 * The activity log records *that* a settings screen was saved and which settings it touched,
 * but not the values: "Updated hotel settings: Cancellation policy, Spa slot" cannot tell
 * anybody whether the free-cancellation window went from 48 hours to 24 or from 48 to 72.
 * A configuration audit, a dispute or a handover all need the value itself.
 *
 * This table holds one row per write of one setting, written by the Setting model's observer
 * (App\Observers\SettingValueObserver), so every module is covered by the same code path
 * rather than by 69 screens remembering to log. A credential is stored as "Set — hidden"
 * and never as its value.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('setting_changes')) {
            return;
        }

        Schema::create('setting_changes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('key', 60);
            // a value can be a policy paragraph; 2000 characters keeps the trail readable
            // without silently dropping the tail of the ones that matter
            $t->text('old_value')->nullable();
            $t->text('new_value')->nullable();
            $t->boolean('was_secret')->default(false);
            $t->boolean('created')->default(false);   // the setting had no row before this write
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at')->nullable();
            $t->index(['hotel_id', 'key', 'created_at'], 'setting_change_key_idx');
            $t->index(['hotel_id', 'created_at'], 'setting_change_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('setting_changes');
    }
};
