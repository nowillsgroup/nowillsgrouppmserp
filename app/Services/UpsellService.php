<?php

namespace App\Services;

use App\Mail\UpsellOffersMail;
use App\Models\EmailLog;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\StayFixedCharge;
use App\Models\UpsellOffer;
use App\Models\UpsellOrder;
use App\Models\UpsellPresentation;
use App\Support\Numbering;
use App\Support\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * v4.1 – upselling & pre-arrival offers.
 *
 * An offer (paid upgrade, add-on, early check-in, late check-out) is presented on a channel – the pre-arrival e-mail /
 * link, the front desk at check-in, or the in-house guest portal. Eligibility is decided per stay (status, dates, room
 * type, booking source, minimum nights, lead time, daily cap, upgrade inventory). A confirmed order becomes a
 * StayFixedCharge, so the night audit / check-out bill it with the room – nothing is posted twice and a voided posting
 * is treated as waived. Upgrades switch the stay's room type (the channel manager picks the change up by itself).
 * Early check-in / late check-out can wait for the desk's confirmation; desk sales are confirmed at once and can carry a
 * staff incentive.
 */
class UpsellService
{
    public const DEFAULTS = ['upsell_enabled' => '1', 'upsell_days_before' => '3', 'upsell_auto_email' => '1', 'upsell_guest_cancel_hours' => '24', 'upsell_keep_free' => '0'];

    public const SELF_SERVICE = ['pre_arrival', 'portal'];

    public static function cfg(): array
    {
        $g = fn ($k) => setting($k, self::DEFAULTS[$k]);

        return ['enabled' => $g('upsell_enabled') === '1', 'days_before' => max(1, min(30, (int) $g('upsell_days_before'))),
            'auto_email' => $g('upsell_auto_email') === '1', 'guest_cancel_hours' => max(0, min(240, (int) $g('upsell_guest_cancel_hours'))),
            'keep_free' => max(0, min(50, (int) $g('upsell_keep_free')))];
    }

    public static function enabled(): bool
    {
        return self::cfg()['enabled'];
    }

    public static function saveSettings(array $d): void
    {
        $d += self::cfg();   // partial updates keep the other settings
        $vals = ['upsell_enabled' => ! empty($d['enabled']) ? '1' : '0', 'upsell_days_before' => (string) max(1, min(30, (int) ($d['days_before'] ?? 3))),
            'upsell_auto_email' => ! empty($d['auto_email']) ? '1' : '0', 'upsell_guest_cancel_hours' => (string) max(0, min(240, (int) ($d['guest_cancel_hours'] ?? 24))),
            'upsell_keep_free' => (string) max(0, min(50, (int) ($d['keep_free'] ?? 0)))];
        foreach ($vals as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        }
        Setting::$version++;
        ActivityLogger::log('upsell', 'settings', 'Upselling settings updated'.($vals['upsell_enabled'] === '0' ? ' – upselling switched OFF' : ''));
    }

    // ───────────────────────────── eligibility & price ─────────────────────────────

    /** When the guest is expected (hotel check-in time on the arrival date, hotel time zone). */
    public static function arrivalAt(ReservationRoom $s): Carbon
    {
        $time = Tenant::hotel()?->checkin_time ?: '14:00:00';

        return Carbon::parse($s->arrival_date->toDateString().' '.$time, config('app.timezone'));
    }

    /** First night the offer covers: the arrival night, or tonight for in-house guests. */
    protected static function startNight(ReservationRoom $s): Carbon
    {
        return $s->status === 'checked_in' ? bdate()->copy()->startOfDay()->max($s->arrival_date) : $s->arrival_date->copy();
    }

    public static function serviceDate(UpsellOffer $o, ReservationRoom $s): Carbon
    {
        return match ($o->kind) {
            'early_checkin' => $s->arrival_date->copy(),
            'late_checkout' => $s->departure_date->copy(),
            default => self::startNight($s),
        };
    }

    protected static function nightly(UpsellOffer $o): bool
    {
        return in_array($o->pricing, ['per_night', 'per_person_night', 'rate_difference'], true) || $o->kind === 'upgrade';
    }

