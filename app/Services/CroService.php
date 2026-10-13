<?php

namespace App\Services;

use App\Models\BdCompetitor;
use App\Models\BusinessSource;
use App\Models\CroContact;
use App\Models\CroEnquiry;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\User;
use App\Support\Numbering;
use App\Support\Tenant;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * v6.4 Central reservations & lost business.
 *  – Group availability: one search across every hotel of the group the agent may sell (room types, free rooms, rates,
 *    stop-sell / minimum stay / occupancy), and booking straight into any of them.
 *  – Enquiry log: every request for rooms is logged and closed as booked, referred to a sister hotel, or lost – a denial
 *    (we could not offer) or a regret (the guest declined) with a reason, the value at stake and the competitor.
 *  – Referrals: a turnaway booked at a sister hotel is kept in the group; both hotels get a linked enquiry.
 *  – KPIs: conversion, retention, booked / lost revenue per agent and channel, and the unconstrained demand by stay date
 *    (on the books + denied room nights) for revenue management.
 * Sister-hotel work runs inside withHotel(), which switches the tenant for the duration of the call and always restores it.
 */
class CroService
{
    protected static array $memo = [];

    public const DEFAULTS = ['cro_follow_up_hours' => '24', 'cro_expire_days' => '14', 'cro_conversion_target' => '40'];

    public static function flush(): void
    {
        self::$memo = [];
    }

    public static function ensureDefaults(): void
    {
        foreach (self::DEFAULTS as $k => $v) {
            Setting::firstOrCreate(['key' => $k], ['value' => $v]);
        }
        Setting::$version++;
        self::flush();
    }

    public static function cfg(): array
    {
        return self::$memo['cfg'.Tenant::id()] ??= [
            'follow_up_hours' => (int) setting('cro_follow_up_hours', '24'),
            'expire_days' => (int) setting('cro_expire_days', '14'),
            'conversion_target' => (float) setting('cro_conversion_target', '40'),
        ];
    }

    public static function saveSettings(array $d): void
    {
        $f = (int) ($d['follow_up_hours'] ?? 0);
        self::need($f >= 1 && $f <= 168, 'The default follow-up is between 1 and 168 hours.');
        $e = (int) ($d['expire_days'] ?? 0);
        self::need($e >= 1 && $e <= 90, 'Open enquiries lapse after 1 to 90 days.');
        $t = $d['conversion_target'] ?? null;
        self::need(is_numeric($t) && $t >= 1 && $t <= 100, 'The conversion target is between 1 and 100 %.');
        foreach (['cro_follow_up_hours' => (string) $f, 'cro_expire_days' => (string) $e, 'cro_conversion_target' => (string) round((float) $t, 1)] as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        }
        Setting::$version++;
        self::flush();
        ActivityLogger::log('cro', 'settings', 'Central reservations settings updated');
    }

    // ------------------------------------------------------------------ access

    public static function canBook(?User $u): bool
    {
        return $u && ($u->hasPermission('cro.book') || $u->hasPermission('cro.manage'));
    }

    public static function canManage(?User $u): bool
    {
        return (bool) $u?->hasPermission('cro.manage');
    }

    protected static function need(bool $ok, string $msg = 'You do not have permission to do that.'): void
    {
        if (! $ok) {
            throw new RuntimeException($msg);
        }
    }

    /**
     * Hotels this user may search and sell: the whole group when the user belongs to the current hotel's group (a group
     * CRO agent or group administrator), otherwise only the current hotel. The current hotel is always first.
     */
    public static function groupHotels(?User $u): Collection
    {
        $cur = Tenant::hotel();
        if (! $cur) {
            return collect();
        }
        if ($u && $u->hotel_group_id && (int) $cur->hotel_group_id === (int) $u->hotel_group_id) {
            $all = Hotel::where('hotel_group_id', $u->hotel_group_id)->where('is_active', true)->orderBy('name')->get();

            return $all->sortBy(fn ($h) => $h->id === $cur->id ? 0 : 1)->values();
        }

        return collect([$cur]);
    }

    public static function isGroupAgent(?User $u): bool
    {
        return self::groupHotels($u)->count() > 1;
    }

    /** A hotel of the user's selling scope – anything else is refused (no cross-group or deactivated properties). */
    public static function hotelFor($id, ?User $u): Hotel
    {
        $h = self::groupHotels($u)->firstWhere('id', (int) $id);
        self::need((bool) $h, 'That property is not in your central reservations scope.');

        return $h;
    }

    /** Run $fn with $hotel as the tenant; the previous tenant is restored even if $fn throws. */
    public static function withHotel(Hotel $hotel, callable $fn)
    {
        $prev = Tenant::hotel();
        if ($prev && $prev->id === $hotel->id) {
            return $fn();
        }
        Tenant::set($hotel);
        self::flush();
        try {
            return $fn();
        } finally {
            Tenant::set($prev);
            self::flush();
        }
    }

