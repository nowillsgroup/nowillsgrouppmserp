<?php

namespace App\Services;

use App\Models\ChannelAriState;
use App\Models\ChannelConnection;
use App\Services\Interfaces\ChannelDriver;
use App\Services\Interfaces\ChannelDriverRegistry;
use App\Models\ChannelMessage;
use App\Models\ChannelPush;
use App\Models\ChannelRateMap;
use App\Models\Guest;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Support\Numbering;
use App\Support\Tenant;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Throwable;

/**
 * v2.8 two-way channel manager.
 *
 * OUTBOUND (ARI): any change that can move availability, price or restrictions of a room type on a date (bookings,
 * cancellations, amendments, rate overrides, revenue-management publishes, group blocks, contract allotments,
 * channel restrictions) appends (room type, date) rows to channel_dirty. Each connection keeps a watermark into that
 * queue; a push sends the current ARI of the dirty dates as HMAC-signed JSON (31-night chunks), advances the watermark
 * on success and backs off 1 / 5 / 15 / 30 / 60 minutes on failure. A full refresh (the whole push horizon) runs after
 * mapping changes, re-activation, the night audit and on demand. The last acknowledged values per room type & night are
 * kept in channel_ari_states – the rate-parity view compares them with what the PMS would send now.
 *
 * INBOUND: the channel POSTs new / modify / cancel messages to a per-connection webhook signed with the shared secret.
 * Messages are idempotent (same payload = duplicate, answered with the original result). OTA bookings are guaranteed
 * by the channel, so they are always accepted: when inventory is short the reservation is created and flagged
 * "overbooked" for the front office. Unmapped room codes, and changes to stays already in-house, go to the
 * needs-attention inbox instead. Card data is stripped before a message is stored.
 */
class ChannelService
{
    public const BACKOFF = [1, 5, 15, 30, 60];   // minutes, by consecutive failure

    public const CHUNK_DAYS = 31;

    public const HORIZON = 400;

    public const SIGNATURE_TOLERANCE = 300;      // seconds

    protected static array $enabled = [];

    public static function forgetCache(): void
    {
        self::$enabled = [];
    }

    /** Does the current hotel have an active two-way connection? (cheap guard for the model observers) */
    public static function enabled(): bool
    {
        $h = Tenant::id();

        return $h ? (self::$enabled[$h] ??= ChannelConnection::where('is_active', true)->exists()) : false;
    }

    // ------------------------------------------------------------------ dirty queue

    /** Queue nights [from, to) of a room type for the next ARI push. */
    public static function markDirty(?int $typeId, $from, $to): void
    {
        if (! $typeId || ! self::enabled()) {
            return;
        }
        $today = bdate()->copy()->startOfDay();
        $from = Carbon::parse($from)->startOfDay()->max($today);
        $to = Carbon::parse($to)->startOfDay()->min($today->copy()->addDays(self::HORIZON));
        $rows = [];
        for ($d = $from->copy(); $d->lt($to); $d->addDay()) {
            $rows[] = ['hotel_id' => Tenant::id(), 'room_type_id' => $typeId, 'date' => $d->toDateString(), 'created_at' => now()];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('channel_dirty')->insert($chunk);
        }
    }

    /** Inclusive date range variant (rate overrides, restrictions, allotments). */
    public static function markDirtyInclusive(?int $typeId, $from, $to): void
    {
        self::markDirty($typeId, $from, Carbon::parse($to)->addDay());
    }

    /** Something global changed (base rate, rate plan, rooms out of order…): full refresh on every active connection. */
    public static function markAll(?int $connectionId = null): void
    {
        if (! self::enabled()) {
            return;
        }
        ChannelConnection::where('is_active', true)->when($connectionId, fn ($q, $id) => $q->where('id', $id))->update(['full_sync_due' => true]);
    }

    /** Distinct room-nights waiting to be pushed to this connection. */
    public static function pendingCount(ChannelConnection $c): int
    {
        return (int) DB::table('channel_dirty')->where('hotel_id', $c->hotel_id)->where('id', '>', $c->pushed_upto_id)
            ->where('date', '>=', bdate()->toDateString())->distinct()->count(DB::raw("CONCAT(room_type_id, '|', `date`)"));
    }

    public static function registerObservers(): void
    {
        $range = function ($m, bool $original = false) {
            $g = fn ($k) => $original ? $m->getOriginal($k) : $m->{$k};
            if ($g('arrival_date') && $g('departure_date')) {
                self::markDirty((int) $g('room_type_id'), $g('arrival_date'), $g('departure_date'));
            }
        };
        \App\Models\ReservationRoom::saved(function ($s) use ($range) {
            if ($s->wasRecentlyCreated || $s->wasChanged(['status', 'room_type_id', 'arrival_date', 'departure_date', 'room_id'])) {
                $range($s);
                if (! $s->wasRecentlyCreated && $s->wasChanged(['room_type_id', 'arrival_date', 'departure_date'])) {
                    $range($s, true);
                }
            }
        });
        \App\Models\ReservationRoom::deleted(fn ($s) => $range($s));
        $inclusive = fn ($o) => self::markDirtyInclusive((int) $o->room_type_id, $o->date_from, $o->date_to);
        foreach ([\App\Models\RateOverride::class, \App\Models\ContractAllotment::class] as $cls) {
            $cls::saved(function ($o) use ($inclusive) {
                $inclusive($o);
                if ($o->wasChanged(['date_from', 'date_to', 'room_type_id']) && $o->getOriginal('date_from')) {
                    self::markDirtyInclusive((int) $o->getOriginal('room_type_id'), $o->getOriginal('date_from'), $o->getOriginal('date_to'));
                }
            });
            $cls::deleted($inclusive);
        }
        \App\Models\ChannelRestriction::saved(fn ($r) => self::restrictionDirty($r));
        \App\Models\ChannelRestriction::deleted(fn ($r) => self::restrictionDirty($r));
        $block = function ($b) {
            foreach ($b->lines()->pluck('room_type_id')->unique() as $t) {
                self::markDirty((int) $t, $b->arrival_date, $b->departure_date);
            }
        };
        \App\Models\RoomBlock::saved($block);
        \App\Models\RoomBlockLine::saved(fn ($l) => ($b = $l->block ?? \App\Models\RoomBlock::find($l->room_block_id)) ? self::markDirty((int) $l->room_type_id, $b->arrival_date, $b->departure_date) : null);
        \App\Models\RoomBlockLine::deleted(fn ($l) => ($b = \App\Models\RoomBlock::find($l->room_block_id)) ? self::markDirty((int) $l->room_type_id, $b->arrival_date, $b->departure_date) : null);
        \App\Models\RoomType::saved(fn ($t) => $t->wasChanged(['base_rate', 'is_active']) ? self::markAll() : null);
        \App\Models\RatePlan::saved(fn ($p) => $p->wasChanged(['rate', 'is_active']) || $p->wasRecentlyCreated ? self::markAll() : null);
        \App\Models\Room::saved(function ($r) {
            $oos = fn ($s) => in_array($s, ['out_of_order', 'out_of_service'], true);
            if ($r->wasChanged(['is_active', 'room_type_id']) || ($r->wasChanged('status') && $oos($r->status) !== $oos($r->getOriginal('status')))) {
                self::markAll();
            }
        });
        \App\Models\RateContract::saved(fn ($c) => $c->wasChanged('status') ? self::markAll() : null);
        foreach ([\App\Models\ChannelRoomMap::class, \App\Models\ChannelRateMap::class] as $cls) {
            $cls::saved(fn ($m) => ChannelConnection::whereKey($m->channel_connection_id)->update(['full_sync_due' => true]));
            $cls::deleted(fn ($m) => ChannelConnection::whereKey($m->channel_connection_id)->update(['full_sync_due' => true]));
        }
    }

