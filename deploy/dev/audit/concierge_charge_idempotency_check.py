from pathlib import Path
import unittest

SERVICE = (Path(__file__).resolve().parents[2] / "../app/Services/ConciergeService.php").resolve().read_text()


def method(name, next_name):
    start = SERVICE.index(f"public static function {name}(")
    end = SERVICE.index(f"public static function {next_name}(", start)
    return SERVICE[start:end]


class ConciergeChargeIdempotencyTest(unittest.TestCase):
    def test_scheduled_booking_serializes_activity_before_stay_and_departure(self):
        start = SERVICE.index("public static function book(")
        end = SERVICE.index("protected static function checkTiming(", start)
        body = SERVICE[start:end]
        activity_lock = body.index("Activity::with('vendor')->whereKey($a->id)->lockForUpdate()")
        active_check = body.index("if (! $a->is_active || ! $a->vendor?->is_active)", activity_lock)
        stay_lock = body.index("ReservationRoom::with('guest', 'room', 'reservation.guest')->lockForUpdate()")
        departure = body.index("self::departure($a, $start->toDateString(), $time)")
        self.assertLess(activity_lock, active_check)
        self.assertLess(active_check, stay_lock)
        self.assertLess(stay_lock, departure)

    def test_booking_edits_lock_activity_departures_then_recheck_booking(self):
        body = method("update", "confirm")
        activity_lock = body.index("Activity::with('vendor')->whereKey($a->id)->lockForUpdate()")
        departures = body.index("ActivityDeparture::whereIn('id', $departureIds)")
        booking_lock = body.index("ActivityBooking::whereKey($b->id)->lockForUpdate()->firstOrFail()")
        state_check = body.index("if (! $b->isOpen())", booking_lock)
        save = body.index("])->save();", state_check)
        self.assertLess(activity_lock, departures)
        self.assertLess(departures, booking_lock)
        self.assertLess(booking_lock, state_check)
        self.assertLess(state_check, save)

    def test_confirmation_locks_and_rechecks_booking_before_charging(self):
        body = method("confirm", "postCharge")
        departure_lock = body.index("ActivityDeparture::whereKey($waitlistDepartureId)->lockForUpdate()")
        booking_lock = body.index("ActivityBooking::whereKey($b->id)->lockForUpdate()->firstOrFail()")
        status_check = body.index("if (! in_array($b->status, ['requested', 'waitlisted'], true))", booking_lock)
        charge = body.index("self::postCharge($b)")
        self.assertIn("DB::transaction(function ()", body)
        self.assertLess(departure_lock, booking_lock)
        self.assertLess(booking_lock, status_check)
        self.assertLess(status_check, charge)

    def test_cancellation_uses_departure_then_booking_lock_before_waitlist_promotion(self):
        body = method("cancel", "promoteWaitlist")
        departure_lock = body.index("ActivityDeparture::whereKey($departureId)->lockForUpdate()")
        booking_lock = body.index("ActivityBooking::whereKey($b->id)->lockForUpdate()->firstOrFail()")
        stale_status_check = body.index("if (! $b->isOpen())", booking_lock)
        cancel_write = body.index("'status' => 'cancelled'", stale_status_check)
        promote = body.index("self::promoteWaitlist($dep)")
        self.assertLess(departure_lock, booking_lock)
        self.assertLess(booking_lock, stale_status_check)
        self.assertLess(cancel_write, promote)

    def test_departure_cancellation_locks_and_rechecks_departure_and_bookings(self):
        body = method("cancelDeparture", "setDepartureStatus")
        departure_lock = body.index("ActivityDeparture::whereKey($dep->id)->lockForUpdate()->firstOrFail()")
        state = body.index("if ($dep->status === 'cancelled')", departure_lock)
        cancel = body.index("$dep->forceFill(['status' => 'cancelled', 'cancel_reason'", state)
        booking_lock = body.index("ActivityBooking::whereKey($id)->lockForUpdate()->first()", cancel)
        booking_check = body.index("if (! $b || ! $b->isOpen()", booking_lock)
        self.assertLess(departure_lock, state)
        self.assertLess(state, cancel)
        self.assertLess(cancel, booking_lock)
        self.assertLess(booking_lock, booking_check)

    def test_departure_capacity_is_rechecked_under_departure_lock(self):
        body = method("setDepartureStatus", "onReservationClosed")
        departure_lock = body.index("ActivityDeparture::whereKey($dep->id)->lockForUpdate()->firstOrFail()")
        booked = body.index("$booked = $dep->booked()", departure_lock)
        capacity_check = body.index("if ($capacity !== null && $capacity < $booked)", booked)
        save = body.index("$dep->forceFill(['status' => $status", capacity_check)
        self.assertIn("DB::transaction(function ()", body)
        self.assertLess(departure_lock, booked)
        self.assertLess(booked, capacity_check)
        self.assertLess(capacity_check, save)

    def test_waitlist_promotion_locks_departure_and_rechecks_each_entry(self):
        body = method("promoteWaitlist", "complete")
        departure_lock = body.index("ActivityDeparture::whereKey($dep->id)->lockForUpdate()")
        candidates = body.index("where('status', 'waitlisted')->orderBy('id')->pluck('id')")
        booking_lock = body.index("ActivityBooking::whereKey($id)->lockForUpdate()->first()")
        status_check = body.index("if (! $w || $w->status !== 'waitlisted'", booking_lock)
        seats = body.index("$left = $dep->seatsLeft()", status_check)
        promote = body.index("$w->forceFill(['status' => 'requested'])", seats)
        notifications = body.index("foreach ($promotedIds as $id)")
        self.assertIn("DB::transaction(function () use ($dep)", body)
        self.assertLess(departure_lock, candidates)
        self.assertLess(candidates, booking_lock)
        self.assertLess(booking_lock, status_check)
        self.assertLess(status_check, seats)
        self.assertLess(seats, promote)
        self.assertLess(body.index("});", promote), notifications)

    def test_complete_and_no_show_recheck_current_status_under_booking_lock(self):
        for name, next_name in (("complete", "noShow"), ("noShow", "recognise")):
            with self.subTest(action=name):
                body = method(name, next_name)
                lock = body.index("ActivityBooking::whereKey($b->id)->lockForUpdate()->firstOrFail()")
                state = body.index("if ($b->status !== 'confirmed')", lock)
                save = body.index("$b->forceFill(", state)
                self.assertIn("DB::transaction(function ()", body)
                self.assertLess(lock, state)
                self.assertLess(state, save)

    def test_settlement_void_rechecks_under_the_vendor_mutex(self):
        start = SERVICE.index("public static function voidSettlement(")
        end = SERVICE.index("// ───────────────────────── sub-ledger controls", start)
        body = SERVICE[start:end]
        vendor_lock = body.index("ActivityVendor::whereKey($s->activity_vendor_id)->lockForUpdate()")
        settlement_lock = body.index("ActivitySettlement::whereKey($s->id)->lockForUpdate()")
        state = body.index("if ($s->status !== 'posted')")
        reverse = body.index("LedgerService::reverse(")
        self.assertLess(vendor_lock, settlement_lock)
        self.assertLess(settlement_lock, state)
        self.assertLess(state, reverse)

    def test_commission_recognition_is_serialized_by_booking_lock(self):
        body = method("recognise", "cancelDeparture")
        lock = body.index("ActivityBooking::whereKey($b->id)->lockForUpdate()->firstOrFail()")
        already_posted = body.index("if ($b->gl_journal_id || $b->recognised_on", lock)
        journal = body.index("LedgerService::post(", already_posted)
        self.assertLess(lock, already_posted)
        self.assertLess(already_posted, journal)

    def test_settlement_recomputes_locked_vendor_bookings_inside_transaction(self):
        body = method("settle", "voidSettlement")
        vendor_lock = body.index("ActivityVendor::whereKey($v->id)->lockForUpdate()->firstOrFail()")
        rows = body.index("self::unsettled($vendor->id, $upTo, true)")
        totals = body.index("$payable = round(", rows)
        mark_settled = body.index("ActivityBooking::whereIn('id', $rows->pluck('id'))->update")
        self.assertIn("DB::transaction(function ()", body)
        self.assertLess(vendor_lock, rows)
        self.assertLess(rows, totals)
        self.assertLess(totals, mark_settled)
        unsettled = method("unsettled", "balances")
        self.assertIn("bool $forUpdate = false", unsettled)
        self.assertIn("$query->lockForUpdate()->get()", unsettled)

    def test_folio_charge_uses_booking_lock_as_idempotency_mutex(self):
        start = SERVICE.index("public static function postCharge(")
        end = SERVICE.index("protected static function voidCharge(", start)
        body = SERVICE[start:end]
        lock = body.index("ActivityBooking::whereKey($b->id)->lockForUpdate()->firstOrFail()")
        duplicate_check = body.index("if ($locked->folio_transaction_id)")
        charge = body.index("FolioService::charge(")
        save_link = body.index("'folio_transaction_id' => $tx->id")
        self.assertLess(lock, duplicate_check)
        self.assertLess(duplicate_check, charge)
        self.assertLess(charge, save_link)


if __name__ == "__main__":
    unittest.main(verbosity=2)