    // ------------------------------------------------------------------ group availability

    /** Validated stay request: [arrival, departure, nights, rooms, adults, children]. */
    public static function stay(array $q): array
    {
        $a = ! empty($q['arrival']) ? rescue(fn () => Carbon::parse((string) $q['arrival'])->startOfDay(), null, false) : null;
        $dep = ! empty($q['departure']) ? rescue(fn () => Carbon::parse((string) $q['departure'])->startOfDay(), null, false) : null;
        self::need($a && $dep, 'Enter the arrival and departure dates.');
        $n = (int) $a->diffInDays($dep, false);
        self::need($n >= 1, 'The departure must be after the arrival.');
        self::need($n <= 30, 'Central reservations quotes stays of up to 30 nights – use a group block or a long-stay lease beyond that.');
        $rooms = max(1, (int) ($q['rooms'] ?? 1));
        self::need($rooms <= 20, 'Up to 20 rooms per booking – larger parties are a group block.');
        $adults = max(1, (int) ($q['adults'] ?? $rooms));
        self::need($adults >= $rooms, 'At least one adult per room.');

        return [$a, $dep, $n, $rooms, $adults, max(0, (int) ($q['children'] ?? 0))];
    }

    /**
     * Availability and price of every room type in every hotel of the agent's scope for one stay.
     * [ ['hotel', 'bdate', 'error', 'types' => [ ['type', 'free', 'total', 'average', 'bookable', 'reason'] ], 'best', 'free'] ]
     */
    public static function search(array $q, ?User $u): array
    {
        [$a, $dep, $n, $rooms, $adults] = self::stay($q);
        $perRoom = (int) ceil($adults / $rooms);
        $out = [];
        foreach (self::groupHotels($u) as $h) {
            $out[] = self::withHotel($h, function () use ($h, $a, $dep, $n, $rooms, $perRoom) {
                $b = Carbon::parse($h->business_date)->startOfDay();
                $row = ['hotel' => $h, 'bdate' => $b, 'error' => null, 'types' => [], 'best' => null, 'free' => 0];
                if ($a->lt($b)) {
                    $row['error'] = 'Arrival is before this hotel\'s business date ('.$b->format('d M Y').').';

                    return $row;
                }
                $m = AvailabilityService::matrix($a->copy(), $n);
                foreach ($m['types']->sortBy('base_rate') as $t) {
                    $free = empty($m['matrix'][$t->id]) ? 0 : max(0, (int) min($m['matrix'][$t->id]));
                    $quote = RateService::quote($t, $a->toDateString(), $dep->toDateString());
                    $reason = match (true) {
                        $free < 1 => 'Sold out',
                        $free < $rooms => "Only {$free} left",
                        $quote['stop_sell'] => 'Closed for sale',
                        ! $quote['min_stay_ok'] => "Minimum stay {$quote['min_stay']} nights",
                        $t->max_adults && $perRoom > $t->max_adults => "Sleeps {$t->max_adults} adults",
                        default => null,
                    };
                    $row['types'][] = ['type' => $t, 'free' => $free, 'total' => round($quote['total'] * $rooms, 2), 'average' => $quote['average'], 'bookable' => $reason === null, 'reason' => $reason,
                        'denial' => match (true) { $free < $rooms => 'sold_out', $quote['stop_sell'] || ! $quote['min_stay_ok'] => 'restriction', $reason !== null => 'occupancy', default => null }];
                    $row['free'] += $free;
                }
                $ok = collect($row['types'])->where('bookable', true);
                $row['best'] = $ok->isEmpty() ? null : $ok->sortBy('average')->first();

                return $row;
            });
        }

        return $out;
    }

    // ------------------------------------------------------------------ enquiries

    protected static function nights(Carbon $a, Carbon $d): int
    {
        return max(1, (int) $a->diffInDays($d));
    }

    protected static function reasonType(string $reason): ?string
    {
        return array_key_exists($reason, CroEnquiry::DENIALS) ? 'denial' : (array_key_exists($reason, CroEnquiry::REGRETS) ? 'regret' : null);
    }

