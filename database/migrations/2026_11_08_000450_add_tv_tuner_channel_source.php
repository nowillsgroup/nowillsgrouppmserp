<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tv_channels')) {
            return;
        }
        if (! Schema::hasColumn('tv_channels', 'source_type')) {
            Schema::table('tv_channels', fn (Blueprint $table) => $table->string('source_type', 16)->default('stream'));
        }
        if (! Schema::hasColumn('tv_channels', 'tuner_number')) {
            Schema::table('tv_channels', fn (Blueprint $table) => $table->unsignedSmallInteger('tuner_number')->nullable());
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('tv_channels')) {
            return;
        }
        // Tuner mappings would be lost on rollback; convert or export them first.
        if (Schema::hasColumn('tv_channels', 'tuner_number') && \Illuminate\Support\Facades\DB::table('tv_channels')->whereNotNull('tuner_number')->exists()) {
            throw new \RuntimeException('Cannot roll back tuner mappings while tuner channels exist.');
        }
        foreach (['tuner_number', 'source_type'] as $column) {
            if (Schema::hasColumn('tv_channels', $column)) {
                Schema::table('tv_channels', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
