#!/usr/bin/env python3
"""Static regression audit for the full-shell room profile page."""

from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
ROOM = (ROOT / "resources/views/reservations/desk-room.blade.php").read_text()
FRAME = (ROOT / "resources/views/reservations/desk-frame.blade.php").read_text()
CONTROLLER = (ROOT / "app/Http/Controllers/ReservationDeskController.php").read_text()
APP_LAYOUT = (ROOT / "resources/views/layouts/app.blade.php").read_text()
SERVICE = (ROOT / "app/Services/ReservationDeskService.php").read_text()


class RoomPagePolishTest(unittest.TestCase):
    def test_full_shell_room_profile_has_accessible_operational_sections(self):
        self.assertIn("@section('hidePageHead', '1')", FRAME)
        self.assertIn("@unless($__env->hasSection('hidePageHead'))", APP_LAYOUT)
        for marker in (
            '<main class="room-detail-page" id="roomProfile">',
            '<header class="room-profile-hero">',
            'ROOM PROFILE', '<h1>Room {{ $room->room_no }}</h1>',
            'aria-label="Room photos"', 'id="roomHeroPhoto"', 'data-room-photo=',
            'aria-label="Choose a room photo"', 'aria-pressed=',
            'aria-label="Current room and housekeeping status"',
            'aria-label="Room overview"', 'Current occupancy', 'Amenities &amp; notes',
            'Housekeeping activity', 'Maintenance', 'Upcoming stays', 'Previous stays',
            'class="room-upcoming-list"', 'class="room-upcoming-date"',
            'No upcoming reserved stay is currently assigned to this room.',
            '<caption class="sr-only">Latest completed stays in room',
            '<th scope="col">Guest</th>', '<th scope="col">Reservation</th>',
            'href="{{ route(\'reservations.index\', [\'view\' => \'board\']) }}"',
            'room-page-breadcrumb', 'aria-label="Breadcrumb"', 'View room timeline',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, ROOM + FRAME)

    def test_room_controller_preloads_scoped_operational_context(self):
        self.assertIn("$roomRelations = ['roomType.amenityLinks.amenity', 'roomType.images', 'floor', 'images', 'amenityLinks.amenity', 'currentStay.reservation.guest']", CONTROLLER)
        self.assertIn("if (can_do('folio.view')) {\n            $roomRelations[] = 'currentStay.folio';", CONTROLLER)
        self.assertIn("RoomOutage::where('room_id', $room->id)", CONTROLLER)
        self.assertIn("->whereDate('ends_on', '>', $today)", CONTROLLER)
        self.assertIn("HousekeepingTask::with('employee')->where('room_id', $room->id)", CONTROLLER)
        self.assertIn("MaintenanceRequest::where('room_id', $room->id)", CONTROLLER)
        self.assertIn("whereIn('status', ['open', 'in_progress'])->count()", CONTROLLER)
        self.assertIn("ReservationRoom::with('reservation.guest')->where('room_id', $room->id)", CONTROLLER)
        self.assertIn("ReservationRoom::with(['reservation.guest', 'reservation.source'])", CONTROLLER)
        self.assertIn("->where('room_id', $room->id)->where('status', 'reserved')->whereDate('arrival_date', '>=', $today)", CONTROLLER)
        for key in ("'outages'", "'tasks'", "'tickets'", "'openTicketCount'", "'history'", "'upcomingStays'", "'roomImages'"):
            with self.subTest(key=key):
                self.assertIn(key, CONTROLLER)

    def test_folio_balance_and_relation_are_hidden_without_folio_permission(self):
        self.assertIn("if (can_do('folio.view')) {\n            $roomRelations[] = 'currentStay.folio';", CONTROLLER)
        self.assertIn("$canViewFolio = can_do('folio.view');", ROOM)
        self.assertIn("$stayBal = $canViewFolio && $stay?->folio ? $stay->folio->balance() : null;", ROOM)
        self.assertIn("@if($canViewFolio)<div><dt>Folio balance</dt>", ROOM)

    def test_room_actions_preserve_permissions_csrf_and_routes(self):
        for marker in (
            "@if(can_do('reservations.edit') || can_do('housekeeping.manage') || can_do('maintenance.manage'))",
            "@if(can_do('reservations.edit'))", "@if(can_do('housekeeping.manage'))",
            "@elseif(can_do('housekeeping.view'))", "@if(can_do('maintenance.manage'))",
            "route('reservations.outages.store')", "route('reservations.outages.preview')",
            "route('reservations.outages.release', $outage)", "route('reservations.images.store')",
            "route('housekeeping.tasks.store')", "route('housekeeping.status')", "route('maintenance.store')",
            'enctype="multipart/form-data"', 'name="block_room"', 'name="hk_status"',
            'name="task_type"', 'name="room_id"', '@csrf',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, ROOM)
        self.assertIn("$this->card($request, 'reservations.desk-room'", CONTROLLER)

    def test_room_page_dates_and_exclusive_block_end_are_explicit(self):
        for marker in (
            'datetime="{{ $stay->arrival_date->toDateString() }}"',
            'datetime="{{ $stay->departure_date->toDateString() }}"',
            'room returns <time datetime="{{ $outage->ends_on->toDateString() }}">',
            'Room returns on',
            'The return date is the morning the room becomes available again.',
            'datetime="{{ $old->departure_date->toDateString() }}"',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, ROOM)
        self.assertIn("The end date is the morning the room comes back, like a departure.", SERVICE)
        self.assertIn("it returns on {$ends->format('d M Y')}", SERVICE)

    def test_room_photo_and_preview_controls_are_progressive_and_announced(self):
        for marker in (
            'data-print-room', "window.print()",
            "hero.src = button.dataset.roomPhoto;", "hero.alt = button.dataset.roomAlt",
            "item.setAttribute('aria-pressed', selected ? 'true' : 'false')",
            "headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf }",
            "'Checking dates and room availability…'", "'Could not check the block right now.",
            'aria-live="polite" role="status"', 'form.dataset.seen === keyOf(form)',
            'accept="image/*"', 'maxlength="160"',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, ROOM)

    def test_room_profile_has_responsive_dark_print_reduced_motion_and_focus_styles(self):
        for marker in (
            '@media(max-width:1050px)', '@media(max-width:760px)', '@media(max-width:520px)',
            '@media(prefers-reduced-motion:reduce)', '@media print', "@push('head')",
            'body.dark .room-detail-page', 'body.dark .room-profile-panel',
            '.room-detail-page :is(a,button,input,select):focus-visible',
            '.room-history-table', 'overflow-x:auto',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, ROOM)

    def test_operational_status_colors_meet_wcag_aa_contrast(self):
        def luminance(color):
            channels = [int(color[index:index + 2], 16) / 255 for index in (1, 3, 5)]
            linear = [value / 12.92 if value <= .04045 else ((value + .055) / 1.055) ** 2.4 for value in channels]
            return sum(value * weight for value, weight in zip(linear, (.2126, .7152, .0722)))

        pairs = (
            ('.room-state-vacant{border-color:#c7e4d0;background:#edf8f0;color:#205d36}', '#205d36', '#edf8f0'),
            ('.room-state-occupied{border-color:#f0d5d1;background:#fff3f0;color:#943b31}', '#943b31', '#fff3f0'),
            ('.room-state-out-of-order,.room-state-out-of-service,.room-state-inactive{border-color:#e5d7d4;background:#f8f1ef;color:#7d4239}', '#7d4239', '#f8f1ef'),
            ('.hk-state-clean,.hk-state-inspected{border-color:#cfe5d3;background:#f2f8f2;color:#356442}', '#356442', '#f2f8f2'),
            ('.hk-state-dirty{border-color:#f0d8c7;background:#fff6ef;color:#875031}', '#875031', '#fff6ef'),
            ('.hk-state-touch-up{border-color:#eadfb9;background:#fff9e9;color:#785b1e}', '#785b1e', '#fff9e9'),
            ('.room-upcoming-status.status-tentative{border-color:#ecdcae;background:#fff9e9;color:#79601d}', '#79601d', '#fff9e9'),
            ('.room-upcoming-status.status-confirmed{border-color:#cfe5d3;background:#f0f8f1;color:#31633d}', '#31633d', '#f0f8f1'),
            ('body.dark .room-state-occupied{border-color:#68443f;background:#382724;color:#f0c1ba}', '#f0c1ba', '#382724'),
            ('body.dark .hk-state-touch-up{border-color:#65562f;background:#352f20;color:#e7d79f}', '#e7d79f', '#352f20'),
            ('.room-upcoming-date{display:flex;width:52px;min-height:58px;flex:none;flex-direction:column;align-items:center;justify-content:center;border:1px solid #dce9de;border-radius:10px;background:#edf6ee;color:#2c6640}', '#2c6640', '#edf6ee'),
            ('.room-upcoming-copy>a{overflow:hidden;color:#285d39;', '#285d39', '#fbfdfb'),
            ('.room-upcoming-copy span,.room-upcoming-copy small{color:#65756a;', '#65756a', '#fbfdfb'),
            ('.room-action-quote{grid-column:1/-1;margin:0;padding:9px 10px;border:1px solid #e3ebe3;border-radius:8px;background:#f7faf7;color:#647469;', '#647469', '#f7faf7'),
            ('body.dark .room-profile-copy h1,body.dark .room-panel-heading h2,body.dark .room-action-heading h2', '#e4eee5', '#1c2a20'),
        )
        for rule, foreground, background in pairs:
            self.assertIn(rule, ROOM)
            lighter, darker = sorted((luminance(foreground), luminance(background)), reverse=True)
            with self.subTest(rule=rule):
                self.assertGreaterEqual((lighter + .05) / (darker + .05), 4.5)


if __name__ == '__main__':
    unittest.main(verbosity=2)