    /**
     * Log an enquiry for the current hotel. outcome 'open' (follow-up due) or 'lost' (reason required). Bookings come
     * through book(), which creates / closes the enquiry itself.
     */
    public static function logEnquiry(array $d, User $u): CroEnquiry
    {
        self::need(self::canBook($u), 'You cannot log reservations enquiries.');
        [$a, $dep, $n, $rooms, $adults, $children] = self::stay($d);
        self::need($a->gte(bdate()->copy()->startOfDay()), 'The stay asked for has already started – enquiries are for arrivals from today\'s business date.');
        $name = trim((string) ($d['caller_name'] ?? ''));
        self::need($name !== '', 'Enter the caller\'s name.');
        $channel = array_key_exists($d['channel'] ?? '', CroEnquiry::CHANNELS) && ($d['channel'] ?? '') !== 'referral' ? $d['channel'] : 'phone';
        $type = ! empty($d['room_type_id']) ? RoomType::find((int) $d['room_type_id']) : null;
        self::need(empty($d['room_type_id']) || $type, 'Choose a room type of this hotel.');
        $rate = ($d['quoted_rate'] ?? '') === '' || $d['quoted_rate'] === null ? null : round((float) $d['quoted_rate'], 2);
        self::need($rate === null || $rate >= 0, 'The quoted rate cannot be negative.');
        if ($rate === null && $type) {
            $rate = RateService::quote($type, $a->toDateString(), $dep->toDateString())['average'];
        }
        $outcome = ($d['outcome'] ?? 'open') === 'lost' ? 'lost' : 'open';
        $reason = (string) ($d['loss_reason'] ?? '');
        if ($outcome === 'lost') {
            self::need(self::reasonType($reason) !== null, 'Choose why the enquiry was lost.');
        }
        $comp = self::competitor($d, $reason);
        $follow = null;
        if ($outcome === 'open') {
            $follow = ! empty($d['follow_up_at']) ? rescue(fn () => Carbon::parse($d['follow_up_at']), null, false) : null;
            $follow ??= now()->addHours(self::cfg()['follow_up_hours']);
        }
        $e = DB::transaction(fn () => CroEnquiry::create([
            'number' => Numbering::next('cro_enquiry'), 'channel' => $channel, 'caller_name' => mb_substr($name, 0, 120),
            'phone' => ($d['phone'] ?? null) ?: null, 'email' => ($d['email'] ?? null) ?: null,
            'guest_id' => ! empty($d['guest_id']) && Guest::whereKey((int) $d['guest_id'])->exists() ? (int) $d['guest_id'] : null,
            'company_id' => ! empty($d['company_id']) && \App\Models\Company::whereKey((int) $d['company_id'])->exists() ? (int) $d['company_id'] : null,
            'arrival_date' => $a->toDateString(), 'departure_date' => $dep->toDateString(), 'rooms' => $rooms, 'adults' => $adults, 'children' => $children,
            'room_type_id' => $type?->id, 'quoted_rate' => $rate, 'quoted_total' => $rate !== null ? round($rate * $n * $rooms, 2) : null,
            'status' => $outcome, 'loss_type' => $outcome === 'lost' ? self::reasonType($reason) : null, 'loss_reason' => $outcome === 'lost' ? $reason : null,
            'competitor_id' => $outcome === 'lost' ? $comp : null, 'loss_note' => $outcome === 'lost' ? (($d['loss_note'] ?? null) ?: null) : null,
            'duration_sec' => ! empty($d['duration_min']) ? min(65535, (int) round((float) $d['duration_min'] * 60)) : null,
            'follow_up_at' => $follow, 'notes' => ($d['notes'] ?? null) ?: null, 'taken_by' => $u->id,
            'closed_by' => $outcome === 'lost' ? $u->id : null, 'closed_at' => $outcome === 'lost' ? now() : null,
        ]));
        ActivityLogger::log('cro', 'enquiry', "Enquiry {$e->number} logged for {$e->caller_name} ({$e->arrival_date->format('d M')} – {$e->departure_date->format('d M')}, {$rooms} room(s))"
            .($outcome === 'lost' ? ' – lost: '.$e->reasonLabel() : ''), $e);

        return $e;
    }

    protected static function competitor(array $d, string $reason): ?int
    {
        if ($reason !== 'competitor' || empty($d['competitor_id'])) {
            return null;
        }
        self::need(BdCompetitor::whereKey((int) $d['competitor_id'])->exists(), 'Choose a hotel of the competitive set.');

        return (int) $d['competitor_id'];
    }

    public static function markLost(CroEnquiry $e, array $d, User $u): CroEnquiry
    {
        self::need(self::canBook($u), 'You cannot close reservations enquiries.');
        self::need($e->status === 'open', 'Only open enquiries can be closed as lost.');
        $reason = (string) ($d['loss_reason'] ?? '');
        self::need(self::reasonType($reason) !== null, 'Choose why the enquiry was lost.');
        $e->update(['status' => 'lost', 'loss_type' => self::reasonType($reason), 'loss_reason' => $reason, 'competitor_id' => self::competitor($d, $reason),
            'loss_note' => ($d['loss_note'] ?? null) ?: null, 'follow_up_at' => null, 'closed_by' => $u->id, 'closed_at' => now()]);
        ActivityLogger::log('cro', 'lost', "Enquiry {$e->number} lost – ".CroEnquiry::LOSS_TYPES[$e->loss_type][0].': '.$e->reasonLabel(), $e);

        return $e;
    }

