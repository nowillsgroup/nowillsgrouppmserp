<?php

namespace App\Support;

use App\Models\ApiClient;
use App\Services\WebhookService;

/**
 * v4.0 – the single description of REST API v1: drives the in-app reference (Administration › API & Webhooks › Docs),
 * the "Try it" console and the public OpenAPI 3.0 document at /api/v1/openapi.json.
 */
class ApiSpec
{
    /** [method, path, scope|null, summary, description, params[[name, in, type, required, description]], example body|null, tag] */
    public static function endpoints(): array
    {
        $range = [['from', 'query', 'date', false, 'First night (YYYY-MM-DD, default: business date)'], ['to', 'query', 'date', false, 'Departure date – exclusive (default: from + 1)'],
            ['room_type_id', 'query', 'integer', false, 'Only this room type']];

        return [
            ['GET', '/', null, 'Who am I', 'The hotel, business date and the calling key\'s scopes and rate limit. Use it to test a key.', [], null, 'General'],
            ['GET', '/hotel', null, 'Property & room types', 'Hotel details, check-in / check-out times, currency and the active room types (ids and codes used by the other calls).', [], null, 'General'],
            ['GET', '/events', null, 'Webhook event types', 'The events a webhook endpoint can subscribe to and how deliveries are signed.', [], null, 'General'],
            ['GET', '/availability', 'availability:read', 'Availability', 'Sellable rooms per room type and night (after in-house guests, reservations, group blocks and contract allotments). With rates:read the nightly rate, stop-sell and minimum stay are included, plus a `stay` quote for the whole from→to stay. At most 90 nights.', $range, null, 'Inventory'],
            ['GET', '/rates', 'rates:read', 'Rates & restrictions', 'Nightly rate, stop-sell and minimum stay per room type (seasons, overrides and live revenue-management pricing applied). At most 366 nights.', $range, null, 'Inventory'],
            ['GET', '/reservations', 'reservations:read', 'List reservations', 'Newest first, 25 per page (max 100). For incremental sync pass `modified_since` – results are then ordered by last change, oldest first; store the last `updated_at` you saw and resume from it.', [
                ['status', 'query', 'string', false, 'Comma list: tentative, confirmed, checked_in, checked_out, cancelled, no_show'],
                ['arrival_from', 'query', 'date', false, ''], ['arrival_to', 'query', 'date', false, ''], ['departure_from', 'query', 'date', false, ''], ['departure_to', 'query', 'date', false, ''],
                ['modified_since', 'query', 'date-time', false, 'ISO 8601, e.g. 2026-09-27T10:00:00+05:30'], ['mine', 'query', 'boolean', false, '1 = only reservations created with this key'],
                ['external_ref', 'query', 'string', false, 'Your own reference (reservations created with this key)'], ['per_page', 'query', 'integer', false, '1–100'], ['page', 'query', 'integer', false, '']], null, 'Reservations'],
            ['GET', '/reservations/{reservation_no}', 'reservations:read', 'Get a reservation', 'Guest, room lines (type, assigned room, rate, status), totals and cancellation details.', [['reservation_no', 'path', 'string', true, 'e.g. RES-00042']], null, 'Reservations'],
            ['POST', '/reservations', 'reservations:write', 'Create a reservation', 'Same rules as the booking engine: stop-sell, minimum stay and live availability are checked inside a locked transaction. Without `rate` the hotel\'s rate for the stay applies. Send an `Idempotency-Key` header so a retried request never books twice; `external_ref` must be unique per key. The guest gets the usual confirmation e-mail / message unless `notify_guest` is false.', [
                ['Idempotency-Key', 'header', 'string', false, 'Any unique value (e.g. a UUID) – replays return the first response']], [
                'arrival' => '2026-10-10', 'departure' => '2026-10-12', 'room_type' => 'DLX', 'rooms' => 1, 'adults' => 2, 'children' => 0,
                'guest' => ['first_name' => 'Ama', 'last_name' => 'Mensah', 'email' => 'ama.mensah@example.com', 'phone' => '+233 24 555 0101', 'nationality' => 'Ghanaian'],
                'external_ref' => 'WEB-10293', 'special_request' => 'Late arrival, around 23:00', 'notify_guest' => true], 'Reservations'],
            ['POST', '/reservations/{reservation_no}/cancel', 'reservations:write', 'Cancel a reservation', 'Cancels every room line still pending arrival. A late-cancellation fee is charged to the reservation\'s advance folio according to the hotel\'s policy (returned as `cancellation_fee`).', [
                ['reservation_no', 'path', 'string', true, ''], ['Idempotency-Key', 'header', 'string', false, '']], ['reason' => 'Guest changed travel plans'], 'Reservations'],
            ['GET', '/reservations/{reservation_no}/folio', 'folios:read', 'Folio of a reservation', 'Every folio (per room and the advance folio) with its charge / payment lines and balance.', [['reservation_no', 'path', 'string', true, '']], null, 'Reservations'],
            ['GET', '/rooms', 'rooms:read', 'Rooms', 'Every active room with occupancy, housekeeping status and the in-house reservation (number and departure – no guest data).', [
                ['housekeeping', 'query', 'string', false, 'clean, dirty, inspected, touch_up'], ['status', 'query', 'string', false, 'vacant, occupied, out_of_order, out_of_service'], ['room_type_id', 'query', 'integer', false, '']], null, 'Rooms'],
            ['PATCH', '/rooms/{room_no}', 'housekeeping:write', 'Set housekeeping status', 'For housekeeping / facility apps. Occupancy is controlled by check-in and check-out, not by this call.', [['room_no', 'path', 'string', true, 'e.g. 101']],
                ['housekeeping' => 'clean', 'note' => 'Cleaned by the day team'], 'Rooms'],
            ['GET', '/guests', 'guests:read', 'Find guests', 'Look-up by exact e-mail, phone (last 10 digits) or name (≥ 3 characters); at most 20 matches. There is deliberately no way to page through the whole guest database. Erased profiles are never returned.', [
                ['email', 'query', 'string', false, ''], ['phone', 'query', 'string', false, ''], ['name', 'query', 'string', false, '']], null, 'Guests'],
            ['GET', '/guests/{id}', 'guests:read', 'Get a guest', 'Profile, company, loyalty points and number of stays. Erased profiles return only `{id, erased: true}`.', [['id', 'path', 'integer', true, '']], null, 'Guests'],
            ['GET', '/reports/daily', 'reports:read', 'Daily statistics', 'Figures frozen by the night audit: rooms, occupancy %, ADR, RevPAR, room / other revenue, tax, collections, arrivals, departures and no-shows. Pass `date` for one day or `from` / `to` (max 93 days; default: the last 7 days).', [
                ['date', 'query', 'date', false, 'One business date'], ['from', 'query', 'date', false, ''], ['to', 'query', 'date', false, 'exclusive']], null, 'Reports'],
        ];
    }

