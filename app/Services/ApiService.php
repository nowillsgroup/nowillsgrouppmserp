<?php

namespace App\Services;

use App\Models\ApiClient;
use App\Models\ApiRequestLog;
use App\Models\BusinessSource;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\NightAudit;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\User;
use App\Support\Numbering;
use App\Support\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * v4.0 – the REST API: client tokens (issue / rotate / revoke, scopes, IP allow-list, rate limit), authentication,
 * request logging, the JSON representations shared by the API and webhook payloads, and the write operations.
 */
class ApiService
{
    public const VERSION = 'v1';

    public const DEFAULTS = ['api_enabled' => '1', 'api_log_days' => '30', 'api_allow_http' => '0', 'api_allow_private' => '0', 'webhook_disable_after' => '20'];

    public const MAX_RANGE_DAYS = 90;

    // ───────────────────────────── settings ─────────────────────────────

    public static function cfg(): array
    {
        $g = fn ($k) => setting($k, self::DEFAULTS[$k]);

        return ['enabled' => $g('api_enabled') === '1', 'log_days' => max(1, min(365, (int) $g('api_log_days'))),
            'allow_http' => $g('api_allow_http') === '1', 'allow_private' => $g('api_allow_private') === '1',
            'disable_after' => max(3, min(500, (int) $g('webhook_disable_after')))];
    }

    public static function saveSettings(array $d): void
    {
        $vals = ['api_enabled' => ! empty($d['enabled']) ? '1' : '0', 'api_log_days' => (string) max(1, min(365, (int) ($d['log_days'] ?? 30))),
            'api_allow_http' => ! empty($d['allow_http']) ? '1' : '0', 'api_allow_private' => ! empty($d['allow_private']) ? '1' : '0',
            'webhook_disable_after' => (string) max(3, min(500, (int) ($d['disable_after'] ?? 20)))];
        foreach ($vals as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        }
        Setting::$version++;
        ActivityLogger::log('api', 'settings', 'API & webhook settings updated'.($vals['api_enabled'] === '0' ? ' – API switched OFF' : ''));
    }

    // ───────────────────────────── clients & tokens ─────────────────────────────

    /** @return array{0: ApiClient, 1: string} the client and its token (shown once) */
    public static function createClient(array $d): array
    {
        $c = new ApiClient(self::clientAttrs($d) + ['created_by' => auth()->id()]);
        $token = self::newToken($c);
        $c->save();
        ActivityLogger::log('api', 'client_create', "API client \"{$c->name}\" created with scopes ".implode(', ', $c->scopes), $c);

        return [$c, $token];
    }

    public static function updateClient(ApiClient $c, array $d): ApiClient
    {
        if ($c->revoked_at) {
            throw new RuntimeException('A revoked client cannot be changed – create a new one.');
        }
        $c->fill(self::clientAttrs($d))->save();
        ActivityLogger::log('api', 'client_update', "API client \"{$c->name}\" updated (scopes: ".implode(', ', $c->scopes).')', $c);

        return $c;
    }

    /** New secret – the old token stops working immediately. */
    public static function rotate(ApiClient $c): string
    {
        if ($c->revoked_at) {
            throw new RuntimeException('A revoked client cannot be re-issued.');
        }
        $token = self::newToken($c);
        $c->rotated_at = now();
        $c->save();
        ActivityLogger::log('api', 'client_rotate', "Token of API client \"{$c->name}\" rotated – the previous token no longer works", $c);

        return $token;
    }

    public static function revoke(ApiClient $c, string $reason): void
    {
        $c->forceFill(['revoked_at' => now(), 'is_active' => false])->save();
        ActivityLogger::log('api', 'client_revoke', "API client \"{$c->name}\" revoked: {$reason}", $c);
    }

    protected static function newToken(ApiClient $c): string
    {
        do {
            $prefix = strtolower(Str::random(8));
        } while (! preg_match('/^[a-z0-9]{8}$/', $prefix) || ApiClient::withoutGlobalScope('hotel')->where('token_prefix', $prefix)->exists());
        $token = 'nwp_'.$prefix.'_'.Str::random(40);
        $c->token_prefix = $prefix;
        $c->token_hash = hash('sha256', $token);

        return $token;
    }

