#!/usr/bin/env python3
"""The reservation workspace: the desk's day, on one screen.

The workspace already showed the rooms. This round turned it into something staff can
run a shift from, and these checks keep the pieces wired together:

  · the head counts the day's queues and the KPI strip is built with the view model;
  · the command bar keeps search, filters and the layout switch in one sticky strip;
  · the rack is built from the same cards, so both views always agree;
  · grouping regroups the same cards (and never drops them);
  · the rail leads with what has to be acted on — unassigned arrivals, refused
    check-ins, held checkouts, rooms not ready, the waitlist;
  · hidden really is hidden: the board's own CSS has to beat its flex display.

Run with: python3 deploy/dev/audit/workspace_check.py
"""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]


def read(path):
    return (ROOT / path).read_text()


CONTROLLER = read("app/Http/Controllers/ReservationWorkspaceController.php")
SERVICE = read("app/Services/ReservationWorkspaceService.php")
VIEW = read("resources/views/reservations/workspace.blade.php")
SAMPLE = read("deploy/dev/preview/workspace-sample.mjs")
REPORT = read("resources/views/reservations/workspace-report.blade.php")
BUILDER = read("deploy/dev/build-workspace-preview.mjs")
SERVER = read("deploy/dev/management-dashboard-preview-server.mjs")
CHECK = read("deploy/dev/audit/workspace_preview_check.js")


