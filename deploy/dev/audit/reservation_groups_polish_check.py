#!/usr/bin/env python3
"""Static regression checks for the Reservation Desk group-reservations view."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
VIEW = (ROOT / "resources/views/reservations/index.blade.php").read_text()
SERVICE = (ROOT / "app/Services/ReservationDeskService.php").read_text()
BLOCKS = (ROOT / "app/Services/BlockService.php").read_text()


class ReservationGroupsPolishTest(unittest.TestCase):
    def test_group_view_has_its_own_operational_hero_and_summary(self):
        for marker in (
            "'groups' => 'Group Reservations'", 'class="groups-hero"',
            'GROUP STAY COORDINATION', 'Bring every group stay together.',
            'Rooming-list pickup uses the existing block preview and confirmation flow.',
            'class="groups-summary"', 'Group reservation summary',
            "$groupSummary['total']", "$groupSummary['room_stays']",
            "$groupSummary['with_block']", "$groupSummary['tentative']",
            "$groupSummary['confirmed']", "$groupSummary['in_house']",
            "&& $view !== 'waitlist' && $view !== 'groups' && $view !== 'noshow' && $view !== 'cancelled')",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW)

    def test_group_summary_is_computed_before_search_status_and_block_filters(self):
        load_at = SERVICE.index("$groupRows = in_array($view, ['dashboard', 'groups'], true)")
        summary_at = SERVICE.index("$groupSummary = [", load_at)
        status_at = SERVICE.index("$groupStatus =", summary_at)
        search_at = SERVICE.index("if ($view === 'groups' && $request->filled('q'))", status_at)
        filter_at = SERVICE.index("if ($view === 'groups')", search_at)
        self.assertLess(load_at, summary_at)
        self.assertLess(summary_at, status_at)
        self.assertLess(status_at, search_at)
        self.assertLess(search_at, filter_at)
        for marker in (
            "'total' => $groupRows->count()",
            "'tentative' => $groupRows->where('status', 'tentative')->count()",
            "'confirmed' => $groupRows->where('status', 'confirmed')->count()",
            "'in_house' => $groupRows->where('status', 'checked_in')->count()",
            "'completed' => $groupRows->where('status', 'checked_out')->count()",
            "'room_stays' => $groupRows->sum(fn ($reservation) => $reservation->rooms->where('status', '!=', 'cancelled')->count())",
            "in_array($groupStatus, ['tentative', 'confirmed', 'checked_in', 'checked_out'], true)",
            "in_array($groupFocus, ['with_block', 'without_block'], true)",
            "'group_status' => '', 'group_focus' => ''",
            "$groups = $groups->filter(fn ($reservation) => $reservation->block !== null)->values()",
            "$groups = $groups->filter(fn ($reservation) => $reservation->block === null)->values()",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, SERVICE)

    def test_group_segments_and_toolbar_are_specific_and_accessible(self):
        for marker in (
            'aria-label="Filter group reservations"', 'All groups', 'Tentative',
            'Confirmed', 'In-house', 'Completed', 'With block', 'No block',
            "'group_status' => 'tentative'", "'group_status' => 'confirmed'",
            "'group_status' => 'checked_in'", "'group_status' => 'checked_out'",
            "'group_focus' => 'with_block'", "'group_focus' => 'without_block'",
            'class="groups-toolbar"', 'id="groupsDate"',
            'aria-label="Previous group window start"', 'aria-label="Next group window start"',
            'id="groupsDays"', 'id="groupsSearch"', 'type="search"',
            'Find a group, lead guest or room type',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW)

    def test_group_manifest_is_semantic_and_shows_real_block_pickup_state(self):
        for marker in (
            'class="groups-manifest"', 'id="groupsManifestTitle"',
            'class="groups-table"', 'caption class="sr-only"',
            'scope="col">Group &amp; booking', 'scope="col">Lead guest',
            'scope="col">Stay window', 'scope="col">Room stays',
            'scope="col">Status', 'scope="col">Block &amp; rooming list',
            'groups-room-count', 'groups-stay-dates', 'groups-status',
            'groups-block-link', 'Block · {{ ucfirst($r->block->status) }}',
            'groups-pickup-details', 'Preview pickup', 'Confirm pickup',
            'No room block linked', 'Open group engine',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW)

    def test_group_phone_and_search_are_permission_gated(self):
        self.assertIn("can_do('guests.view') && $groupPhone", VIEW)
        self.assertIn("$canSearchGroupPhone = can_do('guests.view')", SERVICE)
        self.assertIn("$canSearchGroupPhone ? $reservation->guest?->phone : null", SERVICE)
        self.assertIn("can_do('guests.view') ? 'Group, confirmation, guest, company, type or phone'", VIEW)

    def test_rooming_list_pickup_stays_on_existing_preview_and_locked_availability_flow(self):
        self.assertIn("route('blocks.pickup', $r->block)", VIEW)
        self.assertIn("route('blocks.pickup.preview', $r->block)", VIEW)
        self.assertIn("$r->block->isHolding() && can_do('reservations.create')", VIEW)
        self.assertIn("AvailabilityService::typeAvailable($type->id, $from, $to, $block->id)", BLOCKS)
        self.assertIn("Nothing is saved until you confirm.", BLOCKS)
        self.assertIn("$res->rooms()->create(['room_type_id' => $type->id", BLOCKS)
        pickup = BLOCKS[BLOCKS.index('public static function pickup('):BLOCKS.index('public static function release(')]
        self.assertNotIn("'room_id' =>", pickup)

    def test_empty_state_and_responsive_dark_print_styles_are_present(self):
        for marker in (
            'class="groups-empty" role="status"', 'No groups in this window',
            'No group bookings match these filters', 'Clear filters',
            '.groups-table-wrap{width:100%;overflow-x:auto',
            '.desk.dark[data-view="groups"]', '@media(max-width:1100px)',
            '@media(max-width:760px)', '@media(max-width:560px)', '@media print',
            '.groups-pickup-details,.desk-views{display:none!important}',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW)

    def test_groups_are_a_dedicated_full_shell_reservation_desk_view(self):
        self.assertIn("@elseif($view === 'groups')\n    <section class=\"groups-manifest\"", VIEW)
        self.assertIn("'groups' => $groups, 'groupSummary' => $groupSummary", SERVICE)
        self.assertIn("'groups' => 'Groups', 'noshow' => 'No-show', 'cancelled' => 'Cancelled']", VIEW)
        self.assertIn("'groups' => 'Groups', 'noshow' => 'No-show', 'cancelled' => 'Cancelled', 'list' => 'List'] as $key => $label", VIEW)


if __name__ == "__main__":
    unittest.main(verbosity=2)
