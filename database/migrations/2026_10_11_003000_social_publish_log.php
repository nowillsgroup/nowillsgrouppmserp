<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** v7.39 Social publishing — per-channel results of the live Facebook / WhatsApp post. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dm_posts', function (Blueprint $t) {
            $t->json('publish_log')->nullable()->after('post_url');
        });
    }

    public function down(): void
    {
        Schema::table('dm_posts', function (Blueprint $t) {
            $t->dropColumn('publish_log');
        });
    }
};
