<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v7.57 – Robita credentials record whether the cloud actually accepted the
 * command. Before this, a timeout was swallowed and the row still said
 * "active", so the PMS could show a working credential that the door never
 * received. `sync_state` makes that visible and retryable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('robita_credentials') || Schema::hasColumn('robita_credentials', 'sync_state')) {
            return;
        }

        Schema::table('robita_credentials', function (Blueprint $t) {
            $t->string('sync_state', 12)->default('ok');            // ok|pending|failed
            $t->unsignedTinyInteger('sync_attempts')->default(0);
            $t->string('last_error', 200)->nullable();
            $t->timestamp('synced_at')->nullable();
            $t->index(['sync_state', 'sync_attempts'], 'robita_cred_sync');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('robita_credentials') || ! Schema::hasColumn('robita_credentials', 'sync_state')) {
            return;
        }

        Schema::table('robita_credentials', function (Blueprint $t) {
            $t->dropIndex('robita_cred_sync');
            $t->dropColumn(['sync_state', 'sync_attempts', 'last_error', 'synced_at']);
        });
    }
};