    public static function reopen(CroEnquiry $e, User $u): CroEnquiry
    {
        self::need(self::canBook($u), 'You cannot reopen reservations enquiries.');
        self::need($e->status === 'lost', 'Only lost enquiries can be reopened.');
        self::need($e->departure_date->gt(bdate()), 'The requested stay is already over.');
        $e->update(['status' => 'open', 'loss_type' => null, 'loss_reason' => null, 'competitor_id' => null, 'loss_note' => null,
            'closed_by' => null, 'closed_at' => null, 'follow_up_at' => now()->addHours(self::cfg()['follow_up_hours'])]);
        ActivityLogger::log('cro', 'reopen', "Enquiry {$e->number} reopened", $e);

        return $e;
    }

    public static function addContact(CroEnquiry $e, array $d, User $u): CroContact
    {
        self::need(self::canBook($u), 'You cannot record contacts on enquiries.');
        $note = trim((string) ($d['note'] ?? ''));
        self::need($note !== '', 'Write what was said.');
        $type = array_key_exists($d['type'] ?? '', CroEnquiry::CONTACT_TYPES) ? $d['type'] : 'note';
        $c = $e->contacts()->create(['type' => $type, 'note' => mb_substr($note, 0, 500), 'user_id' => $u->id]);
        if ($e->status === 'open') {
            $next = ! empty($d['follow_up_at']) ? rescue(fn () => Carbon::parse($d['follow_up_at']), null, false) : null;
            $e->update(['follow_up_at' => $next]);
        }

        return $c;
    }

    /** Close open enquiries whose stay has started or that stayed open too long: lost, regret "no response". */
    public static function expire(): int
    {
        $cut = now()->subDays(self::cfg()['expire_days']);
        $n = 0;
        foreach (CroEnquiry::where('status', 'open')->where(fn ($q) => $q->where('arrival_date', '<', bdate()->toDateString())->orWhere('created_at', '<', $cut))->get() as $e) {
            $e->update(['status' => 'lost', 'loss_type' => 'regret', 'loss_reason' => 'no_response', 'follow_up_at' => null, 'closed_at' => now(),
                'loss_note' => $e->arrival_date->lt(bdate()) ? 'Lapsed – arrival date passed without a booking' : 'Lapsed – no booking after '.self::cfg()['expire_days'].' days']);
            $n++;
        }
        if ($n) {
            ActivityLogger::log('cro', 'expire', "{$n} open enquiry(ies) lapsed as lost (no response)");
        }

        return $n;
    }

    // ------------------------------------------------------------------ booking (any hotel of the group)