    /** Price for this stay: unit (per charge line), qty (persons), nights charged and the total before tax. */
    public static function quote(UpsellOffer $o, ReservationRoom $s): array
    {
        $start = self::startNight($s);
        $nights = max(0, (int) $start->diffInDays($s->departure_date, false));
        $pax = max(1, (int) $s->adults + (int) $s->children);
        $unit = (float) $o->price;
        $qty = in_array($o->pricing, ['per_person', 'per_person_night'], true) ? $pax : 1;
        if ($o->kind === 'upgrade' && $o->pricing === 'rate_difference' && $o->toRoomType) {
            $target = $nights > 0 ? RateService::quote($o->toRoomType, $start, $s->departure_date)['average'] : 0;
            $diff = max(0, $target - (float) $s->rate);
            $unit = $diff > 0 ? max((float) ($o->min_price ?? 0), round($diff * (float) $o->diff_pct / 100 / 10) * 10) : 0;
        }
        $n = self::nightly($o) ? $nights : 1;
        $amount = round($unit * $qty * $n, 2);
        $parts = [money($unit)];
        if ($qty > 1) {
            $parts[] = "{$qty} guests";
        }
        if (self::nightly($o)) {
            $parts[] = $n.' night'.($n === 1 ? '' : 's');
        }

        return ['unit' => round($unit, 2), 'qty' => $qty, 'nights' => $n, 'amount' => $amount, 'breakdown' => implode(' × ', $parts)];
    }

    /** null when the offer can be sold to this stay on this channel, otherwise the reason (guest-readable). */
    public static function check(UpsellOffer $o, ReservationRoom $s, string $channel): ?string
    {
        if (! self::enabled()) {
            return 'Offers are not available at the moment.';
        }
        if (! $o->is_active) {
            return 'This offer is no longer available.';
        }
        if (! in_array($channel, $o->channels ?? [], true)) {
            return 'This offer is not available here – please ask the front desk.';
        }
        $allowed = in_array($o->kind, ['upgrade', 'early_checkin'], true) ? ['reserved'] : ['reserved', 'checked_in'];
        if (! in_array($s->status, $allowed, true) || in_array($s->reservation?->status, ['cancelled', 'no_show', 'checked_out'], true)) {
            return $s->status === 'checked_in' ? 'Only available before check-in.' : 'This stay can no longer be changed.';
        }
        if ($s->departure_date->lt(bdate()) || ($o->kind !== 'late_checkout' && $s->departure_date->lte(bdate()))) {
            return 'This stay has ended.';
        }
        if ($o->kind === 'early_checkin' && now()->gte(self::arrivalAt($s))) {
            return 'The standard check-in time has already passed.';
        }
        if (($o->valid_from && $o->valid_from->gt($s->arrival_date)) || ($o->valid_to && $o->valid_to->lt($s->arrival_date))) {
            return 'Not valid for your dates.';
        }
        if ($s->nights < $o->min_nights) {
            return "Requires a stay of at least {$o->min_nights} nights.";
        }
        if (in_array($channel, self::SELF_SERVICE, true) && $s->status === 'reserved' && $o->lead_hours > 0
            && now()->diffInMinutes(self::arrivalAt($s), false) < $o->lead_hours * 60) {
            return 'It is too close to your arrival to book this online – please ask the front desk.';
        }
        if ($o->source_ids && ! in_array((int) $s->reservation?->business_source_id, array_map('intval', $o->source_ids), true)) {
            return 'Not available for this booking.';
        }
        if ($o->kind === 'upgrade') {
            $to = $o->toRoomType;
            if (! $to || ! $to->is_active) {
                return 'This offer is no longer available.';
            }
            if ($to->id === (int) $s->room_type_id) {
                return 'You are already booked in this room type.';
            }
            if ($o->room_type_ids ? ! in_array((int) $s->room_type_id, array_map('intval', $o->room_type_ids), true)
                : (float) $to->base_rate <= (float) optional(RoomType::find($s->room_type_id))->base_rate) {
                return 'Not available for your room type.';
            }
        } elseif ($o->room_type_ids && ! in_array((int) $s->room_type_id, array_map('intval', $o->room_type_ids), true)) {
            return 'Not available for your room type.';
        }
        $open = UpsellOrder::where('reservation_room_id', $s->id)->whereIn('status', ['requested', 'confirmed']);
        if ((clone $open)->where('upsell_offer_id', $o->id)->exists()) {
            return 'Already added to this stay.';
        }
        if ($o->kind !== 'addon' && (clone $open)->where('kind', $o->kind)->exists()) {
            return 'This stay already has '.strtolower($o->kindLabel()).' arranged.';
        }
        $q = self::quote($o, $s);
        if ($q['amount'] <= 0 && ! ($o->price == 0 && $o->pricing !== 'rate_difference')) {
            return self::nightly($o) && $q['nights'] < 1 ? 'No nights left to add this to.' : 'Not available for your booking.';
        }
        if ($o->kind === 'upgrade' && AvailabilityService::typeAvailable($o->to_room_type_id, self::startNight($s), $s->departure_date) <= self::cfg()['keep_free']) {
            return 'Sold out for your dates.';
        }
        if ($o->daily_cap && UpsellOrder::where('upsell_offer_id', $o->id)->whereIn('status', ['requested', 'confirmed'])
            ->whereDate('service_date', self::serviceDate($o, $s))->count() >= $o->daily_cap) {
            return 'Fully booked for that day.';
        }

        return null;
    }

