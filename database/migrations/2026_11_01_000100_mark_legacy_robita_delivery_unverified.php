<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Old `ok` meant a response from an undocumented endpoint, not hardware acceptance. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('robita_credentials') && Schema::hasColumn('robita_credentials', 'sync_state')) {
            DB::table('robita_credentials')->where('sync_state', 'ok')->update(['sync_state' => 'unverified']);
        }
    }

    public function down(): void
    {
        // Deliberately do not turn unverified legacy records back into a claim of delivery.
    }
};
