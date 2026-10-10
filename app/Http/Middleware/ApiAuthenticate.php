<?php

namespace App\Http\Middleware;

use App\Models\Hotel;
use App\Models\User;
use App\Services\ApiService;
use App\Support\Tenant;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * v4.0 – REST API gate (stateless: no session, cookies or CSRF on /api/v1).
 * Bearer token → client → hotel (tenant) → state (revoked / expired / paused) → IP allow-list → acting user → per-client
 * rate limit. Adds X-Request-Id and X-RateLimit-* headers and writes every call (also refused ones) to the request log.
 */
class ApiAuthenticate
{
    public function handle(Request $request, Closure $next): Response
    {
        $rid = (string) Str::uuid();
        $request->attributes->set('api_request_id', $rid);
        $start = microtime(true);
        $client = ApiService::authenticate($request->bearerToken());
        $limit = null;
        $response = $this->gate($request, $client, $limit) ?? $next($request);

        $response->headers->set('X-Request-Id', $rid);
        $response->headers->set('Cache-Control', 'no-store');
        if ($limit) {
            $response->headers->set('X-RateLimit-Limit', (string) $limit[0]);
            $response->headers->set('X-RateLimit-Remaining', (string) max(0, $limit[1]));
        }
        $data = $response instanceof JsonResponse ? $response->getData(true) : null;
        ApiService::log($client && Tenant::id() === $client->hotel_id ? $client : null, [
            'request_id' => $rid, 'method' => $request->method(), 'path' => mb_substr('/'.$request->path(), 0, 190),
            'route' => ($n = $request->route()?->getName()) ? mb_substr(Str::after($n, 'apiv1.'), 0, 60) : null,
            'status' => $response->getStatusCode(), 'error_code' => is_array($data) ? mb_substr((string) ($data['error']['code'] ?? ''), 0, 40) ?: null : null,
            'duration_ms' => (int) round((microtime(true) - $start) * 1000), 'ip' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 190),
        ]);

        return $response;
    }

    /** null = let the request through */
    protected function gate(Request $request, $client, ?array &$limit): ?JsonResponse
    {
        $bad = 'api-bad-token:'.$request->ip();       // brute-force brake: 30 bad / missing keys per minute per IP
        if (RateLimiter::tooManyAttempts($bad, 30)) {
            return self::error('rate_limited', 'Too many failed authentication attempts – wait a minute.', 429, ['Retry-After' => (string) RateLimiter::availableIn($bad)]);
        }
        if (! $client) {
            RateLimiter::hit($bad, 60);
        }
        if (! $request->bearerToken()) {
            return self::error('unauthenticated', 'Send your API key as "Authorization: Bearer nwp_…".', 401, ['WWW-Authenticate' => 'Bearer']);
        }
        if (! $client) {
            return self::error('invalid_token', 'The API key is not valid.', 401, ['WWW-Authenticate' => 'Bearer error="invalid_token"']);
        }
        $hotel = Hotel::where('is_active', true)->find($client->hotel_id);
        if (! $hotel) {
            return self::error('invalid_token', 'The API key is not valid.', 401);
        }
        Tenant::set($hotel);
        if (! ApiService::cfg()['enabled']) {
            return self::error('api_disabled', 'The hotel has switched the API off.', 503);
        }
        $state = $client->state();
        if ($state !== 'active') {
            return match ($state) {
                'revoked' => self::error('token_revoked', 'This API key was revoked.', 401),
                'expired' => self::error('token_expired', 'This API key expired on '.$client->expires_on->toDateString().'.', 401),
                default => self::error('client_paused', 'This API client is paused by the hotel.', 403),
            };
        }
        if (! ApiService::ipAllowed($client, $request->ip())) {
            return self::error('ip_not_allowed', 'Calls from '.$request->ip().' are not allowed for this key.', 403);
        }
        $owner = $client->owner_id ? User::where('hotel_id', $hotel->id)->where('is_active', true)->find($client->owner_id) : null;
        if (! $owner) {
            return self::error('owner_inactive', 'The hotel user this key acts for is inactive – ask the hotel to update the key.', 403);
        }
        auth()->setUser($owner);    // bookings, logs and folio entries are attributed to the acting user
        $key = 'api-client:'.$client->id;
        if (RateLimiter::tooManyAttempts($key, $client->rate_limit)) {
            $limit = [$client->rate_limit, 0];

            return self::error('rate_limited', 'Too many requests – at most '.$client->rate_limit.' per minute.', 429, ['Retry-After' => (string) RateLimiter::availableIn($key)]);
        }
        RateLimiter::hit($key, 60);
        $limit = [$client->rate_limit, $client->rate_limit - RateLimiter::attempts($key)];
        $request->attributes->set('api_client', $client);

        return null;
    }

    public static function error(string $code, string $message, int $status, array $headers = [], array $extra = []): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message] + $extra, 'request_id' => request()->attributes->get('api_request_id') ?? (string) \Illuminate\Support\Str::uuid()], $status, $headers);
    }
}