    /**
     * Book rooms at $hotel. $d: room_type_id, arrival, departure, rooms, adults, children, first_name, last_name, phone,
     * email, nationality, status, special_request, guest_id, rate (managers only), channel, asked_hotel_id,
     * enquiry_id (an enquiry of the current hotel), referral_reason.
     * Booking where the caller asked = enquiry booked; booking elsewhere in the group = a referral.
     */
    public static function book(Hotel $hotel, array $d, User $u): array
    {
        self::need(self::canBook($u), 'You cannot make central reservations.');
        $hotel = self::hotelFor($hotel->id, $u);
        $cur = Tenant::hotel();
        $enq = ! empty($d['enquiry_id']) ? CroEnquiry::find((int) $d['enquiry_id']) : null;
        self::need(empty($d['enquiry_id']) || $enq, 'That enquiry was not found.');
        if ($enq) {
            self::need($enq->status === 'open' || ($enq->status === 'lost' && $enq->loss_type === 'denial'), "Enquiry {$enq->number} is already {$enq->statusLabel()}.");
        }
        $asked = $enq ? $cur : (! empty($d['asked_hotel_id']) ? self::hotelFor($d['asked_hotel_id'], $u) : $hotel);
        $referral = $asked->id !== $hotel->id;
        $refReason = (string) ($d['referral_reason'] ?? 'sold_out');
        if ($referral) {
            self::need(self::reasonType($refReason) !== null, 'Choose why the guest could not be kept at '.$asked->name.'.');
        }
        [$a, $dep, $n, $rooms, $adults, $children] = self::stay($d);
        $first = trim((string) ($d['first_name'] ?? ''));
        self::need($first !== '', 'Enter the guest\'s first name.');
        $channel = array_key_exists($d['channel'] ?? '', CroEnquiry::CHANNELS) && ($d['channel'] ?? '') !== 'referral' ? $d['channel'] : ($enq?->channel ?? 'phone');
        $override = ($d['rate'] ?? '') !== '' && $d['rate'] !== null;
        self::need(! $override || self::canManage($u), 'Only a reservations manager can override the rate.');
        $agent = $u->name;

        // 1. the reservation at the target hotel (tenant switched, locked availability re-check)
        $made = self::withHotel($hotel, function () use ($hotel, $d, $a, $dep, $n, $rooms, $adults, $children, $first, $override, $referral, $asked, $agent, $u) {
            $b = Carbon::parse($hotel->business_date)->startOfDay();
            self::need($a->gte($b), 'Arrival is before '.$hotel->name.'\'s business date.');
            $type = RoomType::where('is_active', true)->find((int) ($d['room_type_id'] ?? 0));
            self::need((bool) $type, 'Choose a room type of '.$hotel->name.'.');
            $perRoom = (int) ceil($adults / $rooms);
            self::need(! $type->max_adults || $perRoom <= $type->max_adults, "{$type->name} sleeps at most {$type->max_adults} adult(s) per room – book more rooms.");
            $quote = RateService::quote($type, $a->toDateString(), $dep->toDateString());
            self::need(! $quote['stop_sell'], "{$type->name} is closed for sale on those dates at {$hotel->name}.");
            self::need($quote['min_stay_ok'], "A minimum stay of {$quote['min_stay']} nights applies at {$hotel->name}.");
            $rate = $override ? round((float) $d['rate'], 2) : (float) $quote['average'];
            self::need($rate >= 0, 'The rate cannot be negative.');

            $res = DB::transaction(function () use ($d, $type, $a, $dep, $rooms, $adults, $children, $perRoom, $first, $rate, $referral, $asked, $agent, $hotel, $u, $quote) {
                AvailabilityService::lockRoomInventory([$type->id]);
                self::need(AvailabilityService::typeAvailable($type->id, $a->toDateString(), $dep->toDateString()) >= $rooms,
                    "Not enough {$type->name} rooms left at {$hotel->name} for those dates.");
                $guest = self::resolveGuest($d, $first);
                self::need(! $guest->is_blacklisted || self::canManage($u), "{$guest->full_name} is blacklisted at {$hotel->name} – a reservations manager must decide.");
                $src = BusinessSource::firstOrCreate(['name' => $referral ? 'Sister-hotel referral' : 'Central Reservations'], ['type' => 'direct']);
                $res = Reservation::create([
                    'reservation_no' => Numbering::next('reservation'), 'guest_id' => $guest->id, 'business_source_id' => $src->id,
                    'arrival_date' => $a->toDateString(), 'departure_date' => $dep->toDateString(), 'adults' => $adults, 'children' => $children,
                    'status' => ($d['status'] ?? 'confirmed') === 'tentative' ? 'tentative' : 'confirmed',
                    'special_request' => ($d['special_request'] ?? null) ?: null, 'created_by' => $u->id,
                    'purpose' => $referral ? 'Referred by '.$asked->name : null,
                ]);
                $left = $adults;
                for ($i = 0; $i < $rooms; $i++) {
                    $ad = min($perRoom, $left - ($rooms - $i - 1));
                    $left -= $ad;
                    $res->rooms()->create(['room_type_id' => $type->id, 'guest_id' => $guest->id, 'arrival_date' => $a->toDateString(), 'departure_date' => $dep->toDateString(),
                        'rate' => $rate, 'adults' => $ad, 'children' => $i === 0 ? $children : 0, 'status' => 'reserved']);
                }
                ActivityLogger::log('cro', 'book', "Reservation {$res->reservation_no} booked by central reservations ({$agent})".($referral ? " – referral from {$asked->name}" : '')
                    .(abs($rate - $quote['average']) > 0.005 ? ' at '.money($rate).' (rate '.money($quote['average']).')' : ''), $res);

                return $res;
            });
            rescue(fn () => MailService::autoConfirm($res), null, false);
            rescue(fn () => MessageService::autoConfirm($res), null, false);

            return ['reservation' => $res, 'rate' => $rate, 'type' => $type, 'guest' => $res->guest];
        });
        $res = $made['reservation'];
        $value = round($made['rate'] * $n * $rooms, 2);
        $base = ['arrival_date' => $a->toDateString(), 'departure_date' => $dep->toDateString(), 'rooms' => $rooms, 'adults' => $adults, 'children' => $children,
            'caller_name' => $enq?->caller_name ?? mb_substr(trim($first.' '.($d['last_name'] ?? '')), 0, 120), 'phone' => $enq?->phone ?? (($d['phone'] ?? null) ?: null),
            'email' => $enq?->email ?? (($d['email'] ?? null) ?: null), 'duration_sec' => $enq?->duration_sec ?? (! empty($d['duration_min']) ? min(65535, (int) round((float) $d['duration_min'] * 60)) : null)];

        // 2. the enquiry at the hotel the caller asked for: booked, or referred (kept in the group)
        $origin = self::withHotel($asked, function () use ($enq, $base, $referral, $hotel, $res, $made, $value, $refReason, $u, $channel) {
            $close = $referral
                ? ['status' => 'referred', 'loss_type' => self::reasonType($refReason), 'loss_reason' => $refReason, 'referred_hotel_id' => $hotel->id,
                    'referred_reservation_id' => $res->id, 'referred_reservation_no' => $res->reservation_no, 'booked_value' => $value]
                : ['status' => 'booked', 'loss_type' => null, 'loss_reason' => null, 'reservation_id' => $res->id, 'booked_value' => $value,
                    'room_type_id' => $made['type']->id, 'guest_id' => $made['guest']->id, 'quoted_rate' => $made['rate'], 'quoted_total' => $value];
            $close += ['follow_up_at' => null, 'closed_by' => $u->id, 'closed_at' => now(), 'competitor_id' => null];
            if ($enq) {
                $enq->update($close);

                return $enq;
            }

            return CroEnquiry::create($base + ['number' => Numbering::next('cro_enquiry'), 'channel' => $channel, 'taken_by' => $u->id,
                'quoted_rate' => $made['rate'], 'quoted_total' => $value] + $close);
        });

        // 3. a referral also appears at the receiving hotel as a booked enquiry from its sister
        $mirror = null;
        if ($referral) {
            $asked->refresh();
            $mirror = self::withHotel($hotel, fn () => CroEnquiry::create($base + ['number' => Numbering::next('cro_enquiry'), 'channel' => 'referral', 'status' => 'booked',
                'guest_id' => $made['guest']->id, 'room_type_id' => $made['type']->id, 'quoted_rate' => $made['rate'], 'quoted_total' => $value, 'reservation_id' => $res->id,
                'booked_value' => $value, 'referred_from_hotel_id' => $asked->id, 'origin_enquiry_id' => $origin->id, 'taken_by' => $u->id, 'closed_by' => $u->id, 'closed_at' => now(),
                'notes' => "Referred by {$asked->name} (enquiry {$origin->number})"]));
            self::withHotel($asked, fn () => ActivityLogger::log('cro', 'refer', "Enquiry {$origin->number} referred to {$hotel->name} – reservation {$res->reservation_no} (".money($value).')', $origin));
        }

        return ['reservation' => $res, 'hotel' => $hotel, 'enquiry' => $origin, 'mirror' => $mirror, 'referral' => $referral, 'value' => $value];
    }

