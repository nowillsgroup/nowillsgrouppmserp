<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v7.61 – two indexes the ingest path actually needs.
 *
 * 1. `vehicle_trackers.token_hash` is looked up on **every** position push, without the hotel scope
 *    (the token is what identifies the property). Without an index that is a growing table scan on
 *    the hottest endpoint in the module.
 * 2. `vehicle_positions (hotel_id, recorded_at)` covers the fleet-wide window scans — speeding,
 *    the nightly prune and the retention page — which the existing per-vehicle index cannot serve.
 *
 * An existing installation may already be slow before this runs; nothing here rewrites data.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vehicle_trackers') && ! $this->hasIndex('vehicle_trackers', 'vehicle_trackers_token_hash_unique')) {
            Schema::table('vehicle_trackers', function (Blueprint $table) {
                // Unique, not merely indexed: two devices sharing a hash would mean a push could be
                // attributed to the wrong vehicle. Fails loudly rather than routing positions wrongly.
                $table->unique('token_hash', 'vehicle_trackers_token_hash_unique');
            });
        }

        if (Schema::hasTable('vehicle_positions') && ! $this->hasIndex('vehicle_positions', 'vehicle_positions_hotel_id_recorded_at_index')) {
            Schema::table('vehicle_positions', function (Blueprint $table) {
                $table->index(['hotel_id', 'recorded_at'], 'vehicle_positions_hotel_id_recorded_at_index');
            });
        }
    }

    public function down(): void
    {
        foreach ([
            ['vehicle_trackers', 'vehicle_trackers_token_hash_unique'],
            ['vehicle_positions', 'vehicle_positions_hotel_id_recorded_at_index'],
        ] as [$table, $index]) {
            if (Schema::hasTable($table) && $this->hasIndex($table, $index)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropIndex($index));
            }
        }
    }

    protected function hasIndex(string $table, string $index): bool
    {
        try {
            return collect(Schema::getIndexes($table))->contains(fn ($i) => ($i['name'] ?? null) === $index);
        } catch (\Throwable) {
            return false;   // an engine that cannot list indexes: let the create attempt speak for itself
        }
    }
};