class WorkspaceTest(unittest.TestCase):
    # ── the view model ──────────────────────────────────────────────────────

    def test_the_controller_hands_the_view_its_attention_list_and_kpis(self):
        self.assertIn("'attention' => $this->attention($snap)", CONTROLLER)
        self.assertIn("'kpiTiles' => $this->kpiTiles($snap)", CONTROLLER)

    def test_the_attention_list_covers_the_desks_own_work(self):
        start = CONTROLLER.index("protected function attention(array $snap): array")
        end = CONTROLLER.index("public function report(")
        attention = CONTROLLER[start:end]
        for key in ["unassigned", "checkin-blocked", "checkout-held", "rooms-not-ready", "waitlist"]:
            self.assertIn(f"'key' => '{key}'", attention, f"{key} is not reported")
        self.assertIn("can_do(", attention, "links must follow permissions")
        self.assertIn("$snap['inventoryBlocks']", attention, "a blocked room is why a check-in is refused")
        self.assertIn("$snap['departureFigures']", attention, "a held checkout comes from the departure figures")

    def test_the_kpi_strip_is_built_with_the_view_model(self):
        tiles = CONTROLLER[CONTROLLER.index("protected function kpiTiles"):CONTROLLER.index("protected function boardDate")]
        self.assertIn("'occupancy' => ['Occupancy'", tiles)
        self.assertIn("'revenue' => [$isFuture ?", tiles)
        self.assertIn("can_do($permission)", tiles)
        self.assertIn("$snap['trend'][$key]", tiles)
        # the view no longer builds them
        self.assertNotIn("$tileLinks = [", VIEW)
        self.assertIn("@foreach ($kpiTiles as $k)", VIEW)

    def test_the_assignable_rooms_are_ordered_by_the_service(self):
        self.assertIn("public static function sortAssignable", SERVICE)
        self.assertIn("$freeRooms = WS::sortAssignable($freeRooms, $canViewHousekeeping);", VIEW)
        self.assertNotIn("$freeRooms->sort(function ($a, $b)", VIEW)

    # ── the command bar ─────────────────────────────────────────────────────

    def test_the_day_queues_sit_in_the_head(self):
        self.assertIn('$headStats = [', VIEW)
        for label in ["'Arrivals'", "'Departures'", "'In house'", "'Rooms sold'"]:
            self.assertIn(label, VIEW)
        self.assertIn('<div class="ws-head-stats">', VIEW)

    def test_the_command_bar_keeps_the_controls_together(self):
        self.assertIn('<div class="ws-cmd" id="wsCmd">', VIEW)
        self.assertIn('.ws-cmd{position:sticky;top:var(--top-h,58px)', VIEW)
        for control in ['id="fSearch"', 'id="fStatus"', 'id="fType"', 'id="fFloor"', 'id="fSource"', 'id="fVip"',
                        'id="clearWsFilters"', 'id="wsVisibleCount"', 'id="wsGroupBy"', 'id="wsChips"']:
            self.assertIn(control, VIEW, f"{control} left the command bar")

    def test_the_two_layouts_are_offered_and_labelled(self):
        self.assertIn('role="tablist" aria-label="Room board layout"', VIEW)
        self.assertIn('data-ws-view="board"', VIEW)
        self.assertIn('data-ws-view="rack"', VIEW)
        self.assertIn('aria-controls="wsRack"', VIEW)
        self.assertIn("id=\"wsTabRack\"", VIEW)

    # ── the rack ────────────────────────────────────────────────────────────

    def test_the_rack_is_built_from_the_board(self):
        self.assertIn('<tbody id="wsRackBody"></tbody>', VIEW)
        self.assertIn("function buildRack()", VIEW)
        self.assertIn("document.querySelectorAll('#wsGrid .ws-card')", VIEW)
        self.assertIn("body.dataset.built = '1'", VIEW)
        self.assertIn('data-ws-sort="rate"', VIEW)
        self.assertIn('function sortRack(key, dir)', VIEW)

    def test_the_rack_and_the_board_share_one_filter(self):
        self.assertIn("function wsMatch(el)", VIEW)
        self.assertIn("cards.forEach(card => {\n      card.hidden = !wsMatch(card);", VIEW)
        self.assertIn("rows.forEach(row => { row.hidden = !wsMatch(row); });", VIEW)

    # ── grouping ────────────────────────────────────────────────────────────

    def test_grouping_regroups_the_same_cards(self):
        self.assertIn("function applyGrouping(mode)", VIEW)
        self.assertIn("const key = groupMode === 'floor' ? 'floorName' : groupMode === 'type' ? 'typeName' : 'statusLabel';", VIEW)
        # reading the cards before dropping their wrappers is what keeps them
        self.assertLess(VIEW.index("const groupCards = [...grid.querySelectorAll('.ws-card')];"),
                        VIEW.index("grid.querySelectorAll('.ws-group').forEach(group => group.remove());"))
        self.assertIn('data-floor-name="{{ $room->floor?->name }}"', VIEW)
        self.assertIn('data-type-name="{{ $room->roomType?->name }}"', VIEW)
        self.assertIn('data-status-label=', VIEW)

    # ── the rail ────────────────────────────────────────────────────────────

    def test_the_printed_day_carries_the_same_list(self):
        # the day report is handed to the next shift, so it leads with the work too
        self.assertIn("view('reservations.workspace-report', $snap + ['attention' => $this->attention($snap)])", CONTROLLER)
        self.assertIn("$attentionItems as $item", REPORT)
        self.assertIn("{{ $item['label'] }}", REPORT)
        self.assertIn('Nothing outstanding', REPORT)

    def test_the_rail_leads_with_what_needs_acting_on(self):
        self.assertIn('id="wsAttention"', VIEW)
        self.assertIn("NEEDS ATTENTION", VIEW)
        self.assertIn("All clear", VIEW)
        rail = VIEW.index('<div class="ws-rail">')
        self.assertLess(VIEW.index('id="wsAttention"', rail), VIEW.index('class="ws-cal-head"', rail),
                        "attention must come before the calendar")

    # ── polish ──────────────────────────────────────────────────────────────

    def test_hidden_rooms_are_really_hidden(self):
        # .ws-card asks for display:flex, which otherwise beats the hidden attribute
        rule = [line for line in VIEW.splitlines() if line.startswith('.ws-card[hidden]')]
        self.assertTrue(rule, "no rule makes a filtered-out card disappear")
        self.assertIn('display:none!important', rule[0])
        for selector in ['.ws-grid[hidden]', '.ws-rack[hidden]', '.ws-group[hidden]', '.ws-rack-table tr[hidden]']:
            self.assertIn(selector, rule[0], f"{selector} would stay visible")

    def test_the_card_leads_with_the_room(self):
        # the picture is the card; the facts are laid over it
        self.assertIn('.ws-photo{position:relative;aspect-ratio:16/10', VIEW)
        self.assertIn("minmax(min(348px,100%),1fr)", VIEW)
        # the board fetches the card-sized copy; the dialog the picture itself
        self.assertIn("'photo' => $room->images->first()?->url('thumb'),", SERVICE)
        self.assertIn("'photo_full' => $room->images->first()?->url(),", SERVICE)
        self.assertIn("$cardPhoto = $c['photo'] ?? $room->roomType?->image;", VIEW)
        self.assertNotIn('ws-room-meta', VIEW)
        # the rack shows the same picture, small enough for a table row
        self.assertIn("data-photo=\"{{ $cardPhoto }}\"", VIEW)
        self.assertIn("thumb.className = 'ws-rack-thumb'", VIEW)
        # a real image element, so the browser can defer the ones below the fold
        self.assertIn('<img class="ws-photo-img" src="{{ $cardPhoto }}" alt="" loading="lazy" decoding="async">', VIEW)
        self.assertIn('.ws-photo-img{position:absolute;inset:0;', VIEW)
        # a printed board stays on the page
        self.assertIn('.ws-photo{aspect-ratio:auto;min-height:104px}', VIEW)

    def test_the_room_dialog_stands_on_its_own(self):
        # the dialogs are a second loop over the same cards; anything the board loop
        # defines has to be defined again here or the page 500s on the first dialog
        start = VIEW.index('{{-- Room detail modals --}}')
        end = VIEW.index("@push('scripts')")
        block = VIEW[start:end]
        for name in ['$cardPhoto', '$stayGuestName', '$stayGuestInitial']:
            self.assertIn(f'{name} =', block, f'{name} is borrowed from the board loop')
        self.assertIn("$cardPhoto ? 'url('.$cardPhoto.')", block)

    def test_the_screen_is_documented(self):
        doc = read("docs/WORKSPACE.md")
        self.assertIn('reservations.workspace', doc)
        self.assertIn('Needs attention', doc)
        self.assertIn('`unassigned`', doc)
        self.assertIn('RoomImage::url(\'thumb\')', doc)

    def test_a_single_room_says_guest(self):
        # "1 guests" is what a hard-coded plural gives you; a single room is common
        self.assertIn("Str::plural('guest'", VIEW)
        self.assertEqual(VIEW.count("Str::plural('guest'"), 2, 'the card and the modal both count guests')
        self.assertIn('nothing says "1 guests"', CHECK)

    def test_the_screen_says_what_it_means(self):
        # icon-only controls need a name, and every picture needs an alt
        self.assertIn('aria-label="Close room details"', VIEW)
        self.assertIn('loading="lazy" decoding="async"', VIEW)
        self.assertIn('alt=""', VIEW, 'the room picture is decorative: the card is named by its aria-label')
        self.assertIn('every control has a name', CHECK)
        self.assertIn('every picture is described or marked decorative', CHECK)

    def test_the_keyboard_reaches_the_board(self):
        self.assertIn("if (event.key === '/')", VIEW)
        self.assertIn("event.key === 'r' || event.key === 'R'", VIEW)
        self.assertIn("event.key === 'g' || event.key === 'G'", VIEW)

    def test_escape_clears_the_search_before_leaving_it(self):
        # a half-typed filter should not have to be deleted by hand
        self.assertIn("if (event.target === search && search.value)", VIEW)
        self.assertIn("search.value = '';\n          applyWsFilters();", VIEW)

    def test_the_board_prints(self):
        self.assertIn('@media print{', VIEW)
        self.assertIn('.ws-cmd,.ws-rail', VIEW)
        # a printed rack has to fit the sheet instead of scrolling off it
        self.assertIn('.ws-rack-table{min-width:0', VIEW)
        self.assertIn('.ws-rack-scroll{overflow:visible', VIEW)

    def test_the_rack_keeps_the_actions_that_post(self):
        # the rack copies the board's action markup; a copy of a form does not keep
        # its listeners, so the submit handling has to be delegated
        self.assertIn("node.matches('form')", VIEW)
        self.assertIn("document.addEventListener('submit'", VIEW)
        self.assertIn('}, true);', VIEW)
        self.assertIn("copy.querySelectorAll('label[for]')", VIEW)

    def test_a_filtered_board_can_be_linked(self):
        # the server renders the filters from the address bar; the script seeds them too
        self.assertIn("[[status, 'status'], [type, 'type'], [floor, 'floor'], [source, 'source'], [housekeeping, 'hk']]", VIEW)
        self.assertIn("element.value = [...element.options].some(option => option.value === wanted) ? wanted : '';", VIEW)
        self.assertIn("if (vipOnly && opening.get('vip') === '1') vipOnly.checked = true;", VIEW)

    def test_a_layout_can_be_linked(self):
        # a supervisor should be able to send "the rack, grouped by floor" to a colleague
        self.assertIn("syncUrlParam('view'", VIEW)
        self.assertIn("syncUrlParam('group'", VIEW)
        self.assertIn("opening.get('view')", VIEW)
        self.assertIn("targetParams.set('view', 'rack')", VIEW)

    def test_the_chosen_order_survives_a_refresh(self):
        self.assertIn("sessionStorage.setItem('wsSort'", VIEW)
        self.assertIn("sessionStorage.getItem('wsSort')", VIEW)
        # a new column starts ascending, the column already sorted reverses
        self.assertIn("rackSort?.key === key && rackSort.dir === 'asc' ? 'desc' : 'asc'", VIEW)
        self.assertIn('aria-sort', VIEW)

    def test_the_page_carries_its_own_styles_and_script(self):
        self.assertIn("@push('head')", VIEW)
        self.assertIn("@push('scripts')", VIEW)

    # ── the preview ─────────────────────────────────────────────────────────

    def test_the_preview_renders_the_real_view(self):
        self.assertIn("renderer.section('reservations/workspace', 'content'", BUILDER)
        self.assertIn("renderer.push('reservations/workspace', 'head'", BUILDER)
        self.assertIn("renderer.push('reservations/workspace', 'scripts'", BUILDER)
        self.assertIn("'/reservations-workspace'", SERVER)
        self.assertIn('workspaceContext', SAMPLE)

    def test_the_sample_matches_the_snapshot(self):
        for key in ["date", "businessDate", "cards", "arrivals", "departures", "departureFigures", "vip",
                    "trend", "deltas", "statusSummary", "waitlist", "assignableByStay", "roomTypes", "floors",
                    "kpiTiles", "attention"]:
            self.assertRegex(SAMPLE, rf"\b{key}\s*[:,]", f"the sample is missing {key}")

    def test_the_preview_is_used_as_a_working_screen(self):
        self.assertIn('runScripts', CHECK)
        self.assertIn('every room has a rack row', CHECK)
        self.assertIn('no card is lost when grouping', CHECK)
        self.assertIn('hidden rooms are really hidden by the stylesheet', CHECK)
        self.assertIn('no preview furniture on the screen', CHECK)
        self.assertIn('the saved order comes back', CHECK)
        self.assertIn('extending from the rack names the date it will use', CHECK)
        self.assertIn('a link opens the rack it points at', CHECK)


if __name__ == "__main__":
    unittest.main(verbosity=2)
