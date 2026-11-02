#!/usr/bin/env python3
"""Regression checks for public booking rates, occupancy quotes, and the booking dialog."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
VIEW = (ROOT / "resources/views/public/booking.blade.php").read_text()
LAYOUT = (ROOT / "resources/views/public/layout.blade.php").read_text()
CONTROLLER = (ROOT / "app/Http/Controllers/BookingEngineController.php").read_text()
RATE_SERVICE = (ROOT / "app/Services/RateService.php").read_text()
CONTRACT_SERVICE = (ROOT / "app/Services/ContractService.php").read_text()
CRM_SERVICE = (ROOT / "app/Services/CrmService.php").read_text()
ROUTES = (ROOT / "routes/web.php").read_text()


class BookingEnginePricingPolishTest(unittest.TestCase):
    def test_existing_public_booking_routes_stay_public_and_use_the_same_store_action(self):
        self.assertIn("Route::get('/book/{code}'", ROUTES)
        self.assertIn("Route::post('/book/{code}'", ROUTES)
        self.assertIn("->name('booking.show')", ROUTES)
        self.assertIn("->name('booking.store')", ROUTES)
        self.assertIn("route('booking.store', $hotel->code)", VIEW)
        self.assertIn("route('booking.show', $hotel->code)", VIEW)

    def test_room_and_adult_query_values_are_bounded_and_defaults_match_the_modal(self):
        self.assertIn("'rooms' => 'nullable|integer|min:1|max:5'", CONTROLLER)
        self.assertIn("'adults' => 'nullable|integer|min:1|max:20'", CONTROLLER)
        self.assertIn("$rooms = (int) $request->input('rooms', 1)", CONTROLLER)
        self.assertIn("$adults = (int) $request->input('adults', 2)", CONTROLLER)
        self.assertIn("$adultsPerRoom = max(1, intdiv($adults, max(1, $rooms)))", CONTROLLER)
        self.assertIn(':value="$rooms"', VIEW)
        self.assertIn(':value="$adults"', VIEW)

    def test_contract_quote_uses_the_selected_adults_per_room_and_rate_service_remains_source_of_truth(self):
        self.assertIn("$adultsPerRoom = max(1, intdiv($adults, max(1, $rooms)))", CONTROLLER)
        self.assertIn("use ($arr, $dep, $promoCode, &$promoError, $contract, $adultsPerRoom)", CONTROLLER)
        self.assertIn("ContractService::quote($contract, $t, $arr, $dep, $adultsPerRoom)", CONTROLLER)
        self.assertIn("RateService::quote($t, $arr, $dep)", CONTROLLER)
        self.assertIn("$adults >= 2 && $line->double_rate !== null", CONTRACT_SERVICE)
        self.assertIn("$total = array_sum(array_column($nights, 'rate'))", RATE_SERVICE)
        self.assertIn("$quote['average'] = round($quote['total'] / max(1, count($quote['nights'])), 2)", CRM_SERVICE)

    def test_final_booking_still_revalidates_guests_inventory_and_prices_server_side(self):
        for field in ("'arrival' => 'required|date|after_or_equal:'", "'departure' => 'required|date|after:arrival'", "'rooms' => 'required|integer|min:1|max:5'", "'adults' => 'required|integer|min:1|max:20'"):
            with self.subTest(field=field):
                self.assertIn(field, CONTROLLER)
        self.assertIn("$quote = $contract ? ContractService::quote($contract, $type, $data['arrival'], $data['departure'], max(1, intdiv((int) $data['adults'], max(1, (int) $data['rooms']))))", CONTROLLER)
        self.assertIn("RateService::quote($type, $data['arrival'], $data['departure'])", CONTROLLER)
        self.assertIn("AvailabilityService::typeAvailable($type->id, $data['arrival'], $data['departure'], null, $contract?->id) < $data['rooms']", CONTROLLER)
        self.assertIn('AvailabilityService::lockRoomInventory([$type->id])', CONTROLLER)

    def test_public_rate_breakdown_renders_actual_per_night_quote_and_season_labels(self):
        self.assertIn("$t->quote['nights']", VIEW)
        self.assertIn("$night['date']", VIEW)
        self.assertIn("$night['rate']", VIEW)
        self.assertIn("$night['season']", VIEW)
        self.assertIn('Nightly rate breakdown', VIEW)
        self.assertIn('Nightly room rates for {{ $t->name }}', VIEW)
        self.assertIn('<time datetime="{{ $nightDate->toDateString() }}">', VIEW)
        self.assertIn("$night['full_rate']", VIEW)
        self.assertIn("$night['public_rate']", VIEW)

    def test_no_false_base_rate_markdown_when_only_seasonality_changes_the_quote(self):
        self.assertIn("@elseif($t->promo)", VIEW)
        self.assertIn("$t->quote['original_average']", VIEW)
        self.assertIn("$t->quote['public_total'] / max(1, $t->quote['count'])", VIEW)
        self.assertNotIn("$t->quote['average'] < $t->base_rate", VIEW)
        self.assertIn('average per room · per night', VIEW)
        self.assertIn('before taxes', VIEW)

    def test_room_totals_and_availability_reflect_the_requested_number_of_rooms(self):
        self.assertIn("$t->quote['total'] * $rooms", VIEW)
        self.assertIn("Total for {{ $rooms }}", VIEW)
        self.assertIn("{{ $adultsPerRoom }}", VIEW)
        self.assertIn("$t->available >= $rooms", VIEW)
        self.assertIn('reduce the room count to continue', VIEW)
        self.assertIn("data-frooms=\"{{ $rooms }}\"", VIEW)
        self.assertIn("data-fadults=\"{{ $adults }}\"", VIEW)

    def test_search_preserves_promo_corporate_and_attribution_inputs_with_accessible_date_labels(self):
        for field in ('name="arrival"', 'name="departure"', 'name="promo"', 'name="corp"', 'name="m"', 'name="dl"'):
            with self.subTest(field=field):
                self.assertIn(field, VIEW)
        self.assertIn('for="pbeArrival"', VIEW)
        self.assertIn('for="pbeDeparture"', VIEW)
        self.assertIn('min="{{ $hotel->business_date->toDateString() }}"', VIEW)
        self.assertIn('min="{{ $departureMin }}"', VIEW)
        self.assertIn("Promo codes cannot be combined with a corporate rate.", CONTROLLER)

    def test_booking_dialog_keeps_required_guest_fields_and_accessible_modal_controls(self):
        for field in ('rooms', 'adults', 'children', 'first_name', 'last_name', 'phone', 'email', 'special_request', 'marketing_opt_in'):
            with self.subTest(field=field):
                self.assertIn(f'name="{field}"', VIEW)
        self.assertIn('role="dialog" aria-modal="true" aria-labelledby="pbeModalTitle"', VIEW)
        self.assertIn('aria-label="Close booking form"', VIEW)
        self.assertIn('data-title="{{ $t->name }}"', VIEW)
        self.assertNotIn("onclick=\"document.getElementById('bkType')", VIEW)
        self.assertIn("name=\"website\"", VIEW)
        self.assertIn("'website' => 'nullable|size:0'", CONTROLLER)

    def test_validation_errors_reopen_the_booking_dialog_with_prior_room_and_guest_input(self):
        self.assertIn("old('room_type_id')", VIEW)
        self.assertIn("old('arrival', $arrival)", VIEW)
        self.assertIn("old('departure', $departure)", VIEW)
        self.assertIn("$errors->any() && old('room_type_id')", VIEW)
        self.assertIn("window.openModal?.('bookModal')", VIEW)
        self.assertIn("old('marketing_opt_in')", VIEW)

    def test_promotions_and_corporate_rates_remain_exclusive_and_are_explained(self):
        self.assertIn('promoError', VIEW)
        self.assertIn('corpError', VIEW)
        self.assertIn("@if($contract)", VIEW)
        self.assertIn("@elseif($t->promo)", VIEW)
        self.assertIn("$t->promo->offerLabel()", VIEW)
        self.assertIn("$contract->company->name", VIEW)
        self.assertIn("$contract->access_code", VIEW)

    def test_public_booking_layout_head_stack_and_responsive_accessible_styles_are_present(self):
        self.assertIn('@stack(\'head\')', LAYOUT)
        for token in ('@media(max-width:900px)', '@media(max-width:600px)', '@media(max-width:360px)', 'prefers-reduced-motion:reduce'):
            with self.subTest(token=token):
                self.assertIn(token, VIEW)
        self.assertIn('role="list" aria-label="Available room types"', VIEW)
        self.assertIn('class="pbe-sr-only"', VIEW)


if __name__ == "__main__":
    unittest.main(verbosity=2)
