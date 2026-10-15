<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v7.42 OTA adapters — vendor credentials get their own home.
 *
 * `channel_connections.secret` is the PMS-generated HMAC secret the channel uses
 * to sign INBOUND webhook pushes (ChannelService::verify). The Expedia Rapid
 * adapter, however, needed a vendor api_secret to sign OUTBOUND requests with
 * HMAC-SHA512, and the only place to put it was that same `secret` column — which
 * silently broke inbound signature verification the moment an operator set it.
 * `api_secret` is the outbound vendor credential: encrypted at rest, never
 * serialised, blank-on-edit means "keep what is stored", and it is optional for
 * the adapters that authenticate with a bearer token only (EPS, SiteMinder, hub).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_connections', function (Blueprint $table) {
            $table->text('api_secret')->nullable()->after('api_key');
        });
    }

    public function down(): void
    {
        Schema::table('channel_connections', function (Blueprint $table) {
            $table->dropColumn('api_secret');
        });
    }
};