    protected static function clientAttrs(array $d): array
    {
        $name = trim((string) ($d['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Give the client a name (the partner or app using the key).');
        }
        $scopes = array_values(array_intersect(array_keys(ApiClient::SCOPES), (array) ($d['scopes'] ?? [])));
        if (! $scopes) {
            throw new RuntimeException('Choose at least one permission (scope) for the key.');
        }
        $ips = self::parseIps((string) ($d['ip_allowlist'] ?? ''));
        $owner = ! empty($d['owner_id']) ? User::where('hotel_id', Tenant::id())->where('is_active', true)->find($d['owner_id']) : null;
        if (! empty($d['owner_id']) && ! $owner) {
            throw new RuntimeException('The acting user must be an active user of this hotel.');
        }
        $owner ??= auth()->user();
        $expires = ! empty($d['expires_on']) ? Carbon::parse($d['expires_on'])->toDateString() : null;
        if ($expires && $expires < bdate()->toDateString()) {
            throw new RuntimeException('The expiry date is in the past.');
        }
        $src = ! empty($d['business_source_id']) ? BusinessSource::find($d['business_source_id']) : null;

        return ['name' => mb_substr($name, 0, 80), 'description' => trim((string) ($d['description'] ?? '')) ?: null, 'scopes' => $scopes,
            'ip_allowlist' => $ips ? implode("\n", $ips) : null, 'rate_limit' => max(10, min(6000, (int) ($d['rate_limit'] ?? 120))),
            'expires_on' => $expires, 'is_active' => ! array_key_exists('is_active', $d) || ! empty($d['is_active']),
            'owner_id' => $owner?->id, 'business_source_id' => $src?->id];
    }

    /** IPs / CIDRs separated by commas, spaces or new lines – each one validated. */
    public static function parseIps(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $entry) {
            [$ip, $bits] = array_pad(explode('/', $entry, 2), 2, null);
            $v6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
            if (! filter_var($ip, FILTER_VALIDATE_IP) || ($bits !== null && (! ctype_digit($bits) || (int) $bits > ($v6 ? 128 : 32)))) {
                throw new RuntimeException("\"{$entry}\" is not a valid IP address or CIDR range.");
            }
            $out[] = $entry;
        }

        return array_values(array_unique($out));
    }

    /** Resolve a bearer token to its client (constant-time hash comparison; no tenant needed). */
    public static function authenticate(?string $token): ?ApiClient
    {
        if (! $token || ! preg_match('/^nwp_([a-z0-9]{8})_[A-Za-z0-9]{40}$/', $token, $m)) {
            return null;
        }
        $c = ApiClient::withoutGlobalScope('hotel')->where('token_prefix', $m[1])->first();

        return $c && hash_equals($c->token_hash, hash('sha256', $token)) ? $c : null;
    }

    public static function ipAllowed(ApiClient $c, ?string $ip): bool
    {
        $list = self::parseIps((string) $c->ip_allowlist);

        return ! $list || ($ip && IpUtils::checkIp($ip, $list));
    }

    public static function log(?ApiClient $c, array $d): void
    {
        try {
            // raw insert: a refused call (no valid key) must stay hotel-less – the model's tenant stamping would attribute it to whatever tenant is current
            ApiRequestLog::withoutGlobalScope('hotel')->insert(array_intersect_key($d, array_flip(['request_id', 'method', 'path', 'route', 'status', 'error_code', 'duration_ms', 'ip', 'user_agent']))
                + ['hotel_id' => $c?->hotel_id, 'api_client_id' => $c?->id, 'created_at' => now()]);
            if ($c && (! $c->last_used_at || $c->last_used_at->lt(now()->subSeconds(30)) || $c->last_ip !== ($d['ip'] ?? null))) {
                ApiClient::withoutGlobalScope('hotel')->whereKey($c->id)->update(['last_used_at' => now(), 'last_ip' => $d['ip'] ?? null]);
            }
        } catch (\Throwable $e) {
            report($e);   // never fail an API call because of logging
        }
    }

    /** 24 h usage per client for the console: [client_id => [requests, errors]] */
    public static function usage(int $hours = 24): Collection
    {
        return ApiRequestLog::where('created_at', '>=', now()->subHours($hours))->whereNotNull('api_client_id')
            ->groupBy('api_client_id')->selectRaw('api_client_id, count(*) n, sum(status >= 400) errors, round(avg(duration_ms)) ms')->get()->keyBy('api_client_id');
    }

