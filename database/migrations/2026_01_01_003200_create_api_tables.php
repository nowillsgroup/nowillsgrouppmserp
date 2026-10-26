<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 31 (v4.0): Open REST API & webhooks – the integration platform.
 *  • API clients: per-hotel bearer tokens (nwp_<prefix>_<secret>; only the SHA-256 is stored) with scopes, an IP / CIDR
 *    allow-list, a per-client rate limit and an optional expiry. Every call is written to the request log.
 *  • Idempotency keys make POST /reservations safe to retry (same key + same body → the stored response is replayed).
 *  • Webhook endpoints subscribe to business events; deliveries are written to an outbox at the moment the event happens
 *    (after the DB commit), signed with HMAC-SHA256 and retried with exponential back-off by `pms:webhooks`.
 */
return new class extends Migration
{
    public function up(): void
    {
                if (Schema::hasTable('api_clients')) {
            return;
        }

        Schema::create('api_clients', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('name', 80);
            $t->string('description', 255)->nullable();
            $t->string('token_prefix', 8)->unique();            // shown in lists: nwp_<prefix>_…
            $t->char('token_hash', 64);                         // sha256 of the full token
            $t->json('scopes');
            $t->text('ip_allowlist')->nullable();               // IPs / CIDRs, one per line (empty = any)
            $t->unsignedSmallInteger('rate_limit')->default(120); // requests per minute
            $t->date('expires_on')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamp('revoked_at')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->string('last_ip', 45)->nullable();
            $t->timestamp('rotated_at')->nullable();
            $t->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();  // bookings are made in this user's name
            $t->foreignId('business_source_id')->nullable()->constrained()->nullOnDelete();   // reservations are credited to this source
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

                if (Schema::hasTable('api_request_logs')) {
            return;
        }

        Schema::create('api_request_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->nullable()->constrained()->cascadeOnDelete();   // null = the token could not be resolved
            $t->foreignId('api_client_id')->nullable()->constrained()->nullOnDelete();
            $t->string('request_id', 36);
            $t->string('method', 7);
            $t->string('path', 190);
            $t->string('route', 60)->nullable();                // e.g. reservations.store
            $t->unsignedSmallInteger('status');
            $t->string('error_code', 40)->nullable();
            $t->unsignedInteger('duration_ms')->default(0);
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 190)->nullable();
            $t->timestamp('created_at')->nullable();
            $t->index(['hotel_id', 'created_at']);
            $t->index(['api_client_id', 'created_at']);
        });

                if (Schema::hasTable('api_idempotency_keys')) {
            return;
        }

        Schema::create('api_idempotency_keys', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('api_client_id')->constrained()->cascadeOnDelete();
            $t->string('idem_key', 80);
            $t->char('request_hash', 64);
            $t->unsignedSmallInteger('status_code')->nullable();   // null while the first request is still running
            $t->mediumText('response')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->unique(['api_client_id', 'idem_key']);
        });

                if (Schema::hasTable('webhook_endpoints')) {
            return;
        }

        Schema::create('webhook_endpoints', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->string('name', 80);
            $t->string('url', 500);
            $t->json('events');                                 // event types, or ["*"]
            $t->text('secret');                                 // encrypted (whsec_…) – needed in clear to sign
            $t->text('previous_secret')->nullable();            // still signed with for 24 h after a rotation
            $t->timestamp('previous_expires_at')->nullable();
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('consecutive_failures')->default(0);
            $t->timestamp('disabled_at')->nullable();
            $t->string('disabled_reason', 255)->nullable();
            $t->timestamp('last_success_at')->nullable();
            $t->timestamp('last_failure_at')->nullable();
            $t->string('description', 255)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

                if (Schema::hasTable('webhook_deliveries')) {
            return;
        }

        Schema::create('webhook_deliveries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('webhook_endpoint_id')->constrained()->cascadeOnDelete();
            $t->string('event', 40);
            $t->string('event_id', 40);                         // evt_<uuid> – the same for every endpoint receiving the event
            $t->json('payload');
            $t->string('status', 10)->default('pending');       // pending | success | failed
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->timestamp('next_attempt_at')->nullable();
            $t->unsignedSmallInteger('response_code')->nullable();
            $t->text('response_body')->nullable();
            $t->string('error', 255)->nullable();
            $t->unsignedInteger('duration_ms')->nullable();
            $t->json('attempt_log')->nullable();                // [{at, code, ms, error}] – last attempts
            $t->timestamp('delivered_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'next_attempt_at']);
            $t->index(['hotel_id', 'created_at']);
        });

        Schema::table('reservations', function (Blueprint $t) {
            $t->foreignId('api_client_id')->nullable()->after('created_by')->constrained()->nullOnDelete();
            $t->string('external_ref', 60)->nullable()->after('api_client_id');   // the partner's own booking reference
            $t->unique(['api_client_id', 'external_ref']);
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $t) {
            $t->dropForeign(['api_client_id']);                 // the FK uses the unique index – drop it first
            $t->dropUnique(['api_client_id', 'external_ref']);
            $t->dropColumn(['api_client_id', 'external_ref']);
        });
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
        Schema::dropIfExists('api_idempotency_keys');
        Schema::dropIfExists('api_request_logs');
        Schema::dropIfExists('api_clients');
    }
};