    /** The guest profile at the current (target) hotel: the chosen one, a match on e-mail or phone, or a new profile. */
    protected static function resolveGuest(array $d, string $first): Guest
    {
        if (! empty($d['guest_id']) && ($g = Guest::whereNull('erased_at')->find((int) $d['guest_id']))) {
            return $g;
        }
        $email = trim((string) ($d['email'] ?? ''));
        $phone = trim((string) ($d['phone'] ?? ''));
        $g = $email !== '' ? Guest::whereNull('erased_at')->where('email', $email)->first() : null;
        $g ??= $phone !== '' ? Guest::whereNull('erased_at')->where('phone', $phone)->where('first_name', $first)->first() : null;
        $g ??= Guest::create(['first_name' => mb_substr($first, 0, 80), 'last_name' => ($d['last_name'] ?? null) ?: null, 'email' => $email ?: null,
            'phone' => $phone ?: null, 'nationality' => ($d['nationality'] ?? null) ?: null]);

        return $g;
    }

    // ------------------------------------------------------------------ cross-property guest lookup

    /** Guests across the agent's hotels by name, phone, e-mail or reservation number (erased profiles are never shown). */
    public static function guestLookup(string $term, ?User $u): Collection
    {
        $term = trim($term);
        if (mb_strlen($term) < 3) {
            return collect();
        }
        $hotels = self::groupHotels($u)->keyBy('id');
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
        $resGuests = Reservation::withoutGlobalScope('hotel')->whereIn('hotel_id', $hotels->keys())->where('reservation_no', $term)->pluck('guest_id');
        $guests = Guest::withoutGlobalScope('hotel')->whereIn('hotel_id', $hotels->keys())->whereNull('erased_at')
            ->where(fn ($q) => $q->where(DB::raw("CONCAT_WS(' ', first_name, last_name)"), 'like', $like)->orWhere('phone', 'like', $like)->orWhere('email', 'like', $like)->orWhereIn('id', $resGuests))
            ->orderByDesc('last_stay_on')->orderBy('first_name')->limit(40)->get();
        $ids = $guests->pluck('id');
        $next = Reservation::withoutGlobalScope('hotel')->whereIn('guest_id', $ids)->whereIn('status', ['confirmed', 'tentative', 'checked_in'])
            ->where('departure_date', '>=', bdate()->toDateString())->orderBy('arrival_date')->get(['id', 'guest_id', 'reservation_no', 'arrival_date', 'departure_date', 'status'])->groupBy('guest_id');
        $counts = Reservation::withoutGlobalScope('hotel')->whereIn('guest_id', $ids)->selectRaw('guest_id, count(*) n')->groupBy('guest_id')->pluck('n', 'guest_id');

        return $guests->map(fn ($g) => ['guest' => $g, 'hotel' => $hotels[$g->hotel_id], 'upcoming' => $next[$g->id] ?? collect(), 'bookings' => (int) ($counts[$g->id] ?? 0)]);
    }

