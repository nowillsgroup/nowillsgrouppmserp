<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ApiAuthenticate;
use App\Models\ApiClient;
use App\Models\ApiIdempotencyKey;
use App\Models\Guest;
use App\Models\NightAudit;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Services\ApiService;
use App\Services\WebhookService;
use App\Support\ApiSpec;
use App\Support\Tenant;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * v4.0 – REST API v1 (JSON). Authentication, tenant, scopes and logging are handled by the api.client / api.scope
 * middleware; errors are rendered as {"error": {...}, "request_id"} by the exception handler (bootstrap/app.php).
 * The reference (docs page + /api/v1/openapi.json) is generated from App\Support\ApiSpec.
 */
class V1Controller extends Controller
{
    protected function client(Request $r): ApiClient
    {
        return $r->attributes->get('api_client');
    }

    protected function ok($data, int $status = 200, array $extra = []): JsonResponse
    {
        return response()->json(['data' => $data] + $extra, $status, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    protected function findReservation(string $no): Reservation
    {
        return Reservation::where('reservation_no', $no)->firstOrFail();
    }

    // ───────────────────────────── meta ─────────────────────────────

    public function index(Request $r): JsonResponse
    {
        $c = $this->client($r);

        return $this->ok(['api_version' => ApiService::VERSION, 'hotel' => Tenant::hotel()->code, 'business_date' => bdate()->toDateString(),
            'client' => ['name' => $c->name, 'scopes' => $c->scopes, 'rate_limit_per_minute' => $c->rate_limit, 'expires_on' => $c->expires_on?->toDateString()],
            'reference' => url('/api/v1/openapi.json')]);
    }

    public function openapi(): JsonResponse
    {
        return response()->json(ApiSpec::openapi(), 200, ['Access-Control-Allow-Origin' => '*', 'Cache-Control' => 'public, max-age=300'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function hotel(): JsonResponse
    {
        return $this->ok(ApiService::hotel());
    }

    public function events(): JsonResponse
    {
        return $this->ok(collect(WebhookService::EVENTS)->map(fn ($e, $k) => ['type' => $k, 'description' => $e[0], 'group' => $e[1]])->values(),
            200, ['signature' => ['header' => 'X-Nowills-Signature', 'format' => 't=<unix seconds>,v1=<hex HMAC-SHA256(secret, "<t>.<raw body>")>',
                'tolerance_seconds' => 300, 'retries' => 'after 1m, 5m, 30m, 2h, 6h, 12h, 24h']]);
    }

    // ───────────────────────────── inventory ─────────────────────────────

    public function availability(Request $r): JsonResponse
    {
        [$from, $to] = ApiService::range($r->query('from'), $r->query('to'));

        return $this->ok(ApiService::availability($from, $to, $r->integer('room_type_id') ?: null, $this->client($r)->hasScope('rates:read')));
    }

    public function rates(Request $r): JsonResponse
    {
        [$from, $to] = ApiService::range($r->query('from'), $r->query('to'), 366);
        $av = ApiService::availability($from, $to, $r->integer('room_type_id') ?: null, true);
        // rates without the inventory numbers unless the key may also see availability
        if (! $this->client($r)->hasScope('availability:read')) {
            foreach ($av['room_types'] as &$t) {
                $t['dates'] = array_map(fn ($d) => array_diff_key($d, ['available' => 1]), $t['dates']);
                unset($t['stay']['available'], $t['stay']['bookable']);
            }
        }

        return $this->ok($av);
    }

    // ───────────────────────────── reservations ─────────────────────────────

    public function reservations(Request $r): JsonResponse
    {
        $q = $r->validate([
            'status' => 'nullable|string|max:80', 'arrival_from' => 'nullable|date_format:Y-m-d', 'arrival_to' => 'nullable|date_format:Y-m-d',
            'departure_from' => 'nullable|date_format:Y-m-d', 'departure_to' => 'nullable|date_format:Y-m-d',
            'modified_since' => 'nullable|date', 'mine' => 'nullable|boolean', 'external_ref' => 'nullable|string|max:60',
            'per_page' => 'nullable|integer|min:1|max:100', 'page' => 'nullable|integer|min:1',
        ]);
        $statuses = array_values(array_filter(explode(',', (string) ($q['status'] ?? ''))));
        if ($bad = array_diff($statuses, Reservation::STATUSES)) {
            throw ValidationException::withMessages(['status' => ['Unknown status '.implode(', ', $bad).' – use '.implode(', ', Reservation::STATUSES).'.']]);
        }
        $sync = ! empty($q['modified_since']);
        $page = Reservation::with('guest', 'source', 'rooms.roomType', 'rooms.room')
            ->when($statuses, fn ($x) => $x->whereIn('status', $statuses))
            ->when($q['arrival_from'] ?? null, fn ($x, $v) => $x->where('arrival_date', '>=', $v))
            ->when($q['arrival_to'] ?? null, fn ($x, $v) => $x->where('arrival_date', '<=', $v))
            ->when($q['departure_from'] ?? null, fn ($x, $v) => $x->where('departure_date', '>=', $v))
            ->when($q['departure_to'] ?? null, fn ($x, $v) => $x->where('departure_date', '<=', $v))
            ->when($sync, fn ($x) => $x->where('updated_at', '>=', Carbon::parse($q['modified_since'])->setTimezone(config('app.timezone'))))
            ->when(! empty($q['mine']), fn ($x) => $x->where('api_client_id', $this->client($r)->id))
            ->when($q['external_ref'] ?? null, fn ($x, $v) => $x->where('api_client_id', $this->client($r)->id)->where('external_ref', $v))
            // incremental sync pages oldest-change-first (resume from the last updated_at); plain lists newest first
            ->when($sync, fn ($x) => $x->orderBy('updated_at')->orderBy('id'), fn ($x) => $x->orderByDesc('id'))
            ->paginate((int) ($q['per_page'] ?? 25))->withQueryString();

        return $this->ok($page->getCollection()->map(fn ($x) => ApiService::reservation($x))->values(), 200, [
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
            'links' => ['next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl()]]);
    }

    public function reservation(string $no): JsonResponse
    {
        return $this->ok(ApiService::reservation($this->findReservation($no)));
    }

    public function folio(string $no): JsonResponse
    {
        $res = $this->findReservation($no);

        return $this->ok($res->folios()->orderBy('id')->get()->map(fn ($f) => ApiService::folio($f))->values());
    }

    public function storeReservation(Request $r): Response
    {
        return $this->idempotent($r, function () use ($r) {
            $max = bdate()->copy()->addDays(730)->toDateString();
            $d = $r->validate([
                'arrival' => 'required|date_format:Y-m-d|after_or_equal:'.bdate()->toDateString().'|before:'.$max, 'departure' => 'required|date_format:Y-m-d|after:arrival',
                'room_type_id' => 'required_without:room_type|nullable|integer', 'room_type' => 'nullable|string|max:20',
                'rooms' => 'nullable|integer|min:1|max:10', 'adults' => 'required|integer|min:1|max:40', 'children' => 'nullable|integer|min:0|max:20',
                'rate' => 'nullable|numeric|min:0|max:10000000', 'status' => 'nullable|in:confirmed,tentative',
                'external_ref' => ['nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9_\-\/.:#]+$/'], 'special_request' => 'nullable|string|max:1000', 'purpose' => 'nullable|string|max:60',
                'notify_guest' => 'nullable|boolean',
                'guest' => 'required|array', 'guest.first_name' => 'required|string|max:80', 'guest.last_name' => 'nullable|string|max:80',
                'guest.email' => 'nullable|required_without:guest.phone|email|max:150', 'guest.phone' => 'nullable|string|max:30', 'guest.nationality' => 'nullable|string|max:60',
            ]);
            if (Carbon::parse($d['arrival'])->diffInDays(Carbon::parse($d['departure'])) > 60) {
                throw ValidationException::withMessages(['departure' => ['At most 60 nights per reservation.']]);
            }
            $res = ApiService::createReservation($this->client($r), $d);
            if ($d['notify_guest'] ?? true) {
                rescue(fn () => \App\Services\MailService::autoConfirm($res), null, true);
                rescue(fn () => \App\Services\MessageService::autoConfirm($res), null, true);
            }

            return $this->ok(ApiService::reservation($res->fresh()), 201)->header('Location', url('/api/v1/reservations/'.$res->reservation_no));
        });
    }

    public function cancelReservation(Request $r, string $no): Response
    {
        return $this->idempotent($r, function () use ($r, $no) {
            $d = $r->validate(['reason' => 'required|string|min:3|max:255']);
            $res = $this->findReservation($no);
            $out = ApiService::cancelReservation($this->client($r), $res, $d['reason']);

            return $this->ok(ApiService::reservation($res->fresh()) + $out);
        });
    }

    // ───────────────────────────── rooms ─────────────────────────────

    public function rooms(Request $r): JsonResponse
    {
        $q = $r->validate(['housekeeping' => 'nullable|in:clean,dirty,inspected,touch_up', 'status' => 'nullable|in:vacant,occupied,out_of_order,out_of_service',
            'room_type_id' => 'nullable|integer']);
        $stays = ReservationRoom::with('reservation')->where('status', 'checked_in')->whereNotNull('room_id')->get()->keyBy('room_id');
        $rooms = Room::with('roomType', 'floor')->where('is_active', true)
            ->when($q['housekeeping'] ?? null, fn ($x, $v) => $x->where('hk_status', $v))->when($q['status'] ?? null, fn ($x, $v) => $x->where('status', $v))
            ->when($q['room_type_id'] ?? null, fn ($x, $v) => $x->where('room_type_id', $v))->orderBy('room_no')->get();

        return $this->ok($rooms->map(fn ($m) => ApiService::room($m, $stays->get($m->id)))->values());
    }

    public function updateRoom(Request $r, string $roomNo): JsonResponse
    {
        $d = $r->validate(['housekeeping' => 'required|string', 'note' => 'nullable|string|max:190']);
        $room = Room::where('is_active', true)->where('room_no', $roomNo)->firstOrFail();
        ApiService::setHousekeeping($this->client($r), $room, $d['housekeeping'], $d['note'] ?? null);

        $stay = ReservationRoom::with('reservation')->where('status', 'checked_in')->where('room_id', $room->id)->first();

        return $this->ok(ApiService::room($room->fresh(['roomType', 'floor']), $stay));
    }

    // ───────────────────────────── guests ─────────────────────────────

    /** Look-up only (email / phone / name) – the API never pages through the whole guest database. */
    public function guests(Request $r): JsonResponse
    {
        $q = $r->validate(['email' => 'nullable|email|max:150', 'phone' => 'nullable|string|min:6|max:30', 'name' => 'nullable|string|min:3|max:80']);
        if (empty($q['email']) && empty($q['phone']) && empty($q['name'])) {
            throw ValidationException::withMessages(['email' => ['Search by email, phone or name (at least 3 characters).']]);
        }
        $digits = preg_replace('/\D/', '', (string) ($q['phone'] ?? ''));
        $guests = Guest::with('company')->where(fn ($x) => $x->whereNull('privacy_status')->orWhere('privacy_status', '!=', 'erased'))
            ->when($q['email'] ?? null, fn ($x, $v) => $x->where('email', $v))
            ->when($digits, fn ($x) => $x->where('phone', 'like', '%'.substr($digits, -10)))
            ->when($q['name'] ?? null, fn ($x, $v) => $x->where(fn ($w) => $w->whereRaw("CONCAT_WS(' ', first_name, last_name) LIKE ?", ['%'.addcslashes($v, '%_\\').'%'])))
            ->orderByDesc('id')->limit(20)->get();

        return $this->ok($guests->map(fn ($g) => ApiService::guest($g, true))->values());
    }

    public function guest(int $id): JsonResponse
    {
        return $this->ok(ApiService::guest(Guest::with('company')->findOrFail($id), true));
    }

    // ───────────────────────────── reports ─────────────────────────────

    public function daily(Request $r): JsonResponse
    {
        if ($r->filled('date')) {
            $r->validate(['date' => 'date_format:Y-m-d']);
            $a = NightAudit::where('business_date', $r->query('date'))->first();
            if (! $a) {
                return ApiAuthenticate::error('not_found', 'No night audit has closed '.$r->query('date').' yet.', 404);
            }

            return $this->ok(ApiService::audit($a));
        }
        [$from, $to] = ApiService::range($r->query('from', bdate()->copy()->subDays(7)->toDateString()), $r->query('to', bdate()->toDateString()), 93, true);

        return $this->ok(NightAudit::whereDate('business_date', '>=', $from->toDateString())->whereDate('business_date', '<=', $to->copy()->subDay()->toDateString())->orderBy('business_date')->get()
            ->map(fn ($a) => ApiService::audit($a))->values());
    }

    // ───────────────────────────── idempotency ─────────────────────────────

    /**
     * Idempotency-Key: the first request's outcome (2xx / 4xx) is stored for 24 h and replayed for the same key and body;
     * the same key with a different body is refused; a 5xx releases the key so the client can retry.
     */
    protected function idempotent(Request $r, Closure $fn): Response
    {
        $key = $r->header('Idempotency-Key');
        if ($key === null) {
            return $fn();
        }
        if (! preg_match('/^[A-Za-z0-9_\-:.]{1,80}$/', $key)) {
            return ApiAuthenticate::error('invalid_idempotency_key', 'Idempotency-Key: 1–80 characters of A-Z a-z 0-9 _ - : .', 422);
        }
        $c = $this->client($r);
        $body = $r->json()->all() ?: $r->request->all();
        ksort($body);
        $hash = hash('sha256', $r->method().' '.$r->path().' '.json_encode($body));
        try {
            $row = ApiIdempotencyKey::create(['api_client_id' => $c->id, 'idem_key' => $key, 'request_hash' => $hash, 'created_at' => now()]);
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? '') !== '23000') {
                throw $e;
            }
            $prev = ApiIdempotencyKey::where('api_client_id', $c->id)->where('idem_key', $key)->first();
            if (! $prev || $prev->request_hash !== $hash) {
                return ApiAuthenticate::error('idempotency_key_reused', 'This Idempotency-Key was already used with a different request.', 422);
            }
            if ($prev->status_code === null) {
                return ApiAuthenticate::error('idempotency_in_progress', 'The first request with this Idempotency-Key is still being processed.', 409);
            }

            return response()->json(json_decode($prev->response, true), $prev->status_code, ['Idempotent-Replayed' => 'true'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        try {
            $resp = $fn();
        } catch (\Throwable $e) {
            $handler = app(\Illuminate\Contracts\Debug\ExceptionHandler::class);
            $handler->report($e);
            $resp = $handler->render($r, $e);
        }
        if ($resp->getStatusCode() >= 500) {
            $row->delete();
        } else {
            $row->forceFill(['status_code' => $resp->getStatusCode(), 'response' => $resp->getContent()])->save();
        }

        return $resp;
    }
}
