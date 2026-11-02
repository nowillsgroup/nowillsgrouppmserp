<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('front_desk_shift_sessions') && ! Schema::hasColumn('front_desk_shift_sessions', 'handover_snapshot')) {
            Schema::table('front_desk_shift_sessions', fn (Blueprint $table) => $table->json('handover_snapshot')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('front_desk_shift_sessions') && Schema::hasColumn('front_desk_shift_sessions', 'handover_snapshot')) {
            Schema::table('front_desk_shift_sessions', fn (Blueprint $table) => $table->dropColumn('handover_snapshot'));
        }
    }
};