    /** Sellable offers for a stay on a channel: [['offer' => UpsellOffer, 'quote' => [...]], …] in catalogue order. */
    public static function offersFor(ReservationRoom $s, string $channel): Collection
    {
        if (! self::enabled()) {
            return collect();
        }
        $s->loadMissing('reservation');

        return UpsellOffer::with('toRoomType')->where('is_active', true)->orderBy('sort')->orderBy('name')->get()
            ->filter(fn ($o) => self::check($o, $s, $channel) === null)
            ->map(fn ($o) => ['offer' => $o, 'quote' => self::quote($o, $s)])->values();
    }

    // ───────────────────────────── orders ─────────────────────────────

    public static function place(ReservationRoom $s, UpsellOffer $o, string $channel, ?string $note = null): UpsellOrder
    {
        abort_unless(array_key_exists($channel, UpsellOrder::CHANNELS), 422);

        return DB::transaction(function () use ($s, $o, $channel, $note) {
            $o = UpsellOffer::with('toRoomType')->lockForUpdate()->findOrFail($o->id);   // serialises the daily-cap / inventory check
            $s = ReservationRoom::with('reservation')->lockForUpdate()->findOrFail($s->id);
            if ($why = self::check($o, $s, $channel)) {
                throw new RuntimeException($why);
            }
            $q = self::quote($o, $s);
            $order = UpsellOrder::create(['order_no' => Numbering::next('upsell'), 'reservation_id' => $s->reservation_id, 'reservation_room_id' => $s->id,
                'upsell_offer_id' => $o->id, 'kind' => $o->kind, 'channel' => $channel, 'status' => 'requested', 'quantity' => $q['qty'], 'nights' => $q['nights'],
                'unit_price' => $q['unit'], 'amount' => $q['amount'], 'from_room_type_id' => $s->room_type_id,
                'to_room_type_id' => $o->kind === 'upgrade' ? $o->to_room_type_id : null, 'service_date' => self::serviceDate($o, $s),
                'sold_by' => $channel === 'desk' ? auth()->id() : null, 'guest_note' => $note ? mb_substr($note, 0, 255) : null]);
            UpsellPresentation::where('reservation_id', $s->reservation_id)->where('channel', $channel)->whereNull('converted_at')->update(['converted_at' => now()]);
            $who = $channel === 'desk' ? '' : ' by the guest ('.UpsellOrder::CHANNELS[$channel].')';
            ActivityLogger::log('upsell', 'order', "{$order->order_no}: {$o->name} for {$s->reservation->reservation_no}{$who} – ".money($q['amount']), $order);
            if ($o->auto_confirm || $channel === 'desk') {
                self::confirm($order);
            }

            return $order->fresh();
        });
    }

