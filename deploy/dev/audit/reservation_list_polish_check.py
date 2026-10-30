#!/usr/bin/env python3
"""Static regression checks for the reservation register refresh."""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[3]
VIEW = (ROOT / "resources/views/reservations/index.blade.php").read_text()
SERVICE = (ROOT / "app/Services/ReservationDeskService.php").read_text()
DESK_CONTROLLER = (ROOT / "app/Http/Controllers/ReservationDeskController.php").read_text()
PMS_CONFIG = (ROOT / "config/pms.php").read_text()
SIDEBAR_PARTIAL = (ROOT / "resources/views/partials/sidebar-nav.blade.php").read_text()
APP_LAYOUT = (ROOT / "resources/views/layouts/app.blade.php").read_text()


class ReservationListPolishTest(unittest.TestCase):
    def test_reservation_list_sidebar_link_opens_and_marks_the_register_active(self):
        start = PMS_CONFIG.index("['label' => 'Reservation List'")
        item = PMS_CONFIG[start:PMS_CONFIG.index("\n", start)]
        self.assertIn("'route' => 'reservations.index'", item)
        self.assertIn("'params' => ['view' => 'list']", item)
        for source in (SIDEBAR_PARTIAL, APP_LAYOUT):
            with self.subTest(source='sidebar navigation active state'):
                self.assertIn("parse_str((string) parse_url($target, PHP_URL_QUERY), $targetQuery)", source)
                self.assertIn("request()->query($key) != $value", source)

    def test_list_view_gets_a_dedicated_register_shell(self):
        self.assertIn('data-view="{{ $view }}"', VIEW)
        self.assertIn("@if($view === 'list')", VIEW)
        self.assertIn("Every stay, clearly in view.", VIEW)
        self.assertIn("Print this page", VIEW)
        self.assertIn('class="reservation-list-module"', VIEW)

    def test_list_hides_room_dashboard_controls_that_do_not_apply(self):
        guard = "@if($view !== 'list')"
        self.assertIn(guard, VIEW)
        self.assertIn('<section class="kpi-strip"', VIEW)
        self.assertIn('<nav class="desk-nav"', VIEW)
        self.assertIn('<form class="desk-tools"', VIEW)
        self.assertIn("'reservation-list-views'", VIEW)

    def test_list_request_skips_heavy_dashboard_and_inventory_loading(self):
        list_branch = SERVICE.index("if ($view === 'list')")
        inventory_load = SERVICE.index("$rooms = Room::with(")
        self.assertLess(list_branch, inventory_load)
        list_path = SERVICE[list_branch:inventory_load]
        self.assertIn("Company::orderBy('name')->get(['id', 'name', 'is_active'])", list_path)
        self.assertIn("$company->name.($company->is_active ? '' : ' (inactive)')", list_path)
        self.assertIn("self::syncAmenities();", SERVICE[inventory_load - 80:inventory_load])
        self.assertRegex(VIEW, r"@if\(\$view !== 'list'\)\s+@php\s+\$credit")
        self.assertIn("if (desk && desk.dataset.view !== 'list')", VIEW)

    def test_search_and_filters_remain_server_submitted_and_accessible(self):
        start = VIEW.index("@elseif($view === 'list')")
        end = VIEW.index("@elseif($view === 'arrivals')", start)
        list_markup = VIEW[start:end]
        for needle in (
            'id="reservationListFilter"', 'type="search" name="q"',
            'data-clear-reservation-search', 'placeholder="Guest, email, reservation, room, phone, company or OTA reference"', 'name="arrival_on"', 'name="departure_on"', 'name="from"',
            'name="to"', 'name="source"', 'name="type"', 'name="channel"', 'name="company"',
            'name="sort"', 'name="per_page"', 'aria-label="Search reservations"',
            'role="status" aria-live="polite" aria-atomic="true"',
        ):
            with self.subTest(needle=needle):
                self.assertIn(needle, list_markup)
        self.assertNotIn('name="date"', list_markup)

    def test_guest_phone_is_a_safe_click_to_call_link_when_well_formed(self):
        self.assertIn("$callPhone = preg_match('/^\\\\+?[0-9][0-9 .()-]{5,}$/', $guestPhone)", VIEW)
        self.assertIn("preg_replace('/[^0-9+]/', '', $guestPhone)", VIEW)
        self.assertIn('href="tel:{{ $callPhone }}"', VIEW)
        self.assertIn('aria-label="Call {{ $r->guest?->full_name ?? \'guest\' }} at {{ $guestPhone }}"', VIEW)
        self.assertIn('.reservation-guest-meta a{color:inherit', VIEW)

    def test_ota_channel_is_eager_loaded_and_shown_in_the_source_column(self):
        model = (ROOT / "app/Models/Reservation.php").read_text()
        self.assertIn("return $this->belongsTo(OtaChannel::class, 'ota_channel_id')", model)
        self.assertIn("'otaChannel'", SERVICE)
        self.assertIn('$r->otaChannel?->name ?? $r->source?->name ?? \'Direct\'', VIEW)
        self.assertIn('data-label="Channel / source"', VIEW)
        self.assertIn('>Source · {{ $r->source->name }}</span>', VIEW)
        self.assertIn('>Ref · {{ $r->ota_reference }}</span>', VIEW)

    def test_company_channel_search_and_export_match_the_register(self):
        self.assertIn("'channel', 'per_page'", SERVICE)
        self.assertNotIn("'channel', 'company', 'per_page'", SERVICE)
        self.assertIn("->orWhereHas('company'", SERVICE)
        self.assertIn("->orWhereHas('otaChannel'", SERVICE)
        self.assertIn("->orWhereHas('rooms.room'", SERVICE)
        self.assertIn("->orWhereHas('rooms.roomType'", SERVICE)
        self.assertIn("'Booked on', 'Guest'", DESK_CONTROLLER)
        self.assertIn("$bookedAt = $r->created_at?->format($sortValue === 'created' ? 'd M Y, H:i' : 'd M Y')", VIEW)
        self.assertIn('reservation-created-at">Booked {{ $bookedAt }}', VIEW)
        self.assertIn("'Booking channel', 'Source', 'Company'", DESK_CONTROLLER)
        self.assertIn("$r->created_at?->format('Y-m-d H:i:s')", DESK_CONTROLLER)
        self.assertIn('$r->otaChannel?->name, $r->source?->name, $r->company?->name', DESK_CONTROLLER)
        self.assertIn('response()->streamDownload(', DESK_CONTROLLER)
        self.assertIn('$query->chunk(500,', DESK_CONTROLLER)
        self.assertNotIn('limit(5000)', DESK_CONTROLLER)
        self.assertIn('fwrite($out, "\\xEF\\xBB\\xBF")', DESK_CONTROLLER)

    def test_legacy_no_show_and_cancelled_tabs_map_cleanly_into_list_status(self):
        self.assertIn("$legacyTab = $request->input('tab')", SERVICE)
        self.assertIn("$legacyTab === 'noshow' ? 'no_show' : 'cancelled'", SERVICE)
        self.assertIn("$request->query->remove('tab')", SERVICE)

    def test_invalid_arrival_range_is_constrained_and_explained(self):
        self.assertIn("$fromValue > $toValue", VIEW)
        self.assertIn("$arrivalOnValue < $fromValue", VIEW)
        self.assertIn("$arrivalOnValue > $toValue", VIEW)
        self.assertIn("$departureOnValue <= $earliestArrivalValue", VIEW)
        self.assertIn("Departure on must be after the earliest selected arrival date.", VIEW)
        self.assertIn('min="{{ $departureMinValue }}"', VIEW)
        self.assertIn('max="{{ $toValue }}"', VIEW)
        self.assertIn('min="{{ $fromValue }}"', VIEW)
        self.assertIn('class="reservation-date-alert" role="alert">{{ $dateFilterWarning }}', VIEW)

    def test_empty_state_distinguishes_no_bookings_from_no_matches(self):
        self.assertIn("$hasListCriteria ? 'No reservations match these filters' : 'No reservations yet'", VIEW)
        self.assertIn("route('reservations.create')", VIEW)
        self.assertIn('Clear filters</a>', VIEW)

    def test_desk_view_switcher_uses_navigation_not_an_incomplete_tab_widget(self):
        self.assertIn('<nav class="desk-views', VIEW)
        self.assertIn('aria-label="Reservation desk views"', VIEW)
        self.assertIn('aria-current="page"', VIEW)
        self.assertNotIn('role="tablist"', VIEW)
        self.assertNotIn('role="tab"', VIEW)

    def test_selected_status_is_exposed_as_a_removable_active_filter(self):
        self.assertIn("if ($currentStatus !== 'all') $filterChips[] = ['status', 'Status: '.$statusLabels[$currentStatus]]", VIEW)
        self.assertIn("unset($withoutFilter[$filterKey], $withoutFilter['page'])", VIEW)
        self.assertIn("'status' => $statusValue", VIEW)

    def test_status_navigation_and_table_have_accessible_semantics(self):
        self.assertIn('aria-label="Filter reservations by status"', VIEW)
        self.assertIn('aria-current="page"', VIEW)
        self.assertIn('<caption class="sr-only">Reservation search results</caption>', VIEW)
        for label in ("Reservation", "Guest", "Stay", "Rooms", "Guests", "Channel / source", "Status", "Actions"):
            with self.subTest(label=label):
                self.assertIn(f'data-label="{label}"', VIEW)
        self.assertIn('aria-label="View reservation {{ $r->reservation_no }}"', VIEW)
        self.assertIn('class="reservation-active-filters" role="group" aria-label="Active reservation filters"', VIEW)
        self.assertIn('<abbr title="Arrival">ARR</abbr>', VIEW)
        self.assertIn('<abbr title="Departure">DEP</abbr>', VIEW)

    def test_register_ignores_hidden_legacy_tab_filters(self):
        self.assertIn("$registerView = $request->input('view') === 'list'", SERVICE)
        self.assertIn("if ($request->input('sort') === 'arrival_asc' && $request->input('status') !== 'checked_out')", SERVICE)
        self.assertIn("$tab = $registerView ? null : $request->input('tab')", SERVICE)
        self.assertIn("$vipOnly = ! $registerView && ($request->input('tab') === 'vip' || $request->boolean('vip'))", SERVICE)
        self.assertIn("->when($tab === 'arrivals'", SERVICE)
        self.assertIn("->when($vipOnly", SERVICE)
        self.assertIn("if (($query['view'] ?? null) === 'list')", VIEW)
        self.assertIn("unset($query['tab'], $query['vip'])", VIEW)

    def test_status_facets_follow_search_filters_but_ignore_the_selected_status(self):
        self.assertIn("'counts' => $view === 'list' ? self::listStatusCounts($request)", SERVICE)
        self.assertIn("unset($filters['status'], $filters['page'])", SERVICE)
        self.assertIn("groupBy('status')->pluck('aggregate', 'status')", SERVICE)

    def test_page_size_is_allowlisted_and_used_for_pagination(self):
        self.assertIn('$pageSize = self::listPageSize($request)', SERVICE)
        self.assertIn('->paginate($pageSize)', SERVICE)
        self.assertIn("filter_var((string) $value, FILTER_VALIDATE_INT)", SERVICE)
        self.assertIn('in_array($perPage, [25, 50, 100], true)', SERVICE)
        self.assertIn('$pageSizeValue = $reservations->perPage()', VIEW)

    def test_pagination_normalizes_bad_pages_and_clamps_past_the_last_page(self):
        self.assertIn("'page' => 1", SERVICE)
        self.assertIn("$pageNumber = is_scalar($rawPage) ? filter_var($rawPage, FILTER_VALIDATE_INT) : false", SERVICE)
        self.assertIn("$reservations->currentPage() > $reservations->lastPage()", SERVICE)
        self.assertIn("$request->merge(['page' => $reservations->lastPage()])", SERVICE)

    def test_query_parameters_are_normalized_before_building_the_search(self):
        self.assertIn("$scalarDefaults = [", SERVICE)
        self.assertIn("if ($value !== null && ! is_scalar($value))", SERVICE)
        self.assertIn("$search = is_string($rawSearch) ? mb_substr(trim($rawSearch), 0, 160) : ''", SERVICE)
        self.assertIn("in_array($rawStatus, ['tentative', 'confirmed', 'checked_in', 'checked_out', 'cancelled', 'no_show'], true)", SERVICE)
        self.assertIn("private static function validDateString($value): ?string", SERVICE)
        self.assertIn("$request->merge([$dateFilter => self::validDateString($rawDate) ?? ''])", SERVICE)
        self.assertIn("private static function validPositiveId($value): ?int", SERVICE)
        self.assertIn("$request->merge([$idFilter => $id === null ? '' : (string) $id])", SERVICE)
        self.assertIn("$companyId = self::validPositiveId($request->input('company'))", SERVICE)
        self.assertIn("$arrivalOn = self::validDateString($request->input('arrival_on'))", SERVICE)
        self.assertIn("$departureOn = self::validDateString($request->input('departure_on'))", SERVICE)
        self.assertIn("->when($arrivalOn, fn ($w) => $w->whereDate('arrival_date', $arrivalOn))", SERVICE)
        self.assertIn("->when($departureOn, fn ($w) => $w->whereDate('departure_date', $departureOn))", SERVICE)
        self.assertIn("'departure_on' => ''", SERVICE)
        self.assertIn("'departure_on', 'from'", SERVICE)

    def test_keyboard_focus_is_visible_across_list_controls(self):
        self.assertIn('.reservation-status-tab:focus-visible', VIEW)
        self.assertIn('.reservation-active-filters a:focus-visible', VIEW)
        self.assertIn('.reservation-guest-meta a:focus-visible', VIEW)
        self.assertIn('.reservation-row-actions .btn:focus-visible', VIEW)

    def test_search_shortcuts_are_discoverable_and_do_not_interrupt_typing(self):
        self.assertIn('data-search-shortcut', VIEW)
        self.assertIn('id="reservationSearchHint"', VIEW)
        self.assertIn('Press slash to focus search. Press Escape to clear and apply the search.', VIEW)
        self.assertIn("if (event.key === '/' && !isEditing)", VIEW)
        self.assertIn("event.key === 'Escape' && target === reservationSearch", VIEW)
        self.assertIn('syncReservationSearchControls();', VIEW)
        self.assertIn('.reservation-search-shortcut[hidden]{display:none}', VIEW)

    def test_sort_keys_are_allowlisted_and_have_a_stable_tie_breaker(self):
        self.assertIn("'created' => ['created_at', 'desc']", SERVICE)
        self.assertIn("'arrival_asc' => ['arrival_date', 'asc']", SERVICE)
        self.assertIn("if ($sort === 'arrival_asc' && $status !== 'checked_out')", SERVICE)
        self.assertIn("$requestedSort !== 'arrival_asc' || $currentStatus === 'checked_out'", VIEW)
        self.assertIn("'arrival_desc' => ['arrival_date', 'desc']", SERVICE)
        self.assertIn("'departure' => ['departure_date', 'asc']", SERVICE)
        self.assertIn("'arrival_asc', 'arrival_desc'", VIEW)
        self.assertIn("'departure_desc' => ['departure_date', 'desc']", SERVICE)
        self.assertIn("in_array($sort, ['created', 'arrival_desc', 'departure_desc'], true)", SERVICE)
        self.assertIn("$q->orderBy($column, $direction)->orderBy('id', $tieDirection)", SERVICE)
        self.assertIn("$currentStatus === 'checked_out' ? 'latest' : 'earliest'", VIEW)

    def test_active_arrival_date_chips_use_the_register_date_format(self):
        for key, value, label in (
            ('from', 'fromValue', 'Arrival from'), ('to', 'toValue', 'Arrival to'),
            ('arrival_on', 'arrivalOnValue', 'Arrival on'), ('departure_on', 'departureOnValue', 'Departure on'),
        ):
            with self.subTest(filter=key):
                self.assertIn(f"['{key}', '{label}:", VIEW)
                self.assertIn(f"Carbon::parse(${value})->format('d M Y')", VIEW)

    def test_list_supporting_text_meets_small_text_contrast(self):
        def luminance(color):
            channels = [int(color[index:index + 2], 16) / 255 for index in (1, 3, 5)]
            linear = [value / 12.92 if value <= .04045 else ((value + .055) / 1.055) ** 2.4 for value in channels]
            return sum(value * weight for value, weight in zip(linear, (.2126, .7152, .0722)))

        foreground = '#5f6d64'
        for background in ('#ffffff', '#f7faf8'):
            lighter, darker = sorted((luminance(foreground), luminance(background)), reverse=True)
            with self.subTest(background=background):
                self.assertGreaterEqual((lighter + .05) / (darker + .05), 4.5)
        for selector in (
            '.reservation-filter-label{', '.reservation-table thead th{', '.reservation-created-at{',
            '.reservation-guest-meta{', '.reservation-stay-dates small{',
            '.reservation-night-count,.reservation-cell-note{', '.reservation-pax-sub{',
            '.reservation-empty{', '.reservation-table tbody td:before{',
        ):
            with self.subTest(selector=selector):
                rules = re.findall(re.escape(selector) + r'([^}]*)}', VIEW)
                self.assertTrue(any('color:#5f6d64' in rule for rule in rules))

    def test_print_uses_a_light_palette_even_when_the_desk_is_dark(self):
        self.assertIn('#desk[data-view="list"] .reservation-table-wrap{background:#fff!important', VIEW)
        self.assertIn('#desk[data-view="list"] .reservation-table tbody td{border-color:#edf1ee!important;background:#fff!important', VIEW)
        self.assertIn('#desk[data-view="list"] .reservation-active-filters a{border-color:#dce8df!important;background:#f6faf7!important', VIEW)

    def test_register_has_mobile_dark_print_and_reduced_motion_styles(self):
        self.assertIn(".reservation-active-filters a{display:inline-flex;align-items:center;gap:7px;max-width:100%;padding:5px 8px;border:1px solid #dce8df;border-radius:999px;background:#f6faf7;color:#46604e;font-size:.68rem;text-decoration:none;overflow-wrap:anywhere}", VIEW)
        for needle in (
            '@media(max-width:640px)', 'data-label);',
            '.reservation-source{white-space:normal}', '.reservation-stay-dates{flex-direction:column',
            '.reservation-guest-name{min-width:0;white-space:normal;overflow-wrap:anywhere}',
            '.reservation-row-tag,.reservation-row-tag>span:last-child{min-width:0;overflow-wrap:anywhere}',
            '.reservation-active-filters a{',
            '.desk.dark[data-view="list"]', '@media print{',
            '.reservation-pagination,.reservation-row-actions{display:none!important}',
            '@media(prefers-reduced-motion:reduce)',
        ):
            with self.subTest(needle=needle):
                self.assertIn(needle, VIEW)


if __name__ == "__main__":
    unittest.main(verbosity=2)