    protected static function restrictionDirty($r): void
    {
        $types = $r->room_type_id ? [$r->room_type_id] : \App\Models\ChannelRoomMap::where('channel_connection_id', $r->channel_connection_id)->pluck('room_type_id')->all();
        foreach ($types as $t) {
            self::markDirtyInclusive((int) $t, $r->date_from, $r->date_to);
        }
    }

    // ------------------------------------------------------------------ ARI

    /** Channel sell price of a rate plan on a night: (PMS nightly rate + meal-plan supplement) × (1 + markup), rounded. */
    public static function price(ChannelConnection $c, RoomType $type, ?RatePlan $plan, float $nightly): float
    {
        $p = $nightly + ($plan ? max(0, (float) $plan->rate - (float) $type->base_rate) : 0);
        $p *= 1 + (float) $c->rate_markup_pct / 100;

        return match ($c->rate_rounding) {
            'unit' => (float) round($p),
            'ten' => (float) (round($p / 10) * 10),
            default => round($p, 2),
        };
    }

    /**
     * ARI for a connection: [typeId => [Y-m-d => code, available, stop_sell, min_stay, cta, rates{code: price}]].
     * $only limits the output to [typeId][date] pairs (delta pushes).
     */
    public static function ari(ChannelConnection $c, Carbon $from, int $days, ?array $only = null): array
    {
        $from = $from->copy()->startOfDay();
        $to = $from->copy()->addDays($days);
        $maps = $c->roomMaps()->where('is_active', true)->get()->keyBy('room_type_id');
        if ($maps->isEmpty() || $days < 1) {
            return [];
        }
        // A stable type order keeps ARI payloads reproducible across database engines.
        $types = RoomType::whereIn('id', $maps->keys())->where('is_active', true)->orderBy('id')->get()->keyBy('id');
        $rateMaps = $c->rateMaps()->where('is_active', true)->with('ratePlan')->orderBy('id')->get()->groupBy('room_type_id');
        $avail = AvailabilityService::matrix($from, $days)['matrix'];
        $cal = RateService::calendar($types->values(), $from, $days);
        $restr = $c->restrictions()->whereDate('date_to', '>=', $from)->whereDate('date_from', '<', $to)->get();
        $out = [];
        foreach ($types as $typeId => $type) {
            $map = $maps[$typeId];
            for ($d = $from->copy(); $d->lt($to); $d->addDay()) {
                $key = $d->toDateString();
                if ($only !== null && empty($only[$typeId][$key])) {
                    continue;
                }
                $r = $cal[$typeId][$key];
                $cr = $restr->filter(fn ($x) => ($x->room_type_id === null || $x->room_type_id === $typeId) && $x->date_from->lte($d) && $x->date_to->gte($d));
                $a = max(0, (int) ($avail[$typeId][$key] ?? 0));
                if ($map->max_sell !== null) {
                    $a = min($a, (int) $map->max_sell);
                }
                $rates = [];
                foreach ($rateMaps->get($typeId, collect()) as $rm) {
                    $rates[$rm->channel_rate_code] = self::price($c, $type, $rm->ratePlan, (float) $r['rate']);
                }
                $out[$typeId][$key] = ['code' => $map->channel_room_code, 'available' => $a, 'stop_sell' => $r['stop'] || $cr->contains('stop_sell', true),
                    'min_stay' => (int) max($r['min_stay'], (int) $cr->max('min_stay')), 'cta' => $cr->contains('closed_to_arrival', true), 'rates' => $rates, 'bar' => (float) $r['rate']];
            }
        }

        return $out;
    }

    /** The JSON document sent to the channel (Nowills Channel API v1). */
    public static function payload(ChannelConnection $c, array $ari, string $kind): array
    {
        // Room type IDs are the stable canonical order; SQL result order is otherwise undefined.
        ksort($ari, SORT_NUMERIC);
        $rooms = [];
        foreach ($ari as $byDate) {
            $first = reset($byDate);
            $rooms[] = ['room_code' => $first['code'], 'days' => array_values(array_map(fn ($v, $date) => [
                'date' => $date, 'available' => $v['available'], 'stop_sell' => $v['stop_sell'], 'min_stay' => $v['min_stay'], 'closed_to_arrival' => $v['cta'],
                'rates' => array_map(fn ($code, $price) => ['rate_code' => (string) $code, 'price' => $price], array_keys($v['rates']), $v['rates']),
            ], $byDate, array_keys($byDate)))];
        }

        return ['api_version' => '1.0', 'type' => 'ari_update', 'kind' => $kind, 'property_code' => $c->property_code ?: Tenant::hotel()?->code,
            'currency' => Tenant::hotel()?->currency ?: 'GHS', 'sent_at' => now()->toIso8601String(), 'rooms' => $rooms];
    }