    public static function prune(): array
    {
        $days = self::cfg()['log_days'];
        $logs = ApiRequestLog::where('created_at', '<', now()->subDays($days))->delete();
        $keys = \App\Models\ApiIdempotencyKey::where('created_at', '<', now()->subDay())->delete();

        return [$logs, $keys];
    }

    // ───────────────────────────── representations ─────────────────────────────

    public static function hotel(): array
    {
        $h = Tenant::hotel();

        return ['code' => $h->code, 'name' => $h->name, 'property_type' => $h->property_type, 'city' => $h->city, 'country' => $h->country,
            'phone' => $h->phone, 'email' => $h->email, 'website' => $h->website, 'currency' => $h->currency, 'timezone' => $h->timezone,
            'check_in_time' => substr((string) $h->checkin_time, 0, 5) ?: null, 'check_out_time' => substr((string) $h->checkout_time, 0, 5) ?: null,
            'business_date' => bdate()->toDateString(),
            'room_types' => RoomType::where('is_active', true)->withCount(['rooms' => fn ($q) => $q->where('is_active', true)])->orderBy('base_rate')->get()
                ->map(fn ($t) => self::roomType($t) + ['rooms' => $t->rooms_count, 'base_rate' => (float) $t->base_rate, 'max_adults' => (int) $t->max_adults,
                    'max_children' => (int) $t->max_children, 'bookable_online' => (bool) $t->show_online])->values()->all()];
    }

    public static function roomType(?RoomType $t): ?array
    {
        return $t ? ['id' => $t->id, 'code' => $t->code, 'name' => $t->name] : null;
    }

    public static function guest(?Guest $g, bool $full = false): ?array
    {
        if (! $g) {
            return null;
        }
        if ($g->privacy_status === 'erased') {
            return ['id' => $g->id, 'erased' => true];
        }
        $out = ['id' => $g->id, 'title' => $g->title, 'first_name' => $g->first_name, 'last_name' => $g->last_name, 'email' => $g->email, 'phone' => $g->phone,
            'nationality' => $g->nationality, 'vip' => (bool) $g->is_vip];
        if ($full) {
            $out += ['city' => $g->city, 'country' => $g->country, 'company' => $g->company?->name, 'loyalty_points' => (int) $g->loyalty_points,
                'preferences' => $g->preferences, 'restricted' => $g->privacy_status === 'restricted',
                'stays' => ReservationRoom::where('guest_id', $g->id)->where('status', 'checked_out')->count(),
                'created_at' => $g->created_at?->toIso8601String()];
        }

        return $out;
    }

    public static function stay(ReservationRoom $s, bool $withGuest = false): array
    {
        $out = ['stay_id' => $s->id, 'status' => $s->status, 'room_type' => self::roomType($s->roomType), 'room_no' => $s->room?->room_no,
            'arrival' => $s->arrival_date->toDateString(), 'departure' => $s->departure_date->toDateString(), 'rate' => (float) $s->rate,
            'adults' => (int) $s->adults, 'children' => (int) $s->children,
            'checked_in_at' => $s->checked_in_at?->toIso8601String(), 'checked_out_at' => $s->checked_out_at?->toIso8601String()];
        if ($withGuest) {
            $out = ['reservation_no' => $s->reservation?->reservation_no] + $out + ['guest' => self::guest($s->guest)];
        }

        return $out;
    }

    public static function reservation(Reservation $r): array
    {
        $r->loadMissing('guest', 'source', 'rooms.roomType', 'rooms.room');
        $rooms = $r->rooms->sortBy('id')->values();
        $live = $rooms->whereNotIn('status', ['cancelled', 'no_show']);

        return ['reservation_no' => $r->reservation_no, 'status' => $r->status, 'arrival' => $r->arrival_date->toDateString(), 'departure' => $r->departure_date->toDateString(),
            'nights' => $r->nights, 'adults' => (int) $r->adults, 'children' => (int) $r->children, 'source' => $r->source?->name,
            'external_ref' => $r->external_ref, 'ota_reference' => $r->ota_reference, 'guest' => self::guest($r->guest),
            'rooms' => $rooms->map(fn ($s) => self::stay($s))->all(),
            'room_total' => round($live->sum(fn ($s) => (float) $s->rate * max(1, $s->arrival_date->diffInDays($s->departure_date))), 2),
            'special_request' => $r->special_request, 'cancellation' => $r->status === 'cancelled' ? ['at' => $r->cancelled_at?->toIso8601String(),
                'reason' => $r->cancel_reason, 'fee' => (float) $r->cancellation_fee] : null,
            'created_at' => $r->created_at?->toIso8601String(), 'updated_at' => $r->updated_at?->toIso8601String()];
    }

