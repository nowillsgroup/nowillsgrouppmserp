#!/usr/bin/env python3
"""Regression checks for the reservation desk dashboard refresh."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
INDEX = (ROOT / "resources/views/reservations/index.blade.php").read_text()
DASHBOARD = (ROOT / "resources/views/reservations/desk-dashboard.blade.php").read_text()
DESK_LINES = (ROOT / "resources/views/reservations/desk-lines.blade.php").read_text()
SERVICE = (ROOT / "app/Services/ReservationDeskService.php").read_text()
AVAILABILITY = (ROOT / "app/Services/AvailabilityService.php").read_text()
ROUTES = (ROOT / "routes/web.php").read_text()
DESK_STAY = (ROOT / "resources/views/reservations/desk-stay.blade.php").read_text()
DESK_CONTROLLER = (ROOT / "app/Http/Controllers/ReservationDeskController.php").read_text()


class ReservationDashboardPolishTest(unittest.TestCase):
    def test_timeline_is_a_dedicated_hotel_tape_chart(self):
        for needle in (
            '@elseif($view === \'timeline\')',
            'class="timeline-hero"', 'FRONT OFFICE', 'ROOM TIMELINE', '<h2>Every room, every stay</h2>',
            "@section('title', match($view ?? null) { 'dashboard' => 'Reservation Desk', 'timeline' => 'Hotel Timeline'",
            'class="timeline-hero-metrics"', 'timeline-range-line', 'Print timeline',
            '<span>Unassigned</span><strong>{{ number_format($loose->count()) }}</strong>',
            'class="timeline-guide"', 'class="timeline-legend"', 'legend-swatch is-outage', 'Room block', 'Deposit on file',
            'aria-label="Hotel room timeline from {{ $date->format(\'d M Y\') }} for {{ $days }} days"',
            'tabindex="0" aria-describedby="timelineScrollHint"', 'id="timelineScrollHint"',
            'class="timeline-day-cell', 'class="timeline-edge is-in"', 'class="timeline-edge is-out"',
            'class="timeline-room-status status-{{ $room->status }}"', 'Live status · HK · type',
            'class="timeline-empty-state"', 'Clear room filters', 'Clear timeline filters', 'Clear filters',
            '@foreach($loose->take(6) as $s)', '$loose->skip(6)', 'View {{ number_format($loose->count() - 6) }} more unassigned',
            'aria-label="Unassigned stay: reservation {{ $s->reservation->reservation_no }}',
            '.timeline-loose-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(205px,1fr))',
            "subDays($view === 'timeline' ? $days : 1)", "addDays($view === 'timeline' ? $days : 1)",
            "Previous timeline window", "Next timeline window",
            'role="region"', 'aria-label="Room {{ $room->room_no }}, {{ $s->reservation->guest->full_name }}',
            'title="{{ $s->reservation->guest->full_name }} · {{ $s->reservation->reservation_no }} · {{ $s->arrival_date->format(\'d M Y\') }}',
            '<small>{{ $s->reservation->reservation_no }} · {{ $barLabel }} · {{ money($s->rate) }}',
            'aria-label="Room {{ $room->room_no }}, {{ $room->roomType->name ?? \'type not set\' }}',
            '.stay-bar[draggable="true"]', 'can_do(\'frontdesk.roommove\')', 'class="desk-toast"',
            '#desk[data-view="timeline"] .tape', 'timeline-hero-metrics',
            '#desk[data-view="timeline"] .stay-bar:focus-visible',
            '#desk[data-view="timeline"] .desk-tools{display:grid',
            '@media(prefers-reduced-motion:reduce){#desk[data-view="timeline"] .stay-bar{transition:none}}',
            'window.addEventListener(\'beforeprint\'', 'window.addEventListener(\'afterprint\'',
            "@page { size: A4 landscape; margin: 8mm; }",
            "timelinePrintDetails = [...document.querySelectorAll('.timeline-loose-more:not([open])')]",
            'timelinePrintDetails.forEach(details => { details.open = true; });',
            'timelinePrintDetails.forEach(details => { details.open = false; });',
            '#desk[data-view="timeline"] .timeline-loose-more summary{display:none}',
            '#desk[data-view="timeline"] .tape-head,#desk[data-view="timeline"] .room-row{grid-template-columns:var(--room-col) repeat(var(--days),minmax(0,1fr));min-width:0}',
        ):
            with self.subTest(needle=needle):
                self.assertIn(needle, INDEX)
        self.assertIn("@if($view !== 'list' && $view !== 'timeline' && $view !== 'board' && $view !== 'arrivals' && $view !== 'departures' && $view !== 'inhouse' && $view !== 'unassigned' && $view !== 'waitlist' && $view !== 'groups' && $view !== 'noshow' && $view !== 'cancelled')", INDEX)
        self.assertIn("@elseif($view !== 'dashboard' && $view !== 'list')\n  <nav class=\"desk-nav\"", INDEX)
        self.assertIn("@if(!request('tab') && in_array($view, ['timeline', 'board'], true)) aria-current=\"page\" @endif", INDEX)
        self.assertIn("$navCurrent = request('tab') ? request('tab') === $key : $view === $key;", INDEX)
        self.assertIn('@if($navCurrent) aria-current="page" @endif', INDEX)
        self.assertIn('aria-valuetext="52 pixels per day"', INDEX)
        self.assertIn("zoom.setAttribute('aria-valuetext', `${zoom.value} pixels per day`)", INDEX)
        self.assertIn("@elseif($view !== 'list')\n  <form class=\"desk-tools", INDEX)
        self.assertIn("$due = $s->status === 'checked_in' && $s->departure_date->isSameDay($date);", INDEX)
        self.assertIn("$s->departure_date->toDateString() < $to->toDateString()", INDEX)
        self.assertIn("$s->arrival_date->toDateString() < $to->toDateString()", INDEX)

    def test_timeline_drag_assignment_covers_unassigned_stays_and_correct_permissions(self):
        self.assertIn("Route::post('/tape-chart/drop', 'tapeDrop')->middleware('perm:frontdesk.roommove,reservations.edit')->name('tapechart.drop');", ROUTES)
        self.assertIn("Route::post('/stays/{stay}/place', [Controllers\\ReservationDeskController::class, 'place'])->middleware('perm:reservations.edit,frontdesk.roommove')->name('place');", ROUTES)
        self.assertIn("abort_unless(can_do('frontdesk.roommove') || ($stay->status === 'reserved' && can_do('reservations.edit')), 403);", DESK_CONTROLLER)
        self.assertIn("@if((can_do('frontdesk.roommove') || ($stay->status === 'reserved' && can_do('reservations.edit'))) && in_array($stay->status, ['reserved', 'checked_in'], true))", DESK_STAY)
        self.assertIn("@if(can_do('reservations.edit'))<input name=\"rate\"", DESK_STAY)
        self.assertIn('Keyboard assignment: open a reserved stay', INDEX)
        self.assertIn("in_array($s->status, ['reserved', 'checked_in'], true) && (can_do('frontdesk.roommove') || ($s->status === 'reserved' && can_do('reservations.edit'))) ? 'true' : 'false'", INDEX)
        self.assertIn(".stay-bar[draggable=\"true\"], .timeline-loose-link[draggable=\"true\"]", INDEX)
        self.assertIn("source.classList.add('is-dragging')", INDEX)
        self.assertIn("source.classList.remove('is-dragging')", INDEX)
        self.assertIn('id="desk-toast" class="desk-toast" role="status" aria-live="polite" aria-atomic="true" hidden', INDEX)
        self.assertIn("Drag a stay bar or unassigned reservation card to a room", INDEX)
        self.assertIn("Drag reserved stays or unassigned reservation cards to a room to assign; in-house moves require front-desk room-move permission.", INDEX)
        self.assertIn("? ', drag to assign a room' : ''", INDEX)
        self.assertIn('data-room-check-note role="status" aria-live="polite" aria-atomic="true"', DESK_STAY)
        self.assertEqual(5, DESK_STAY.count('class="quote tiny muted" role="status" aria-live="polite" aria-atomic="true"'))
        self.assertIn("form.querySelector('[data-room-check-note]')", INDEX)
        self.assertIn('roomCheck.disabled = true;', INDEX)
        self.assertIn("roomCheck.setAttribute('aria-busy', 'true')", INDEX)
        self.assertIn("previewKey(form) !== checkedKey", INDEX)
        self.assertIn("roomCheck.removeAttribute('aria-busy')", INDEX)
        self.assertIn('Checking room availability…', INDEX)
        self.assertIn('form.dataset.roomCheckSeen = checkedKey;', INDEX)
        self.assertIn("const roomCheck = form.querySelector('[data-room-check]');", INDEX)
        self.assertIn('form.dataset.roomCheckSeen !== roomKey', INDEX)
        self.assertIn('if (!roomCheck.disabled) roomCheck.click();', INDEX)
        self.assertIn('Press Confirm room to save.', INDEX)
        self.assertIn('Could not reach the server. The room was not changed.', INDEX)

    def test_timeline_text_colors_meet_wcag_contrast(self):
        def luminance(color):
            channels = [int(color[index:index + 2], 16) / 255 for index in (1, 3, 5)]
            linear = [value / 12.92 if value <= .04045 else ((value + .055) / 1.055) ** 2.4 for value in channels]
            return sum(value * weight for value, weight in zip(linear, (.2126, .7152, .0722)))

        rules = (
            ('.timeline-loose-heading span{color:#80613a;', '#80613a', ('#fff9ee',)),
            ('#desk[data-view="timeline"] .timeline-room-heading small{color:#5f6d64;', '#5f6d64', ('#f6faf7',)),
            ('#desk[data-view="timeline"] .timeline-day small{color:#5e6c62;', '#5e6c62', ('#f6faf7', '#f3f6f3', '#e5f2e8')),
            ('#desk[data-view="timeline"] .timeline-day em{position:absolute;top:2px;color:#5e6c62;', '#5e6c62', ('#f6faf7', '#f3f6f3', '#e5f2e8')),
            ('#desk[data-view="timeline"] .timeline-empty-state p{margin:3px 0 0;color:#5f6d64;', '#5f6d64', ('#f8fbf8',)),
            ('#desk[data-view="timeline"]>.desk-nav em{display:inline-grid;min-width:20px;height:19px;place-items:center;padding:0 5px;border-radius:6px;background:#f1f4f1;color:#5d6b61;font-size:.62rem;font-variant-numeric:tabular-nums;opacity:1}', '#5d6b61', ('#f1f4f1',)),
            ('#desk[data-view="timeline"] .stay-bar.in{background:linear-gradient(110deg,#0d756b,#0f756b)}', '#ffffff', ('#0d756b', '#0f756b')),
            ('#desk[data-view="timeline"] .stay-bar.due{background:linear-gradient(110deg,#a65a08,#a4560a)}', '#ffffff', ('#a65a08', '#a4560a')),
            ('#desk[data-view="timeline"] .stay-bar.tent{border:1px dashed #64748b;background:#606c78}', '#ffffff', ('#606c78',)),
            ('#desk[data-view="timeline"] .stay-bar.out{background:#5e6c62}', '#ffffff', ('#5e6c62',)),
        )
        for rule, foreground, backgrounds in rules:
            self.assertIn(rule, INDEX)
            for background in backgrounds:
                lighter, darker = sorted((luminance(foreground), luminance(background)), reverse=True)
                with self.subTest(foreground=foreground, background=background):
                    self.assertGreaterEqual((lighter + .05) / (darker + .05), 4.5)
        self.assertIn('#desk[data-view="timeline"] .stay-bar small{font-size:.57rem;font-weight:600;opacity:.94}', INDEX)
        for background in ('#0d756b', '#0f756b', '#a65a08', '#a4560a', '#606c78', '#5e6c62'):
            base = [int(background[index:index + 2], 16) for index in (1, 3, 5)]
            effective = tuple(round(255 * .94 + value * .06) for value in base)
            foreground_luminance = sum(value * weight for value, weight in zip(
                [channel / 12.92 if channel <= .04045 else ((channel + .055) / 1.055) ** 2.4 for channel in (c / 255 for c in effective)],
                (.2126, .7152, .0722),
            ))
            lighter, darker = sorted((foreground_luminance, luminance(background)), reverse=True)
            with self.subTest(foreground="94%-white", background=background):
                self.assertGreaterEqual((lighter + .05) / (darker + .05), 4.5)

    def test_dark_timeline_text_meets_wcag_contrast(self):
        def luminance(color):
            channels = [int(color[index:index + 2], 16) / 255 for index in (1, 3, 5)]
            linear = [value / 12.92 if value <= .04045 else ((value + .055) / 1.055) ** 2.4 for value in channels]
            return sum(value * weight for value, weight in zip(linear, (.2126, .7152, .0722)))

        pairs = (
            ('.desk.dark[data-view="timeline"] .timeline-day small,.desk.dark[data-view="timeline"] .timeline-day em{color:#a9bbac}', '#a9bbac', ('#192e20', '#17291c', '#244630')),
            ('.desk.dark[data-view="timeline"] .room-label small{color:#adbdaf}', '#adbdaf', ('#172a1d',)),
            ('.desk.dark[data-view="timeline"]>.desk-nav a,.desk.dark[data-view="timeline"]>.desk-views .desk-view-link,.desk.dark[data-view="timeline"] .desk-tools label,.desk.dark[data-view="timeline"] .timeline-legend li,.desk.dark[data-view="timeline"] .timeline-guide-copy span,.desk.dark[data-view="timeline"] .timeline-guide-note{color:#b8c8ba}', '#b8c8ba', ('#14251a',)),
            ('.desk.dark[data-view="timeline"] .timeline-loose-heading span,.desk.dark[data-view="timeline"] .timeline-loose-link small{color:#c5b596}', '#c5b596', ('#2b2418', '#302718')),
            ('.desk.dark[data-view="timeline"] .timeline-empty-state p{color:#aebdaf}', '#aebdaf', ('#172a1d',)),
            ('.desk.dark[data-view="timeline"] .timeline-room-status.status-out_of_order,.desk.dark[data-view="timeline"] .timeline-room-status.status-out_of_service{background:#4c2925;color:#f1b6ac}', '#f1b6ac', ('#4c2925',)),
        )
        for rule, foreground, backgrounds in pairs:
            self.assertIn(rule, INDEX)
            for background in backgrounds:
                lighter, darker = sorted((luminance(foreground), luminance(background)), reverse=True)
                with self.subTest(foreground=foreground, background=background):
                    self.assertGreaterEqual((lighter + .05) / (darker + .05), 4.5)

    def test_room_board_preserves_room_operations_and_has_responsive_presentation(self):
        for needle in (
            "@elseif($view === 'board')",
            "'board' => 'Room Board'",
            'class="room-board-hero"', 'ROOM BOARD', '<h2>Every room, at a glance</h2>',
            'Desk date · <time datetime="{{ $date->toDateString() }}">',
            'Window · <time datetime="{{ $date->toDateString() }}">',
            '<time datetime="{{ $date->copy()->addDays($days - 1)->toDateString() }}">',
            'Business date · <time datetime="{{ bdate()->toDateString() }}">',
            'class="room-board-metrics"', 'Rooms shown', 'Occupied now', 'Vacant now', 'Rooms with blocks',
            'class="room-board" role="region" aria-label="Hotel rooms grouped by {{ $group }}"',
            'aria-labelledby="roomBoardRoom{{ $room->id }}"', 'id="roomBoardRoom{{ $room->id }}"',
            "$boardStay = $roomStays->first(fn ($stay) => $stay->arrival_date->toDateString() <= $date->toDateString() && $stay->departure_date->toDateString() > $date->toDateString());",
            "$dueOutStay = $roomStays->first(fn ($stay) => in_array($stay->status, ['checked_in', 'checked_out'], true) && $stay->departure_date->toDateString() === $date->toDateString());",
            "$cardStay = $boardStay ?? $dueOutStay ?? $nextStay;",
            "$nextStay = $roomStays->where('status', 'reserved')->filter(fn ($stay) => $stay->arrival_date->toDateString() >= $date->toDateString())->sortBy('arrival_date')->first();",
            "'Due out'", 'class="room-board-turnover"', '@if($boardStay && $dueOutStay)',
            'Due out · <time datetime="{{ $date->toDateString() }}">',
            'aria-label="Open departing stay for {{ $dueOutStay->reservation?->guest?->full_name ?? \'guest profile unavailable\' }}"',
            '.room-board-stay-state.due-out{', '.desk.dark[data-view="board"] .room-board-stay-state.due-out{',
            'class="room-board-blocks" aria-label="Room blocks for room {{ $room->room_no }}"',
            '@foreach($roomBlocks as $roomBlock)',
            "$roomBlockActive = $roomBlock->starts_on->toDateString() <= $date->toDateString() && $roomBlock->ends_on->toDateString() > $date->toDateString();",
            "{{ ($roomBlockActive ? 'Active · ' : 'Upcoming · ').$blockReason }}",
            'room returns {{ $roomBlock->ends_on->format(\'d M Y\') }}',
            'Starts <time datetime="{{ $roomBlock->starts_on->toDateString() }}">',
            '· returns <time datetime="{{ $roomBlock->ends_on->toDateString() }}">',
            "{{ $roomBlockActive ? 'is-active' : 'is-upcoming' }}", '.room-board-block.is-upcoming{',
            '.desk.dark[data-view="board"] .room-board-block.is-upcoming{',
            "route('reservations.room', $room)", "route('reservations.stay', $cardStay)",
            "@if($cardGuest && can_do('guests.view'))", "route('guests.show', $cardGuest)",
            'class="room-board-reservation"', 'aria-label="Open reservation {{ $cardStay->reservation?->reservation_no ?? \'details\' }}"',
            'datetime="{{ $roomBlock->starts_on->toDateString() }}"',
            'datetime="{{ $cardStay->arrival_date->toDateString() }}"',
            'datetime="{{ $cardStay->departure_date->toDateString() }}"',
            "Room {{ $room->room_no }}", 'HK · {{ $hkStatusLabel }}',
            'VIP guest', 'Group stay', 'Request · {{ \Illuminate\Support\Str::limit($cardStay->reservation->special_request, 72) }}',
            '.room-board-stay small{max-width:100%;overflow-wrap:anywhere;', '.room-board-flag.request{max-width:100%;overflow-wrap:anywhere;',
            'class="room-board-clear"', 'Nothing shown for this date and filters',
            'class="room-board-empty"', 'Clear room board filters',
            "@if($view !== 'list' && $view !== 'timeline' && $view !== 'board' && $view !== 'arrivals' && $view !== 'departures' && $view !== 'inhouse' && $view !== 'unassigned' && $view !== 'waitlist' && $view !== 'groups' && $view !== 'noshow' && $view !== 'cancelled')",
            "in_array($view, ['timeline', 'board'], true)",
            "#desk[data-view=\"board\"]", '.desk.dark[data-view=\"board\"]',
            '#desk[data-view="board"] .room-board-card a:focus-visible',
            '.desk.dark[data-view="board"] .room-board-card a:focus-visible',
            '@media(max-width:1280px)', '@media(max-width:620px)', '@media(prefers-reduced-motion:reduce)',
            '@media print', '#desk[data-view="board"] .room-board-card{break-inside:avoid',
        ):
            with self.subTest(needle=needle):
                self.assertIn(needle, INDEX)
        self.assertIn("['view' => 'board', 'date' => $date->toDateString(), 'days' => $days, 'group' => $group]", INDEX)
        self.assertIn("$roomBlocks = ($outages[$room->id] ?? collect())->sortBy('starts_on')->values();", INDEX)
        self.assertIn("$boardBlockedCount = $rooms->filter(fn ($room) => ($outages[$room->id] ?? collect())->isNotEmpty())->count();", INDEX)
        self.assertIn("->whereDate('departure_date', $view === 'board' ? '>=' : '>', $date->toDateString())", SERVICE)

    def test_room_board_operational_colors_meet_wcag_contrast(self):
        def luminance(color):
            channels = [int(color[index:index + 2], 16) / 255 for index in (1, 3, 5)]
            linear = [value / 12.92 if value <= .04045 else ((value + .055) / 1.055) ** 2.4 for value in channels]
            return sum(value * weight for value, weight in zip(linear, (.2126, .7152, .0722)))

        # Contrast uses the lightest hero-gradient endpoint and the 7.5%-white metric-panel blend.
        pairs = (
            ('.room-board-eyebrow{display:flex;align-items:center;gap:8px;color:#e7f2ea;font-size:.64rem;font-weight:850;letter-spacing:.14em}', '#e7f2ea', '#26764d'),
            ('.room-board-hero-copy>p{margin:0;color:#e7f2ea;font-size:.82rem;line-height:1.5}', '#e7f2ea', '#26764d'),
            ('.room-board-date-line{display:flex;flex-wrap:wrap;gap:6px 13px;margin-top:11px;color:#e7f2ea;font-size:.68rem}', '#e7f2ea', '#26764d'),
            ('.room-board-metrics span{color:#f7fbf8;font-size:.56rem;font-weight:850;letter-spacing:.07em;text-transform:uppercase}', '#f7fbf8', '#36805a'),
            ('.room-board-metrics small{color:#fff;font-size:.59rem}', '#ffffff', '#36805a'),
            ('.room-board-status.status-occupied{background:#e4f1e7;color:#215b37}', '#215b37', '#e4f1e7'),
            ('.room-board-status.status-out_of_order,.room-board-status.status-out_of_service{background:#fff0ed;color:#9c3328}', '#9c3328', '#fff0ed'),
            ('.room-board-status.status-reserved{background:#edf2ff;color:#394d7b}', '#394d7b', '#edf2ff'),
            ('.room-board-hk.hk-clean,.room-board-hk.hk-inspected{border-color:#c9e4cf;background:#eef8f0;color:#27623b}', '#27623b', '#eef8f0'),
            ('.room-board-hk.hk-dirty,.room-board-hk.hk-touch_up{border-color:#f0d8a6;background:#fff7e6;color:#81520f}', '#81520f', '#fff7e6'),
            ('.room-board-block.is-upcoming{border-color:#ecd9ad;background:#fff8e8;color:#74510e}', '#74510e', '#fff8e8'),
            ('.room-board-stay-state.due-out{background:#fff1dc;color:#80500d}', '#80500d', '#fff1dc'),
            ('.room-board-turnover span{color:#74510e;', '#74510e', '#fff8e8'),
            ('.room-board-turnover a{max-width:100%;overflow:hidden;color:#513b16;', '#513b16', '#fff8e8'),
            ('.room-board-turnover small{max-width:100%;overflow-wrap:anywhere;color:#74510e;', '#74510e', '#fff8e8'),
            ('.room-board-clear span{color:#5f6d64;', '#5f6d64', '#f8fbf8'),
            ('.desk.dark[data-view="board"] .room-board-turnover span,.desk.dark[data-view="board"] .room-board-turnover small{color:#f0d69a}', '#f0d69a', '#3c321b'),
            ('.desk.dark[data-view="board"] .room-board-turnover a{color:#f4e7c4}', '#f4e7c4', '#3c321b'),
            ('.desk.dark[data-view="board"] .room-board-stay-state.due-out{background:#3c321b;color:#f0d69a}', '#f0d69a', '#3c321b'),
            ('.desk.dark[data-view="board"] .room-board-block.is-upcoming{border-color:#725b2b;background:#3c321b;color:#f0d69a}', '#f0d69a', '#3c321b'),
        )
        for rule, foreground, background in pairs:
            self.assertIn(rule, INDEX)
            lighter, darker = sorted((luminance(foreground), luminance(background)), reverse=True)
            with self.subTest(foreground=foreground, background=background):
                self.assertGreaterEqual((lighter + .05) / (darker + .05), 4.5)

    def test_dashboard_surfaces_a_permission_safe_priority_queue_before_room_analytics(self):
        self.assertLess(DASHBOARD.index('class="desk-priority-board"'), DASHBOARD.index('class="card desk-dashboard-room-panel"'))
        self.assertIn("$deskPriorities = [", DASHBOARD)
        for token in (
            "'label' => 'Arrivals due'", "'label' => $date->isSameDay(bdate()) ? 'Departures to review' : 'Departures scheduled'",
            "'label' => 'Rooms to assign'", "'label' => 'Guest details to complete'", "'label' => 'Past ETA'",
            "'view' => 'arrivals', 'due' => 'in'", "'focus' => 'unassigned'", "'focus' => 'guest_details'", "'focus' => 'late_eta'",
            "aria-labelledby=\"deskPriorityTitle\"", 'aria-label="{{ $priority[\'label\'] }}:', 'Open queue',
        ):
            with self.subTest(token=token):
                self.assertIn(token, DASHBOARD)

    def test_dashboard_has_a_distinct_daily_operations_hero(self):
        for needle in (
            "@elseif($view === 'dashboard')",
            'class="desk-head desk-dashboard-hero"',
            'FRONT OFFICE', 'DAILY OPERATIONS',
            "{{ $date->isSameDay(bdate()) ? 'Today at a glance' : 'Desk overview' }}",
            "Business date · {{ bdate()->format('d M Y') }}",
            "{{ number_format($kpis['sellable']) }} rooms on sale",
        ):
            with self.subTest(needle=needle):
                self.assertIn(needle, INDEX)

    def test_dashboard_has_six_operational_links_and_collapsible_detail_metrics(self):
        self.assertIn('$dashboardStats = [', INDEX)
        for label in ('Rooms available', 'Arrivals', 'Departures', 'In-house rooms', 'Occupancy', 'Unassigned stays'):
            with self.subTest(metric=label):
                self.assertIn(f"['{label}'", INDEX)
        self.assertIn("'room stays arriving '.$date->format('d M').' · '.$kpis['due_in'].' still due to check in'", INDEX)
        self.assertIn("$historicalDeskDate = $date->copy()->startOfDay()->lt(bdate()->copy()->startOfDay());", INDEX)
        self.assertIn("$historicalDeskDate ? 'Room stays with this departure date, including completed or overdue stays' : 'Room stays scheduled to depart on this date'", INDEX)
        self.assertIn("['No-shows', $kpis['noshow'], ['view' => 'noshow', 'date' => $date->toDateString(), 'days' => 1]", INDEX)
        self.assertIn("['Cancelled', $kpis['cancelled'], ['view' => 'cancelled', 'date' => $date->toDateString(), 'days' => 1]", INDEX)
        self.assertEqual(2, INDEX.count("['view' => 'departures', 'tab' => $currentDeskDate ? 'dueout' : null]"))
        self.assertIn("$historicalDeskDate ? 'Room stays that arrived on this date' : 'Room stays scheduled to arrive on this date'", INDEX)
        self.assertIn("? 'room stays arrived '.$date->format('d M')", INDEX)
        self.assertIn("? 'room stays with departure date '.$date->format('d M')", INDEX)
        self.assertIn("{{ $historicalDeskDate ? 'Departures' : 'Due departures' }}", DASHBOARD)
        self.assertIn("'checked-in room stays due out '.$date->format('d M')", INDEX)
        self.assertIn("'booked room stays due out '.$date->format('d M')", INDEX)
        self.assertIn("'currently checked in'", INDEX)
        self.assertIn("$label === 'Outstanding' && can_do('folio.view') => route('folios.index', ['status' => 'open', 'scope' => 'reservation_desk'])", INDEX)
        self.assertIn("$label === 'Posted room' && can_do('folio.view') => route('folios.transactions'", INDEX)
        self.assertIn("$label === 'Posted all' && can_do('folio.view') => route('folios.transactions'", INDEX)
        self.assertIn("$label === 'Deposits' && can_do('folio.view') => route('folios.transactions'", INDEX)
        self.assertEqual(INDEX.count('href="{{ $folioMetricHref($label, $extra) }}"'), 2, 'Dashboard and compact desk figures must share permission-aware folio drill-downs')
        self.assertIn("Folio::where('status', 'open')", SERVICE)
        self.assertIn("whereNotNull('reservation_id')->orWhereNotNull('reservation_room_id')", SERVICE)
        self.assertIn("'balance_review' => $canViewInhouseFolios", SERVICE)
        self.assertIn("} elseif ($focus === 'balance')", SERVICE)
        self.assertIn("abs($inhouseMoneyFlags[$stay->id]['balance'] ?? 0) > 0.009", SERVICE)
        self.assertIn('aria-label="Front desk figures for {{ $date->format(\'d M Y\') }}"', INDEX)
        self.assertIn("'Free for this desk date after bookings and blocks'", INDEX)
        self.assertIn("'Housekeeping tasks currently in progress for this task date'", INDEX)
        for live_hint in (
            'Active rooms in inventory now', 'Currently marked occupied in room status',
            'Currently vacant and clean', 'Currently vacant and dirty',
            'Currently marked inspected by housekeeping', 'Currently marked out of order',
            'Currently marked out of service',
        ):
            with self.subTest(live_hint=live_hint):
                self.assertIn(f"'{live_hint}'", INDEX)
        self.assertIn("'Advance payments posted on this desk date", INDEX)
        self.assertIn("'Currently checked-in stays covering this date and departing after it'", INDEX)
        self.assertIn("'Walk-in reservations arriving on this date'", INDEX)
        self.assertIn('role="group" aria-label="Front desk snapshot"', INDEX)
        self.assertIn('class="desk-summary-card tone-{{ $tone }}"', INDEX)
        self.assertIn('<details class="desk-metric-details">', INDEX)
        self.assertIn('<summary>All desk figures', INDEX)

    def test_selected_date_does_not_mislabel_live_status_or_eta_alerts(self):
        self.assertIn("'lateArrivals' => $view === 'dashboard' && $date->isSameDay(bdate()) ? self::lateArrivals($date) : collect()", SERVICE)
        self.assertIn("self::etaHasPassed(", SERVICE)
        self.assertIn("public static function etaHasPassed(Carbon $arrivalDate, ?string $eta, Carbon $now): bool", SERVICE)
        self.assertIn("$arrivalDate->copy()->startOfDay()->setTimeFromTimeString($time)", SERVICE)
        self.assertIn("return $etaAt->lt($now);", SERVICE)
        self.assertIn("'desk_date' => $g->whereIn('id', $deskDateIds)->count()", SERVICE)
        self.assertIn("$row['desk_date']", DASHBOARD)
        self.assertIn("$dashGo = fn (array $extra = []) => route('reservations.index', array_merge(request()->only('date'), $extra));", DASHBOARD)
        for needle in (
            'Current in-house', 'ETA watch', 'Live date only',
            'Past-ETA alerts are only evaluated for the current business date.',
            'Desk date',
            'due out on {{ $date->format(\'d M\') }}',
            'Comps &amp; house use — {{ $date->format(\'d M Y\') }}',
            'More desk activity', 'Rooms on desk date',
            'reservations on file', 'Room stays in window',
            "{{ $date->isSameDay(bdate()) ? 'Today' : 'Desk date' }}",
            "? 'Arrivals' : 'Expected arrivals'",
            "'checked_out' ? 'Checked out' : \\App\\Services\\StayService::checkInReady($s)",
        ):
            with self.subTest(needle=needle):
                self.assertIn(needle, DASHBOARD)
        self.assertIn("$s->status === 'reserved' ? \\App\\Services\\StayService::checkInReady($s) : ($s->status === 'checked_in' ? 'Already checked in.' : 'Stay completed.')", DESK_LINES)

    def test_dashboard_revenue_uses_nightly_rates_for_the_overlapping_window(self):
        self.assertIn('private static function roomNightsInWindow(ReservationRoom $stay, Carbon $start, Carbon $end, Carbon $businessDate): int', SERVICE)
        self.assertIn('private static function roomRevenueInWindow(ReservationRoom $stay, Carbon $start, Carbon $end, Carbon $businessDate): float', SERVICE)
        self.assertIn('return (float) $stay->rate * self::roomNightsInWindow($stay, $start, $end, $businessDate);', SERVICE)
        self.assertIn("['reserved', 'checked_in', 'checked_out']", SERVICE)
        self.assertEqual(3, SERVICE.count('self::roomRevenueInWindow('))
        self.assertIn('Expected room revenue (pre-tax), summed per occupied night', SERVICE)
        self.assertIn('nightly revenue for stays covering the selected $days-day window', SERVICE)

    def test_on_books_amounts_name_their_inclusive_date_windows(self):
        self.assertIn("$forecastEnd = $date->copy()->addDays(13);", DASHBOARD)
        self.assertIn("$thirtyDayEnd = $date->copy()->addDays(29);", DASHBOARD)
        self.assertIn("{{ $date->format('d M Y') }} to {{ $forecastEnd->format('d M Y') }}", DASHBOARD)
        self.assertIn("{{ $date->format('d M Y') }} to {{ $thirtyDayEnd->format('d M Y') }}", DASHBOARD)
        self.assertIn("{{ money($onTheBooks['days14']) }}", DASHBOARD)
        self.assertIn("{{ money($onTheBooks['days30']) }}", DASHBOARD)

    def test_occupancy_is_unavailable_when_no_rooms_are_sellable(self):
        self.assertIn("'occ' => ($sellableByDay[$ds] ?? 0) ? round($rooms / $sellableByDay[$ds] * 100, 1) : null", SERVICE)
        self.assertIn("$p['occ'] === null ? 'unavailable; no sellable rooms'", DASHBOARD)
        self.assertIn("$p['occ'] === null ? '—' : $p['occ'].'%'", DASHBOARD)

    def test_historical_availability_uses_dated_records_not_current_room_flags(self):
        self.assertIn("AvailabilityService::historicalReservationRooms($date)->count()", SERVICE)
        self.assertIn("AvailabilityService::historicalReservationRooms($date)->pluck('id')", SERVICE)
        self.assertIn("$available = $historical", SERVICE)
        self.assertIn("AvailabilityService::historicalOutageRoomIds($date)", SERVICE)
        self.assertIn("'blocked' => $historical", SERVICE)
        self.assertIn("public static function historicalReservationRooms($date, ?int $roomTypeId = null): Collection", AVAILABILITY)
        self.assertIn("public static function historicalOutageRoomIds($date): Collection", AVAILABILITY)
        self.assertIn("status flags are not historical data", AVAILABILITY)
        self.assertIn("whereIn('status', ['checked_in', 'checked_out'])", AVAILABILITY)
        self.assertIn("LeaseService::blockedRoomIds($day, $nextDay)", AVAILABILITY)
        self.assertIn("self::historicalOutageRoomIds($day)", AVAILABILITY)

    def test_forecast_flow_counts_expected_departures_and_historical_completed_stays(self):
        self.assertIn("'departures' => $historical || $current ? ['checked_in', 'checked_out'] : ['reserved', 'checked_in']", SERVICE)
        self.assertIn("'due_out' => $historical ? ['checked_in', 'checked_out'] : ($current ? ['checked_in'] : ['reserved', 'checked_in'])", SERVICE)
        self.assertIn("whereIn('status', $dateStatuses['arrivals'])", SERVICE)
        self.assertIn("whereIn('status', $dateStatuses['departures'])", SERVICE)
        self.assertIn("'arrivals' => $s->arrival_date->toDateString() === $day && in_array($s->status, $dateStatuses['arrivals'], true)", SERVICE)
        self.assertIn("'departures' => $s->departure_date->toDateString() === $day && in_array($s->status, $dateStatuses['departures'], true)", SERVICE)
        self.assertIn("'dueout' => ReservationRoom::whereDate('departure_date', $d)->where('status', 'checked_in')->count()", SERVICE)
        self.assertIn("'walkins' => ReservationRoom::whereDate('arrival_date', $d)->whereIn('status', $dateStatuses['arrivals'])->whereHas('reservation.source'", SERVICE)
        self.assertIn("if ($request->input('tab') === 'dueout')", SERVICE)
        self.assertIn("$departures = $departures->where('status', 'checked_in')->values();", SERVICE)
        self.assertIn("$key === 'dueout' ? 'departures'", INDEX)
        self.assertIn("whereIn('status', $dateStatuses['covering'])", SERVICE)
        self.assertIn("&& in_array($s->status, $dateStatuses['covering'], true)", SERVICE)
        self.assertIn("$s->departure_date->toDateString() > $day", SERVICE)
        self.assertIn("whereDate('arrival_date', '<=', $date)->whereDate('departure_date', '>', $date)", SERVICE)
        self.assertIn("$stay->arrival_date->toDateString() <= $day", SERVICE)
        self.assertIn("$stay->departure_date->toDateString() > $day", SERVICE)
        self.assertIn("whereDate('arrival_date', '<=', $d)->whereDate('departure_date', '>', $d)->count()", SERVICE)
        self.assertIn("$s->arrival_date->toDateString() <= $day && $s->departure_date->toDateString() > $day", SERVICE)
        self.assertIn("'reservation_covering' => $historical ? ['checked_in', 'checked_out'] : ['tentative', 'confirmed', 'checked_in']", SERVICE)
        self.assertIn("'reservation_arrivals' => $historical ? ['checked_in', 'checked_out'] : ['tentative', 'confirmed', 'checked_in']", SERVICE)
        self.assertIn("'groups' => self::groupReservationsInWindow($date, $days)->count()", SERVICE)
        self.assertIn("$groupRows = in_array($view, ['dashboard', 'groups'], true)", SERVICE)
        self.assertIn("$statuses = $deskDate->lt($businessDate)", SERVICE)
        self.assertIn("? ['checked_in', 'checked_out']", SERVICE)
        self.assertIn(": ['tentative', 'confirmed', 'checked_in']", SERVICE)
        self.assertIn("->whereIn('status', $statuses)", SERVICE)
        self.assertIn("$s->status === 'reserved')Not checked in.", DESK_LINES)
        self.assertIn("$s->status === 'reserved')Not checked in.", DASHBOARD)

    def test_unassigned_preview_discloses_rows_hidden_beyond_the_six_item_sample(self):
        self.assertIn('@forelse($unassigned->take(8) as $s)', DASHBOARD)
        self.assertIn('@if($unassigned->count() > 8)', DASHBOARD)
        self.assertIn('+ {{ $unassigned->count() - 8 }} more stays in the full unassigned list', DASHBOARD)

    def test_eta_watch_shows_alert_count_and_links_to_full_arrival_list(self):
        self.assertIn('{{ $lateArrivals->count() }} alerts', DASHBOARD)
        self.assertIn("$dashGo(['view' => 'arrivals'])", DASHBOARD)
        self.assertIn('@if($lateArrivals->count() > 6)', DASHBOARD)
        self.assertIn('+ {{ $lateArrivals->count() - 6 }} more alerts in the full arrivals list', DASHBOARD)

    def test_arrival_and_departure_previews_disclose_truncated_rows(self):
        self.assertIn('@forelse($arrivalsByEta->take(8) as $s)', DASHBOARD)
        self.assertIn('@if($arrivalsByEta->count() > 8)', DASHBOARD)
        self.assertIn('+ {{ $arrivalsByEta->count() - 8 }} more arrivals in the full arrivals list', DASHBOARD)
        self.assertIn('{{ $departures->count() }} stays', DASHBOARD)
        self.assertIn('@forelse($departures->take(8) as $s)', DASHBOARD)
        self.assertIn('@if($departures->count() > 8)', DASHBOARD)
        self.assertIn('+ {{ $departures->count() - 8 }} more departures in the full list', DASHBOARD)

    def test_group_and_waitlist_previews_disclose_truncated_rows(self):
        self.assertIn('@foreach($groups->take(4) as $g)', DASHBOARD)
        self.assertIn('@if($groups->count() > 4)', DASHBOARD)
        self.assertIn('Group bookings · {{ $groups->count() }} · {{ $date->format(\'d M Y\') }} to {{ $groupWindowEnd->format(\'d M Y\') }}', DASHBOARD)
        self.assertIn('+ {{ $groups->count() - 4 }} more groups in this window', DASHBOARD)
        self.assertIn("$waitingList = $waitlist->where('status', 'waiting')", DASHBOARD)
        self.assertIn('@forelse($waitingList->take(4) as $w)', DASHBOARD)
        self.assertIn('@if($waitingList->count() > 4)', DASHBOARD)
        self.assertIn("$dashGo(['view' => 'waitlist'])", DASHBOARD)
        self.assertIn('+ {{ $waitingList->count() - 4 }} more waiting', DASHBOARD)

    def test_dashboard_search_opens_the_reservation_register(self):
        self.assertIn('class="desk-dashboard-search"', INDEX)
        self.assertIn('method="get" role="search"', INDEX)
        self.assertIn('name="view" value="list"', INDEX)
        self.assertIn('name="date" value="{{ $date->toDateString() }}"', INDEX)
        self.assertIn('id="deskDashboardSearch" type="search" name="q"', INDEX)
        self.assertIn('Guest, confirmation, phone or room', INDEX)
        self.assertIn("can_do('frontdesk.checkin')", INDEX)
        self.assertIn("route('frontdesk.walkin')", INDEX)

    def test_dashboard_tools_are_available_without_cluttering_the_hero(self):
        self.assertIn('<details class="desk-dashboard-tools">', INDEX)
        self.assertIn('<summary><x-icon name="grid" size="sm"/> Desk tools</summary>', INDEX)
        for route in ('reservations.plan', 'reservations.daysummary', 'reservations.export', 'frontdesk.tapechart', 'reservations.amenities'):
            with self.subTest(route=route):
                self.assertIn(f"route('{route}'", INDEX)
        self.assertIn('desk-dashboard-date-tools', INDEX)
        self.assertIn('Update desk date', INDEX)
        self.assertIn('@if($view === \'timeline\')<label class="zoom">', INDEX)
        self.assertIn('@elseif($view !== \'dashboard\' && $view !== \'list\')\n  <nav class="desk-nav', INDEX)

    def test_room_snapshot_is_compact_and_accessible(self):
        for needle in (
            'LIVE ROOM STATUS', 'Room snapshot', 'role="list" aria-label="Active rooms, live room status"',
            'role="listitem"', 'housekeeping {{ ucwords', 'class="room-status-label"',
            'room-strip-legend', 'Out of order / service', '.desk-dashboard .room-strip{display:flex',
        ):
            with self.subTest(needle=needle):
                haystack = INDEX if needle.startswith('.desk-dashboard') else DASHBOARD
                self.assertIn(needle, haystack)

    def test_dashboard_tables_have_captions_and_scoped_column_headers(self):
        self.assertEqual(DASHBOARD.count('<caption class="sr-only">'), 3)
        self.assertEqual(DASHBOARD.count('<th scope="col">'), 12)
        for caption in (
            'Arrivals, departures, expected rooms and pre-tax room revenue from {{ $date->format(\'d M Y\') }} through {{ $weekEnd->format(\'d M Y\') }}',
            'Booking sources, room counts and expected pre-tax revenue from {{ $date->format(\'d M Y\') }} through {{ $forecastEnd->format(\'d M Y\') }}',
            'Room counts and expected pre-tax room revenue by room type from {{ $date->format(\'d M Y\') }} through {{ $forecastEnd->format(\'d M Y\') }}',
        ):
            with self.subTest(caption=caption):
                self.assertIn(caption, DASHBOARD)

    def test_forecast_charts_have_text_alternatives_and_responsive_styles(self):
        for needle in (
            'aria-label="Expected occupancy over 14 days from the selected desk date"',
            'aria-label="Expected room revenue before tax over 14 days from the selected desk date"',
            'aria-label="Daily arrivals and departures from {{ $date->format(\'d M Y\') }} through {{ $weekEnd->format(\'d M Y\') }}"',
            'Trends — {{ $date->format(\'d M Y\') }} to {{ $forecastEnd->format(\'d M Y\') }}',
            'Booking sources — 14-day window',
            'VIP arrivals — {{ $date->format(\'d M Y\') }} to {{ $weekEnd->format(\'d M Y\') }}',
            'Group arrivals — {{ $date->format(\'d M Y\') }} to {{ $weekEnd->format(\'d M Y\') }}',
            'Room types — 14-day window',
            'role="img" aria-label="{{ $p[\'date\']->format(\'d M\') }}: expected occupancy',
            'desk-chart-legend', 'desk-flow-bars',
        ):
            with self.subTest(needle=needle):
                self.assertIn(needle, DASHBOARD if 'aria-label=' in needle else INDEX + DASHBOARD)
        for selector in (
            '#desk[data-view="dashboard"] .desk-dashboard-hero',
            '.desk-summary-grid{grid-template-columns:repeat(3',
            '@media(max-width:760px)', '@media(max-width:420px)',
            '.desk.dark[data-view="dashboard"]', '@media(prefers-reduced-motion:reduce)',
        ):
            with self.subTest(selector=selector):
                self.assertIn(selector, INDEX)


if __name__ == "__main__":
    unittest.main(verbosity=2)