    /** Split ARI into windows of CHUNK_DAYS nights (one request each). */
    protected static function chunks(array $ari): array
    {
        $out = [];
        foreach ($ari as $typeId => $byDate) {
            foreach ($byDate as $date => $v) {
                $w = intdiv(Carbon::parse($date)->diffInDays(bdate()->copy()->startOfDay(), true), self::CHUNK_DAYS);
                $out[$w][$typeId][$date] = $v;
            }
        }
        ksort($out);

        return array_values($out);
    }

    public static function sign(string $secret, string $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /** Constant-time check of an inbound signature ("sha256=<hex>" or "<hex>") with a ±5 minute timestamp window. */
    public static function verify(ChannelConnection $c, ?string $timestamp, ?string $signature, string $body): bool
    {
        if (! $timestamp || ! $signature || ! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::SIGNATURE_TOLERANCE) {
            return false;
        }
        $sig = str_starts_with($signature, 'sha256=') ? substr($signature, 7) : $signature;

        return hash_equals(self::sign($c->secret, $timestamp, $body), strtolower($sig));
    }

    /**
     * Push pending ARI changes (or a full refresh) to one connection. Returns the push log row, or null when there was
     * nothing to send.
     */
    /** The transport driver for this connection (hub / simulator / mirror / vendor adapters). */
    public static function driverFor(ChannelConnection $c): ChannelDriver
    {
        return ChannelDriverRegistry::for($c);
    }

    /** Probe the channel endpoint through its driver — no state changes. */
    public static function testConnection(ChannelConnection $c): array
    {
        $res = self::driverFor($c)->test($c);
        ActivityLogger::log('channels', $res['ok'] ? 'test_ok' : 'test_failed',
            'Connection test '.($res['ok'] ? 'OK' : 'failed').' ('.self::driverFor($c)->id().'): '.$res['message'], $c);

        return $res;
    }

    public static function push(ChannelConnection $c, bool $full = false, ?int $userId = null): ?ChannelPush
    {
        if (! $c->is_active) {
            return null;
        }
        $today = bdate()->copy()->startOfDay();
        $horizon = $today->copy()->addDays(max(1, (int) $c->push_days));
        $maxId = (int) DB::table('channel_dirty')->where('hotel_id', $c->hotel_id)->max('id');
        $full = $full || $c->full_sync_due;
        $only = null;
        if ($full) {
            $from = $today;
            $days = (int) $c->push_days;
        } else {
            $rows = DB::table('channel_dirty')->where('hotel_id', $c->hotel_id)->where('id', '>', $c->pushed_upto_id)->where('id', '<=', $maxId)
                ->where('date', '>=', $today->toDateString())->where('date', '<', $horizon->toDateString())->get(['room_type_id', 'date']);
            foreach ($rows as $r) {
                $only[$r->room_type_id][Carbon::parse($r->date)->toDateString()] = true;
            }
            if (! $only) {
                if ($maxId > $c->pushed_upto_id) {
                    $c->forceFill(['pushed_upto_id' => $maxId])->save();
                }

                return null;
            }
            $dates = $rows->pluck('date')->map(fn ($d) => Carbon::parse($d));
            $from = $dates->min()->copy()->startOfDay();
            $days = (int) $from->diffInDays($dates->max()) + 1;
        }
        $ari = self::ari($c, $from, $days, $only);
        $roomDays = array_sum(array_map('count', $ari));
        if (! $full && $roomDays === 0) {   // only unmapped room types changed
            $c->forceFill(['pushed_upto_id' => max($c->pushed_upto_id, $maxId)])->save();

            return null;
        }
        $kind = $full ? 'full' : 'delta';
        $t0 = microtime(true);
        $status = 'ok';
        $http = null;
        $error = null;
        $requests = 0;
        $chunks = self::chunks($ari);
        try {
            $result = self::driverFor($c)->pushAri($c, $chunks, $kind, fn (array $chunk) => self::saveStates($c, $chunk));
            $requests = $result['requests'];
            $http = $result['http'] ?? null;
        } catch (Throwable $e) {
            $status = 'failed';
            $error = mb_substr($e->getMessage(), 0, 500);
        }
        $attempt = $c->fail_count + 1;
        $to = $from->copy()->addDays($days - 1);
        if ($status === 'ok') {
            $c->forceFill(['pushed_upto_id' => max($c->pushed_upto_id, $maxId), 'full_sync_due' => false, 'fail_count' => 0, 'next_retry_at' => null,
                'last_push_ok' => true, 'last_push_at' => now(), 'last_push_status' => 'OK – '.($full ? 'full refresh, ' : '')."{$roomDays} room-night(s)"])->save();
        } else {
            $fails = $c->fail_count + 1;
            $c->forceFill(['fail_count' => $fails, 'next_retry_at' => now()->addMinutes(self::BACKOFF[min($fails, count(self::BACKOFF)) - 1]),
                'last_push_ok' => false, 'last_push_at' => now(), 'last_push_status' => mb_substr('ERROR – '.$error, 0, 255), 'full_sync_due' => $full ?: $c->full_sync_due])->save();
            ActivityLogger::log('channels', 'push_failed', "ARI push to {$c->name} failed (attempt {$attempt}): {$error}", $c);
        }
        $push = ChannelPush::create(['channel_connection_id' => $c->id, 'kind' => $kind, 'date_from' => $from, 'date_to' => $to, 'room_days' => $roomDays,
            'requests' => $requests, 'status' => $status, 'http_status' => $http, 'error' => $error, 'duration_ms' => (int) round((microtime(true) - $t0) * 1000),
            'attempt' => $attempt, 'user_id' => $userId]);
        if ($userId && $status === 'ok') {
            ActivityLogger::log('channels', 'push', "ARI {$kind} push to {$c->name}: {$roomDays} room-night(s)", $c);
        }

        return $push;
    }

    protected static function saveStates(ChannelConnection $c, array $ari): void
    {
        $rows = [];
        foreach ($ari as $typeId => $byDate) {
            foreach ($byDate as $date => $v) {
                $rows[] = ['hotel_id' => $c->hotel_id, 'channel_connection_id' => $c->id, 'room_type_id' => $typeId, 'date' => $date, 'available' => $v['available'],
                    'stop_sell' => $v['stop_sell'], 'min_stay' => $v['min_stay'], 'cta' => $v['cta'], 'rates' => json_encode($v['rates']), 'pushed_at' => now()];
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('channel_ari_states')->upsert($chunk, ['channel_connection_id', 'room_type_id', 'date'], ['available', 'stop_sell', 'min_stay', 'cta', 'rates', 'pushed_at']);
        }
    }

    /** Scheduler entry point: push every active connection that has changes (respecting retry back-off), then prune. */
    public static function pushDue(): array
    {
        $out = [];
        foreach (ChannelConnection::where('is_active', true)->orderBy('id')->get() as $c) {
            if ($c->next_retry_at && $c->next_retry_at->isFuture()) {
                continue;
            }
            if ($p = self::push($c)) {
                $out[] = $p;
            }
        }
        self::prune();

        return $out;
    }

    public static function prune(): void
    {
        $min = ChannelConnection::where('is_active', true)->min('pushed_upto_id');
        DB::table('channel_dirty')->where('hotel_id', Tenant::id())->where(fn ($q) => $q->where('date', '<', bdate()->toDateString())
            ->when($min !== null, fn ($w) => $w->orWhere('id', '<=', $min)))->delete();
        ChannelPush::where('created_at', '<', now()->subDays(90))->delete();
        ChannelAriState::where('date', '<', bdate()->toDateString())->delete();
    }

    /** Night audit: the horizon moved by a day and allotments may have released – full refresh everywhere. */
    public static function nightly(): void
    {
        ChannelConnection::where('is_active', true)->update(['full_sync_due' => true]);
        self::prune();
    }

    // ------------------------------------------------------------------ inbound reservations

    public static function rules(): array
    {
        return [
            'event' => 'required|in:new,modify,cancel',
            'booking_id' => 'required|string|max:60',
            'arrival' => 'exclude_if:event,cancel|required|date_format:Y-m-d',
            'departure' => 'exclude_if:event,cancel|required|date_format:Y-m-d|after:arrival',
            'guest' => 'exclude_if:event,cancel|required|array',
            'guest.first_name' => 'exclude_if:event,cancel|required|string|max:80',
            'guest.last_name' => 'nullable|string|max:80',
            'guest.email' => 'nullable|email|max:255',
            'guest.phone' => 'nullable|string|max:30',
            'guest.country' => 'nullable|string|max:80',
            'rooms' => 'exclude_if:event,cancel|required|array|min:1|max:10',
            'rooms.*.room_code' => 'required|string|max:40',
            'rooms.*.rate_code' => 'nullable|string|max:40',
            'rooms.*.adults' => 'required|integer|min:1|max:10',
            'rooms.*.children' => 'nullable|integer|min:0|max:10',
            'rooms.*.amount' => 'required|numeric|min:0|max:99999999',
            'total' => 'nullable|numeric|min:0|max:999999999',
            'currency' => 'nullable|string|size:3',
            'payment' => 'nullable|array',
            'payment.model' => 'nullable|in:hotel_collect,channel_collect',
            'commission' => 'nullable|numeric|min:0',
            'remarks' => 'nullable|string|max:1000',
        ];
    }

    /** Remove anything that looks like payment-card data before a payload is stored (PCI). */
    public static function sanitize(array $a): array
    {
        $out = [];
        foreach ($a as $k => $v) {
            if (is_string($k) && preg_match('/card|cvv|cvc|^pan$|expiry|security_code/i', $k)) {
                continue;
            }
            $out[$k] = is_array($v) ? self::sanitize($v) : $v;
        }

        return $out;
    }

    protected static function canonical(array $a): array
    {
        ksort($a);

        return array_map(fn ($v) => is_array($v) ? self::canonical($v) : $v, $a);
    }

    /** Entry point for the webhook and the simulator. Returns [http status, JSON body]. */
    public static function receive(ChannelConnection $c, array $payload): array
    {
        $clean = self::sanitize($payload);
        $hash = hash('sha256', json_encode(self::canonical($clean)));
        $dup = ChannelMessage::where('channel_connection_id', $c->id)->where('payload_hash', $hash)->first();
        if ($dup && $dup->status !== 'received') {
            if ($dup->status === 'failed') {
                return [422, ['status' => 'rejected', 'error' => $dup->error, 'message_id' => $dup->id]];
            }

            return [200, ['status' => 'duplicate', 'result' => $dup->status, 'message_id' => $dup->id, 'reservation_no' => $dup->reservation?->reservation_no]];
        }
        $v = Validator::make($payload, self::rules());
        try {
            $msg = $dup ?? ChannelMessage::create(['channel_connection_id' => $c->id, 'channel_ref' => mb_substr((string) ($payload['booking_id'] ?? '?'), 0, 60),
                'event' => in_array($payload['event'] ?? null, ['new', 'modify', 'cancel'], true) ? $payload['event'] : 'new', 'payload_hash' => $hash, 'payload' => $clean,
                'amount' => is_numeric($payload['total'] ?? null) ? $payload['total'] : null]);
        } catch (QueryException $e) {
            // Two deliveries of a new payload can race before either has
            // started processing. The unique payload hash is the idempotency
            // boundary; turn the loser into the same duplicate response.
            if (($e->errorInfo[0] ?? null) !== '23000') {
                throw $e;
            }
            $msg = ChannelMessage::where('channel_connection_id', $c->id)->where('payload_hash', $hash)->firstOrFail();
            if ($msg->status === 'failed') {
                return [422, ['status' => 'rejected', 'error' => $msg->error, 'message_id' => $msg->id]];
            }
            if ($msg->status !== 'received') {
                return [200, ['status' => 'duplicate', 'result' => $msg->status, 'message_id' => $msg->id, 'reservation_no' => $msg->reservation?->reservation_no]];
            }
        }
        if ($v->fails()) {
            $msg->update(['status' => 'failed', 'error' => mb_substr(implode(' ', $v->errors()->all()), 0, 500)]);

            return [422, ['status' => 'rejected', 'error' => $msg->error, 'errors' => $v->errors()->toArray(), 'message_id' => $msg->id]];
        }

        return self::process($msg);
    }

    /** Apply a stored message (first delivery or "reprocess" after fixing the mapping). */
    public static function process(ChannelMessage $msg, bool $reprocess = false): array
    {
        $c = $msg->connection;
        try {
            [$code, $body] = DB::transaction(function () use ($msg, $c, $reprocess) {
                // Serialize all messages for one channel connection. Without
                // this, two different OTA payloads for the same booking can
                // both observe "no reservation" and create competing stays.
                $lockedConnection = ChannelConnection::whereKey($c->id)->lockForUpdate()->firstOrFail();
                $lockedMessage = ChannelMessage::whereKey($msg->id)->lockForUpdate()->firstOrFail();
                if (! $reprocess && $lockedMessage->status !== 'received') {
                    return [200, ['status' => 'duplicate', 'result' => $lockedMessage->status, 'message_id' => $lockedMessage->id,
                        'reservation_no' => $lockedMessage->reservation?->reservation_no]];
                }
                $payload = $lockedMessage->payload;

                return $lockedMessage->event === 'cancel'
                    ? self::applyCancel($lockedConnection, $lockedMessage, $payload)
                    : self::applyBooking($lockedConnection, $lockedMessage, $payload);
            });
        } catch (DomainException $e) {
            $msg->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);

            return [422, ['status' => 'rejected', 'error' => $msg->error, 'message_id' => $msg->id]];
        }
        $c->forceFill(['last_booking_at' => now()])->save();

        return [$code, $body + ['message_id' => $msg->id]];
    }

    protected static function findReservation(ChannelConnection $c, string $ref): ?Reservation
    {
        // Channel application runs inside the per-connection transaction. Lock
        // an existing reservation before inspecting it so a cancellation,
        // check-in, or desk amendment cannot be overwritten by a stale OTA
        // message after its initial read.
        return Reservation::where('channel_connection_id', $c->id)->where('ota_reference', $ref)->orderByDesc('id')->lockForUpdate()->first();
    }

    protected static function attention(ChannelMessage $msg, string $why, string $error, ?Reservation $res = null): array
    {
        $msg->update(['status' => 'attention', 'attention' => $why, 'error' => mb_substr($error, 0, 500), 'reservation_id' => $res?->id ?? $msg->reservation_id]);
        ActivityLogger::log('channels', 'attention', "{$msg->connection->name} {$msg->channel_ref}: {$error}", $msg);

        return [202, ['status' => 'attention', 'reason' => $why, 'error' => $error, 'reservation_no' => $res?->reservation_no]];
    }

    protected static function applyBooking(ChannelConnection $c, ChannelMessage $msg, array $p): array
    {
        $ref = (string) $p['booking_id'];
        $res = self::findReservation($c, $ref);
        $warnings = [];
        if ($res && $res->status === 'cancelled') {
            if ($msg->event === 'modify') {
                return self::attention($msg, 'cancelled_booking', "Modification received for {$res->reservation_no}, which is already cancelled.", $res);
            }
            $res = null;   // re-booked under the same channel reference: new reservation
        }
        if (! $res && $msg->event === 'modify') {
            $warnings[] = 'Modification for a booking the PMS did not have – created as a new reservation.';
        }
        if ($res && $msg->event === 'new') {
            $warnings[] = "Booking already existed ({$res->reservation_no}) – applied as a modification.";
        }
        if ($res && (in_array($res->status, ['checked_in', 'checked_out', 'no_show'], true) || $res->rooms()->whereIn('status', ['checked_in', 'checked_out'])->exists())) {
            return self::attention($msg, 'modify_inhouse', "Modification for {$res->reservation_no}, which is already in-house / departed – amend the stay manually.", $res);
        }
        $maps = $c->roomMaps()->where('is_active', true)->get()->keyBy(fn ($m) => mb_strtoupper($m->channel_room_code));
        $unmapped = collect($p['rooms'])->pluck('room_code')->map(fn ($x) => mb_strtoupper($x))->reject(fn ($x) => $maps->has($x))->unique();
        if ($unmapped->isNotEmpty()) {
            return self::attention($msg, 'unmapped_room', 'Room code(s) '.$unmapped->implode(', ').' are not mapped to a room type – map them, then reprocess.', $res);
        }
        $hotel = Tenant::hotel();
        if (! empty($p['currency']) && strtoupper($p['currency']) !== strtoupper($hotel->currency ?: 'GHS')) {
            throw new DomainException("Currency {$p['currency']} does not match the hotel currency {$hotel->currency}.");
        }
        $arr = Carbon::parse($p['arrival'])->startOfDay();
        $dep = Carbon::parse($p['departure'])->startOfDay();
        $nights = (int) $arr->diffInDays($dep);
        if ($nights > 60) {
            throw new DomainException('Stays longer than 60 nights cannot be booked through a channel.');
        }
        if (! $res && $arr->lt(bdate()->copy()->startOfDay())) {
            throw new DomainException('Arrival date '.$arr->toDateString().' is before the hotel business date '.bdate()->toDateString().'.');
        }
        $rateMaps = ChannelRateMap::where('channel_connection_id', $c->id)->where('is_active', true)->with('ratePlan')->get();
        $types = RoomType::whereIn('id', $maps->pluck('room_type_id'))->get()->keyBy('id');
        $lines = collect($p['rooms'])->map(function ($l) use ($maps, $rateMaps, $types) {
            $typeId = $maps[mb_strtoupper($l['room_code'])]->room_type_id;
            $rm = ! empty($l['rate_code']) ? $rateMaps->first(fn ($m) => $m->room_type_id === $typeId && mb_strtoupper($m->channel_rate_code) === mb_strtoupper($l['rate_code'])) : null;

            return ['type' => $types[$typeId], 'rate_map' => $rm, 'adults' => (int) $l['adults'], 'children' => (int) ($l['children'] ?? 0),
                'amount' => round((float) $l['amount'], 2), 'room_code' => $l['room_code'], 'rate_code' => $l['rate_code'] ?? null];
        });
        // informational rate check against what we are publishing (before this booking moves occupancy-based prices)
        foreach ($lines as $l) {
            $expected = round(collect(RateService::quote($l['type'], $arr, $dep)['nights'])->sum(fn ($n) => self::price($c, $l['type'], $l['rate_map']?->ratePlan, (float) $n['rate'])), 2);
            if (abs($l['amount'] - $expected) > max(1, $expected * 0.01)) {
                $warnings[] = "Rate mismatch {$l['room_code']}".($l['rate_code'] ? "/{$l['rate_code']}" : '').': channel sent '.money($l['amount']).', PMS publishes '.money($expected).'.';
            }
            if ($l['rate_code'] && ! $l['rate_map']) {
                $warnings[] = "Rate code {$l['rate_code']} is not mapped – booked without a rate plan.";
            }
        }
        if ($res) {
            foreach ($res->rooms()->where('status', 'reserved')->get() as $old) {
                $old->delete();
            }
        }
        // OTA bookings may be accepted as explicit overbookings, but the
        // inventory snapshot and normal reservation writers still need one
        // physical-row lock domain while this message is applied.
        AvailabilityService::lockRoomInventory($lines->pluck('type.id')->all());
        $short = [];
        foreach ($lines->groupBy(fn ($l) => $l['type']->id) as $typeId => $group) {
            $free = AvailabilityService::typeAvailable((int) $typeId, $arr, $dep);
            if ($free < $group->count()) {
                $short[] = $types[$typeId]->name.' (booked '.$group->count().', free '.max(0, $free).')';
            }
        }
        $g = $p['guest'];
        $total = round((float) ($p['total'] ?? $lines->sum('amount')), 2);
        $model = $p['payment']['model'] ?? $c->payment_model;
        $commission = isset($p['commission']) ? round((float) $p['commission'], 2) : round($total * (float) $c->commission_pct / 100, 2);
        if ($res) {
            $guest = $res->guest;
            $guest->update(array_filter(['first_name' => $g['first_name'], 'last_name' => $g['last_name'] ?? null, 'email' => $g['email'] ?? null,
                'phone' => $g['phone'] ?? null, 'country' => $g['country'] ?? null], fn ($x) => $x !== null && $x !== ''));
        } else {
            $guest = (! empty($g['email']) ? Guest::where('email', $g['email'])->where('first_name', $g['first_name'])->first() : null)
                ?? Guest::create(['first_name' => $g['first_name'], 'last_name' => $g['last_name'] ?? null, 'email' => $g['email'] ?? null, 'phone' => $g['phone'] ?? null,
                    'country' => $g['country'] ?? null, 'company_id' => null, 'notes' => "Created from {$c->name} booking {$ref}"]);
        }
        $data = ['guest_id' => $guest->id, 'company_id' => $c->company_id, 'business_source_id' => $c->business_source_id, 'arrival_date' => $arr->toDateString(),
            'departure_date' => $dep->toDateString(), 'adults' => $lines->sum('adults'), 'children' => $lines->sum('children'), 'status' => 'confirmed',
            'ota_reference' => $ref, 'channel_connection_id' => $c->id, 'channel_payment' => $model, 'channel_amount' => $total, 'channel_commission' => $commission,
            'billing_instruction' => $model === 'channel_collect' && $c->company_id ? 'company' : 'guest',
            'special_request' => ! empty($p['remarks']) ? mb_substr($p['remarks'], 0, 1000) : ($res?->special_request), 'channel_attention' => $short ? 'overbooked' : null];
        $isNew = ! $res;
        if ($res) {
            $res->update($data);
        } else {
            $res = Reservation::create($data + ['reservation_no' => Numbering::next('reservation'), 'purpose' => 'Leisure', 'booking_type' => 'individual']);
        }
        foreach ($lines as $l) {
            $res->rooms()->create(['room_type_id' => $l['type']->id, 'rate_plan_id' => $l['rate_map']?->rate_plan_id, 'guest_id' => $guest->id,
                'arrival_date' => $arr->toDateString(), 'departure_date' => $dep->toDateString(), 'rate' => round($l['amount'] / max(1, $nights), 2),
                'adults' => $l['adults'], 'children' => $l['children'], 'status' => 'reserved']);
        }
        $msg->update(['status' => $short ? 'attention' : 'processed', 'attention' => $short ? 'overbooked' : null, 'reservation_id' => $res->id, 'amount' => $total,
            'warnings' => $warnings ?: null, 'error' => $short ? 'No inventory – '.implode('; ', $short).'. The booking was accepted (OTA bookings are guaranteed): find a room or relocate the guest.' : null]);
        ActivityLogger::log('channels', $isNew ? 'booking' : 'modify', "{$c->name} ".($isNew ? 'booking' : 'modification')." {$ref} → {$res->reservation_no} ({$arr->format('d M')} – {$dep->format('d M')}, ".money($total).')'.($short ? ' – OVERBOOKED' : ''), $res);

        return [$short ? 202 : 200, ['status' => $short ? 'attention' : 'accepted', 'reservation_no' => $res->reservation_no, 'overbooked' => (bool) $short, 'warnings' => $warnings]
            + ($short ? ['reason' => 'overbooked', 'error' => $res->reservation_no.' accepted with no inventory ('.implode('; ', $short).') – find a room or relocate the guest.'] : [])];
    }

    protected static function applyCancel(ChannelConnection $c, ChannelMessage $msg, array $p): array
    {
        $res = self::findReservation($c, (string) $p['booking_id']);
        if (! $res || $res->status === 'cancelled') {
            $w = [$res ? "{$res->reservation_no} was already cancelled." : 'Unknown booking – nothing to cancel.'];
            $msg->update(['status' => 'processed', 'reservation_id' => $res?->id, 'warnings' => $w]);

            return [200, ['status' => 'accepted', 'reservation_no' => $res?->reservation_no, 'warnings' => $w]];
        }
        if ($res->rooms()->whereIn('status', ['checked_in', 'checked_out'])->exists()) {
            return self::attention($msg, 'cancel_inhouse', "Cancellation for {$res->reservation_no}, which is already in-house / departed – settle with the channel manually.", $res);
        }
        foreach ($res->rooms()->where('status', 'reserved')->get() as $s) {
            $s->update(['status' => 'cancelled']);
        }
        $res->update(['status' => 'cancelled', 'cancel_reason' => mb_substr("Cancelled on {$c->name}".(! empty($p['remarks']) ? ': '.$p['remarks'] : ''), 0, 255), 'cancelled_at' => now(), 'channel_attention' => null]);
        ChannelMessage::where('reservation_id', $res->id)->where('status', 'attention')->where('attention', 'overbooked')
            ->update(['status' => 'processed', 'resolution' => 'Booking cancelled by the channel', 'resolved_at' => now()]);
        $msg->update(['status' => 'processed', 'reservation_id' => $res->id]);
        ActivityLogger::log('channels', 'cancel', "{$c->name} cancellation {$res->ota_reference} → {$res->reservation_no}", $res);

        return [200, ['status' => 'accepted', 'reservation_no' => $res->reservation_no, 'cancelled' => true]];
    }

    /** Front office handled it (room found / guest relocated / stay amended manually). */
    public static function resolve(ChannelMessage $m, string $note, ?int $userId): void
    {
        DB::transaction(function () use ($m, $note, $userId) {
            $m->update(['status' => $m->reservation_id ? 'processed' : 'dismissed', 'resolution' => mb_substr($note, 0, 255), 'resolved_by' => $userId, 'resolved_at' => now()]);
            if ($m->reservation_id && $m->attention === 'overbooked') {
                Reservation::whereKey($m->reservation_id)->update(['channel_attention' => null]);
            }
            ActivityLogger::log('channels', 'resolve', "Resolved {$m->connection->name} {$m->channel_ref}: {$note}", $m);
        });
    }

    public static function resolveReservation(Reservation $r, string $note, ?int $userId): void
    {
        DB::transaction(function () use ($r, $note, $userId) {
            $r->update(['channel_attention' => null]);
            ChannelMessage::where('reservation_id', $r->id)->where('status', 'attention')->where('attention', 'overbooked')
                ->update(['status' => 'processed', 'resolution' => mb_substr($note, 0, 255), 'resolved_by' => $userId, 'resolved_at' => now()]);
            ActivityLogger::log('channels', 'resolve', "Overbooking on {$r->reservation_no} resolved: {$note}", $r);
        });
    }

    /**
     * v7.42 Outbound cancellation — the other half of the two-way loop.
     *
     * Every driver implements cancelBooking(), but nothing called it: a reservation cancelled at the desk stayed live
     * at the OTA, so the room remained sold on the channel and the guest could still arrive holding a confirmation.
     * Call this AFTER the PMS cancellation has committed (CancellationService::cancel does exactly that). It never
     * throws and never rolls the cancellation back — a channel that refuses is written to the activity log and flagged
     * on channel_attention so the desk cancels it at the channel by hand.
     *
     * A partial cancellation (one room of many) is deliberately not sent: the booking still stands at the channel.
     * A no-show is not sent either — the OTA booking stands and its retention fee is claimed here.
     *
     * @return array{sent: bool, message: string}|null  null when this booking did not come from a live channel
     */
    public static function cancelAtChannel(Reservation $res): ?array
    {
        $c = $res->channel;
        $ref = trim((string) $res->ota_reference);
        if (! $c || ! $c->is_active || $ref === '' || $res->status !== 'cancelled') {
            return null;
        }
        try {
            $out = self::driverFor($c)->cancelBooking($c, $ref);
        } catch (\Throwable $e) {
            report($e);
            $out = ['ok' => false, 'message' => get_class($e).': '.$e->getMessage()];
        }
        $sent = (bool) ($out['ok'] ?? false);
        $message = mb_substr(trim((string) ($out['message'] ?? '')), 0, 200);
        ActivityLogger::log('channels', $sent ? 'cancel_sent' : 'cancel_failed',
            "{$c->name} {$ref} ({$res->reservation_no}): ".($message !== '' ? $message : ($sent ? 'cancelled at the channel.' : 'the channel did not answer.')), $res);

        // Only ever touch our own flag: channel_attention also carries overbooking warnings, which must survive.
        $flag = "Cancellation was not delivered to {$c->name} – cancel {$ref} at the channel by hand.";
        $current = (string) $res->channel_attention;
        if (! $sent) {
            $res->forceFill(['channel_attention' => $flag])->save();
        } elseif ($current !== '' && str_contains($current, 'Cancellation was not delivered')) {
            $res->forceFill(['channel_attention' => null])->save();
        }

        return ['sent' => $sent, 'message' => $message];
    }

    // ------------------------------------------------------------------ simulator & stats

    /** Build a realistic channel message for the built-in test channel and feed it through the normal inbound path. */
    /**
     * v7.39 Pull-mode sync (Booking.com EPS and other pull adapters): fetch the
     * channel's new / modified / cancelled bookings and feed each one through
     * receive() so the mapping pipeline applies it idempotently.
     */
    public static function pull(ChannelConnection $c): array
    {
        $since = $c->last_booking_at ?: now()->subDays(30);
        $rows = self::driverFor($c)->pullBookings($c, $since);
        $summary = ['pulled' => count($rows), 'applied' => 0, 'duplicates' => 0, 'rejected' => 0, 'reservations' => []];
        foreach ($rows as $row) {
            [$code, $body] = self::receive($c, self::canonicalRow($row));
            if ($code === 200 && ($body['status'] ?? '') === 'duplicate') {
                $summary['duplicates']++;
            } elseif ($code === 200) {
                $summary['applied']++;
                if (! empty($body['reservation_no'])) {
                    $summary['reservations'][] = $body['reservation_no'];
                }
            } else {
                $summary['rejected']++;
            }
        }

        return $summary;
    }

    /** Raw driver rows → the canonical message shape receive() validates. Canonical rows pass through untouched. */
    public static function canonicalRow(array $row): array
    {
        if (isset($row['event'], $row['booking_id'], $row['guest'])) {
            return $row;
        }
        $name = trim((string) ($row['guest'] ?? ''));
        $parts = preg_split('/\s+/', $name, 2) ?: ['Guest'];

        return [
            'event' => in_array($row['event'] ?? null, ['new', 'modify', 'cancel'], true) ? $row['event'] : 'new',
            'booking_id' => (string) ($row['ref'] ?? $row['booking_id'] ?? ''),
            'arrival' => $row['arrival'] ?? null,
            'departure' => $row['departure'] ?? null,
            'currency' => $row['currency'] ?? (Tenant::hotel()?->currency ?: 'GHS'),
            'guest' => ['first_name' => $parts[0], 'last_name' => $parts[1] ?? null,
                'email' => $row['email'] ?? null, 'phone' => $row['phone'] ?? null, 'country' => $row['country'] ?? null],
            'rooms' => [[
                'room_code' => (string) ($row['room_type'] ?? $row['room_code'] ?? ''),
                'rate_code' => $row['rate_code'] ?? null,
                'adults' => (int) ($row['adults'] ?? 2),
                'children' => (int) ($row['children'] ?? 0),
                'amount' => (float) ($row['rate'] ?? $row['amount'] ?? 0),
            ]],
            'total' => $row['rate'] ?? $row['total'] ?? null,
        ];
    }

    public static function simulate(ChannelConnection $c, array $in): array
    {
        if ($in['event'] === 'cancel') {
            return self::receive($c, ['event' => 'cancel', 'booking_id' => $in['booking_id'], 'remarks' => 'Guest cancelled (simulator)']);
        }
        $map = $c->roomMaps()->with('roomType')->findOrFail($in['room_map_id']);
        $rm = ChannelRateMap::where('channel_connection_id', $c->id)->where('room_type_id', $map->room_type_id)->where('is_active', true)
            ->when($in['rate_code'] ?? null, fn ($q, $code) => $q->where('channel_rate_code', $code))->with('ratePlan')->orderBy('id')->first();
        $quote = RateService::quote($map->roomType, $in['arrival'], $in['departure']);
        $amount = round(collect($quote['nights'])->sum(fn ($n) => self::price($c, $map->roomType, $rm?->ratePlan, (float) $n['rate'])), 2);
        $rooms = max(1, (int) ($in['rooms'] ?? 1));
        $payload = ['event' => $in['event'], 'booking_id' => $in['booking_id'] ?: $c->code.'-'.random_int(10000000, 99999999),
            'arrival' => $in['arrival'], 'departure' => $in['departure'], 'currency' => Tenant::hotel()->currency ?: 'GHS',
            'guest' => ['first_name' => $in['first_name'], 'last_name' => $in['last_name'] ?? null, 'email' => $in['email'] ?? null, 'phone' => $in['phone'] ?? null, 'country' => $in['country'] ?? 'India'],
            'rooms' => array_fill(0, $rooms, ['room_code' => $map->channel_room_code, 'rate_code' => $rm?->channel_rate_code, 'adults' => (int) $in['adults'], 'children' => 0, 'amount' => $amount]),
            'total' => round($amount * $rooms, 2), 'payment' => ['model' => $c->payment_model], 'remarks' => $in['remarks'] ?? null, 'created_at' => now()->toIso8601String()];

        return self::receive($c, $payload);
    }

    /** Per-connection production over the last N days (by booking date): bookings, nights, revenue, commission, cancellations. */
    public static function stats(int $days = 30): Collection
    {
        $since = now()->subDays($days);
        $rows = Reservation::whereNotNull('channel_connection_id')->where('created_at', '>=', $since)
            ->get(['id', 'channel_connection_id', 'status', 'arrival_date', 'departure_date', 'channel_amount', 'channel_commission'])->groupBy('channel_connection_id');

        return $rows->map(function ($g) {
            $live = $g->where('status', '!=', 'cancelled');

            return ['bookings' => $live->count(), 'nights' => $live->sum(fn ($r) => $r->arrival_date->diffInDays($r->departure_date)),
                'revenue' => round((float) $live->sum('channel_amount'), 2), 'commission' => round((float) $live->sum('channel_commission'), 2),
                'cancelled' => $g->where('status', 'cancelled')->count()];
        });
    }

    /** Needs-attention count (sidebar badge, dashboard alert). */
    public static function attentionCount(): int
    {
        return ChannelMessage::where('status', 'attention')->count() + ChannelMessage::where('status', 'failed')->where('created_at', '>=', now()->subDays(7))->count();
    }

    /**
     * Parity grid for one connection: [typeId][date] => expected (now) vs acknowledged (last push) values and the direct price.
     */
    public static function parity(ChannelConnection $c, Carbon $from, int $days): array
    {
        $expected = self::ari($c, $from, $days);
        $states = ChannelAriState::where('channel_connection_id', $c->id)->whereBetween('date', [$from->toDateString(), $from->copy()->addDays($days - 1)->toDateString()])
            ->get()->groupBy('room_type_id')->map(fn ($g) => $g->keyBy(fn ($s) => $s->date->toDateString()));
        $out = [];
        foreach ($expected as $typeId => $byDate) {
            foreach ($byDate as $date => $e) {
                $s = $states[$typeId][$date] ?? null;
                $has = $s ? ['available' => $s->available, 'stop_sell' => $s->stop_sell, 'min_stay' => $s->min_stay, 'cta' => $s->cta, 'rates' => $s->rates ?? []] : null;
                $diff = ! $has || $has['available'] !== $e['available'] || $has['stop_sell'] !== $e['stop_sell'] || $has['min_stay'] !== $e['min_stay'] || $has['cta'] !== $e['cta']
                    || collect($e['rates'])->contains(fn ($p, $code) => ! isset($has['rates'][$code]) || abs((float) $has['rates'][$code] - $p) > 0.004);
                $out[$typeId][$date] = ['expected' => $e, 'has' => $has, 'out_of_sync' => $diff, 'pushed_at' => $s?->pushed_at];
            }
        }

        return $out;
    }
}