    public static function room(Room $r, ?ReservationRoom $stay = null): array
    {
        return ['room_no' => $r->room_no, 'room_type' => self::roomType($r->roomType), 'floor' => $r->floor?->name, 'status' => $r->status,
            'housekeeping' => $r->hk_status, 'occupied' => $r->status === 'occupied',
            'stay' => $stay ? ['reservation_no' => $stay->reservation?->reservation_no, 'departure' => $stay->departure_date->toDateString()] : null];
    }

    public static function folio(Folio $f): array
    {
        $tx = $f->transactions()->where('is_void', false)->orderBy('business_date')->orderBy('id')->get();

        return ['folio_no' => $f->folio_no, 'status' => $f->status, 'room_no' => $f->stay?->room?->room_no, 'invoice_no' => $f->invoice_no,
            'lines' => $tx->map(fn ($t) => ['date' => $t->business_date->toDateString(), 'type' => $t->type, 'category' => $t->category, 'description' => $t->description,
                'amount' => (float) $t->amount, 'tax' => (float) $t->tax_amount])->all(),
            'charges' => round((float) $tx->where('type', 'charge')->sum(fn ($t) => $t->amount + $t->tax_amount), 2),
            'payments' => round((float) $tx->where('type', 'payment')->sum('amount'), 2), 'balance' => round($f->balance(), 2)];
    }

    public static function audit(NightAudit $a): array
    {
        return ['date' => $a->business_date->toDateString(), 'rooms_total' => (int) $a->rooms_total, 'rooms_occupied' => (int) $a->rooms_occupied,
            'occupancy_pct' => (float) $a->occupancy_pct, 'adr' => (float) $a->adr, 'revpar' => (float) $a->revpar, 'room_revenue' => (float) $a->room_revenue,
            'other_revenue' => (float) $a->other_revenue, 'tax_collected' => (float) $a->tax_collected, 'collections' => (float) $a->collections,
            'arrivals' => (int) $a->arrivals, 'departures' => (int) $a->departures, 'no_shows' => (int) $a->no_shows];
    }

    // ───────────────────────────── inventory ─────────────────────────────