    /** requested → confirmed: switch the room type (upgrades), stamp early / late times and create the fixed charge. */
    public static function confirm(UpsellOrder $order, ?string $note = null): UpsellOrder
    {
        return DB::transaction(function () use ($order, $note) {
            $order = UpsellOrder::with('offer.toRoomType')->lockForUpdate()->findOrFail($order->id);
            if ($order->status !== 'requested') {
                throw new RuntimeException("{$order->order_no} is already ".strtolower($order->statusLabel()).'.');
            }
            $s = ReservationRoom::with('reservation', 'room', 'roomType')->lockForUpdate()->findOrFail($order->reservation_room_id);
            $o = $order->offer;
            if (! in_array($s->status, ['reserved', 'checked_in'], true)) {
                throw new RuntimeException('The stay is '.str_replace('_', ' ', $s->status).' – it can no longer be changed.');
            }
            $start = $order->service_date->copy();
            $charge = ['reservation_room_id' => $s->id, 'category' => $o->category ?: (UpsellOffer::KINDS[$o->kind][2] ?? 'misc'), 'apply_tax' => $o->apply_tax,
                'is_active' => true, 'created_by' => auth()->id()];
            if ($order->kind === 'upgrade') {
                if ($s->status !== 'reserved') {
                    throw new RuntimeException('The guest is already in house – use a room move for an upgrade now.');
                }
                AvailabilityService::lockRoomInventory([$s->room_type_id, $order->to_room_type_id]);
                if (AvailabilityService::typeAvailable($order->to_room_type_id, $s->arrival_date, $s->departure_date) < 1) {
                    throw new RuntimeException('No '.($o->toRoomType?->name ?? 'room').' is free for these dates any more.');
                }
                $fromName = $s->roomType?->name;
                $s->room_type_id = $order->to_room_type_id;
                if ($s->room_id && $s->room?->room_type_id !== (int) $order->to_room_type_id) {
                    $s->room_id = null;   // the pre-assigned room is of the old type
                }
                $s->save();
                $charge += ['description' => "Upgrade {$fromName} → ".($o->toRoomType?->name ?? ''), 'amount' => $order->unit_price, 'frequency' => 'daily',
                    'start_date' => $s->arrival_date, 'end_date' => null];
            } else {
                $daily = $order->kind === 'addon' && in_array($o->pricing, ['per_night', 'per_person_night'], true);
                $charge += ['description' => $o->name.($order->quantity > 1 ? " ×{$order->quantity}" : '').($o->timeLabel() ? ' ('.$o->timeLabel().')' : ''),
                    'amount' => $daily ? round((float) $order->unit_price * $order->quantity, 2) : (float) $order->amount,
                    'frequency' => $daily ? 'daily' : 'once', 'start_date' => $start, 'end_date' => null];
                if ($order->kind === 'early_checkin') {
                    $s->forceFill(['early_checkin_from' => $o->service_time])->save();
                } elseif ($order->kind === 'late_checkout') {
                    $s->forceFill(['late_checkout_until' => $o->service_time])->save();
                }
            }
            $fc = StayFixedCharge::create($charge);
            $incentive = $order->sold_by ? match ($o->incentive_type) {
                'pct' => round((float) $order->amount * (float) $o->incentive_value / 100, 2),
                'fixed' => (float) $o->incentive_value,
                default => 0,
            } : 0;
            $order->update(['status' => 'confirmed', 'confirmed_by' => auth()->id(), 'confirmed_at' => now(), 'stay_fixed_charge_id' => $fc->id,
                'incentive_amount' => $incentive, 'staff_note' => $note ?: $order->staff_note]);
            if ($order->wasChanged() && auth()->id() && $order->channel !== 'desk') {
                ActivityLogger::log('upsell', 'confirm', "Confirmed {$order->order_no} ({$o->name}) for {$s->reservation->reservation_no}", $order);
            }

            return $order;
        });
    }

    public static function decline(UpsellOrder $order, string $reason): UpsellOrder
    {
        if ($order->status !== 'requested') {
            throw new RuntimeException('Only requests that are still awaiting confirmation can be declined.');
        }
        $order->update(['status' => 'declined', 'staff_note' => mb_substr($reason, 0, 255), 'closed_by' => auth()->id(), 'closed_at' => now()]);
        ActivityLogger::log('upsell', 'decline', "Declined {$order->order_no} ({$order->offer->name}): {$reason}", $order);

        return $order;
    }

    /** Can the guest still cancel this online? null = yes, otherwise why not. */
    public static function guestCancelBlock(UpsellOrder $order): ?string
    {
        if (! $order->isOpen()) {
            return 'Already '.strtolower($order->statusLabel()).'.';
        }
        if ($order->status === 'requested') {
            return null;
        }
        $s = $order->stay;
        if ($s->status !== 'reserved') {
            return 'Please ask the front desk to change this.';
        }
        $h = self::cfg()['guest_cancel_hours'];
        if ($h && now()->diffInMinutes(self::arrivalAt($s), false) < $h * 60) {
            return "Online changes close {$h} hours before arrival – please contact the front desk.";
        }

        return null;
    }