    public const ERRORS = [
        'unauthenticated' => [401, 'No Authorization: Bearer header'], 'invalid_token' => [401, 'Unknown or malformed key'],
        'token_revoked' => [401, 'The key was revoked'], 'token_expired' => [401, 'The key is past its expiry date'],
        'client_paused' => [403, 'The hotel paused the key'], 'ip_not_allowed' => [403, 'The caller\'s IP is not on the key\'s allow-list'],
        'insufficient_scope' => [403, 'The key lacks the scope (see required_scope)'], 'owner_inactive' => [403, 'The hotel user the key acts for was deactivated'],
        'not_found' => [404, 'No such reservation / room / guest / path'], 'conflict' => [409, 'Duplicate external_ref or the reservation is already cancelled'],
        'idempotency_in_progress' => [409, 'The first request with this key is still running'], 'validation_failed' => [422, 'See fields for each problem'],
        'idempotency_key_reused' => [422, 'Same Idempotency-Key, different body'], 'invalid_idempotency_key' => [422, 'Idempotency-Key must be 1–80 of A-Z a-z 0-9 _ - : .'], 'unprocessable' => [422, 'A business rule refused the request'],
        'rate_limited' => [429, 'Too many requests – honour Retry-After'], 'api_disabled' => [503, 'The hotel switched the API off'],
        'server_error' => [500, 'Unexpected error – quote request_id'],
    ];

    public static function openapi(): array
    {
        $paths = [];
        foreach (self::endpoints() as [$method, $path, $scope, $summary, $desc, $params, $body, $tag]) {
            $op = ['summary' => $summary, 'description' => $desc.($scope ? "\n\nRequires scope `{$scope}`." : ''), 'tags' => [$tag],
                'operationId' => strtolower($method).str_replace(['/', '{', '}'], ['_', '', ''], rtrim($path, '/') ?: '_root'),
                'parameters' => array_map(fn ($p) => ['name' => $p[0], 'in' => $p[1], 'required' => $p[3], 'description' => $p[4],
                    'schema' => match ($p[2]) { 'integer' => ['type' => 'integer'], 'boolean' => ['type' => 'boolean'], 'date' => ['type' => 'string', 'format' => 'date'],
                        'date-time' => ['type' => 'string', 'format' => 'date-time'], default => ['type' => 'string'] }], $params),
                'responses' => [($method === 'POST' && ! str_ends_with($path, 'cancel') ? '201' : '200') => ['description' => 'OK – {"data": …}',
                    'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['data' => new \stdClass]]]]],
                    'default' => ['description' => 'Error', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]]]];
            if ($body) {
                $op['requestBody'] = ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object'], 'example' => $body]]];
            }
            if ($scope) {
                $op['x-required-scope'] = $scope;
            }
            $paths[$path === '/' ? '/' : $path][strtolower($method)] = $op;
        }

        return ['openapi' => '3.0.3',
            'info' => ['title' => 'Nowills PMS API', 'version' => '1.0.0', 'description' => 'REST API of the Nowills hotel PMS. Authenticate with a per-hotel key: `Authorization: Bearer nwp_…`. '
                .'Every response carries X-Request-Id; errors are {"error": {"code", "message", "fields"?}, "request_id"}. Rate limits are per key (X-RateLimit-Limit / -Remaining, Retry-After on 429).'],
            'servers' => [['url' => url('/api/v1')]],
            'security' => [['bearer' => []]],
            'tags' => array_map(fn ($t) => ['name' => $t], array_values(array_unique(array_column(self::endpoints(), 7)))),
            'paths' => $paths,
            'components' => [
                'securitySchemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'Scopes: '.implode(', ', array_keys(ApiClient::SCOPES))]],
                'schemas' => ['Error' => ['type' => 'object', 'properties' => [
                    'error' => ['type' => 'object', 'properties' => ['code' => ['type' => 'string', 'enum' => array_keys(self::ERRORS)], 'message' => ['type' => 'string'], 'fields' => ['type' => 'object']]],
                    'request_id' => ['type' => 'string']]]],
            ],
            'x-webhooks' => ['events' => array_keys(WebhookService::EVENTS), 'signature' => 'X-Nowills-Signature: t=<unix>,v1=<hex HMAC-SHA256(secret, "<t>.<raw body>")>'],
        ];
    }
}
