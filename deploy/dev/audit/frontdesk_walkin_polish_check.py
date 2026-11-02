#!/usr/bin/env python3
"""Static regression audit for the actual Front Office walk-in check-in page."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
VIEW = (ROOT / "resources/views/frontdesk/walkin.blade.php").read_text()
CONTROLLER = (ROOT / "app/Http/Controllers/FrontDeskController.php").read_text()
RESERVATION_CONTROLLER = (ROOT / "app/Http/Controllers/ReservationController.php").read_text()
GUEST_CONTROLLER = (ROOT / "app/Http/Controllers/GuestController.php").read_text()
ACCOUNTS_CONTROLLER = (ROOT / "app/Http/Controllers/AccountsController.php").read_text()
GUEST_SCHEMA = (ROOT / "database/migrations/2026_01_01_000100_create_front_office_tables.php").read_text()
TITLE_MIGRATION = (ROOT / "database/migrations/2026_10_31_000100_expand_guest_title_to_30_characters.php").read_text()
ROUTES = (ROOT / "routes/web.php").read_text()


class FrontDeskWalkInPolishTest(unittest.TestCase):
    def test_actual_walkin_page_keeps_guest_room_and_stay_capture(self):
        for marker in (
            "@extends('layouts.app')", 'class="walkin-page"', 'Walk-in check-in',
            'id="walkinForm"', 'name="room_id"', 'name="nights"', 'name="rate_plan_id"',
            'name="rate"', 'name="adults"', 'name="children"', 'name="business_source_id"',
            'name="company_id"', 'id="walkinBillingNote"', 'Direct billing is enabled',
            'id="walkinCreateCityLedger"', 'Create City Ledger / Company', 'id="walkinCompanyCreateForm"',
            "route('frontdesk.walkin.company.store')", "dispatchEvent(new CustomEvent('walkin-company-created'",
            "form.addEventListener('submit', async event =>", 'body: new FormData(form)',
            'response.status === 422 && payload?.errors', 'showErrors(Object.values(payload.errors).flat())',
            "headers: {'Accept':'application/json', 'X-Requested-With':'XMLHttpRequest'}",
            'guest billing is the default', 'id="walkinGuestLink"', 'id="walkinGuestUnlink"', 'name="purpose"',
            'Ready for arrival', 'Confirm room readiness with Housekeeping before arrival.',
            'Other housekeeping states require confirmation with Housekeeping before arrival.',
            'roomPreview.dataset.state = isReady ? \'ready\' : \'attention\';',
            "const readinessWarning = ['clean', 'inspected'].includes(housekeeping) ? ''", "@include('reservations._guest', ['allowCustomGuestTitle' => true])",
            'name="special_request"', 'Check in walk-in', 'if (event.defaultPrevented) return;', 'window.confirm(message)',
            'checks the guest in and records any deposit.',
            'guestLookupResults?.addEventListener(\'click\'', 'const clearLinkedGuest = () =>',
            'Editing name, mobile, email or ID details clears this profile link.'
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW)
        guest_picker = (ROOT / "resources/views/reservations/_guest.blade.php").read_text()
        self.assertIn("String(guest.id_number).slice(-4)", guest_picker)
        self.assertIn("details.textContent = [guest.phone, guest.email, idHint]", guest_picker)
        self.assertIn("$guestTitleOptions['__other__'] = 'Others'", guest_picker)
        self.assertIn('name="guest[custom_title]"', guest_picker)
        self.assertIn('id="guestCustomTitleSave"', guest_picker)
        self.assertIn('maxlength="30"', guest_picker)
        self.assertIn('Up to 30 characters.', guest_picker)
        self.assertIn('title.length > 30', guest_picker)
        self.assertIn('Save title', guest_picker)
        self.assertIn("customTitleSave.addEventListener('click'", guest_picker)
        self.assertIn("titleSelect.add(option, otherOption || null)", guest_picker)
        self.assertIn("titleSelect.addEventListener('guest-title-loaded', syncCustomTitle)", guest_picker)
        self.assertIn("input.value = value", guest_picker)
        self.assertIn("input.dispatchEvent(new Event('guest-title-loaded'))", guest_picker)

    def test_advance_single_split_tender_and_cheque_capture_are_preserved(self):
        for marker in (
            'name="advance"', 'name="advance_mode"', 'name="advance_ref"',
            '<x-cheque-fields/>', '<x-payment-split-tender', 'walkinAdvanceSplit',
            'Estimated room charges', 'Payment received', 'before applicable taxes and extras',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW)

    def test_room_plan_and_contract_preview_use_safe_dom_and_business_date(self):
        for marker in (
            'const businessDate =', 'dateFromBusinessDay', 'dateValue(departure)',
            'new URLSearchParams(', 'document.createElement(\'option\')',
            'planOption.textContent', 'walkinRoomPreview', 'aria-live="polite"',
            'id="walkinRateNote"', 'id="walkinRateRefresh"', 'rateNote.textContent = message',
            'rateRefresh?.addEventListener(\'click\'',
            "$canQuoteRates = can_do('reservations.view')", 'if (!canQuoteRates)',
            "$restoreManualRate = session()->hasOldInput('rate')", 'const restoreManualRate = @json($restoreManualRate)',
            'id="walkinRateRefresh"', 'rateNote.textContent = message', 'refreshRateForContext',
            'let rateQuoteTotal = null', 'Number(result.total)', 'rateQuoteTotal !== null && !manualRate',
            'nights.addEventListener(\'input\', () => { priceRequest++; rateQuoteTotal = null; updateSummary(); });',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW)
        self.assertNotIn('plan.innerHTML', VIEW)
        self.assertNotIn('insertAdjacentHTML', VIEW)

    def test_walkin_route_and_server_behavior_remain_gated_and_validated(self):
        self.assertIn("Route::get('/walk-in', 'walkIn')->name('walkin')", ROUTES)
        self.assertIn("Route::post('/walk-in', 'storeWalkIn')->name('walkin.store')", ROUTES)
        self.assertIn("Route::middleware('perm:frontdesk.checkin')->group(function ()", ROUTES)
        self.assertIn("'room_id' => 'required|integer|owned:rooms'", CONTROLLER)
        walkin = CONTROLLER[CONTROLLER.index('public function walkIn(Request $request)'):CONTROLLER.index('public function storeWalkIn(Request $request)')]
        self.assertIn("'companyBilling' => $companyBilling", walkin)
        self.assertIn("(bool) $company->direct_billing", walkin)
        store_walkin = CONTROLLER[CONTROLLER.index('public function storeWalkIn(Request $request)'):CONTROLLER.index('protected function checkInSuccessRedirect')]
        self.assertIn("'guest.custom_title' => 'required_if:guest.title,__other__|nullable|string|max:30|not_in:__other__'", store_walkin)
        self.assertIn("$data['guest']['title'] = trim((string) $data['guest']['custom_title']);", store_walkin)
        self.assertIn("if (! empty($data['guest_id']) && ! can_do('reservations.view'))", store_walkin)
        self.assertIn('Existing guest profile lookup requires reservation-view access.', store_walkin)
        self.assertIn("'guest.title' => 'nullable|string|max:30'", CONTROLLER)
        self.assertIn("'guest.title' => 'nullable|string|max:30'", RESERVATION_CONTROLLER)
        self.assertIn("'title' => 'nullable|string|max:30'", GUEST_CONTROLLER)
        self.assertIn("$t->string('title', 30)->nullable()", GUEST_SCHEMA)
        self.assertIn("$table->string('title', 30)->nullable()->change()", TITLE_MIGRATION)
        self.assertIn("Cannot shrink guest titles to 10 characters while longer titles are stored.", TITLE_MIGRATION)
        company_creator = CONTROLLER[CONTROLLER.index('public function storeWalkInCompany(Request $request)'):CONTROLLER.index('public function storeWalkIn(Request $request)')]
        self.assertIn("can_do('frontdesk.checkin') && can_do('credit.manage')", company_creator)
        self.assertIn("'direct_billing' => false", company_creator)
        self.assertIn("CreditService::setTerms(", company_creator)
        self.assertIn("                true,", company_creator)
        self.assertIn("'credit_status' => 'open'", company_creator)
        self.assertIn("'type' => 'required|in:corporate,travel_agent'", company_creator)
        self.assertIn("Route::post('/walk-in/company', 'storeWalkInCompany')->middleware('perm:credit.manage')->name('walkin.company.store')", ROUTES)
        self.assertIn("Company::orderBy('name')->get()", ACCOUNTS_CONTROLLER)
        self.assertIn('AvailabilityService::blockingStays($arr, $dep)', CONTROLLER)
        self.assertIn('$stay = StayService::checkIn($stay, $room->id);', CONTROLLER)
        self.assertIn('FolioService::payment($stay->folio', CONTROLLER)


if __name__ == "__main__":
    unittest.main(verbosity=2)
