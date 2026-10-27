<?php

namespace App\Services;

use App\Models\FolioTransaction;
use App\Models\FormCRecord;
use App\Models\KeyCard;
use App\Models\PosOrder;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\RoomMove;
use App\Models\RoomOutage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StayService
{
    /** What the existing check-in screen still requires. Does not write. */
    public static function checkInReady(ReservationRoom $stay): string
    {
        if ($stay->status === 'checked_in') {
            return 'Checked in.';
        }
        if ($stay->status !== 'reserved') {
            return ucwords(str_replace('_', ' ', (string) $stay->status)).'.';
        }
        $stay->loadMissing(['reservation.guest', 'room']);
        $guest = $stay->reservation?->guest;
        $needs = [];
        if (! $guest || ! filled($guest->id_type) || ! filled($guest->id_number)) {
            $needs[] = 'ID';
        }
        if (! $guest || ! filled($guest->phone)) {
            $needs[] = 'phone';
        }
        if (! $stay->room_id) {
            $needs[] = 'a room';
        }
        if ($needs) {
            return 'Needs '.implode(', ', $needs).'. Check-in still uses the existing screen.';
        }
        if (in_array($stay->room?->hk_status, ['dirty', 'touch_up'], true)) {
            return 'ID and phone on file. Room is '.str_replace('_', ' ', $stay->room->hk_status).'. Check-in does not clean it.';
        }

        return 'ID and phone on file.';
    }

    public static function checkIn(ReservationRoom $stay, int $roomId): ReservationRoom
    {
        $in = self::doCheckIn($stay, $roomId);
        try {
            GuestPortalService::onCheckIn($in);
        } catch (\Throwable $e) {
            report($e);
        }
        try {
            FormCService::onCheckIn($in); // v3.4: foreign national → Form C due within 24 h
        } catch (\Throwable $e) {
            report($e); // never blocks a check-in
        }
        try {
            GuestMessageService::onCheckIn($in); // v3.5: messages / parcels that arrived before the guest are announced
        } catch (\Throwable $e) {
            report($e);
        }
        try {
            CallAccountingService::onCheckIn($in); // v3.7: PABX check-in – extension opened with the default class of service
        } catch (\Throwable $e) {
            report($e);
        }
        try {
            KeyCardService::onCheckIn($in);        // v3.7: key cards encoded automatically when configured
        } catch (\Throwable $e) {
            report($e);
        }
        try {
            RobitaService::onCheckIn($in);         // v7.57: the room lock is provisioned for the stay
        } catch (\Throwable $e) {
            report($e); // never blocks a check-in
        }
        try {
            IptvService::onCheckIn($in);           // v7.1: TV PIN issued; the room TV shows the personalised welcome
        } catch (\Throwable $e) {
            report($e);
        }

        return $in;
    }

    protected static function doCheckIn(ReservationRoom $stay, int $roomId): ReservationRoom
    {
        return DB::transaction(function () use ($stay, $roomId) {
            // Keep the lifecycle lock order reservation → stay → room so
            // check-in, cancellation and checkout cannot deadlock each other.
            $reservation = Reservation::whereKey($stay->reservation_id)->lockForUpdate()->firstOrFail();
            $stay = ReservationRoom::whereKey($stay->id)->lockForUpdate()->firstOrFail();
            $stay->setRelation('reservation', $reservation);
            if ($stay->status !== 'reserved') {
                throw new RuntimeException('Only reserved rooms can be checked in.');
            }
            $room = Room::lockForUpdate()->findOrFail($roomId);
            if ($room->status !== 'vacant') {
                throw new RuntimeException("Room {$room->room_no} is not vacant ({$room->status}).");
            }
            $checkInTo = $stay->departure_date->gt(bdate()) ? $stay->departure_date : bdate()->copy()->addDay();
            if (! AvailabilityService::roomAvailableForReservation($room->id, bdate(), $checkInTo, $stay->id)) {
                throw new RuntimeException("Room {$room->room_no} is blocked for this stay (outage, lease, or another reservation).");
            }
            if ($room->room_type_id !== $stay->room_type_id && ! request()->boolean('allow_upgrade', true)) {
                throw new RuntimeException('Room type mismatch.');
            }
            $conflict = AvailabilityService::blockingStays(bdate(), $stay->departure_date)
                ->where('room_id', $room->id)->where('id', '!=', $stay->id)->exists();
            if ($conflict) {
                throw new RuntimeException("Room {$room->room_no} is blocked by another reservation in this period.");
            }
            if ($stay->arrival_date->gt(bdate())) {
                throw new RuntimeException('Arrival date is in the future (business date '.bdate()->format('d M Y').').');
            }
            $stay->update([
                'room_id' => $room->id, 'status' => 'checked_in', 'arrival_date' => bdate()->toDateString(),
                'checked_in_at' => now(), 'checked_in_by' => auth()->id(),
            ]);
            if ($stay->departure_date->lte(bdate())) {
                $stay->update(['departure_date' => bdate()->addDay()->toDateString()]);
            }
            $room->update(['status' => 'occupied']);
            $folio = FolioService::openForStay($stay);
            // Move any reservation-level advance folio payments (unassigned) into this folio
            \App\Models\Folio::where('reservation_id', $stay->reservation_id)->whereNull('reservation_room_id')->where('status', 'open')
                ->each(function ($adv) use ($folio) {
                    $adv->transactions()->update(['folio_id' => $folio->id]);
                    $adv->delete();
                });
            $stay->reservation->syncStatus();
            ActivityLogger::log('frontdesk', 'checkin', "Checked in {$stay->reservation->reservation_no} to room {$room->room_no}", $stay);

            return $stay->fresh();
        });
    }

    /** Nights already charged for this stay (business dates). */
    public static function postedNights(ReservationRoom $stay): array
    {
        if (! $stay->folio) {
            return [];
        }

        return FolioTransaction::where('folio_id', $stay->folio->id)->where('category', 'room')
            ->where('is_void', false)->where('source_type', 'night')
            ->pluck('reference')->all();
    }

    public static function postNight(ReservationRoom $stay, Carbon $night): ?FolioTransaction
    {
        $folio = FolioService::openForStay($stay);
        FixedChargeService::postForNight($stay, $folio, $night);
        PackageService::allocateAddons($stay); // v5.5 add-on packages earn their entitlement once the charge has posted
        $key = $night->toDateString();
        if (in_array($key, self::postedNights($stay), true)) {
            return null;
        }
        $rate = (float) $stay->rate;
        $tax = TaxService::roomTax($rate);
        $roomNo = $stay->room?->room_no;
        $tx = FolioService::charge($folio, 'room', "Room Tariff - Room {$roomNo} ({$night->format('d M')})", $rate, false,
            ['tax_amount' => $tax['amount'], 'tax_rate' => $tax['rate'], 'source_type' => 'night', 'reference' => $key]);
        if ((float) $stay->extra_charge > 0) {
            FolioService::charge($folio, 'extra_bed', "Extra Person Charge ({$night->format('d M')})", (float) $stay->extra_charge, false,
                ['tax_rate' => $tax['rate'], 'source_type' => 'night_extra', 'reference' => $key]);
        }
        PackageService::allocate($stay, $night, $tx); // v5.5 breakfast / dinner … included in the rate

        return $tx;
    }

    /** Post any not-yet-charged nights up to (excluding) $until. Day-use = at least 1 night. */
    public static function postPendingNights(ReservationRoom $stay, Carbon $until): void
    {
        $d = $stay->arrival_date->copy();
        $end = $until->copy()->min($stay->departure_date);
        while ($d->lt($end)) {
            self::postNight($stay, $d);
            $d->addDay();
        }
        if (empty(self::postedNights($stay->fresh('folio')))) {
            self::postNight($stay, $stay->arrival_date->copy());
        }
        // One-off packages (e.g. airport drop on the departure day) that no night covered yet
        if ($folio = $stay->fresh('folio')->folio) {
            FixedChargeService::postPendingOnce($stay, $folio, $until);
            PackageService::allocateAddons($stay); // v5.5
        }
    }

    /** Room nights and fixed charges checkout would post. Does not write. */
    public static function quoteCheckoutFigures(ReservationRoom $stay): array
    {
        if ($stay->status !== 'checked_in') {
            throw new RuntimeException('Stay is not checked in. Nothing was changed.');
        }
        $stay->loadMissing(['room', 'folio', 'reservation']);
        $posted = self::postedNights($stay);
        $cursor = $stay->arrival_date->copy()->startOfDay();
        $end = bdate()->copy()->startOfDay()->min($stay->departure_date->copy()->startOfDay());
        $nights = [];
        while ($cursor->lt($end)) {
            if (! in_array($cursor->toDateString(), $posted, true)) {
                $nights[] = $cursor->copy();
            }
            $cursor->addDay();
        }
        if ($posted === [] && $nights === []) {
            $nights[] = $stay->arrival_date->copy()->startOfDay();
        }
        $missing = count($nights);
        $tax = TaxService::roomTax((float) $stay->rate);
        $roomDue = round($missing * (float) $stay->rate, 2);
        $taxDue = round($missing * $tax['amount'], 2);
        $extra = (float) $stay->extra_charge;
        $extraDue = round($missing * $extra, 2);
        $extraTax = round($missing * round($extra * $tax['rate'] / 100, 2), 2);
        $fixed = self::unpostedFixed($stay, $nights, bdate());
        $balance = $stay->folio ? round($stay->folio->balance(), 2) : null;
        $add = round($roomDue + $taxDue + $extraDue + $extraTax + $fixed['net'] + $fixed['tax'], 2);
        $due = $balance === null ? null : round($balance + $add, 2);

        return compact('missing', 'roomDue', 'taxDue', 'extraDue', 'extraTax', 'fixed', 'balance', 'due');
    }

    /** What checkout would do. Does not post a night, close a folio, or change the room. */
    public static function quoteCheckout(ReservationRoom $stay): string
    {
        $fig = self::quoteCheckoutFigures($stay);
        $roomNo = $stay->room?->room_no ?? 'unassigned';
        $parts = ["Checkout would mark room {$roomNo} vacant and dirty."];
        if ($fig['missing'] > 0) {
            $extra = $fig['extraDue'] > 0 ? ', extra '.money($fig['extraDue']).' plus tax '.money($fig['extraTax']) : '';
            $parts[] = $fig['missing'].' room night(s) are not on the folio yet ('.money($fig['roomDue']).' plus tax '.money($fig['taxDue']).$extra.').';
        } else {
            $parts[] = 'Room nights up to the business date are already on the folio.';
        }
        $parts[] = $fig['fixed']['count'] > 0
            ? $fig['fixed']['count'].' fixed charge(s) would be added ('.money($fig['fixed']['net']).' plus tax '.money($fig['fixed']['tax']).').'
            : 'No unposted fixed charge would be added.';
        $parts[] = 'Included packages are taken from the room charge and do not add to this figure.';
        if ($fig['balance'] === null) {
            $parts[] = 'No folio is open. Checkout cannot close a bill that does not exist.';
        } else {
            $parts[] = 'Current balance is '.money($fig['balance']).'. Balance to settle would be '.money($fig['due']).'. The folio must be zero before checkout can close it.';
        }
        $holds = self::checkoutHolds($stay);
        if ($holds['pos']['count']) {
            $parts[] = $holds['pos']['count'].' unsettled POS order(s) would block checkout.';
        }
        $parts[] = $holds['trips']['count']
            ? 'Transport waiting on the room bill: '.money($holds['trips']['total']).' ('.$holds['trips']['count'].'). It blocks checkout until it is added or changed.'
            : 'Transport waiting on the room bill: none.';
        $parts[] = $holds['activities']['count']
            ? 'Activities that would block checkout: '.money($holds['activities']['total']).' ('.$holds['activities']['count'].'). They are not on the bill.'
            : 'Activities that would block checkout: none.';
        $parts[] = $holds['laundry']['count']
            ? 'Open laundry not posted by checkout: '.money($holds['laundry']['total']).' ('.$holds['laundry']['count'].').'
            : 'Open laundry not posted by checkout: none.';
        if ($holds['formc']['count']) {
            $parts[] = 'Form C '.$holds['formc']['form_no'].' is still pending and would block checkout.';
        }
        $cards = KeyCard::where('reservation_room_id', $stay->id)->where('status', 'active')->count();
        if ($cards) {
            $parts[] = $cards.' active key card(s) would be expired by the existing checkout.';
        }
        $parts[] = 'Nothing is saved until you complete the existing checkout.';

        return implode(' ', $parts);
    }

    /** Amounts that can stop or sit beside checkout. Reads only. */
    public static function checkoutHolds(ReservationRoom $stay): array
    {
        $trips = TransportService::pendingForStay($stay->id);
        $acts = ConciergeService::pendingForStay($stay->id);
        $laundry = LaundryService::pendingForStay($stay->id);
        // An unsettled POS order and a pending Form C stop doCheckOut() as well, so
        // they belong in the same read-only picture: a checkout quote, the desk
        // board and the day report must not disagree with the guard that runs.
        $pos = PosOrder::where('reservation_room_id', $stay->id)->whereIn('status', ['open', 'billed'])->get();
        $fc = (FormCService::enabled() && (FormCService::cfg()['block_checkout'] ?? '0') === '1')
            ? FormCRecord::where('reservation_room_id', $stay->id)->where('status', 'pending')->first()
            : null;

        return [
            'trips' => ['count' => $trips->count(), 'total' => round((float) $trips->sum('total'), 2)],
            'activities' => ['count' => $acts->count(), 'total' => round((float) $acts->sum('gross'), 2)],
            'laundry' => ['count' => $laundry->count(), 'total' => round((float) $laundry->sum('total'), 2)],
            'pos' => ['count' => $pos->count(), 'total' => round((float) $pos->sum('total'), 2)],
            'formc' => ['count' => $fc ? 1 : 0, 'total' => 0.0, 'form_no' => $fc?->form_no],
            // Only these four actually refuse a checkout. Open laundry is posted
            // separately and does not hold the guest at the desk.
            'blocking' => ($pos->count() ? 1 : 0) + $trips->count() + $acts->count() + ($fc ? 1 : 0),
        ];
    }

    /**
     * Post everything still sitting outside the folio onto it, so a departure
     * can actually check out: unsettled outlet bills, pending laundry and
     * transport trips billed to the room. Anything that needs a human first
     * (guest QR items awaiting confirmation, laundry not yet processed, an
     * activity waiting on its operator, Form C) is reported as skipped rather
     * than forced. Returns counts, totals and the skip reasons.
     */
    public static function assembleBill(ReservationRoom $stay): array
    {
        if ($stay->status !== 'checked_in') {
            throw new RuntimeException('Stay is not checked in.');
        }
        $done = ['pos' => 0, 'pos_total' => 0.0, 'laundry' => 0, 'laundry_total' => 0.0, 'trips' => 0, 'skipped' => []];

        DB::transaction(function () use ($stay, &$done) {
            foreach (PosOrder::where('reservation_room_id', $stay->id)->whereIn('status', ['open', 'billed'])->orderBy('id')->get() as $o) {
                if ($o->items()->where('is_pending', true)->where('is_cancelled', false)->exists()) {
                    $done['skipped'][] = "outlet bill {$o->order_no} has guest QR items awaiting confirmation";
                    continue;
                }
                PosOpsService::postToRoom($o, $stay);
                $done['pos']++;
                $done['pos_total'] += (float) $o->total;
            }
            foreach (LaundryService::pendingForStay($stay->id) as $o) {
                if (! in_array($o->status, ['processing', 'ready'], true) || (float) $o->total <= 0) {
                    $done['skipped'][] = "laundry {$o->order_no} is {$o->statusLabel()}";
                    continue;
                }
                LaundryService::deliver($o);
                $done['laundry']++;
                $done['laundry_total'] += (float) $o->total;
            }
            $done['trips'] = TransportService::postPendingForStay($stay);
        });

        return $done;
    }

    /** Fixed charges postPendingNights would add. Reads the folio; does not post. */
    protected static function unpostedFixed(ReservationRoom $stay, array $nights, Carbon $until): array
    {
        $charges = \App\Models\StayFixedCharge::where('reservation_room_id', $stay->id)->where('is_active', true)->get();
        $net = 0.0;
        $tax = 0.0;
        $count = 0;
        $seenOnce = [];
        $add = function ($c, ?string $nightKey) use (&$net, &$tax, &$count, &$seenOnce, $stay) {
            if ($c->frequency === 'once' && ((int) $c->times_posted > 0 || isset($seenOnce[$c->id]))) {
                return;
            }
            $already = $stay->folio && $stay->folio->transactions()->where('source_type', 'fixed')->where('source_id', $c->id)->where('is_void', false)
                ->when($c->frequency === 'daily', fn ($q) => $q->where('reference', $nightKey))->exists();
            if ($already) {
                return;
            }
            $amount = round((float) $c->amount, 2);
            $net += $amount;
            $tax += $c->apply_tax ? TaxService::forCategory($c->category, $amount)['amount'] : 0;
            $count++;
            if ($c->frequency === 'once') {
                $seenOnce[$c->id] = true;
            }
        };
        foreach ($nights as $night) {
            foreach ($charges as $c) {
                if ($night->lt($c->start_date) || ($c->end_date && $night->gt($c->end_date))) {
                    continue;
                }
                $add($c, $night->toDateString());
            }
        }
        foreach ($charges as $c) {
            if ($c->frequency !== 'once' || (int) $c->times_posted > 0 || isset($seenOnce[$c->id]) || $c->start_date->gt($until)) {
                continue;
            }
            $add($c, null);
        }

        return ['net' => round($net, 2), 'tax' => round($tax, 2), 'count' => $count];
    }

    public static function checkOut(ReservationRoom $stay, bool $force = false): ReservationRoom
    {
        $out = self::doCheckOut($stay, $force);
        // Run every post-commit hook independently: a failure in one subsystem
        // must not leave the others (or the caller) believing checkout failed.
        foreach ([
            fn () => GuestPortalService::closeForStay($out),
            fn () => UpsellService::onCheckout($out),       // v4.1: unconfirmed early / late requests lapse
            fn () => SecurityService::onCheckOut($out),     // v4.2: expected visitors lapse, visitors still inside are flagged
            fn () => FormCService::onCheckOut($out),        // v3.4: the departure now has to be reported to the FRRO
            fn () => WakeupService::onCheckOut($out),       // v3.5: pending wake-up calls are cancelled
            fn () => GuestMessageService::onCheckOut($out), // v3.5: undelivered messages lapse (parcels stay to be forwarded)
            fn () => CallAccountingService::onCheckOut($out), // v3.7: extension barred on the PABX
            fn () => KeyCardService::onCheckOut($out),        // v3.7: the stay's key cards stop working
            fn () => RobitaService::onCheckOut($out),         // v7.57: revoke the door credential, not just let it expire
            fn () => IptvService::onCheckOut($out),           // v7.1: end TV session, rentals and welcome screen
            fn () => \App\Models\HousekeepingTask::where('room_id', $out->room_id)->whereDate('task_date', bdate()->toDateString())
                ->where('status', 'pending')->where('task_type', 'cleaning')
                ->update(['task_type' => 'departure', 'credits' => HousekeepingService::credits('departure')]),
        ] as $hook) {
            self::runPostCheckoutHook($hook);
        }
        if ($out->reservation->status === 'checked_out') {
            self::runPostCheckoutHook(fn () => MessageService::autoThankYou($out->reservation, $out->folio()->first()));
            self::runPostCheckoutHook(fn () => FeedbackService::scheduleFor($out->reservation)); // v3.1
        }

        return $out;
    }

    /** Report a post-commit failure without changing the result of the committed checkout. */
    protected static function runPostCheckoutHook(callable $hook): void
    {
        try {
            $hook();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    protected static function doCheckOut(ReservationRoom $stay, bool $force): ReservationRoom
    {
        return DB::transaction(function () use ($stay, $force) {
            // The stay and its room must be one serialized checkout command;
            // otherwise two agents can both post the final night and close the
            // same folio. Keep the common reservation → stay → room order.
            $reservation = Reservation::whereKey($stay->reservation_id)->lockForUpdate()->firstOrFail();
            $stay = ReservationRoom::whereKey($stay->id)->lockForUpdate()->firstOrFail();
            $room = $stay->room_id ? Room::whereKey($stay->room_id)->lockForUpdate()->first() : null;
            $stay->setRelation('reservation', $reservation);
            $stay->loadMissing(['folio']);
            $stay->setRelation('room', $room);
            if ($stay->status !== 'checked_in') {
                throw new RuntimeException('Stay is not checked in.');
            }
            self::postPendingNights($stay, bdate());
            $folio = $stay->folio()->first();
            $pendingPos = \App\Models\PosOrder::where('reservation_room_id', $stay->id)->whereIn('status', ['open', 'billed'])->count();
            if ($pendingPos && ! $force) {
                throw new RuntimeException("There are {$pendingPos} unsettled POS order(s) for this room.");
            }
            // v3.3: a room-billed airport drop after check-out must be on the final bill first
            $pendingTrips = TransportService::pendingForStay($stay->id);
            if ($pendingTrips->isNotEmpty() && ! $force) {
                throw new RuntimeException('Transport '.$pendingTrips->pluck('trip_no')->implode(', ').' ('.money($pendingTrips->sum('total')).') is billed to the room but not on the bill yet – add it to the bill, or change it to direct payment / complimentary.');
            }
            // v5.9: an activity still waiting for the operator would be charged to a closed bill
            $pendingActs = \App\Services\ConciergeService::pendingForStay($stay->id);
            if ($pendingActs->isNotEmpty() && ! $force) {
                throw new RuntimeException('Activity booking '.$pendingActs->pluck('number')->implode(', ').' is to be charged to the room but the operator has not confirmed it yet – confirm or cancel it, or change it to "Guest pays the vendor".');
            }
            // v3.4: optionally refuse check-out while the Form C arrival report is outstanding
            if (! $force && FormCService::enabled() && FormCService::cfg()['block_checkout'] === '1'
                && ($fc = \App\Models\FormCRecord::where('reservation_room_id', $stay->id)->where('status', 'pending')->first())) {
                throw new RuntimeException("Form C {$fc->form_no} ({$fc->name()}) has not been submitted to the FRRO yet – file it before check-out.");
            }
            if ($folio && $folio->status === 'open') {
                FolioService::close($folio);
            }
            $stay->update(['status' => 'checked_out', 'checked_out_at' => now(), 'checked_out_by' => auth()->id(), 'departure_date' => bdate()->max($stay->arrival_date->copy()->addDay())->toDateString()]);
            $stay->room?->update(['status' => 'vacant', 'hk_status' => 'dirty']);
            $stay->reservation->syncStatus();
            CommissionService::accrue($stay->fresh(['reservation.company']));
            ActivityLogger::log('frontdesk', 'checkout', "Checked out {$stay->reservation->reservation_no} from room {$stay->room?->room_no}", $stay);

            return $stay->fresh();
        });
    }

    public static function moveRoom(ReservationRoom $stay, int $toRoomId, ?float $newRate, ?string $reason): void
    {
        $fromId = null;
        DB::transaction(function () use ($stay, $toRoomId, $newRate, $reason, &$fromId) {
            $reservation = Reservation::whereKey($stay->reservation_id)->lockForUpdate()->firstOrFail();
            $stay = ReservationRoom::whereKey($stay->id)->lockForUpdate()->firstOrFail();
            $stay->setRelation('reservation', $reservation);
            if ($stay->status !== 'checked_in') {
                throw new RuntimeException('Only an in-house stay can be moved.');
            }
            $fromId = $stay->room_id;
            $roomIds = collect([$stay->room_id, $toRoomId])->filter()->unique()->sort()->values();
            $rooms = Room::whereIn('id', $roomIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $to = $rooms->get($toRoomId) ?? Room::findOrFail($toRoomId);
            if ($to->status !== 'vacant') {
                throw new RuntimeException("Room {$to->room_no} is not vacant.");
            }
            $fromDate = bdate()->copy();
            $toDate = $stay->departure_date->gt($fromDate) ? $stay->departure_date : $fromDate->copy()->addDay();
            if (! AvailabilityService::roomAvailableForReservation($to->id, $fromDate, $toDate, $stay->id)) {
                throw new RuntimeException("Room {$to->room_no} is blocked for the remainder of this stay (lease, outage, or another reservation).");
            }
            $from = $stay->room_id ? $rooms->get($stay->room_id) : null;
            RoomMove::create([
                'reservation_room_id' => $stay->id, 'from_room_id' => $from?->id, 'to_room_id' => $to->id,
                'old_rate' => $stay->rate, 'new_rate' => $newRate ?? $stay->rate, 'reason' => $reason, 'user_id' => auth()->id(),
            ]);
            $stay->update(['room_id' => $to->id, 'room_type_id' => $to->room_type_id, 'rate' => $newRate ?? $stay->rate]);
            $to->update(['status' => 'occupied']);
            $from?->update(['status' => 'vacant', 'hk_status' => 'dirty']);
            ActivityLogger::log('frontdesk', 'room_move', "Room move {$from?->room_no} → {$to->room_no} ({$stay->reservation->reservation_no})", $stay);
            WakeupService::onRoomMove($stay); // v3.5: the wake-up call follows the guest
        });
        try {
            CallAccountingService::onRoomMove($stay->fresh(), $fromId); // v3.7: PABX moves the guest's name / class of service
            KeyCardService::onRoomMove($stay->fresh(), $fromId);        // v3.7: old cards cancelled, new ones encoded
        } catch (\Throwable $e) {
            report($e);
        }
        try {
            RobitaService::onRoomMove($stay->fresh(), $fromId);         // v7.57: the old door forgets the guest
        } catch (\Throwable $e) {
            report($e);
        }
        try {
            IptvService::onRoomMove($stay->fresh(), $fromId);           // v7.1: the old room's TV forgets the guest
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Active dated block on this room for any night in [from, to), or null. */
    public static function outageMessage(int $roomId, $from, $to): ?string
    {
        $o = RoomOutage::covering($from, $to, $roomId)->with('room')->first();
        if (! $o) {
            return null;
        }

        return 'Room '.($o->room->room_no ?? $roomId).' is blocked '.$o->starts_on->format('d M').' to '.$o->ends_on->format('d M').' ('.$o->reason.').';
    }

    /**
     * Extend or shorten a stay. Does not post or reverse folio lines — night audit owns room charges.
     * Returns the expected room-charge change so the desk can show it before the audit runs.
     */
    /** Availability and money impact of a date change. Does not write. */
    public static function quoteDates(ReservationRoom $stay, $arrival, $departure): array
    {
        if (! in_array($stay->status, ['reserved', 'checked_in'], true)) {
            throw new RuntimeException('Only a reserved or in-house stay can be extended or shortened.');
        }
        $arrival = Carbon::parse($arrival)->startOfDay();
        $departure = Carbon::parse($departure)->startOfDay();
        if ($departure->lte($arrival)) {
            throw new RuntimeException('Departure must be after arrival.');
        }
        if ($stay->status === 'reserved' && $arrival->lt(bdate()->copy()->startOfDay())) {
            throw new RuntimeException('A reserved stay cannot be moved before the current business date.');
        }
        if ($stay->status === 'checked_in' && ! $arrival->equalTo($stay->arrival_date->copy()->startOfDay())) {
            throw new RuntimeException('An in-house arrival date cannot be moved. Split the stay or move the room instead.');
        }
        if ($stay->status === 'checked_in' && $departure->lte(bdate())) {
            throw new RuntimeException('To finish the stay today, check the guest out. A shortened in-house stay must still depart after the business date.');
        }

        $oldArrival = $stay->arrival_date->copy()->startOfDay();
        $oldDeparture = $stay->departure_date->copy()->startOfDay();
        $oldNights = (int) $oldArrival->diffInDays($oldDeparture);
        if ($stay->room_id) {
            $clash = AvailabilityService::blockingStays($arrival, $departure)->where('room_id', $stay->room_id)->where('id', '!=', $stay->id)->exists();
            if ($clash) {
                throw new RuntimeException('That room is booked by another guest on the new dates. Move one of the stays first.');
            }
            if ($msg = self::outageMessage($stay->room_id, $arrival, $departure)) {
                throw new RuntimeException($msg);
            }
            $roomFree = $stay->status === 'checked_in'
                ? AvailabilityService::freeRooms(bdate(), $departure, null, $stay->id)->contains('id', $stay->room_id)
                : AvailabilityService::roomAvailableForReservation($stay->room_id, $arrival, $departure, $stay->id);
            if (! $roomFree) {
                throw new RuntimeException('That room is not available for the new dates (it may be leased, out of order, or occupied).');
            }
        } else {
            $typeName = $stay->roomType->name ?? 'that type';
            if ($arrival->lt($oldArrival) && AvailabilityService::typeAvailable($stay->room_type_id, $arrival, $oldArrival) < 1) {
                throw new RuntimeException('No '.$typeName.' is free for the earlier arrival.');
            }
            if ($departure->gt($oldDeparture) && AvailabilityService::typeAvailable($stay->room_type_id, $oldDeparture, $departure) < 1) {
                throw new RuntimeException('No '.$typeName.' is free for the extra night(s).');
            }
        }
        $newNights = (int) $arrival->diffInDays($departure);
        $rate = (float) $stay->rate;
        $tax = TaxService::roomTax($rate);
        $delta = $newNights - $oldNights;

        return [
            'arrival' => $arrival, 'departure' => $departure,
            'old_arrival' => $oldArrival, 'old_departure' => $oldDeparture,
            'old_nights' => $oldNights, 'new_nights' => $newNights, 'delta_nights' => $delta,
            'delta_room' => round($delta * $rate, 2), 'delta_tax' => round($delta * $tax['amount'], 2),
            'new_room' => round($newNights * $rate, 2), 'new_tax' => round($newNights * $tax['amount'], 2),
            'new_total' => round($newNights * ($rate + $tax['amount']), 2), 'rate' => $rate,
        ];
    }

    public static function changeDates(ReservationRoom $stay, $arrival, $departure): array
    {
        $checkedIn = false;
        $updated = null;
        $quote = DB::transaction(function () use ($stay, $arrival, $departure, &$checkedIn, &$updated) {
            $reservation = Reservation::whereKey($stay->reservation_id)->lockForUpdate()->firstOrFail();
            $stay = ReservationRoom::whereKey($stay->id)->lockForUpdate()->firstOrFail();
            $stay->setRelation('reservation', $reservation);
            // A date change can turn an assigned stay into an overlap with an
            // unassigned stay of the same type, so always take the type's
            // physical-inventory lock before checking the new range.
            AvailabilityService::lockRoomInventory([$stay->room_type_id]);
            if ($stay->room_id) {
                Room::whereKey($stay->room_id)->lockForUpdate()->first();
            }
            $quote = self::quoteDates($stay, $arrival, $departure);
            $stay->update(['arrival_date' => $quote['arrival']->toDateString(), 'departure_date' => $quote['departure']->toDateString()]);
            $res = $stay->reservation;
            $res->update([
                'arrival_date' => $res->rooms()->min('arrival_date'),
                'departure_date' => $res->rooms()->max('departure_date'),
            ]);
            $checkedIn = $stay->status === 'checked_in';
            $updated = $stay->fresh();
            ActivityLogger::log('reservations', 'amend', "Stay {$res->reservation_no} dates {$quote['old_arrival']->format('d M')}–{$quote['old_departure']->format('d M')} → {$quote['arrival']->format('d M')}–{$quote['departure']->format('d M')}", $stay, [
                'old' => ['arrival' => $quote['old_arrival']->toDateString(), 'departure' => $quote['old_departure']->toDateString()],
                'new' => ['arrival' => $quote['arrival']->toDateString(), 'departure' => $quote['departure']->toDateString()],
            ]);

            return $quote;
        });
        if ($checkedIn) {
            try {
                KeyCardService::onExtend($updated);
            } catch (\Throwable $e) {
                report($e);
            }
            try {
                RobitaService::onExtend($updated); // v7.57: the lock learns the new departure too
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $quote;
    }

    /** What a room assignment would do. Throws the same refusals as placeStay, and writes nothing. */
    public static function describePlace(ReservationRoom $stay, int $roomId): string
    {
        if (! in_array($stay->status, ['reserved', 'checked_in'], true)) {
            throw new RuntimeException('Only a reserved or in-house stay can be assigned.');
        }
        $room = Room::with('roomType')->findOrFail($roomId);
        if ($stay->room_id === $room->id) {
            throw new RuntimeException('The stay is already in room '.$room->room_no.'.');
        }
        if (! $room->is_active || in_array($room->status, ['out_of_order', 'out_of_service'], true)) {
            throw new RuntimeException("Room {$room->room_no} is out of order.");
        }
        $from = $stay->status === 'checked_in' ? bdate()->copy() : $stay->arrival_date;
        $to = $stay->departure_date->gt($from) ? $stay->departure_date : $from->copy()->addDay();
        if (AvailabilityService::blockingStays($from, $to)->where('room_id', $room->id)->where('id', '!=', $stay->id)->exists()) {
            throw new RuntimeException("Room {$room->room_no} is booked between {$from->format('d M')} and {$stay->departure_date->format('d M')}. Move that booking first.");
        }
        if ($msg = self::outageMessage($room->id, $from, $to)) {
            throw new RuntimeException($msg);
        }
        if ($stay->status === 'checked_in' && $room->status !== 'vacant') {
            throw new RuntimeException("Room {$room->room_no} is not vacant, so an in-house guest cannot move there.");
        }
        if (! AvailabilityService::roomAvailableForReservation($room->id, $from, $to, $stay->id)) {
            throw new RuntimeException("Room {$room->room_no} is not available for this stay (it may be occupied or leased).");
        }
        if ($stay->status === 'checked_in' && ! can_do('frontdesk.roommove')) {
            throw new RuntimeException('Moving an in-house guest needs the room-move permission.');
        }
        $type = $stay->room_type_id !== $room->room_type_id ? " Room type would change to {$room->roomType->name}. Rate stays ".money($stay->rate).' unless you set a new rate on confirm.' : ' Rate stays '.money($stay->rate).'.';
        $how = $stay->status === 'checked_in' ? 'This is an audited room move. The old room becomes vacant and dirty.' : 'Nothing is saved until you confirm.';

        return ($stay->room ? "Move {$stay->reservation->reservation_no} from {$stay->room->room_no} to {$room->room_no}." : "Assign {$stay->reservation->reservation_no} to room {$room->room_no}.").$type.' '.$how;
    }

    /** What a split would do. Same refusals as splitStay. Writes nothing. */
    public static function quoteSplit(ReservationRoom $stay, $splitDate, int $roomId, ?float $rate): array
    {
        if (! in_array($stay->status, ['reserved', 'checked_in'], true)) {
            throw new RuntimeException('Only a reserved or in-house stay can be split.');
        }
        $split = Carbon::parse($splitDate)->startOfDay();
        $earliest = $stay->status === 'checked_in'
            ? bdate()->copy()->addDay()->max($stay->arrival_date->copy()->addDay())->startOfDay()
            : $stay->arrival_date->copy()->addDay()->startOfDay();
        if ($split->lt($earliest) || $split->gte($stay->departure_date->copy()->startOfDay())) {
            throw new RuntimeException('The split date must leave at least one night on each part'.($stay->status === 'checked_in' ? ', and the new part must start after today.' : '.'));
        }
        $room = Room::with('roomType')->findOrFail($roomId);
        if (! $room->is_active || in_array($room->status, ['out_of_order', 'out_of_service'], true)) {
            throw new RuntimeException("Room {$room->room_no} is out of order.");
        }
        if (AvailabilityService::blockingStays($split, $stay->departure_date)->where('room_id', $room->id)->where('id', '!=', $stay->id)->exists()) {
            throw new RuntimeException("Room {$room->room_no} is already booked on the second part of the stay.");
        }
        if ($msg = self::outageMessage($room->id, $split, $stay->departure_date)) {
            throw new RuntimeException($msg);
        }
        $roomIsAvailable = $room->id === $stay->room_id
            ? AvailabilityService::freeRooms($split, $stay->departure_date, null, $stay->id)->contains('id', $room->id)
            : AvailabilityService::roomAvailableForReservation($room->id, $split, $stay->departure_date, $stay->id);
        if (! $roomIsAvailable) {
            throw new RuntimeException("Room {$room->room_no} is not available for the second part of the stay (it may be occupied or leased).");
        }
        $arrival = $stay->arrival_date->copy()->startOfDay();
        $oldDep = $stay->departure_date->copy()->startOfDay();
        $firstNights = (int) $arrival->diffInDays($split);
        $secondNights = (int) $split->diffInDays($oldDep);
        $rate1 = (float) $stay->rate;
        $rate2 = $rate ?? $rate1;
        $tax1 = TaxService::roomTax($rate1);
        $tax2 = TaxService::roomTax($rate2);

        return [
            'split' => $split, 'room' => $room, 'old_departure' => $oldDep,
            'first_nights' => $firstNights, 'second_nights' => $secondNights,
            'rate1' => $rate1, 'rate2' => $rate2,
            'first_total' => round($firstNights * ($rate1 + $tax1['amount']), 2),
            'second_total' => round($secondNights * ($rate2 + $tax2['amount']), 2),
        ];
    }

    /** Split one stay into two segments. The second segment is a new stay on the same reservation. */
    public static function splitStay(ReservationRoom $stay, $splitDate, int $roomId, ?float $rate): ReservationRoom
    {
        return DB::transaction(function () use ($stay, $splitDate, $roomId, $rate) {
            $reservation = Reservation::whereKey($stay->reservation_id)->lockForUpdate()->firstOrFail();
            $stay = ReservationRoom::whereKey($stay->id)->lockForUpdate()->firstOrFail();
            $stay->setRelation('reservation', $reservation);
            $roomTypeIds = collect([$stay->room_type_id, Room::whereKey($roomId)->value('room_type_id')])->filter()->all();
            AvailabilityService::lockRoomInventory($roomTypeIds);
            $room = Room::with('roomType')->whereKey($roomId)->lockForUpdate()->firstOrFail();
            $quote = self::quoteSplit($stay, $splitDate, $room->id, $rate);
            $split = $quote['split'];
            $oldDep = $stay->departure_date->copy();
            $stay->update(['departure_date' => $split->toDateString()]);
            $next = $stay->reservation->rooms()->create([
                'room_type_id' => $room->room_type_id, 'room_id' => $room->id, 'rate_plan_id' => $stay->rate_plan_id,
                'guest_id' => $stay->guest_id, 'arrival_date' => $split->toDateString(), 'departure_date' => $oldDep->toDateString(),
                'rate' => $quote['rate2'], 'adults' => $stay->adults, 'children' => $stay->children,
                'extra_charge' => $stay->extra_charge, 'status' => 'reserved',
            ]);
            $res = $stay->reservation;
            $res->update([
                'arrival_date' => $res->rooms()->min('arrival_date'),
                'departure_date' => $res->rooms()->max('departure_date'),
            ]);
            ActivityLogger::log('reservations', 'split', "Split {$res->reservation_no} on {$split->format('d M')} onto room {$room->room_no}", $stay);

            return $next;
        });
    }

    /** Assign, move, upgrade or downgrade. In-house moves go through the audited room-move flow. */
    public static function placeStay(ReservationRoom $stay, int $roomId, ?float $rate, string $via): string
    {
        return DB::transaction(function () use ($stay, $roomId, $rate, $via) {
            $reservation = Reservation::whereKey($stay->reservation_id)->lockForUpdate()->firstOrFail();
            $stay = ReservationRoom::whereKey($stay->id)->lockForUpdate()->firstOrFail();
            $stay->setRelation('reservation', $reservation);
            if (! in_array($stay->status, ['reserved', 'checked_in'], true)) {
                throw new RuntimeException('Only a reserved or in-house stay can be assigned.');
            }
            $room = Room::with('roomType')->whereKey($roomId)->lockForUpdate()->firstOrFail();
            if ($stay->room_id === $room->id && $rate === null) {
                throw new RuntimeException('The stay is already in room '.$room->room_no.'.');
            }
            if (! $room->is_active || in_array($room->status, ['out_of_order', 'out_of_service'], true)) {
                throw new RuntimeException("Room {$room->room_no} is out of order.");
            }
            $from = $stay->status === 'checked_in' ? bdate()->copy() : $stay->arrival_date;
            if ($stay->room_id !== $room->id) {
                if (AvailabilityService::blockingStays($from, $stay->departure_date)->where('room_id', $room->id)->where('id', '!=', $stay->id)->exists()) {
                    throw new RuntimeException("Room {$room->room_no} is booked between {$from->format('d M')} and {$stay->departure_date->format('d M')}.");
                }
                if ($msg = self::outageMessage($room->id, $from, $stay->departure_date)) {
                    throw new RuntimeException($msg);
                }
                $to = $stay->departure_date->gt($from) ? $stay->departure_date : $from->copy()->addDay();
                if (! AvailabilityService::roomAvailableForReservation($room->id, $from, $to, $stay->id)) {
                    throw new RuntimeException("Room {$room->room_no} is not available for this stay (it may be occupied or leased).");
                }
            }
            $typeNote = $stay->room_type_id !== $room->room_type_id ? " Room type is now {$room->roomType->name}." : '';
            $kept = ' Rate kept at '.money($stay->rate).'.';
            if ($stay->status === 'checked_in' && $stay->room_id !== $room->id) {
                if (! can_do('frontdesk.roommove')) {
                    throw new RuntimeException('Moving an in-house guest needs the room-move permission.');
                }
                // The destination is already locked by this transaction; the
                // audited move also serializes the source room and writes the
                // room-move record atomically.
                self::moveRoom($stay, $room->id, $rate, $via);
                $rateNote = $rate !== null ? ' Rate is now '.money($rate).'.' : ' Rate kept at '.money($stay->fresh()->rate).'.';

                return "Guest moved to room {$room->room_no}.{$typeNote}{$rateNote}";
            }
            $oldNo = $stay->room?->room_no;
            $oldRate = (float) $stay->rate;
            $stay->update(['room_id' => $room->id, 'room_type_id' => $room->room_type_id, 'rate' => $rate ?? $stay->rate]);
            ActivityLogger::log('reservations', 'assign', "{$via}: {$stay->reservation->reservation_no} ".($oldNo ? "{$oldNo} → " : 'assigned to ').$room->room_no, $stay);
            $rateNote = $rate !== null && (float) $rate !== $oldRate
                ? ' Rate '.money($oldRate).' → '.money($rate).'. Nights already posted are not rewritten.'
                : $kept;

            return ($oldNo && $oldNo !== $room->room_no ? "Moved to room {$room->room_no}." : "Assigned to room {$room->room_no}.").$typeNote.$rateNote;
        });
    }
}
