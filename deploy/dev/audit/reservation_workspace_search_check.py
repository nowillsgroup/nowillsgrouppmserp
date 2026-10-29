#!/usr/bin/env python3
"""Static regression checks for the reservation workspace quick search."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
WORKSPACE = (ROOT / "resources/views/reservations/workspace.blade.php").read_text()
PREVIEW = (ROOT / "public/preview-reservations.html").read_text()
APP_CSS = (ROOT / "public/css/app.css").read_text()


class ReservationWorkspaceSearchTest(unittest.TestCase):
    def test_workspace_indexes_safe_search_terms_on_each_room_card(self):
        self.assertIn("data-search=\"{{ $searchText }}\"", WORKSPACE)
        self.assertIn("$room->roomType?->code, $room->floor?->name", WORKSPACE)
        self.assertIn("$stay?->reservation?->reservation_no", WORKSPACE)
        self.assertIn("$stay?->reservation?->ota_reference", WORKSPACE)
        self.assertIn("$stay?->reservation?->company?->name", WORKSPACE)
        self.assertIn("$stay?->guest?->phone, $stay?->guest?->email", WORKSPACE)
        self.assertIn("$stay?->reservation?->guest?->phone, $stay?->reservation?->guest?->email", WORKSPACE)
        self.assertIn("implode(' ', $c['amenities'])", WORKSPACE)
        self.assertIn("is_scalar($value)", WORKSPACE)
        workspace_service = (ROOT / "app/Services/ReservationWorkspaceService.php").read_text()
        self.assertIn("guest:id,first_name,last_name,is_vip,nationality,phone,email", workspace_service)
        self.assertIn("reservation:id,guest_id,business_source_id,company_id,reservation_no,group_name,ota_reference", workspace_service)
        self.assertIn("reservation.company:id,name", workspace_service)

    def test_workspace_search_combines_with_filters_and_restores_from_query(self):
        self.assertIn("value=\"{{ $searchQuery }}\"", WORKSPACE)
        self.assertIn('placeholder="Room, type, floor, guest or reference"', WORKSPACE)
        self.assertIn('aria-label="Search this room board by room, room type, floor, amenity, guest, phone, email, company, group or reservation"', WORKSPACE)
        self.assertIn("card.dataset.search || card.textContent", WORKSPACE)
        self.assertIn("normalizeSearchText(card.dataset.search || card.textContent)", WORKSPACE)
        self.assertIn("value.normalize('NFD').replace(/\\p{M}/gu, '')", WORKSPACE)
        self.assertIn("const compactSearchText = value => normalizeSearchText(value).replace(/[^\\p{L}\\p{N}]/gu, '')", WORKSPACE)
        self.assertIn("compactSearchText(cardSearchText).includes(compactQuery)", WORKSPACE)
        self.assertIn("matchesFilters && matchesSearch", WORKSPACE)
        self.assertIn("params.set('q', search.value.trim())", WORKSPACE)
        self.assertIn("if (search) search.value = ''", WORKSPACE)
        self.assertIn('id="clearWsFiltersEmpty"', WORKSPACE)
        self.assertIn("querySelectorAll('#clearWsFilters, #clearWsFiltersEmpty')", WORKSPACE)
        self.assertIn("if (event.currentTarget.id === 'clearWsFiltersEmpty') search?.focus()", WORKSPACE)
        self.assertIn('id="wsNoResults" hidden', WORKSPACE)

    def test_room_detail_dialog_uses_shared_visibility_and_traps_focus(self):
        self.assertIn("activeModal.classList.remove('show')", WORKSPACE)
        self.assertIn("modal.classList.add('show')", WORKSPACE)
        self.assertIn("modal.focus()", WORKSPACE)
        self.assertIn("document.body.style.overflow = 'hidden'", WORKSPACE)
        self.assertIn("document.body.style.overflow = previousBodyOverflow || ''", WORKSPACE)
        self.assertIn("function isolateRoomModal(modal)", WORKSPACE)
        self.assertIn("sibling.setAttribute('inert', '')", WORKSPACE)
        self.assertIn("sibling.setAttribute('aria-hidden', 'true')", WORKSPACE)
        self.assertIn("function restoreRoomBackground()", WORKSPACE)
        self.assertIn("else node.setAttribute('aria-hidden', ariaHidden)", WORKSPACE)
        self.assertIn('.ws-modal{overscroll-behavior:contain}', WORKSPACE)
        self.assertIn('role="dialog" aria-modal="true" aria-labelledby="roomModalTitle{{ $room->id }}" tabindex="-1"', WORKSPACE)
        self.assertIn(".modal.show { display: flex; }", APP_CSS)
        self.assertIn("function trapRoomModalFocus(event)", WORKSPACE)
        self.assertIn("event.shiftKey ? last : first", WORKSPACE)
        self.assertIn("!event.shiftKey && document.activeElement === activeModal", WORKSPACE)
        self.assertIn("if (event.key === 'Escape') closeRoomModal()", WORKSPACE)
        self.assertIn("focusTarget.setAttribute('aria-expanded', 'false')", WORKSPACE)
        self.assertIn("returnFocus?.setAttribute('aria-expanded', 'true')", WORKSPACE)
        self.assertIn('aria-haspopup="dialog" aria-controls="roomModal{{ $room->id }}" aria-expanded="false"', WORKSPACE)
        self.assertIn('role="dialog" aria-modal="true"', WORKSPACE)
        self.assertIn('<h2 id="roomModalTitle{{ $room->id }}" class="bold ws-modal-title">', WORKSPACE)
        self.assertIn('.ws-modal-title{margin:0;font-size:inherit;line-height:1.2;color:inherit}', WORKSPACE)
        self.assertIn('<span class="sr-only">Room {{ $room->room_no }}: </span>', WORKSPACE)
        self.assertIn('aria-label="Stay, reservation and folio details for room {{ $room->room_no }}"', WORKSPACE)
        self.assertIn('<thead class="sr-only"><tr><th scope="col">Detail</th><th scope="col">Value</th><th scope="col">Detail</th><th scope="col">Value</th></tr></thead>', WORKSPACE)

    def test_repeated_room_actions_have_room_specific_accessible_names(self):
        for label in (
            'aria-label="Reserve room {{ $room->room_no }} for {{ $date->format(\'j F Y\') }}"',
            'aria-label="Check in room {{ $room->room_no }}"',
            'aria-label="Check out room {{ $room->room_no }}"',
            'aria-label="Extend stay in room {{ $room->room_no }}"',
        ):
            with self.subTest(label=label):
                self.assertIn(label, WORKSPACE)
        self.assertIn('aria-label="Reservation details for room {{ $room->room_no }}', WORKSPACE)

    def test_room_summary_tables_have_accessible_captions_and_headers(self):
        self.assertIn('<caption class="sr-only">Room status summary by room count</caption>', WORKSPACE)
        self.assertIn('<caption class="sr-only">Current housekeeping status by room count</caption>', WORKSPACE)
        self.assertIn('<th scope="col">Room status</th><th scope="col">Room count</th>', WORKSPACE)
        self.assertIn('<th scope="col">Housekeeping status</th><th scope="col">Room count</th>', WORKSPACE)

    def test_room_cards_expose_room_numbers_as_headings(self):
        self.assertIn('<h2 class="ws-room-no" id="roomHeading{{ $room->id }}"><span class="sr-only">Room </span>{{ $room->room_no }}</h2>', WORKSPACE)
        self.assertIn('<div class="ws-grid" id="wsGrid" role="region" aria-label="Room board for {{ $date->format(\'j F Y\') }}">', WORKSPACE)
        self.assertIn('.ws-room-no{font-size:22px;font-weight:800;margin:0;line-height:1.1}', WORKSPACE)

    def test_card_amenity_icons_have_accessible_names(self):
        self.assertIn('<span role="img" aria-label="{{ $a }}" title="{{ $a }}">', WORKSPACE)
        self.assertIn('@if (count($c[\'amenities\']) > 10)', WORKSPACE)
        self.assertIn('class="ws-amen-more" data-room="{{ $room->id }}"', WORKSPACE)
        self.assertIn("querySelectorAll('.ws-all, .ws-photo-link, .ws-amen-more')", WORKSPACE)
        self.assertIn("@foreach (array_slice($c['amenities'], 0, 12) as $a)", WORKSPACE)
        self.assertIn("@if (count($c['amenities']) > 12)<details class=\"ws-fac-more\">", WORKSPACE)
        self.assertIn("@foreach (array_slice($c['amenities'], 12) as $a)", WORKSPACE)
        self.assertIn("{{ count($c['amenities']) - 12 }} more", WORKSPACE)
        self.assertIn(".ws-fac-more summary:focus-visible", WORKSPACE)

    def test_kpi_trend_colors_do_not_imply_good_or_bad_for_activity_counts(self):
        workspace_service = (ROOT / "app/Services/ReservationWorkspaceService.php").read_text()
        self.assertIn("! in_array($key, ['occupancy', 'revenue'], true) || $delta === null || $delta == 0", WORKSPACE)
        self.assertIn("$k['tone'] === 'neutral' ? 'neutral'", WORKSPACE)
        self.assertIn('aria-label="Change from previous selected day: {{ $k[\'delta\'] === \'—\' ? \'unavailable\' : $k[\'delta\'] }}"', WORKSPACE)
        self.assertIn(".ws-kpi-delta.neutral{color:#64748b}", WORKSPACE)
        self.assertIn("'neutral' => '#64748b'", workspace_service)

    def test_arrival_and_departure_links_preserve_selected_workspace_date(self):
        self.assertIn("route('frontdesk.arrivals', ['date' => $date->toDateString()])", WORKSPACE)
        self.assertIn("route('frontdesk.departures', ['date' => $date->toDateString()])", WORKSPACE)
        self.assertIn("route('reservations.daysummary', ['date' => $date->toDateString()])", WORKSPACE)
        self.assertIn('aria-label="Open pending arrivals for {{ $date->format(\'j F Y\') }}"', WORKSPACE)
        self.assertIn('aria-label="Open departures and overdue checkout queue through {{ $date->format(\'j F Y\') }}"', WORKSPACE)
        self.assertIn('Open pending</a>', WORKSPACE)
        self.assertIn('Open checkout queue</a>', WORKSPACE)
        frontdesk = (ROOT / "app/Http/Controllers/FrontDeskController.php").read_text()
        self.assertIn("$request->input('date', bdate()->toDateString())", frontdesk)

    def test_stay_extension_requires_confirmation_and_rechecks_availability(self):
        self.assertIn('class="ws-extend-form" data-room-no="{{ $room->room_no }}" data-confirm="Extend the stay in room {{ $room->room_no }} through the selected departure date? Availability and stay charges will be rechecked."', WORKSPACE)
        self.assertIn('form.dataset.confirm = `Extend the stay in room ${form.dataset.roomNo} through ${departureDate}? Availability and stay charges will be rechecked.`', WORKSPACE)
        front_desk = (ROOT / "app/Http/Controllers/FrontDeskController.php").read_text()
        amend = front_desk[front_desk.index("public function amend("):]
        self.assertIn("AvailabilityService::blockingStays", amend)

    def test_room_detail_quick_links_include_reserve_and_checkout(self):
        self.assertIn("if (! $isHistory && $c['status'] === 'available' && can_do('reservations.create'))", WORKSPACE)
        self.assertIn("['Reserve Room', 'building', route('reservations.create'", WORKSPACE)
        self.assertIn("if ($isToday && $stay && $stay->status === 'checked_in' && can_do('frontdesk.checkout'))", WORKSPACE)
        self.assertIn("['Check-Out', 'logout', route('frontdesk.checkout', $stay)]", WORKSPACE)
        self.assertIn("$c['status'] === 'reserved' && $room->status === 'vacant' && ! isset($inventoryBlocks[$room->id]) && can_do('frontdesk.checkin')", WORKSPACE)
        self.assertIn("$room->status !== 'vacant' || isset($inventoryBlocks[$room->id])", WORKSPACE)
        self.assertIn("$s->room?->is_active && $s->room?->status === 'vacant' && ! isset($inventoryBlocks[$s->room_id])", WORKSPACE)
        self.assertIn("$checkInBlockReason($room, $inventoryBlocks)", WORKSPACE)
        self.assertIn("$checkInBlockReason($s->room, $inventoryBlocks)", WORKSPACE)
        workspace_service = (ROOT / "app/Services/ReservationWorkspaceService.php").read_text()
        self.assertIn("'room:id,room_no,status,hk_status,is_active'", workspace_service)

    def test_room_status_summary_drills_into_filter_and_toggles_clear(self):
        self.assertIn("$roomStatusKeys = array_flip($roomStatusLabels);", WORKSPACE)
        self.assertIn('data-ws-status-filter="{{ $statusFilterKey }}"', WORKSPACE)
        self.assertIn('aria-label="Filter room board to {{ strtolower($label) }} rooms ({{ $n }})"', WORKSPACE)
        self.assertIn("querySelectorAll('[data-ws-status-filter]')", WORKSPACE)
        self.assertIn("status.value = status.value === nextStatus ? '' : nextStatus;", WORKSPACE)
        self.assertIn('class="ws-status-filter"', WORKSPACE)
        self.assertIn('aria-pressed="{{ $selectedStatus === $statusFilterKey ? \'true\' : \'false\' }}"', WORKSPACE)
        self.assertIn("button.setAttribute('aria-pressed', String(status?.value === button.dataset.wsStatusFilter))", WORKSPACE)
        self.assertIn('.ws-status-filter[aria-pressed="true"]', WORKSPACE)

    def test_kpi_grid_uses_only_the_visible_permission_gated_tiles(self):
        self.assertIn("if ($key === 'revenue' && ! can_do('revenue.view')) continue;", WORKSPACE)
        self.assertIn('style="--ws-kpi-count:{{ count($kpis) }}"', WORKSPACE)
        self.assertIn('grid-template-columns:repeat(var(--ws-kpi-count),minmax(0,1fr))', WORKSPACE)
        self.assertIn('@media (max-width:1200px){.ws-kpis{grid-template-columns:repeat(5,1fr)}', WORKSPACE)
        self.assertIn('.ws-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}', WORKSPACE)

    def test_room_rate_kpis_explain_their_date_and_stay_status_scope(self):
        report = (ROOT / "resources/views/reservations/workspace-report.blade.php").read_text()
        for template in (WORKSPACE, report):
            with self.subTest(template="workspace" if template is WORKSPACE else "report"):
                self.assertIn("$isToday\n      ? 'Current room-rate total includes checked-in stays covering the hotel business date; arrivals not yet checked in are excluded.", template)
                self.assertIn("'Booked room-rate value includes reserved and checked-in stays covering the selected date.", template)
                self.assertIn("'Historical room-rate value includes checked-in and checked-out stays covering the selected date.", template)
        self.assertIn("$k['key'] === 'revenue' ? $revenueExplanation", WORKSPACE)
        self.assertIn("{{ $revenueExplanation }}", WORKSPACE)
        self.assertIn("{{ $revenueExplanation }}", report)

    def test_negative_checkout_amount_is_shown_as_credit_not_settled(self):
        self.assertIn("$cardDue < 0 ? money(abs($cardDue)).' credit' : 'Settled'", WORKSPACE)
        self.assertIn("$cardDue < 0 ? 'Final folio credit' : 'Final folio balance'", WORKSPACE)
        self.assertIn("$cardDue < 0 ? 'Estimated checkout credit", WORKSPACE)
        self.assertIn("$da < 0 ? money(abs($da)).' credit' : 'settled'", WORKSPACE)
        self.assertIn("$da < 0 ? 'Final folio credit' : 'Final folio balance'", WORKSPACE)

    def test_cross_year_stays_show_year_in_room_and_modal_dates(self):
        self.assertIn("$stayDateFormat = static fn ($stay): string => $stay && $stay->arrival_date->year !== $stay->departure_date->year ? 'd M Y' : 'd M';", WORKSPACE)
        self.assertGreaterEqual(WORKSPACE.count("->format($stayDateFormat($stay))"), 4)

    def test_workspace_dark_theme_keeps_panels_and_operational_accents_legible(self):
        self.assertIn('body.dark .ws-kpi,', WORKSPACE)
        self.assertIn('body.dark .ws-card,', WORKSPACE)
        self.assertIn('background:var(--c-surface);color:var(--c-text);border-color:var(--c-border)', WORKSPACE)
        self.assertIn('body.dark .ws-guest.arriving{background:rgba(245,158,11,.08);border-left-color:#fbbf24}', WORKSPACE)
        self.assertIn('body.dark .ws-fac-more{color:#86efac}', WORKSPACE)
        self.assertIn('body.dark .ws-due,body.dark .ws-kpi-delta.down{color:#f87171}', WORKSPACE)
        self.assertIn('body.dark .ws-cal-day.disabled{color:#94a3b8}', WORKSPACE)

    def test_workspace_mobile_header_and_room_modal_fit_the_viewport(self):
        self.assertIn('.ws-modal .modal-box.wide{width:min(920px,calc(100vw - 2rem))}', WORKSPACE)
        self.assertIn('.ws-head{align-items:flex-start;flex-direction:column;gap:8px}', WORKSPACE)
        self.assertIn('.ws-resv-t{table-layout:fixed}', WORKSPACE)
        self.assertIn('.ws-resv-t td{overflow-wrap:anywhere;vertical-align:top}', WORKSPACE)
        self.assertIn('.ws-assign,.ws-assign-none{padding-left:0}', WORKSPACE)

    def test_room_cards_use_natural_room_number_order_before_render(self):
        self.assertIn("$cards = $cards->sort(fn ($a, $b) => strnatcasecmp((string) $a['room']->room_no, (string) $b['room']->room_no))->values()", WORKSPACE)
        self.assertNotIn("new Intl.Collator", WORKSPACE)
        self.assertNotIn("grid.insertBefore(card, noResults)", WORKSPACE)

    def test_vip_guest_link_prefers_reservation_details_when_permitted(self):
        self.assertIn("@if (can_do('reservations.view') && $s->reservation_id)<a class=\"ws-person\" href=\"{{ route('reservations.show', $s->reservation_id) }}\">", WORKSPACE)
        self.assertIn("@elseif (can_do('frontdesk.view'))<a class=\"ws-person\" href=\"{{ route('frontdesk.inhouse') }}\">", WORKSPACE)
        self.assertIn("@if ((can_do('reservations.view') && $s->reservation_id) || can_do('frontdesk.view'))</a>@else</div>@endif", WORKSPACE)

    def test_guest_name_display_falls_back_without_blank_labels(self):
        self.assertIn("$guestDisplayName = static function ($stay, string $fallback = 'Guest details unavailable'): string", WORKSPACE)
        self.assertIn("return $name !== '' ? $name : $fallback;", WORKSPACE)
        self.assertIn("$guest = $stay?->guest ?? $stay?->reservation?->guest;", WORKSPACE)
        self.assertGreaterEqual(WORKSPACE.count('$guestDisplayName($s)'), 3)
        self.assertGreaterEqual(WORKSPACE.count('$guestInitial($s)'), 3)
        self.assertIn("$stayGuestName = $guestDisplayName($stay);", WORKSPACE)

    def test_vip_state_is_consistent_for_occupant_and_reservation_guest(self):
        workspace_service = (ROOT / "app/Services/ReservationWorkspaceService.php").read_text()
        self.assertIn("$isVip = (bool) ($stay?->guest?->is_vip || $stay?->reservation?->guest?->is_vip);", WORKSPACE)
        self.assertIn("data-vip=\"{{ $isVip ? '1' : '0' }}\"", WORKSPACE)
        self.assertIn("{{ $isVip ? ' · VIP' : '' }}", WORKSPACE)
        self.assertIn("$modalGuestIsVip = (bool) ($stay?->guest?->is_vip || $stay?->reservation?->guest?->is_vip);", WORKSPACE)
        self.assertIn("$modalGuestIsVip ? 'VIP' : null", WORKSPACE)
        self.assertIn("{{ $modalGuestContext }}", WORKSPACE)
        self.assertIn("$guestStays->filter(fn ($stay) => $stay->guest?->is_vip || $stay->reservation?->guest?->is_vip)", workspace_service)
        self.assertIn("->filter(fn ($stay) => $stay->guest?->is_vip || $stay->reservation?->guest?->is_vip)", workspace_service)

    def test_vip_filter_combines_and_persists_with_other_room_filters(self):
        self.assertIn("'hk', 'vip', 'q'", WORKSPACE)
        self.assertIn('$selectedVip = ($filters[\'vip\'] ?? \'\') === \'1\'', WORKSPACE)
        self.assertIn('id="fVip" type="checkbox" value="1"', WORKSPACE)
        self.assertIn('Current VIP only', WORKSPACE)
        self.assertIn('aria-label="Filter to guests currently marked VIP"', WORKSPACE)
        self.assertIn('.ws-vip-filter{display:inline-flex;align-items:center;gap:6px;min-height:34px;', WORKSPACE)
        self.assertIn('.ws-vip-filter:focus-within', WORKSPACE)
        self.assertIn('VIP flags reflect current guest profiles.', WORKSPACE)
        self.assertIn("data-vip=\"{{ $isVip ? '1' : '0' }}\"", WORKSPACE)
        self.assertIn("(!vipSelected || card.dataset.vip === '1')", WORKSPACE)
        self.assertIn("if (vipSelected) params.set('vip', '1'); else params.delete('vip')", WORKSPACE)
        self.assertIn("vipOnly?.addEventListener('change', () => applyWsFilters())", WORKSPACE)
        self.assertIn("if (vipOnly) vipOnly.checked = false", WORKSPACE)

    def test_workspace_day_report_gates_housekeeping_and_labels_folio_credits(self):
        report = (ROOT / "resources/views/reservations/workspace-report.blade.php").read_text()
        self.assertIn("$canViewHousekeeping = can_do('housekeeping.view') || can_do('housekeeping.attendant') || can_do('housekeeping.manage');", report)
        self.assertIn("@if ($canViewHousekeeping)\n        <tr><td><b>{{ ! $isToday ? 'Current housekeeping — dirty'", report)
        self.assertIn("{{ ! $isToday ? 'Current housekeeping — touch-up'", report)
        self.assertIn("@if (can_do('folio.view'))Checkout / balance", report)
        self.assertIn("$bal < 0 ? money(abs($bal)).' credit'", report)
        self.assertIn("$c['balance'] < 0 ? money(abs($c['balance'])).' credit'", report)
        self.assertIn("$c['balance'] > 0 ? money($c['balance'])", report)
        self.assertNotIn("money($c['balance']).' due'", report)
        self.assertIn("$guestDisplayName = static function ($stay)", report)
        self.assertIn("'Guest details unavailable'", report)
        self.assertIn("format($stayDateFormat($c['stay']))", report)
        self.assertIn("$waitlistGuestDisplayName = static function ($entry)", report)
        self.assertIn("$waitlistGuestDisplayName($w)", report)
        self.assertIn('<th scope="col">Arrival</th><th scope="col">Departure</th><th scope="col">Nights</th><th scope="col">Room type</th>', report)
        self.assertIn("$w->arrival_date->format('d M Y')", report)
        self.assertIn("$w->departure_date->format('d M Y')", report)
        self.assertIn("max(1, $w->arrival_date->diffInDays($w->departure_date))", report)
        self.assertIn('Current waiting list, up to five entries in priority and arrival order', report)
        self.assertIn('<th scope="col">Room</th><th scope="col">Guest</th>', report)
        self.assertEqual(report.count('<caption class="sr-only">'), 6)
        self.assertIn('<th scope="col">@if (can_do(\'folio.view\'))Checkout / balance', report)
        self.assertIn('<th scope="col">Room</th><th scope="col">Type</th>', report)
        self.assertIn('@media screen and (max-width:760px){.print-page .kpis{grid-template-columns:repeat(2,minmax(0,1fr))!important}', report)
        self.assertIn('.print-page .two{grid-template-columns:minmax(0,1fr)!important}', report)

    def test_housekeeping_filter_combines_with_room_and_source_filters(self):
        self.assertIn("request()->only(['status', 'type', 'floor', 'source', 'hk', 'vip', 'q'])", WORKSPACE)
        self.assertIn('id="fHousekeeping"', WORKSPACE)
        self.assertIn('as $hkKey => $hkLabel', WORKSPACE)
        self.assertIn("$hk['Dirty']", WORKSPACE)
        self.assertIn('aria-label="Filter rooms by current housekeeping status"', WORKSPACE)
        self.assertIn('Current housekeeping: All', WORKSPACE)
        self.assertIn('data-hk="{{ $canViewHousekeeping ? $room->hk_status : \'\' }}"', WORKSPACE)
        self.assertIn("values[4] === 'ready'", WORKSPACE)
        self.assertIn("['clean', 'inspected'].includes(card.dataset.hk)", WORKSPACE)
        self.assertIn("data-ws-hk-filter=\"ready\"", WORKSPACE)
        self.assertIn('aria-label="Filter room board to clean or inspected rooms ({{ $hk[\'Clean\'] }})"', WORKSPACE)
        self.assertIn("querySelectorAll('[data-ws-hk-filter]')", WORKSPACE)
        self.assertIn("housekeeping.value = housekeeping.value === nextStatus ? '' : nextStatus;", WORKSPACE)
        self.assertIn('aria-pressed="{{ $selectedHousekeeping === \'ready\' ? \'true\' : \'false\' }}"', WORKSPACE)
        self.assertIn("button.setAttribute('aria-pressed', String(housekeeping?.value === button.dataset.wsHkFilter))", WORKSPACE)
        self.assertIn("['status', 'type', 'floor', 'source', 'hk']", WORKSPACE)

    def test_source_filter_includes_direct_bookings_without_matching_vacant_rooms(self):
        self.assertIn("collect(['Direct'])->merge($cards->pluck('stay.reservation.businessSource.name')", WORKSPACE)
        self.assertIn("$roomSource = $stay ? ($src ?: 'Direct') : ''", WORKSPACE)
        self.assertIn('data-source="{{ $roomSource }}"', WORKSPACE)
        self.assertIn('Source: {{ $src }}', WORKSPACE)

    def test_workspace_cards_merge_legacy_type_and_linked_room_amenities(self):
        workspace_service = (ROOT / "app/Services/ReservationWorkspaceService.php").read_text()
        self.assertIn("Room::with(['roomType.amenityLinks.amenity', 'amenityLinks.amenity', 'floor'])", workspace_service)
        self.assertIn("preg_split('/[,;|]/', (string) ($rt->amenities ?? ''))", workspace_service)
        self.assertIn("protected static function deskAmenityNames($links)", workspace_service)
        self.assertIn("$amenity && $amenity->is_active && $amenity->show_on_desk", workspace_service)
        self.assertIn("self::deskAmenityNames($rt->amenityLinks)", workspace_service)
        self.assertIn("self::deskAmenityNames($room->amenityLinks)", workspace_service)
        self.assertIn("->merge($roomAmenities)->unique()->values()->all()", workspace_service)

    def test_housekeeping_details_are_permission_gated(self):
        self.assertIn("$canViewHousekeeping = can_do('housekeeping.view') || can_do('housekeeping.attendant') || can_do('housekeeping.manage');", WORKSPACE)
        self.assertIn('@if ($canViewHousekeeping)\n  <select id="fHousekeeping"', WORKSPACE)
        self.assertIn('data-hk="{{ $canViewHousekeeping ? $room->hk_status : \'\' }}"', WORKSPACE)
        self.assertIn("$canViewHousekeeping && $s->room?->hk_status", WORKSPACE)
        self.assertIn("$canViewHousekeeping ? ' · Current: '.$housekeepingLabel($r->hk_status) : ''", WORKSPACE)
        self.assertIn("sort(function ($a, $b) use ($canViewHousekeeping)", WORKSPACE)
        self.assertIn("$hkTone = match ($room->hk_status)", WORKSPACE)
        self.assertIn("if ($canViewHousekeeping) $facts[] = ['Current housekeeping', $housekeepingLabel($room->hk_status)];", WORKSPACE)
        self.assertIn("return $status === '' ? 'Unknown'", WORKSPACE)
        self.assertIn('@if ($canViewHousekeeping)<div class="ws-hk-badge ws-hk-{{ $hkTone }}" title="Current housekeeping status">Current <span aria-hidden="true">HK</span><span class="sr-only">housekeeping</span>: {{ $housekeepingLabel($room->hk_status) }}</div>@endif', WORKSPACE)
        self.assertIn('body.dark .ws-hk-dirty{background:#422006;color:#fcd34d}', WORKSPACE)
        self.assertNotIn('data-hk="{{ $room->hk_status }}"', WORKSPACE)

    def test_mark_dirty_action_requires_confirmation_and_names_current_status(self):
        self.assertIn('class="ws-inline-action" data-confirm="Mark room {{ $room->room_no }} dirty? This updates its current housekeeping status."', WORKSPACE)
        self.assertIn('name="hk_status" value="dirty"', WORKSPACE)

    def test_arrivals_expose_nonready_housekeeping_state(self):
        workspace_service = (ROOT / "app/Services/ReservationWorkspaceService.php").read_text()
        self.assertIn("'room:id,room_no,status,hk_status,is_active'", workspace_service)
        self.assertIn("! in_array($s->room->hk_status, ['clean', 'inspected'], true)", WORKSPACE)
        self.assertIn("Current housekeeping: {{ $housekeepingLabel($s->room->hk_status) }}", WORKSPACE)

    def test_unassigned_stays_are_labeled_actionably_in_workspace_rails(self):
        self.assertEqual(WORKSPACE.count("$s->room?->room_no ? 'Rm '.$s->room->room_no : 'Unassigned'"), 3)
        self.assertNotIn("Rm {{ $s->room?->room_no ?? '—' }}", WORKSPACE)

    def test_checkin_readiness_cues_explain_the_actual_blocker(self):
        self.assertIn("$checkInBlockReason = static function ($room, array $inventoryBlocks) use ($roomStatusLabels): string", WORKSPACE)
        self.assertIn("if (! $room->is_active) return 'The assigned room is inactive.';", WORKSPACE)
        self.assertIn("Assigned room status: ", WORKSPACE)
        self.assertIn("Room has an active '.$blockLabel.' inventory block.'", WORKSPACE)
        self.assertIn('class="ws-checkin-blocker"', WORKSPACE)
        self.assertIn('.ws-checkin-blocker{display:block;', WORKSPACE)
        self.assertIn('body.dark .ws-checkin-blocker{background:#422006;', WORKSPACE)
        self.assertGreaterEqual(WORKSPACE.count('$checkInBlockReason('), 2)
        self.assertNotIn('Resolve room status before check-in.', WORKSPACE)

    def test_checkin_room_picker_prioritizes_ready_rooms_naturally_and_warns_touch_up(self):
        front_desk = (ROOT / "app/Http/Controllers/FrontDeskController.php").read_text()
        checkin_view = (ROOT / "resources/views/frontdesk/checkin.blade.php").read_text()
        self.assertIn("$aReadiness = match ($a->hk_status) { 'inspected' => 0, 'clean' => 1, default => 2 };", front_desk)
        self.assertIn("$bReadiness = match ($b->hk_status) { 'inspected' => 0, 'clean' => 1, default => 2 };", front_desk)
        self.assertIn("strnatcasecmp((string) $a->room_no, (string) $b->room_no)", front_desk)
        self.assertIn("$roomReadinessLabel = match ($r->hk_status)", checkin_view)
        self.assertIn("'inspected' => ' · Inspected'", checkin_view)
        self.assertIn("'clean' => ' · Clean'", checkin_view)
        self.assertIn("'dirty' => ' · ⚠ Dirty'", checkin_view)
        self.assertIn("'touch_up' => ' · ⚠ Touch-up'", checkin_view)
        self.assertIn('data-hk-status="{{ $r->hk_status }}"', checkin_view)
        self.assertIn("form.dataset.confirm = `Room ${option.dataset.roomNo} is ${label}. Check-in will not clean the room. Continue?`", checkin_view)
        self.assertIn("document.getElementById('checkInForm')?.addEventListener('submit'", checkin_view)
        self.assertIn('<label for="checkinRoom">Room <span class="req">*</span></label>', checkin_view)
        self.assertIn('$hasSelectedRoom = $rooms->contains(fn ($room) => (string) $room->id === (string) $selectedRoomId);', checkin_view)
        self.assertIn('$hasAssignedRoom = $stay->room_id && $rooms->contains(fn ($room) => (string) $room->id === (string) $stay->room_id);', checkin_view)
        self.assertIn("$roomWasSubmitted = old('room_id') !== null;", checkin_view)
        self.assertIn("$roomSelectionIssue = $rooms->isEmpty() || ($roomWasSubmitted && ! $hasSelectedRoom) || ($stay->room_id && ! $hasAssignedRoom);", checkin_view)
        self.assertIn('<select id="checkinRoom" name="room_id" required @if($roomSelectionIssue) aria-describedby="checkinRoomStatus" @endif>', checkin_view)
        self.assertIn('@if (! $hasSelectedRoom)<option value="" disabled selected>Choose a room…</option>@endif', checkin_view)
        self.assertIn('@selected((string) $selectedRoomId === (string) $r->id)', checkin_view)
        self.assertIn('id="checkinRoomStatus" class="err" role="status"', checkin_view)
        self.assertIn('No vacant rooms are available for this stay. Review availability before checking in.', checkin_view)
        self.assertIn('The previously assigned room is no longer available. Choose another room.', checkin_view)
        self.assertIn("@if(can_do('frontdesk.view'))<a href=\"{{ route('frontdesk.arrivals') }}\">Arrivals</a>@elseif(can_do('reservations.view'))<a href=\"{{ route('reservations.workspace') }}\">Reservation Workspace</a>", checkin_view)
        self.assertNotIn("$r->hk_status === 'dirty' ? ' ⚠ dirty'", checkin_view)

    def test_workspace_desk_queries_eager_load_direct_guest_profiles(self):
        workspace_service = (ROOT / "app/Services/ReservationWorkspaceService.php").read_text()
        arrivals = next(line for line in workspace_service.splitlines() if line.strip().startswith("$arrivals = ReservationRoom::with("))
        departures = next(line for line in workspace_service.splitlines() if line.strip().startswith("$departures = ReservationRoom::with("))
        direct_guest = "'guest:id,first_name,last_name,is_vip,nationality'"
        self.assertIn(direct_guest, arrivals)
        self.assertIn(direct_guest, departures)

    def test_today_arrival_counts_keep_same_day_checked_out_stays(self):
        workspace_service = (ROOT / "app/Services/ReservationWorkspaceService.php").read_text()
        self.assertIn("($future ? ['reserved', 'checked_in'] : ['reserved', 'checked_in', 'checked_out'])", workspace_service)
        self.assertIn("($isFuture ? ['reserved', 'checked_in'] : ['reserved', 'checked_in', 'checked_out'])", workspace_service)
        self.assertIn("->whereDate('arrival_date', $selectedDay)", workspace_service)

    def test_waitlist_shows_full_guest_name_when_available(self):
        self.assertIn("$waitlistGuestName = trim(trim((string) ($w->guest?->first_name ?? '')).' '.trim((string) ($w->guest?->last_name ?? '')));", WORKSPACE)
        self.assertIn("if ($waitlistGuestName === '') $waitlistGuestName = trim((string) ($w->contact_name ?? ''));", WORKSPACE)
        self.assertIn("if ($waitlistGuestName === '') $waitlistGuestName = 'Guest details unavailable';", WORKSPACE)
        self.assertIn("{{ $waitlistGuestName }}", WORKSPACE)
        self.assertIn("{{ $w->arrival_date->format('d M Y') }} → {{ $w->departure_date->format('d M Y') }}", WORKSPACE)
        self.assertIn("max(1, $w->arrival_date->diffInDays($w->departure_date))", WORKSPACE)
        workspace_service = (ROOT / "app/Services/ReservationWorkspaceService.php").read_text()
        report = (ROOT / "resources/views/reservations/workspace-report.blade.php").read_text()
        self.assertIn("'guest:id,first_name,last_name'", workspace_service)
        self.assertIn("$waitlistCount = (clone $waitlistQuery)->count();", workspace_service)
        self.assertIn("->orderByDesc('priority')->orderBy('arrival_date')->orderByDesc('created_at')->limit(5)->get();", workspace_service)
        self.assertIn("'waitlistCount' => $waitlistCount", workspace_service)
        self.assertIn('WAITING LIST · {{ $waitlistCount }}', WORKSPACE)
        self.assertIn("route('reservations.index', ['view' => 'waitlist'])", WORKSPACE)
        self.assertIn('aria-label="Open full waitlist: {{ $waitlistCount }} waiting entries"', WORKSPACE)
        self.assertIn('Showing the top {{ $waitlist->count() }} of {{ $waitlistCount }} entries by priority.', WORKSPACE)
        self.assertIn('.ws-waitlist-all{margin-left:auto;', WORKSPACE)
        self.assertIn('body.dark .ws-waitlist-all,body.dark .ws-rail-open{color:#86efac}', WORKSPACE)
        self.assertIn('Waiting list · {{ $waitlistCount }}', report)
        self.assertIn('total {{ $waitlistCount }}', report)
        self.assertIn('Showing the top {{ $waitlist->count() }} of {{ $waitlistCount }} entries by priority.', report)

    def test_unassigned_room_picker_requires_an_explicit_choice(self):
        self.assertIn("$assignGuestName = $guestDisplayName($s, 'guest');", WORKSPACE)
        self.assertIn("$aPriority = ! $canViewHousekeeping ? 1 : match ($a->hk_status) { 'inspected' => 0, 'clean' => 1, default => 2 };", WORKSPACE)
        self.assertIn("$bPriority = ! $canViewHousekeeping ? 1 : match ($b->hk_status) { 'inspected' => 0, 'clean' => 1, default => 2 };", WORKSPACE)
        self.assertIn("($aPriority <=> $bPriority) ?: strnatcasecmp((string) $a->room_no, (string) $b->room_no)", WORKSPACE)
        self.assertIn("$canViewHousekeeping ? ' · Current: '.$housekeepingLabel($r->hk_status) : ''", WORKSPACE)
        self.assertIn("$assignReservationNo = $s->reservation?->reservation_no", WORKSPACE)
        self.assertIn('class="ws-assign" data-guest-name="{{ $assignGuestName }}" data-confirm="Assign the selected room to {{ $assignGuestName }}? Availability will be rechecked before saving."', WORKSPACE)
        self.assertIn(r"replace(/\s+/g, ' ')", WORKSPACE)
        self.assertIn('form.dataset.confirm = `Assign ${selectedRoom} to ${form.dataset.guestName}? Room availability will be rechecked before saving.`', WORKSPACE)
        self.assertIn('class="ws-assign-select" aria-label="Room for {{ $assignGuestName ?: \'guest\' }}{{ $assignReservationNo ? \', reservation \'.$assignReservationNo : \'\' }}" required', WORKSPACE)
        self.assertIn('<button type="submit" class="btn btn-sm" aria-label="Assign room to {{ $assignGuestName ?: \'guest\' }}{{ $assignReservationNo ? \', reservation \'.$assignReservationNo : \'\' }}">Assign</button>', WORKSPACE)
        self.assertIn('<option value="" disabled selected>Choose a room…</option>', WORKSPACE)
        self.assertIn("$housekeepingLabel = static function ($status): string", WORKSPACE)
        self.assertIn("'touch_up' => 'Touch-up'", WORKSPACE)
        self.assertIn("' · Current: '.$housekeepingLabel($r->hk_status)", WORKSPACE)
        self.assertIn('<optgroup label="Same room type">', WORKSPACE)
        self.assertIn('<optgroup label="{{ $freeSameType->isNotEmpty() ? \'Other room types\' : \'Available room types\' }}">', WORKSPACE)
        self.assertNotIn('<option disabled>── other types ──</option>', WORKSPACE)
        self.assertIn("No room is free for these stay dates.", WORKSPACE)
        self.assertIn("route('reservations.index', ['view' => 'waitlist'])", WORKSPACE)
        self.assertIn("review the dates and room availability.", WORKSPACE)
        self.assertNotIn('waitlist or extend a departure', WORKSPACE)

    def test_calendar_weekday_initials_have_full_screen_reader_names(self):
        for weekday in ('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'):
            with self.subTest(weekday=weekday):
                self.assertIn(f"'{weekday}'", WORKSPACE)
        self.assertIn('<span class="sr-only">{{ $weekday }}</span>', WORKSPACE)
        self.assertIn('<span aria-hidden="true">{{ $initial }}</span>', WORKSPACE)
        self.assertIn('@if ($selectedDay) aria-current="page" @endif', WORKSPACE)
        self.assertIn("$businessDay ? ', business date' : ''", WORKSPACE)
        self.assertIn('<span aria-hidden="true">{{ $day }}</span><span class="sr-only">', WORKSPACE)
        self.assertIn('outside selectable date range', WORKSPACE)
        self.assertIn('<span class="ws-cal-dots" aria-hidden="true">', WORKSPACE)
        for disabled_control in (
            'aria-label="Previous day unavailable: date range limit"',
            'aria-label="Next day unavailable: date range limit"',
            'aria-label="Previous month unavailable: date range limit"',
            'aria-label="Next month unavailable: date range limit"',
        ):
            with self.subTest(control=disabled_control):
                self.assertIn(disabled_control, WORKSPACE)
        self.assertIn('.ws-date-nav .disabled{opacity:.65}', WORKSPACE)
        self.assertIn('.ws-cal-day.disabled{color:#64748b;cursor:not-allowed}', WORKSPACE)
        self.assertIn('.ws-cal-head .disabled{opacity:.65}', WORKSPACE)
        self.assertNotIn('pointer-events:none', WORKSPACE)

    def test_screen_reader_only_utility_is_globally_visually_hidden(self):
        self.assertRegex(APP_CSS, r"\.sr-only\s*\{[^}]*clip: rect\(0, 0, 0, 0\)")

    def test_workspace_filters_have_accessible_names_and_live_result_count(self):
        for label in (
            'aria-label="Filter rooms by status"',
            'aria-label="Filter rooms by room type"',
            'aria-label="Filter rooms by floor"',
            'aria-label="Filter rooms by booking source"',
            'aria-live="polite" aria-atomic="true"',
        ):
            with self.subTest(label=label):
                self.assertIn(label, WORKSPACE)
        self.assertIn('role="group" aria-label="Room board search and filters"', WORKSPACE)
        self.assertIn("$selectedStatus = is_string($filters['status'] ?? null) ? $filters['status'] : '';", WORKSPACE)
        self.assertIn("$roomStatusLabels = ['available' => 'Available'", WORKSPACE)
        self.assertIn("($selectedStatus !== '' && ! array_key_exists($selectedStatus, $roomStatusLabels))", WORKSPACE)
        self.assertIn("(not a current status)", WORKSPACE)
        self.assertIn('id="wsVisibleCount"', WORKSPACE)
        self.assertIn('id="wsVisibleCount" class="muted small ws-filter-count" aria-hidden="true"', WORKSPACE)
        self.assertIn("const announcement = `${visible} of ${cards.length} ${totalNoun} shown`", WORKSPACE)
        self.assertIn("filterAnnouncementTimer = setTimeout(() => { filterStatus.textContent = announcement; }, announceDelay)", WORKSPACE)
        self.assertIn("search?.addEventListener('input', () => applyWsFilters(300))", WORKSPACE)
        self.assertIn("visibleCount.textContent = `${visible} / ${cards.length} ${totalNoun}`", WORKSPACE)

    def test_static_preview_exposes_and_filters_the_same_search(self):
        self.assertIn("id=\"roomSearch\"", PREVIEW)
        self.assertIn("card.dataset.search", PREVIEW)
        self.assertIn("roomNoResults", PREVIEW)
        self.assertIn("aria-label=\"Find a room, guest or reservation\"", PREVIEW)


if __name__ == "__main__":
    unittest.main(verbosity=2)
