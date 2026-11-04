#!/usr/bin/env python3
"""Static regression checks for the Banquet function diary workspace."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
CONTROLLER = (ROOT / "app/Http/Controllers/BanquetController.php").read_text()
DIARY = (ROOT / "resources/views/banquet/diary.blade.php").read_text()
CSS = (ROOT / "public/css/app.css").read_text()


class BanquetDiaryPolishTest(unittest.TestCase):
    def test_diary_filters_keep_the_selected_date_range_and_enquiry_mode(self):
        self.assertIn("$baseDiaryQuery = ['start' => $from->toDateString(), 'days' => $days, 'enquiries' => $withEnquiries ? 1 : 0]", DIARY)
        self.assertIn("$q = fn ($over) => '?'.http_build_query(array_merge($baseDiaryQuery, $over))", DIARY)
        self.assertIn("Clear the venue filter and show all venues", DIARY)
        self.assertIn("Previous {{ $days }} days", DIARY)
        self.assertIn("Next {{ $days }} days", DIARY)
        self.assertIn("'start' => $from->copy()->subDays($days)->toDateString()", DIARY)
        self.assertIn("'start' => $from->copy()->addDays($days)->toDateString()", DIARY)

    def test_diary_has_accessible_summary_and_dual_desktop_mobile_views(self):
        for expected in (
            'aria-live="polite"',
            'aria-label="Function diary by venue and day"',
            'aria-label="Function diary agenda"',
            'aria-label="Diary status legend"',
            'class="diary-summary diary-summary-panel"',
            'id="diaryEmptyDaysToggle"',
            'aria-controls="diaryAgendaDays"',
            'data-empty-days="{{ $emptyAgendaDays }}"',
            'data-total-days="{{ $days }}"',
            "agenda.classList.toggle('is-compact', hide)",
            'All ${totalDays} days are shown.',
            'aria-label="Quick venue filters"',
            'aria-label="Filter by venue"',
            'diary-venue-card-count',
            'class="diary-agenda-day {{ $dayFunctions->isEmpty() ? \'is-empty\' : \'has-functions\' }}"',
            'class="diary-agenda-chip"',
        ):
            with self.subTest(expected=expected):
                self.assertIn(expected, DIARY)

    def test_quick_venue_snapshot_uses_filtered_schedule_counts_and_preserves_context(self):
        self.assertIn("$emptyAgendaDays = max(0, $days - $dayCounts->count())", DIARY)
        self.assertIn("$venueFunctionCounts = $agendaFunctions->countBy('banquet_hall_id')", DIARY)
        self.assertIn("$venueDayCounts = $agendaFunctions->groupBy('banquet_hall_id')", DIARY)
        self.assertIn("$q(['hall' => $venue->id])", DIARY)
        self.assertIn("$q(['hall' => ''])", DIARY)
        self.assertIn("! $selectedHall && $allHalls->count() > 1", DIARY)

    def test_existing_sheet_and_permission_gated_create_actions_remain(self):
        self.assertIn("route('banquet.sheet')", DIARY)
        self.assertIn("Today's function sheet", DIARY)
        self.assertIn("@if(can_do('banquet.manage'))<a class=\"btn btn-primary\" href=\"{{ route('banquet.create'", DIARY)
        self.assertIn("can_do('banquet.manage') && $d->gte(bdate())", DIARY)
        self.assertIn("route('banquet.create', ['hall' => $h->id, 'date' => $d->toDateString()])", DIARY)

    def test_diary_query_inputs_are_validated_and_page_window_is_bounded(self):
        self.assertIn("in_array($daysValue, [7, 14, 31], true) ? $daysValue : 14", CONTROLLER)
        self.assertIn("is_scalar($requestedStart)", CONTROLLER)
        self.assertIn("checkdate(", CONTROLLER)
        self.assertIn("is_scalar($requestedHall)", CONTROLLER)
        self.assertIn("FILTER_VALIDATE_INT", CONTROLLER)

    def test_diary_visuals_cover_mobile_agenda_and_desktop_calendar(self):
        for expected in (
            '.diary-summary-panel',
            '.diary-venue-snapshot',
            '.diary-mobile-agenda',
            '.diary-agenda.is-compact .diary-agenda-day.is-empty',
            '.diary-venue-cards',
            '.diary-controls-top { background: linear-gradient',
            '.diary-agenda-definite',
            '.diary-agenda-tentative',
            '.diary-agenda-chip',
            '.diary-agenda-day.is-empty',
            '@media (max-width: 760px)',
            '@media (max-width: 480px)',
        ):
            with self.subTest(expected=expected):
                self.assertIn(expected, CSS)


if __name__ == "__main__":
    unittest.main(verbosity=2)
