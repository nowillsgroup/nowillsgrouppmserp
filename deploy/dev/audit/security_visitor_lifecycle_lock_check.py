from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[3]
SERVICE = (ROOT / "app/Services/SecurityService.php").read_text()
CONTROLLER = (ROOT / "app/Http/Controllers/SecurityController.php").read_text()


def method(name):
    start = re.search(rf"(?:public|protected) static function {name}\(", SERVICE).start()
    match = re.search(r"\n    (?:public|protected) static function ", SERVICE[start + 1 :])
    end = start + 1 + match.start() if match else len(SERVICE)
    return SERVICE[start:end]


class SecurityVisitorLifecycleLockTest(unittest.TestCase):
    def test_visitor_mutex_locks_reservation_then_stay_then_visitor(self):
        body = method("lockVisitor")
        reservation = body.index("Reservation::whereKey($staySnapshot->reservation_id)->lockForUpdate()")
        stay = body.index("ReservationRoom::whereKey($staySnapshot->id)->lockForUpdate()", reservation)
        visitor = body.index("Visitor::whereKey($visitor->id)->lockForUpdate()", stay)
        self.assertLess(reservation, stay)
        self.assertLess(stay, visitor)

    def test_walkin_registration_rechecks_guest_occupancy_and_privacy_under_stay_lock(self):
        body = method("register")
        reservation = body.index("Reservation::whereKey($staySnapshot->reservation_id)->lockForUpdate()")
        stay_lock = body.index("ReservationRoom::whereKey($staySnapshot->id)->lockForUpdate()", reservation)
        in_house = body.index("if ($stay->status !== 'checked_in')", stay_lock)
        privacy = body.index("if ($stay->visitor_policy === 'none' && $override === '')", in_house)
        create = body.index("$v->save();", privacy)
        self.assertLess(reservation, stay_lock)
        self.assertLess(stay_lock, in_house)
        self.assertLess(in_house, privacy)
        self.assertLess(privacy, create)

    def test_walkin_reregisters_fresh_watchlist_result_inside_transaction(self):
        body = method("register")
        transaction = body.index("return DB::transaction(function () use ($v, $override, $stayId, $d, $cfg)")
        screen = body.index("$currentHit = self::screen(", transaction)
        block = body.index("if ($currentHit && $currentHit['level'] === 'block' && $override === '')", screen)
        save = body.index("$v->save();", block)
        self.assertLess(transaction, screen)
        self.assertLess(screen, block)
        self.assertLess(block, save)

    def test_guest_override_keeps_pass_expiry_after_cutoff_from_now(self):
        body = method("register")
        cutoff = body.index("if ($now->gte($cut) && $override === '')")
        clamp = body.index("if ($now->lt($cut))", cutoff)
        until = body.index("$until = $now->copy()->addHours($cfg['pass_hours'])", clamp)
        self.assertLess(cutoff, clamp)
        self.assertLess(clamp, until)
        self.assertIn("if ($now->lt($cut))", body[body.index("return DB::transaction(function () use ($v", cutoff):])

    def test_approval_rechecks_waiting_status_and_current_guest_stay_under_mutex(self):
        body = method("approve")
        lock = body.index("self::lockVisitor($v)")
        status = body.index("if ($locked->status !== 'awaiting')", lock)
        stay = body.index("if ($locked->reservation_room_id && $stay?->status !== 'checked_in')", status)
        privacy = body.index("if ($stay?->visitor_policy === 'none')", stay)
        cutoff = body.index("if ($now->gte($cutoff))", privacy)
        save = body.index("$locked->forceFill(['status' => 'in'", cutoff)
        self.assertLess(lock, status)
        self.assertLess(status, stay)
        self.assertLess(stay, privacy)
        self.assertLess(privacy, cutoff)
        self.assertLess(cutoff, save)

    def test_arrival_rechecks_expected_status_and_host_stay_before_admitting(self):
        body = method("arrive")
        lock = body.index("self::lockVisitor($v)")
        status = body.index("if ($locked->status !== 'expected')", lock)
        stay = body.index("if ($locked->reservation_room_id && $stay?->status !== 'checked_in')", status)
        privacy = body.index("if ($locked->host_type === 'guest' && $stay?->visitor_policy === 'none' && $override === '')", stay)
        admit = body.index("$locked->fill(['status' => 'in'", privacy)
        self.assertLess(lock, status)
        self.assertLess(status, stay)
        self.assertLess(stay, privacy)
        self.assertLess(privacy, admit)

    def test_deny_rechecks_status_under_visitor_mutex(self):
        body = method("deny")
        lock = body.index("self::lockVisitor($v)")
        state = body.index("if (! in_array($locked->status, ['awaiting', 'expected'], true))", lock)
        update = body.index("$locked->forceFill(['status' => 'denied'", state)
        self.assertLess(lock, state)
        self.assertLess(state, update)

    def test_checkout_rechecks_visitor_is_still_onsite_under_mutex(self):
        body = method("checkOut")
        lock = body.index("self::lockVisitor($v)")
        state = body.index("if ($locked->status !== 'in')", lock)
        update = body.index("$locked->forceFill(['status' => 'out'", state)
        self.assertLess(lock, state)
        self.assertLess(state, update)

    def test_cancel_rechecks_expected_state_under_mutex(self):
        body = method("cancel")
        lock = body.index("self::lockVisitor($v)")
        state = body.index("if ($locked->status !== 'expected')", lock)
        update = body.index("$locked->forceFill(['status' => 'cancelled'", state)
        self.assertLess(lock, state)
        self.assertLess(state, update)

    def test_extension_rechecks_onsite_state_and_calculates_from_fresh_expiry(self):
        body = method("extend")
        lock = body.index("self::lockVisitor($v)")
        state = body.index("if ($locked->status !== 'in')", lock)
        base = body.index("$base = $locked->valid_until", state)
        update = body.index("$locked->forceFill(['valid_until' => $base", base)
        self.assertLess(lock, state)
        self.assertLess(state, base)
        self.assertLess(base, update)

    def test_policy_update_serializes_with_guest_checkin_and_rechecks_stay(self):
        body = method("setPolicy")
        reservation = body.index("Reservation::whereKey($snapshot->reservation_id)->lockForUpdate()")
        stay = body.index("ReservationRoom::whereKey($snapshot->id)->lockForUpdate()", reservation)
        state = body.index("if (! in_array($locked->portalState(), ['inhouse', 'upcoming'], true))", stay)
        update = body.index("$locked->forceFill(['visitor_policy' => $policy]", state)
        revoke = body.index("if ($policy === 'none')", update)
        pending = body.index("whereIn('status', ['expected', 'awaiting'])", revoke)
        cancel = body.index("'status' => 'cancelled'", pending)
        self.assertLess(reservation, stay)
        self.assertLess(stay, state)
        self.assertLess(state, update)
        self.assertLess(update, revoke)
        self.assertLess(revoke, pending)
        self.assertLess(pending, cancel)

    def test_portal_preregistration_serializes_capacity_and_stay_lifecycle(self):
        body = method("preRegister")
        reservation = body.index("Reservation::whereKey($staySnapshot->reservation_id)->lockForUpdate()")
        stay = body.index("ReservationRoom::whereKey($staySnapshot->id)->lockForUpdate()", reservation)
        state = body.index("if (! in_array($lockedStay->portalState(), ['inhouse', 'upcoming'], true))", stay)
        privacy = body.index("if ($lockedStay->visitor_policy === 'none')", state)
        capacity = body.index("where('status', 'expected')->count()", privacy)
        create = body.index("Visitor::create(", capacity)
        self.assertLess(reservation, stay)
        self.assertLess(stay, state)
        self.assertLess(state, privacy)
        self.assertLess(privacy, capacity)
        self.assertLess(capacity, create)

    def test_controller_uses_fresh_returned_visitor_for_success_feedback(self):
        for action in ("approve", "deny", "arrive", "checkOut", "cancel", "extend"):
            self.assertIn(f"$visitor = SecurityService::{action}(", CONTROLLER)

    def test_checkout_cleanup_only_cancels_pending_visitors_then_flags_people_still_in(self):
        body = method("onCheckOut")
        pending = body.index("whereIn('status', ['expected', 'awaiting'])")
        cancel = body.index("'status' => 'cancelled'", pending)
        onsite = body.index("where('status', 'in')", cancel)
        alert = body.index("Visitors still on site after check-out", onsite)
        self.assertLess(pending, cancel)
        self.assertLess(cancel, onsite)
        self.assertLess(onsite, alert)


if __name__ == "__main__":
    unittest.main(verbosity=2)
