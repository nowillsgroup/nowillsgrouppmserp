#!/usr/bin/env python3
"""Source-contract regression guard for reservation edit child-record safety.

Run with: python3 deploy/dev/audit/reservation_edit_safety_check.py
This is a static guard (the local workspace has no PHP runtime/test DB); it
checks the destructive FK pattern and the safeguards that prevent its return.
"""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
CONTROLLER = (ROOT / "app/Http/Controllers/ReservationController.php").read_text()
FORM = (ROOT / "resources/views/reservations/form.blade.php").read_text()
UPSELL_MIGRATION = (ROOT / "database/migrations/2026_01_01_003300_create_upsell_tables.php").read_text()
CHARGE_MIGRATION = (ROOT / "database/migrations/2026_01_01_000500_create_phase4_tables.php").read_text()
AVAILABILITY = (ROOT / "app/Services/AvailabilityService.php").read_text()


def update_method() -> str:
    start = CONTROLLER.index("public function update(Request $request, Reservation $reservation)")
    end = CONTROLLER.index("public function cancel(Request $request", start)
    return CONTROLLER[start:end]


class ReservationEditSafetyTest(unittest.TestCase):
    def test_edit_never_deletes_reserved_stay_rows(self):
        method = update_method()
        self.assertNotIn("rooms()->where('status', 'reserved')->delete()", method)
        self.assertIn("$existingStays->get((int) $line['stay_id'])->update($attributes)", method)

    def test_existing_stay_identity_is_round_tripped_by_the_form(self):
        self.assertIn("'rooms.*.stay_id' => $update ? 'nullable|integer|min:1' : 'prohibited'", CONTROLLER)
        self.assertIn("d.stay_id ?? ''", FORM)
        self.assertIn("data-k=\"stay_id\"", FORM)

    def test_edit_rejects_room_removal_so_cancellation_policy_cannot_be_bypassed(self):
        method = update_method()
        self.assertIn("$existingStays->keys()->diff($submittedStayIds)->isNotEmpty()", method)
        self.assertIn("Use the Cancel room action on the reservation page", method)
        self.assertNotIn("ReservationRoom::whereIn('id', $removedStayIds)->update", method)
        self.assertIn("if (d.stay_id) { remove.disabled = true;", FORM)

    def test_linked_active_charges_and_orders_block_ambiguous_date_or_type_edits(self):
        method = update_method()
        self.assertIn("$datesChanged || $typeChanged", method)
        self.assertIn("$stay->fixedCharges()->where('is_active', true)->exists()", method)
        self.assertIn("whereIn('status', ['requested', 'confirmed'])->exists()", method)

    def test_availability_excludes_only_this_reservations_actual_occupied_nights(self):
        method = CONTROLLER[CONTROLLER.index("protected function checkInventory"):CONTROLLER.index("protected function lineExtra")]
        self.assertIn("$contractId, $ignoreReservation", method)
        self.assertNotIn("$avail += ReservationRoom::where('reservation_id'", method)
        self.assertIn("$exceptReservationId", AVAILABILITY)
        self.assertIn("$ownStays->filter(fn ($stay) => $stay->arrival_date->lte($night)", AVAILABILITY)
        self.assertIn("->whereDate('arrival_date', '<', $to->toDateString())", AVAILABILITY)

    def test_migrations_confirm_the_original_cascade_risk(self):
        self.assertIn("Schema::create('upsell_orders'", UPSELL_MIGRATION)
        self.assertIn("foreignId('reservation_room_id')->constrained()->cascadeOnDelete()", UPSELL_MIGRATION)
        self.assertIn("Schema::create('stay_fixed_charges'", CHARGE_MIGRATION)
        self.assertIn("foreignId('reservation_room_id')->constrained()->cascadeOnDelete()", CHARGE_MIGRATION)


if __name__ == "__main__":
    unittest.main(verbosity=2)