    public static function cancel(UpsellOrder $order, string $reason, bool $byGuest = false): UpsellOrder
    {
        return DB::transaction(function () use ($order, $reason, $byGuest) {
            $order = UpsellOrder::with('offer', 'stay.room', 'charge')->lockForUpdate()->findOrFail($order->id);
            if ($byGuest && ($why = self::guestCancelBlock($order))) {
                throw new RuntimeException($why);
            }
            if (! $order->isOpen()) {
                throw new RuntimeException("{$order->order_no} is already ".strtolower($order->statusLabel()).'.');
            }
            if ($order->status === 'confirmed') {
                if ($order->postedAmount() > 0) {
                    throw new RuntimeException('Already charged to the folio ('.money($order->postedAmount()).'). Void the folio posting first, then cancel.');
                }
                $s = $order->stay;
                if ($order->kind === 'upgrade') {
                    if ($s->status !== 'reserved') {
                        throw new RuntimeException('The guest is in house in the upgraded room – use a room move instead.');
                    }
                    if ($order->from_room_type_id && (int) $s->room_type_id === (int) $order->to_room_type_id) {
                        AvailabilityService::lockRoomInventory([$s->room_type_id, $order->from_room_type_id]);
                        if (AvailabilityService::typeAvailable($order->from_room_type_id, $s->arrival_date, $s->departure_date) < 1) {
                            throw new RuntimeException('The original room type is sold out for these dates – change the booking manually.');
                        }
                        $s->room_type_id = $order->from_room_type_id;
                        if ($s->room_id && $s->room?->room_type_id !== (int) $order->from_room_type_id) {
                            $s->room_id = null;
                        }
                        $s->save();
                    }
                } elseif ($order->kind === 'early_checkin') {
                    $s->forceFill(['early_checkin_from' => null])->save();
                } elseif ($order->kind === 'late_checkout') {
                    $s->forceFill(['late_checkout_until' => null])->save();
                }
                $order->charge?->update(['is_active' => false]);
            }
            $order->update(['status' => 'cancelled', 'incentive_amount' => 0, 'staff_note' => mb_substr($reason, 0, 255),
                'closed_by' => $byGuest ? null : auth()->id(), 'closed_at' => now()]);
            ActivityLogger::log('upsell', 'cancel', "Cancelled {$order->order_no} ({$order->offer->name})".($byGuest ? ' – by the guest online' : '').": {$reason}", $order);

            return $order;
        });
    }

    /** Reservation cancelled / no-show: close every open order on those stays (their charges stop with the stay). */
    public static function closeForStays(array|Collection $stayIds, string $reason): int
    {
        $n = 0;
        foreach (UpsellOrder::whereIn('reservation_room_id', collect($stayIds)->all())->whereIn('status', ['requested', 'confirmed'])->get() as $o) {
            if ($o->stay_fixed_charge_id) {
                StayFixedCharge::whereKey($o->stay_fixed_charge_id)->update(['is_active' => false]);
            }
            $o->update(['status' => 'cancelled', 'incentive_amount' => 0, 'staff_note' => $reason, 'closed_by' => auth()->id(), 'closed_at' => now()]);
            $n++;
        }

        return $n;
    }

    /** At check-out: requests nobody confirmed are declined. */
    public static function onCheckout(ReservationRoom $s): void
    {
        UpsellOrder::where('reservation_room_id', $s->id)->where('status', 'requested')
            ->update(['status' => 'declined', 'staff_note' => 'Not confirmed before check-out', 'closed_at' => now()]);
    }

    /** Requests whose service date has passed without a decision are declined. */
    public static function expire(): int
    {
        return UpsellOrder::where('status', 'requested')->whereDate('service_date', '<', bdate()->toDateString())
            ->update(['status' => 'declined', 'staff_note' => 'Not confirmed in time', 'closed_at' => now(), 'updated_at' => now()]);
    }

    // ───────────────────────────── presentation & e-mail ─────────────────────────────

    public static function present(Reservation $r, string $channel, Collection $offerIds, ?EmailLog $log = null): UpsellPresentation
    {
        $p = UpsellPresentation::firstOrNew(['reservation_id' => $r->id, 'channel' => $channel]);
        $p->offer_ids = $offerIds->unique()->values()->all();
        if ($log) {
            $p->email_log_id = $log->id;
            $p->sent_at = now();
        }
        $p->save();

        return $p;
    }

