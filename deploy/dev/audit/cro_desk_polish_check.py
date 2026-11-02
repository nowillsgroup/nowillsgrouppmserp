#!/usr/bin/env python3
"""Regression checks for the real CRO Desk presentation and workflow wiring."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
VIEWS = ROOT / "resources/views/cro"
DESK = (VIEWS / "index.blade.php").read_text()
NAV = (VIEWS / "_nav.blade.php").read_text()
LOG_MODAL = (VIEWS / "_log_modal.blade.php").read_text()
CSS = (ROOT / "public/css/cro.css").read_text()
CONTROLLER = (ROOT / "app/Http/Controllers/CroController.php").read_text()
SERVICE = (ROOT / "app/Services/CroService.php").read_text()
ROUTES = (ROOT / "routes/web.php").read_text()
ENQUIRIES = (VIEWS / "enquiries.blade.php").read_text()
GUESTS = (VIEWS / "guests.blade.php").read_text()
LOST = (VIEWS / "lost.blade.php").read_text()
PERFORMANCE = (VIEWS / "performance.blade.php").read_text()
SETTINGS = (VIEWS / "settings.blade.php").read_text()
DETAIL = (VIEWS / "show.blade.php").read_text()


class CroDeskPolishTest(unittest.TestCase):
    def test_desk_uses_real_pms_styles_and_existing_workspace_navigation(self):
        self.assertIn("asset('css/cro.css')", NAV)
        self.assertIn("@include('cro._nav')", DESK)
        self.assertIn('aria-label="Central Reservations workspace"', NAV)
        self.assertIn("request()->routeIs('cro.index')", NAV)
        for route in ('cro.enquiries', 'cro.guests', 'cro.lost', 'cro.performance'):
            with self.subTest(route=route):
                self.assertIn(f"route('{route}')", NAV)
        self.assertIn("@if(can_do('cro.manage'))", NAV)
        self.assertIn("route('cro.settings')", NAV)

    def test_availability_search_keeps_existing_query_contract_and_live_results(self):
        self.assertIn('method="get" class="cro-search-form" id="croSearchForm"', DESK)
        for name in ('arrival', 'departure', 'rooms', 'adults', 'children'):
            with self.subTest(name=name):
                self.assertIn(f'name="{name}"', DESK)
        self.assertIn('@if($group && ! $enq)', DESK)
        self.assertIn('name="asked"', DESK)
        self.assertIn("CRO::search($q, $u)", CONTROLLER)
        self.assertIn("'results' => $results", CONTROLLER)
        self.assertIn("$results = null", CONTROLLER)
        self.assertIn("AvailabilityService", SERVICE)
        self.assertIn("aria-label=\"Live availability results\"", DESK)
        self.assertIn('aria-label="Search CRO room availability"', DESK)
        self.assertIn("@elseif($results === [])", DESK)
        self.assertIn("No properties to compare", DESK)
        self.assertIn("Availability search needs attention", DESK)
        self.assertIn('<caption class="sr-only">Live room types, sellable inventory, nightly rates and stay totals at {{ $h->name }}.</caption>', DESK)
        self.assertIn("data-hotel=\"{{ $h->code }}\"", DESK)
        self.assertIn("data-type=\"{{ $t['type']->code }}\"", DESK)

    def test_booking_and_referral_posts_retain_the_existing_ids_and_permissions(self):
        self.assertIn("action=\"{{ route('cro.book') }}\"", DESK)
        self.assertIn('name="hotel_id"', DESK)
        self.assertIn('name="room_type_id"', DESK)
        self.assertIn('name="arrival"', DESK)
        self.assertIn('name="departure"', DESK)
        self.assertIn('name="asked_hotel_id"', DESK)
        self.assertIn('name="enquiry_id"', DESK)
        self.assertIn('name="referral_reason"', DESK)
        self.assertIn("@if($canBook)", DESK)
        self.assertIn("@if($mgr)", DESK)
        self.assertIn("CRO::book($hotel, $r->all(), $r->user())", CONTROLLER)
        self.assertIn("Route::post('/book', 'book')->name('book')", ROUTES)
        self.assertIn("Route::middleware('perm:cro.book,cro.manage')->group(function ()", ROUTES)
        self.assertIn('aria-labelledby="croBookTitle"', DESK)

    def test_enquiry_logging_and_follow_up_queue_use_the_existing_data_flow(self):
        self.assertIn("@include('cro._log_modal', ['q' => $q])", DESK)
        self.assertIn("route('cro.enquiries.store')", LOG_MODAL)
        self.assertIn("CRO::logEnquiry($r->all(), $r->user())", CONTROLLER)
        self.assertIn("'due' => CroEnquiry::with('agent')->where('status', 'open')->orderByRaw('follow_up_at IS NULL, follow_up_at')->limit(6)->get()", CONTROLLER)
        self.assertIn("$alerts['follow_ups']", DESK)
        self.assertIn("$d->isFollowUpDue()", DESK)
        self.assertIn("route('cro.enquiry', $d)", DESK)
        self.assertIn('aria-labelledby="croLogTitle"', LOG_MODAL)
        self.assertIn('aria-label="Close enquiry form"', LOG_MODAL)
        self.assertIn('aria-labelledby="croLostTitle"', (VIEWS / "show.blade.php").read_text())
        self.assertIn("cro-modal-accessibility.js", NAV)
        modal_script = (ROOT / "public/js/cro-modal-accessibility.js").read_text()
        self.assertIn("event.key === 'Escape'", modal_script)
        self.assertIn("event.shiftKey", modal_script)
        self.assertIn("restoreOpener", modal_script)

    def test_all_cro_pages_share_wayfinding_without_changing_their_routes(self):
        pages = ('enquiries', 'guests', 'lost', 'performance', 'settings', 'show')
        for page in pages:
            with self.subTest(page=page):
                self.assertIn("@include('cro._nav')", (VIEWS / f"{page}.blade.php").read_text())
        for route in ('cro.index', 'cro.enquiries', 'cro.enquiry', 'cro.guests', 'cro.lost', 'cro.performance', 'cro.settings'):
            with self.subTest(route=route):
                self.assertIn(f"name('{route.removeprefix('cro.')}", ROUTES)

    def test_worklists_and_reports_use_descriptive_tables_and_linked_form_labels(self):
        self.assertIn('aria-label="Filter enquiries by status"', ENQUIRIES)
        self.assertIn('aria-current="page"', ENQUIRIES)
        self.assertIn('caption class="sr-only">Reservations enquiries matching the current status', ENQUIRIES)
        self.assertIn('caption class="sr-only">Guest profiles matching the lookup', GUESTS)
        self.assertIn('min-width:min(22rem,100%)', GUESTS)
        for view in (ENQUIRIES, GUESTS, LOST, PERFORMANCE):
            with self.subTest(view="CRO report or worklist"):
                self.assertIn('scope="col"', view)
                self.assertIn('caption class="sr-only"', view)
        self.assertIn('aria-hidden="true"><span class="cro-sold"', LOST)
        self.assertIn('for="croContactFollowUp"', DETAIL)
        self.assertIn('aria-label="Contact note"', DETAIL)
        self.assertIn("$e->arrival_date->year === $e->departure_date->year ? 'd M' : 'd M Y'", ENQUIRIES)
        self.assertIn("$e->arrival_date->year === $e->departure_date->year ? 'D d M' : 'D d M Y'", DETAIL)
        self.assertIn('for="croExpireDays"', SETTINGS)
        self.assertIn('aria-describedby="croConversionTargetHint"', SETTINGS)

    def test_presentation_adapts_to_mobile_dark_mode_and_reduced_motion(self):
        for token in (
            'var(--cro-forest)',
            '.cro-hero',
            '.cro-search-form',
            '.cro-desk-columns',
            '.cro-queue-column',
            '@media(max-width:1320px)',
            '@media(max-width:900px)',
            '@media(max-width:620px)',
            'body.dark .cro-workspace',
            'prefers-reduced-motion:no-preference',
        ):
            with self.subTest(token=token):
                self.assertIn(token, CSS)
        self.assertIn(':focus-visible', CSS)


if __name__ == "__main__":
    unittest.main(verbosity=2)
