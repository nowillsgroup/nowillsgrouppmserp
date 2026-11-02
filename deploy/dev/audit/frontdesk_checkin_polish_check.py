#!/usr/bin/env python3
"""Regression checks for the Front Office guest check-in workflow."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
CONTROLLER = (ROOT / "app/Http/Controllers/FrontDeskController.php").read_text()
VIEW = (ROOT / "resources/views/frontdesk/checkin.blade.php").read_text()
GUEST_PARTIAL = (ROOT / "resources/views/reservations/_guest.blade.php").read_text()
FIELD = (ROOT / "resources/views/components/field.blade.php").read_text()
ROUTES = (ROOT / "routes/web.php").read_text()


class FrontDeskCheckInPolishTest(unittest.TestCase):
    def test_checkin_context_uses_stay_arrival_and_machine_readable_dates(self):
        self.assertIn("$arrival = $stay->arrival_date", VIEW)
        self.assertIn("$departure = $stay->departure_date", VIEW)
        self.assertIn("$arrival->toDateString()", VIEW)
        self.assertIn("$departure->toDateString()", VIEW)
        self.assertIn("Reservation <strong>{{ $stay->reservation->reservation_no }}</strong>", VIEW)
        self.assertNotIn("bdate()->format('d M') →", VIEW)

    def test_form_keeps_room_selection_readiness_and_dirty_room_confirmation(self):
        self.assertIn("AvailabilityService::freeRooms(bdate(), $stay->departure_date->max(bdate()->addDay()), null, $stay->id)", CONTROLLER)
        self.assertIn("$aReadiness = match ($a->hk_status)", CONTROLLER)
        self.assertIn("strnatcasecmp((string) $a->room_no, (string) $b->room_no)", CONTROLLER)
        self.assertIn("data-hk-status=\"{{ $room->hk_status }}\"", VIEW)
        self.assertIn("form.dataset.confirm = `Room ${option.dataset.roomNo} is ${label}. Check-in will not clean the room. Continue?`", VIEW)
        self.assertIn("['dirty', 'touch_up'].includes(status)", VIEW)
        self.assertIn("StayService::checkIn($lockedStay, (int) $data['room_id'])", CONTROLLER)

    def test_guest_identity_required_fields_match_server_validation(self):
        self.assertIn("'guest.first_name' => 'required|string|max:80'", CONTROLLER)
        self.assertIn("'guest.phone' => 'required|string|max:30'", CONTROLLER)
        self.assertIn("'guest.id_type' => 'required|string|max:30'", CONTROLLER)
        self.assertIn("'guest.id_number' => 'required|string|max:60'", CONTROLLER)
        self.assertIn(':required="isset($fullGuest)"', GUEST_PARTIAL)
        self.assertIn("@if($required)<span class=\"req\">*</span>@endif", FIELD)
        self.assertIn("@if($required) required @endif", FIELD)

    def test_guest_search_is_not_rendered_without_reservation_view_permission(self):
        self.assertIn("@if(can_do('reservations.view'))", GUEST_PARTIAL)
        self.assertIn("route('reservations.api.guests')", GUEST_PARTIAL)
        self.assertIn("Route::get('/api/guests', 'apiGuests')->middleware('perm:reservations.view')", ROUTES)
        self.assertIn("aria-controls=\"guestResults\"", GUEST_PARTIAL)
        self.assertIn("role=\"listbox\"", GUEST_PARTIAL)

    def test_split_and_single_deposit_flows_stay_inside_the_checkin_form(self):
        self.assertIn("id=\"checkinAdvanceSingle\"", VIEW)
        self.assertIn("<x-cheque-fields/>", VIEW)
        self.assertIn('<x-payment-split-tender widget-id="checkinAdvanceSplit" single-id="checkinAdvanceSingle"', VIEW)
        self.assertIn("'payments' => 'nullable|array|min:2|max:10'", CONTROLLER)
        self.assertIn("FolioService::payment($stay->folio", CONTROLLER)

    def test_success_redirect_respects_page_permissions_for_checkin_and_walkin(self):
        self.assertIn("protected function checkInSuccessRedirect(int $reservationId, string $message)", CONTROLLER)
        helper = CONTROLLER.split("protected function checkInSuccessRedirect", 1)[1].split("// ---------------- Check-in", 1)[0]
        self.assertIn("if (can_do('reservations.view'))", helper)
        self.assertIn("route('reservations.show', $reservationId)", helper)
        self.assertIn("if (can_do('frontdesk.view'))", helper)
        self.assertIn("route('frontdesk.inhouse')", helper)
        self.assertIn("LoginController::home(auth()->user())", helper)
        self.assertEqual(CONTROLLER.count("$this->checkInSuccessRedirect("), 2)

    def test_upsell_reads_and_presentation_follow_sell_permission(self):
        method = CONTROLLER.split("public function checkInForm", 1)[1].split("public function checkIn(", 1)[0]
        self.assertIn("$canSellUpsell = can_do('upsell.sell')", method)
        self.assertIn("$upOffers = $canSellUpsell ?", method)
        self.assertIn("$upOrders = $canSellUpsell", method)
        self.assertIn("'upOrders' => $upOrders", method)
        self.assertIn("@if(can_do('upsell.sell'))@include('upsell._checkin')@endif", VIEW)

    def test_checkin_page_has_accessible_errors_and_responsive_dark_styles(self):
        for token in (
            'role="alert" aria-labelledby="checkin-error-title"',
            'id="checkin-room-summary" class="checkin-room-summary" role="status" aria-live="polite"',
            'aria-labelledby="checkin-room-title"',
            '@media(max-width:620px)',
            'body.dark .checkin-hero',
            '@media(prefers-reduced-motion:reduce)',
        ):
            self.assertIn(token, VIEW)
        self.assertIn("Complete check-in", VIEW)
        self.assertIn("@disabled($rooms->isEmpty())", VIEW)


if __name__ == "__main__":
    unittest.main(verbosity=2)