    /** @return array{0: Carbon, 1: Carbon} validated [from, to) stay range */
    public static function range(?string $from, ?string $to, int $max = self::MAX_RANGE_DAYS, bool $allowPast = false): array
    {
        $errors = [];
        foreach (['from' => $from, 'to' => $to] as $k => $v) {
            if ($v !== null && $v !== '' && (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || ! strtotime($v))) {
                $errors[$k] = ["{$k} must be a date (YYYY-MM-DD)."];
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $f = $from ? Carbon::parse($from)->startOfDay() : bdate()->copy()->startOfDay();
        $t = $to ? Carbon::parse($to)->startOfDay() : $f->copy()->addDay();
        if (! $allowPast && $f->lt(bdate()->copy()->startOfDay())) {
            throw ValidationException::withMessages(['from' => ['from cannot be before the hotel\'s business date ('.bdate()->toDateString().').']]);
        }
        if ($t->lte($f)) {
            throw ValidationException::withMessages(['to' => ['to must be after from.']]);
        }
        if ($f->diffInDays($t) > $max) {
            throw ValidationException::withMessages(['to' => ["At most {$max} days per request."]]);
        }

        return [$f, $t];
    }

    /** Sellable rooms and the rate for each night, per room type; `stay` = the whole from→to stay. */
    public static function availability(Carbon $from, Carbon $to, ?int $typeId = null, bool $withRates = true): array
    {
        $days = (int) $from->diffInDays($to);
        $m = AvailabilityService::matrix($from, $days);
        $types = $m['types']->where('is_active', true)->when($typeId, fn ($c) => $c->where('id', $typeId))->sortBy('base_rate')->values();
        $grid = $withRates ? RateService::calendar($types, $from->copy(), $days) : [];
        $out = [];
        foreach ($types as $t) {
            $dates = [];
            foreach ($m['matrix'][$t->id] ?? [] as $d => $free) {
                $cell = ['date' => $d, 'available' => max(0, (int) $free)];
                if ($withRates && isset($grid[$t->id][$d])) {
                    $g = $grid[$t->id][$d];
                    $cell += ['rate' => (float) $g['rate'], 'stop_sell' => (bool) $g['stop'], 'min_stay' => (int) $g['min_stay']];
                }
                $dates[] = $cell;
            }
            $row = self::roomType($t) + ['dates' => $dates];
            if ($withRates) {
                $q = RateService::quote($t, $from->toDateString(), $to->toDateString());
                $free = $dates ? min(array_column($dates, 'available')) : 0;
                $row['stay'] = ['nights' => $q['count'], 'total' => $q['total'], 'average' => $q['average'], 'available' => $free,
                    'bookable' => $free > 0 && ! $q['stop_sell'] && $q['min_stay_ok'], 'min_stay' => $q['min_stay']];
            }
            $out[] = $row;
        }

        return ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'currency' => Tenant::hotel()->currency, 'room_types' => $out];
    }

    // ───────────────────────────── writes ─────────────────────────────

    /** POST /reservations – same rules as the booking engine (stop-sell, minimum stay, availability incl. blocks & allotments). */
    public static function createReservation(ApiClient $c, array $d): Reservation
    {
        $type = RoomType::where('is_active', true)->where(fn ($q) => $q->where('id', $d['room_type_id'] ?? 0)->orWhere('code', $d['room_type'] ?? '__none__'))->first();
        if (! $type) {
            throw ValidationException::withMessages(['room_type_id' => ['Unknown room type – see GET /hotel for the list.']]);
        }
        $rooms = (int) ($d['rooms'] ?? 1);
        $adults = (int) $d['adults'];
        if ($adults < $rooms) {
            throw ValidationException::withMessages(['adults' => ['At least one adult per room.']]);
        }
        $perRoom = (int) ceil($adults / $rooms);
        if ($type->max_adults && $perRoom > $type->max_adults) {
            throw ValidationException::withMessages(['adults' => ["{$type->name} sleeps at most {$type->max_adults} adult(s) per room – book more rooms."]]);
        }
        if (! empty($d['external_ref']) && ($dup = Reservation::where('api_client_id', $c->id)->where('external_ref', $d['external_ref'])->first())) {
            throw new ApiConflict("external_ref {$d['external_ref']} was already booked as {$dup->reservation_no}.", $dup);
        }
        $quote = RateService::quote($type, $d['arrival'], $d['departure']);
        if ($quote['stop_sell']) {
            throw ValidationException::withMessages(['room_type_id' => ['This room type is closed for sale on the selected dates.']]);
        }
        if (! $quote['min_stay_ok']) {
            throw ValidationException::withMessages(['departure' => ["A minimum stay of {$quote['min_stay']} nights applies to these dates."]]);
        }
        $rate = isset($d['rate']) && $d['rate'] !== null ? round((float) $d['rate'], 2) : $quote['average'];
        $g = $d['guest'];

        try {
            return DB::transaction(function () use ($c, $d, $type, $rooms, $perRoom, $rate, $g, $adults, $quote) {
            // Lock the physical inventory rows, not only the room-type master;
            // desk/API/online writers therefore serialize on the same rows.
            AvailabilityService::lockRoomInventory([$type->id]);
            if (AvailabilityService::typeAvailable($type->id, $d['arrival'], $d['departure']) < $rooms) {
                throw ValidationException::withMessages(['rooms' => ['Not enough rooms of this type are available for those dates.']]);
            }
            if (! empty($g['email']) && Guest::where('email', $g['email'])->where('first_name', $g['first_name'])->where('is_blacklisted', true)->exists()) {
                throw ValidationException::withMessages(['guest' => ['This guest is blacklisted. Reservation blocked.']]);
            }
            $guest = ! empty($g['email'])
                ? Guest::whereNull('privacy_status')->where('email', $g['email'])->where('first_name', $g['first_name'])->first()
                : null;
            $guest ??= Guest::create(['first_name' => $g['first_name'], 'last_name' => $g['last_name'] ?? null, 'email' => $g['email'] ?? null,
                'phone' => $g['phone'] ?? null, 'nationality' => $g['nationality'] ?? null]);
            if ($guest->is_blacklisted) {
                throw ValidationException::withMessages(['guest' => ['This guest is blacklisted. Reservation blocked.']]);
            }
            if (! $guest->phone && ! empty($g['phone'])) {
                $guest->update(['phone' => $g['phone']]);
            }
            $source = $c->business_source_id ?: BusinessSource::firstOrCreate(['name' => 'API / integrations'], ['type' => 'direct'])->id;
            $res = Reservation::create([
                'reservation_no' => Numbering::next('reservation'), 'guest_id' => $guest->id, 'business_source_id' => $source,
                'arrival_date' => $d['arrival'], 'departure_date' => $d['departure'], 'adults' => $adults, 'children' => (int) ($d['children'] ?? 0),
                'status' => ($d['status'] ?? 'confirmed') === 'tentative' ? 'tentative' : 'confirmed', 'special_request' => $d['special_request'] ?? null,
                'purpose' => $d['purpose'] ?? null, 'created_by' => auth()->id(), 'api_client_id' => $c->id, 'external_ref' => $d['external_ref'] ?? null,
            ]);
            $left = $adults;
            for ($i = 0; $i < $rooms; $i++) {
                $a = min($perRoom, $left - ($rooms - $i - 1));
                $left -= $a;
                $res->rooms()->create(['room_type_id' => $type->id, 'guest_id' => $guest->id, 'arrival_date' => $d['arrival'], 'departure_date' => $d['departure'],
                    'rate' => $rate, 'adults' => $a, 'children' => $i === 0 ? (int) ($d['children'] ?? 0) : 0, 'status' => 'reserved']);
            }
            ActivityLogger::log('api', 'reservation_create', "Reservation {$res->reservation_no} created via API client \"{$c->name}\""
                .(! empty($d['external_ref']) ? " (ref {$d['external_ref']})" : '').(abs($rate - $quote['average']) > 0.005 ? ' at the partner rate '.money($rate).' (hotel rate '.money($quote['average']).')' : ''), $res);

            return $res;
            });
        } catch (QueryException $e) {
            // The unique client/external_ref index is the final race-safe
            // idempotency guard when two requests arrive without a header.
            if (! empty($d['external_ref']) && ($e->errorInfo[0] ?? null) === '23000') {
                $dup = Reservation::where('api_client_id', $c->id)->where('external_ref', $d['external_ref'])->first();
                if ($dup) {
                    throw new ApiConflict("external_ref {$d['external_ref']} was already booked as {$dup->reservation_no}.", $dup);
                }
            }
            throw $e;
        }
    }

    public static function cancelReservation(ApiClient $c, Reservation $r, string $reason): array
    {
        if (in_array($r->status, ['cancelled', 'no_show', 'checked_out'], true)) {
            throw new ApiConflict("Reservation {$r->reservation_no} is already {$r->status}.", $r);
        }
        try {
            [$stays, $fee] = CancellationService::cancel($r, null, $reason, false, null, " via API client \"{$c->name}\"");
        } catch (RuntimeException $e) {
            // A second client can pass the preflight before the first
            // cancellation commits. Convert the service's empty-stay guard
            // into the API's normal conflict response instead of a 500.
            $fresh = Reservation::find($r->id);
            if ($fresh && in_array($fresh->status, ['cancelled', 'no_show', 'checked_out'], true)) {
                throw new ApiConflict("Reservation {$fresh->reservation_no} is already {$fresh->status}.", $fresh);
            }
            throw $e;
        }

        return ['cancelled_rooms' => $stays->count(), 'cancellation_fee' => (float) $fee];
    }

    public static function setHousekeeping(ApiClient $c, Room $room, string $status, ?string $note = null): Room
    {
        if (! in_array($status, ['clean', 'dirty', 'inspected', 'touch_up'], true)) {
            throw ValidationException::withMessages(['housekeeping' => ['One of: clean, dirty, inspected, touch_up.']]);
        }
        $old = $room->hk_status;
        if ($old !== $status) {
            $room->update(['hk_status' => $status]);
            ActivityLogger::log('housekeeping', 'status', "Room {$room->room_no}: {$old} → {$status} via API client \"{$c->name}\"".($note ? " – {$note}" : ''), $room);
        }

        return $room;
    }
}