    // ------------------------------------------------------------------ KPIs

    protected static function scope(Carbon $from, Carbon $to)
    {
        return CroEnquiry::whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);
    }

    /** Headline figures for enquiries logged in the period at the current hotel. */
    public static function kpis(Carbon $from, Carbon $to): array
    {
        $rows = self::scope($from, $to)->get(['status', 'loss_type', 'channel', 'quoted_total', 'booked_value', 'duration_sec', 'arrival_date', 'departure_date', 'rooms']);
        $total = $rows->count();
        $booked = $rows->where('status', 'booked');
        $referred = $rows->where('status', 'referred');
        $lost = $rows->where('status', 'lost');
        $closed = $total - $rows->where('status', 'open')->count();
        $rn = fn ($c) => (int) $c->sum(fn ($e) => max(1, (int) $e->arrival_date->diffInDays($e->departure_date)) * max(1, (int) $e->rooms));
        $dur = $rows->whereNotNull('duration_sec');

        return ['total' => $total, 'open' => $rows->where('status', 'open')->count(), 'booked' => $booked->count(), 'referred' => $referred->count(), 'lost' => $lost->count(),
            'denials' => $lost->where('loss_type', 'denial')->count() + $referred->where('loss_type', 'denial')->count(), 'regrets' => $lost->where('loss_type', 'regret')->count(),
            'conversion' => $closed ? round($booked->count() / $closed * 100, 1) : null,
            'retention' => $closed ? round(($booked->count() + $referred->count()) / $closed * 100, 1) : null,
            'booked_revenue' => round((float) $booked->sum('booked_value'), 2), 'referred_revenue' => round((float) $referred->sum('booked_value'), 2),
            'lost_revenue' => round((float) $lost->sum('quoted_total'), 2), 'booked_nights' => $rn($booked), 'denied_nights' => $rn($lost->where('loss_type', 'denial')->concat($referred->where('loss_type', 'denial'))),
            'regret_nights' => $rn($lost->where('loss_type', 'regret')), 'referrals_in' => $booked->where('channel', 'referral')->count(),
            'avg_handle' => $dur->count() ? (int) round($dur->avg('duration_sec')) : null, 'target' => self::cfg()['conversion_target']];
    }

    /** Per agent (or per channel when $by = 'channel'): enquiries, outcomes, conversion and revenue. */
    public static function breakdown(Carbon $from, Carbon $to, string $by = 'agent'): Collection
    {
        $col = $by === 'channel' ? 'channel' : 'taken_by';
        $rows = self::scope($from, $to)->with('agent')->get();
        $target = self::cfg()['conversion_target'];

        return $rows->groupBy(fn ($e) => (string) $e->{$col})->map(function ($g, $k) use ($by, $target) {
            $closed = $g->where('status', '!=', 'open')->count();
            $booked = $g->where('status', 'booked');
            $dur = $g->whereNotNull('duration_sec');
            $conv = $closed ? round($booked->count() / $closed * 100, 1) : null;

            return ['key' => $k, 'label' => $by === 'channel' ? (CroEnquiry::CHANNELS[$k] ?? ucfirst($k)) : ($g->first()->agent?->name ?? 'Unassigned'),
                'total' => $g->count(), 'open' => $g->where('status', 'open')->count(), 'booked' => $booked->count(), 'referred' => $g->where('status', 'referred')->count(),
                'denials' => $g->where('status', 'lost')->where('loss_type', 'denial')->count(), 'regrets' => $g->where('status', 'lost')->where('loss_type', 'regret')->count(),
                'conversion' => $conv, 'on_target' => $conv !== null && $conv >= $target,
                'revenue' => round((float) $booked->sum('booked_value'), 2), 'referred_revenue' => round((float) $g->where('status', 'referred')->sum('booked_value'), 2),
                'lost_revenue' => round((float) $g->where('status', 'lost')->sum('quoted_total'), 2), 'avg_handle' => $dur->count() ? (int) round($dur->avg('duration_sec')) : null];
        })->sortByDesc('revenue')->values();
    }

    /** Lost business by reason (denials and regrets, incl. referrals' reasons): count, room nights, value, share. */
    public static function lostReasons(Carbon $from, Carbon $to): Collection
    {
        $rows = self::scope($from, $to)->whereIn('status', ['lost', 'referred'])->whereNotNull('loss_reason')->get();
        $all = max(1, $rows->where('status', 'lost')->count());

        return $rows->groupBy('loss_reason')->map(function ($g, $k) use ($all) {
            $lost = $g->where('status', 'lost');

            return ['reason' => $k, 'label' => CroEnquiry::reasons()[$k] ?? $k, 'type' => self::reasonType($k), 'count' => $lost->count(), 'referred' => $g->where('status', 'referred')->count(),
                'nights' => (int) $lost->sum(fn ($e) => $e->roomNights()), 'value' => round((float) $lost->sum('quoted_total'), 2),
                'kept' => round((float) $g->where('status', 'referred')->sum('booked_value'), 2), 'share' => round($lost->count() / $all * 100, 1)];
        })->sortByDesc('count')->values();
    }

    /** Competitors that won our regrets. */
    public static function lostToCompetitors(Carbon $from, Carbon $to): Collection
    {
        return self::scope($from, $to)->where('status', 'lost')->whereNotNull('competitor_id')->with('competitor')->get()->groupBy('competitor_id')
            ->map(fn ($g) => ['name' => $g->first()->competitor?->name ?? '—', 'count' => $g->count(), 'value' => round((float) $g->sum('quoted_total'), 2)])->sortByDesc('count')->values();
    }

    /**
     * Unconstrained demand by stay date: capacity, room nights on the books, room nights denied (lost or referred for lack
     * of availability / restrictions), regretted room nights, and the occupancy the hotel could have run without denials.
     */
    public static function demand(Carbon $from, int $days): Collection
    {
        $from = $from->copy()->startOfDay();
        $to = $from->copy()->addDays($days);
        $m = AvailabilityService::matrix($from->copy(), $days);
        $cap = (int) $m['types']->sum('rooms_count');
        $enq = CroEnquiry::whereIn('status', ['lost', 'referred'])->whereNotNull('loss_type')
            ->whereDate('arrival_date', '<', $to->toDateString())->whereDate('departure_date', '>', $from->toDateString())->get(['status', 'loss_type', 'arrival_date', 'departure_date', 'rooms']);
        $out = collect();
        foreach (CarbonPeriod::create($from, $to->copy()->subDay()) as $d) {
            $k = $d->toDateString();
            $free = collect($m['matrix'])->sum(fn ($byDate) => max(0, (int) ($byDate[$k] ?? 0)));
            $sold = max(0, $cap - $free);
            $on = $enq->filter(fn ($e) => $e->arrival_date->lte($d) && $e->departure_date->gt($d));
            $denied = (int) $on->where('loss_type', 'denial')->sum('rooms');
            $regret = (int) $on->where('loss_type', 'regret')->sum('rooms');
            $out->push(['date' => $d->copy(), 'capacity' => $cap, 'sold' => $sold, 'denied' => $denied, 'regret' => $regret, 'unconstrained' => $sold + $denied,
                'occ' => $cap ? round($sold / $cap * 100, 1) : 0, 'uocc' => $cap ? round(($sold + $denied) / $cap * 100, 1) : 0]);
        }

        return $out;
    }

    /** Referrals between the hotels of the user's scope (enquiries at each origin hotel, read across tenants). */
    public static function referrals(Carbon $from, Carbon $to, ?User $u): Collection
    {
        $hotels = self::groupHotels($u)->keyBy('id');

        return CroEnquiry::withoutGlobalScope('hotel')->whereIn('hotel_id', $hotels->keys())->where('status', 'referred')->whereIn('referred_hotel_id', $hotels->keys())
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->get()
            ->groupBy(fn ($e) => $e->hotel_id.'>'.$e->referred_hotel_id)
            ->map(fn ($g) => ['from' => $hotels[$g->first()->hotel_id], 'to' => $hotels[$g->first()->referred_hotel_id], 'count' => $g->count(),
                'nights' => (int) $g->sum(fn ($e) => $e->roomNights()), 'value' => round((float) $g->sum('booked_value'), 2)])->sortByDesc('value')->values();
    }

    // ------------------------------------------------------------------ alerts

    public static function alerts(): array
    {
        return self::$memo['alerts'.Tenant::id()] ??= [
            'follow_ups' => CroEnquiry::where('status', 'open')->whereNotNull('follow_up_at')->where('follow_up_at', '<=', now())->count(),
            'arriving' => CroEnquiry::where('status', 'open')->whereBetween('arrival_date', [bdate()->toDateString(), bdate()->copy()->addDays(3)->toDateString()])->count(),
        ];
    }

    public static function attention(): int
    {
        if (! can_do('cro.view')) {
            return 0;
        }
        try {
            return self::alerts()['follow_ups'];
        } catch (Throwable) {
            return 0;
        }
    }

    public static function people(): Collection
    {
        return User::where('hotel_id', hotel()->id)->where('is_active', true)->orderBy('name')->get()->filter(fn ($u) => self::canBook($u))->values();
    }
}