    /** The guest opened the offers page. */
    public static function opened(Reservation $r, string $channel, Collection $offerIds): void
    {
        $p = UpsellPresentation::firstOrNew(['reservation_id' => $r->id, 'channel' => $channel]);
        $p->offer_ids ??= $offerIds->unique()->values()->all();
        $p->opened_at ??= now();
        $p->open_count = (int) $p->open_count + 1;
        $p->save();
    }

    /** Offers across the reservation's pending rooms: [stay_id => Collection of offers]. */
    public static function reservationOffers(Reservation $r, string $channel): Collection
    {
        $status = $channel === 'portal' ? ['checked_in'] : ['reserved'];

        return $r->rooms()->with('roomType', 'room', 'reservation')->whereIn('status', $status)->get()
            ->mapWithKeys(fn ($s) => [$s->id => ['stay' => $s, 'offers' => self::offersFor($s, $channel)]]);
    }

    public static function sendOffers(Reservation $r, ?string $to = null): EmailLog
    {
        $r->loadMissing('guest');
        $groups = self::reservationOffers($r, 'pre_arrival');
        $offers = $groups->flatMap(fn ($g) => $g['offers']);
        if ($offers->isEmpty()) {
            throw new RuntimeException("No offers are available for {$r->reservation_no} right now.");
        }
        $top = $offers->unique(fn ($x) => $x['offer']->id)->take(6)->values();
        $log = MailService::send($to ?? $r->guest?->email, new UpsellOffersMail($r, Tenant::hotel(), $top), 'upsell_offers', $r);
        if ($log->status === 'sent') {
            self::present($r, 'pre_arrival', $top->map(fn ($x) => $x['offer']->id), $log);
        }

        return $log;
    }

    /** Hourly: expire stale requests, e-mail offers to confirmed arrivals inside the window (once per reservation). */
    public static function run(): array
    {
        $expired = self::expire();
        $sent = $failed = 0;
        $cfg = self::cfg();
        if ($cfg['enabled'] && $cfg['auto_email']) {
            $from = bdate()->copy()->addDay()->toDateString();
            $to = bdate()->copy()->addDays($cfg['days_before'])->toDateString();
            $due = Reservation::with('guest')->where('status', 'confirmed')->whereBetween('arrival_date', [$from, $to])
                ->whereHas('guest', fn ($q) => $q->whereNotNull('email')->where('email', '!=', ''))
                ->whereNotExists(fn ($q) => $q->from('upsell_presentations as p')->whereColumn('p.reservation_id', 'reservations.id')
                    ->where('p.channel', 'pre_arrival')->whereNotNull('p.sent_at'))
                ->orderBy('arrival_date')->limit(200)->get();
            foreach ($due as $r) {
                try {
                    self::sendOffers($r)->status === 'sent' ? $sent++ : $failed++;
                } catch (RuntimeException) {
                    // nothing to offer this guest (sold out, not eligible) – try again next run
                }
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'expired' => $expired];
    }

    // ───────────────────────────── statistics ─────────────────────────────

    public static function pendingCount(): int
    {
        return UpsellOrder::where('status', 'requested')->count();
    }

    public static function stats(Carbon $from, Carbon $to): array
    {
        $range = [$from->copy()->startOfDay(), $to->copy()->endOfDay()];
        $orders = UpsellOrder::whereBetween('created_at', $range);
        $conf = (clone $orders)->where('status', 'confirmed');
        $pres = UpsellPresentation::whereBetween('created_at', $range);
        $presented = (clone $pres)->count();
        $converted = (clone $pres)->whereNotNull('converted_at')->count();

        return ['revenue' => (float) (clone $conf)->sum('amount'), 'orders' => (clone $conf)->count(), 'all' => (clone $orders)->count(),
            'presented' => $presented, 'opened' => (clone $pres)->whereNotNull('opened_at')->count(), 'converted' => $converted,
            'conversion' => $presented ? round($converted * 100 / $presented, 1) : null,
            'by_kind' => (clone $conf)->groupBy('kind')->selectRaw('kind, count(*) n, sum(amount) amt')->get()->keyBy('kind'),
            'by_channel' => (clone $conf)->groupBy('channel')->selectRaw('channel, count(*) n, sum(amount) amt')->get()->keyBy('channel'),
            'incentives' => (float) (clone $conf)->sum('incentive_amount')];
    }
}
