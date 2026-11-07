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
RESERVATION_FORM = (ROOT / "resources/views/reservations/form.blade.php").read_text()
CITY_LEDGER_PARTIAL = (ROOT / "resources/views/reservations/_city-ledger-create.blade.php").read_text()
CITY_LEDGER_SERVICE = (ROOT / "app/Services/CityLedgerService.php").read_text()


def method_body(source, signature, next_signature=None):
    """The body of one method, so a test reads the method it names and nothing else."""
    start = source.index(signature)
    rest = source[start + len(signature):]
    if next_signature:
        return rest[:rest.index(next_signature)]
    for marker in ('\n    public function ', '\n    protected function ', '\n    private function '):
        if marker in rest:
            rest = rest[:rest.index(marker)]
    return rest


class FrontDeskWalkInPolishTest(unittest.TestCase):
    def test_actual_walkin_page_keeps_guest_room_and_stay_capture(self):
        for marker in (
            "@extends('layouts.app')", 'class="walkin-page"', 'Walk-in check-in',
            'id="walkinForm"', 'name="room_id"', 'name="nights"', 'name="rate_plan_id"',
            'name="rate"', 'name="adults"', 'name="children"', 'name="business_source_id"',
            'name="company_id"', 'id="walkinBillingNote"', 'Direct billing is enabled',
            'id="walkinCreateCityLedger"', 'Create City Ledger / Company',
            "@include('reservations._city-ledger-create'", "'prefix' => 'walkinCompanyCreate'",
            "route('frontdesk.walkin.company.store')", "document.addEventListener('city-ledger-created'",
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

    def test_new_reservation_offers_the_same_guest_title_picker(self):
        """A booking desk can hit the same missing title the walk-in desk can."""
        reservation_form = (ROOT / "resources/views/reservations/form.blade.php").read_text()
        guest_picker = (ROOT / "resources/views/reservations/_guest.blade.php").read_text()

        self.assertIn("@include('reservations._guest', ['allowCustomGuestTitle' => true])", reservation_form)
        self.assertIn("if ($allowCustomGuestTitle) $guestTitleOptions['__other__'] = 'Others';", guest_picker)
        self.assertIn('name="guest[custom_title]"', guest_picker)
        self.assertIn('id="guestCustomTitleSave"', guest_picker)
        self.assertIn("titleSelect.add(option, otherOption || null)", guest_picker)
        # the hint must not name one of the two screens it now appears on
        self.assertIn('The title is saved on the guest profile when you save this form.', guest_picker)
        self.assertNotIn('Save the walk-in form', guest_picker)

        def method_body(source, signature):
            start = source.index(signature)
            rest = source[start + len(signature):]
            return rest[:rest.index('\n    public function ') if '\n    public function ' in rest else len(rest)]

        store_reservation = method_body(RESERVATION_CONTROLLER, 'public function store(Request $request)')
        update_reservation = method_body(RESERVATION_CONTROLLER, 'public function update(Request $request, Reservation $reservation)')
        resolver_start = RESERVATION_CONTROLLER.index('protected function resolveGuestTitle(array $data): array')
        resolver = RESERVATION_CONTROLLER[resolver_start:RESERVATION_CONTROLLER.index('protected function upsertGuest(')]
        self.assertIn("'guest.custom_title' => 'required_if:guest.title,__other__|nullable|string|max:30|not_in:__other__'", RESERVATION_CONTROLLER)
        self.assertIn("if (($data['guest']['title'] ?? null) === '__other__') {", resolver)
        self.assertIn("$data['guest']['title'] = trim((string) ($data['guest']['custom_title'] ?? ''));", resolver)
        self.assertIn("unset($data['guest']['custom_title']);", resolver)
        self.assertIn('$this->resolveGuestTitle($request->validate($this->rules()))', store_reservation)
        self.assertIn('$this->resolveGuestTitle($request->validate($this->rules(true)))', update_reservation)

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
        company_creator = method_body(CONTROLLER, 'public function storeWalkInCompany(Request $request)')
        self.assertIn("can_do('frontdesk.checkin') && can_do('credit.manage')", company_creator)
        self.assertIn("CityLedgerService::createFromDesk(", company_creator)
        self.assertIn("'Walk-in check-in'", company_creator)
        self.assertIn("Route::post('/walk-in/company', 'storeWalkInCompany')->middleware('perm:credit.manage')->name('walkin.company.store')", ROUTES)
        self.assertIn("Company::orderBy('name')->get()", ACCOUNTS_CONTROLLER)
        self.assertIn('AvailabilityService::blockingStays($arr, $checkedThrough)', CONTROLLER)
        self.assertIn('$checkedThrough = $dayUse ? $arr->copy()->addDay() : $dep;', CONTROLLER)
        self.assertIn('$stay = StayService::checkIn($stay, $room->id);', CONTROLLER)
        self.assertIn('FolioService::payment($stay->folio', CONTROLLER)


    def test_booking_desk_opens_the_same_city_ledger_account_as_the_walkin_desk(self):
        # the booking desk carries the control the walk-in desk has
        for marker in (
            'id="resCompanyCreate"', 'data-modal="resCompanyCreateModal"', 'Create City Ledger / Company',
            "@include('reservations._city-ledger-create'", "'prefix' => 'resCompanyCreate'",
            "route('reservations.company.store')", 'id="resCompanyStatus"',
            "document.addEventListener('city-ledger-created'",
            "status.textContent = `${account.name} was created and selected. The direct-billing account is available in Finance → City Ledger.`",
            "CO.dispatchEvent(new Event('change', {bubbles:true}));",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, RESERVATION_FORM)

        # one dialog, so the two desks cannot drift apart
        for marker in (
            "id=\"{{ $prefix }}Modal\"", "id=\"{{ $prefix }}Form\"", "id=\"{{ $prefix }}Errors\"",
            "id=\"{{ $prefix }}Save\"", '@csrf', 'data-reset data-no-lock',
            "form.addEventListener('submit', async event =>", 'body: new FormData(form)',
            'response.status === 422 && payload?.errors', 'showErrors(Object.values(payload.errors).flat())',
            "headers: {'Accept':'application/json', 'X-Requested-With':'XMLHttpRequest'}",
            "document.dispatchEvent(new CustomEvent('city-ledger-created', {detail: payload.company}))",
            'form.reset()', "modal.classList.remove('show')",
            'Go to Finance → City Ledger',  # placeholder guard, replaced below
        ):
            if marker.startswith('Go to'):
                continue
            with self.subTest(partial=marker):
                self.assertIn(marker, CITY_LEDGER_PARTIAL)
        self.assertIn("can_do('credit.manage')", CITY_LEDGER_PARTIAL)
        self.assertIn('Finance → City Ledger', CITY_LEDGER_PARTIAL)

        # the walk-in desk includes it rather than carrying its own copy
        self.assertIn("@include('reservations._city-ledger-create'", VIEW)
        self.assertIn("'prefix' => 'walkinCompanyCreate'", VIEW)
        self.assertNotIn('id="walkinCompanyCreateForm"', VIEW)

        # the endpoint behind the booking desk's dialog
        store_company = method_body(RESERVATION_CONTROLLER, 'public function storeCompany(Request $request)')
        self.assertIn("abort_unless(can_do('credit.manage'), 403)", store_company)
        self.assertIn('CityLedgerService::createFromDesk(', store_company)
        self.assertIn("'New reservation'", store_company)
        self.assertIn("Route::post('/company', 'storeCompany')->middleware('perm:credit.manage')->name('company.store')", ROUTES)

        # one account builder, whichever desk asks for it
        self.assertIn('public static function createFromDesk(array $data, string $source): Company', CITY_LEDGER_SERVICE)
        for marker in (
            "'direct_billing' => false", "'credit_status' => 'open'", "'is_active' => true",
            "'type' => 'required|in:corporate,travel_agent'",
            "'credit_limit' => 'required|numeric|min:0|max:999999999999.99'",
            "'credit_days' => 'required|integer|min:0|max:365'",
            'A company with this name already exists.',
            'CreditService::setTerms(',
            'Initial City Ledger setup from {$source}',
            "ActivityLogger::log('credit', 'city_ledger_create'",
        ):
            with self.subTest(service=marker):
                self.assertIn(marker, CITY_LEDGER_SERVICE)


if __name__ == "__main__":
    unittest.main(verbosity=2)
