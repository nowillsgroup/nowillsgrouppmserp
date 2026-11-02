<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('guests') || ! Schema::hasColumn('guests', 'title')) {
            return;
        }

        Schema::table('guests', fn (Blueprint $table) => $table->string('title', 30)->nullable()->change());
    }

    public function down(): void
    {
        if (! Schema::hasTable('guests') || ! Schema::hasColumn('guests', 'title')) {
            return;
        }

        foreach (DB::table('guests')->whereNotNull('title')->cursor() as $guest) {
            if (mb_strlen((string) $guest->title) > 10) {
                throw new RuntimeException('Cannot shrink guest titles to 10 characters while longer titles are stored.');
            }
        }

        Schema::table('guests', fn (Blueprint $table) => $table->string('title', 10)->nullable()->change());
    }
};
