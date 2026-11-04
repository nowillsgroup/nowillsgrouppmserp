#!/usr/bin/env python3
"""Static regression checks for the Banquet daily function sheet."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
VIEW = (ROOT / "resources/views/banquet/sheet.blade.php").read_text()
CONTROLLER = (ROOT / "app/Http/Controllers/BanquetController.php").read_text()
ROUTES = (ROOT / "routes/web.php").read_text()


class BanquetFunctionSheetPolishTest(unittest.TestCase):
    def test_date_navigation_print_and_return_to_diary_remain(self):
        for expected in (
            "route('banquet.sheet')",
            "?date={{ $date->copy()->subDay()->toDateString() }}",
            "?date={{ bdate()->toDateString() }}",
            "?date={{ $date->copy()->addDay()->toDateString() }}",
            'type="date" name="date"',
            "window.print()",
            "route('banquet.diary', ['start' => $date->toDateString()])",
        ):
            with self.subTest(expected=expected):
                self.assertIn(expected, VIEW)

    def test_function_sheet_keeps_department_operational_details(self):
        for expected in (
            'aria-label="Daily function summary"',
            '$actualFunctionCount = $fns->filter',
            '$actualCoverCount = $fns->sum',
            'Actual covers',
            'Recorded for {{ $actualFunctionCount }} of {{ $functionCount }} functions',
            'aria-label="Functions by status"',
            'aria-label="Functions for {{ $date->format(\'l d F Y\') }}"',
            'Venue &amp; set-up',
            'Client contact',
            'Menu / package',
            '$f->package?->itemList()',
            'class="package-item-list"',
            'Services &amp; equipment',
            'Operational notes',
            "route('banquet.show', $f->event)",
            'target="_blank" rel="noopener"',
            'aria-label="Open banquet record {{ $f->event->event_no }} in a new tab"',
            "Generated {{ now()->format('d M Y, H:i') }}",
            'guaranteed_pax ?? $f->expected_pax',
            '$f->actual_pax',
            '$capacityCheckPax = $f->billablePax()',
            'Guest count including actuals',
            "'Untitled event'",
            "'Function'",
            'BanquetEvent::TYPES[$f->event->event_type]',
            'class="function-type"',
            '$f->items->groupBy',
            '$item->service?->department?->name',
            '$departmentCounts = []',
            '! empty($departmentCounts)',
            '$departmentCounts[$department][$functionStatus]',
            'array_sum($linesByStatus)',
            'aria-label="Service and equipment line totals by department and status"',
            'Service &amp; equipment lines by booking status',
            'includes tentative holds',
            'Guaranteed or expected@if(($statusCounts->get(\'tentative\') ?? 0) > 0)',
            'class="sheet-department-chip"',
            '$f->event->optionExpired()',
            '$f->event->option_date?->format',
            '$f->hall->capacityFor($f->setup_style)',
            'Guest count including actuals',
            'No functions scheduled',
        ):
            with self.subTest(expected=expected):
                self.assertIn(expected, VIEW)

    def test_print_and_mobile_layout_are_explicit(self):
        for expected in (
            '@media(max-width:640px)',
            '@media print',
            '@page{size:A4 portrait;margin:15mm 10mm 12mm}',
            '.sheet-print-running{display:flex;position:fixed',
            '{{ $hotel->name }} · Banquet operations',
            '.noprint,.sheet-toolbar{display:none!important}',
            '.sheet-function{',
            'break-inside:avoid-page',
        ):
            with self.subTest(expected=expected):
                self.assertIn(expected, VIEW)

    def test_controller_scope_eager_loads_and_includes_the_same_sheet_statuses(self):
        self.assertIn("$requestedDate = $request->query('date')", CONTROLLER)
        self.assertIn("is_string($requestedDate) && trim($requestedDate) !== ''", CONTROLLER)
        self.assertIn("Carbon::parse(trim($requestedDate))", CONTROLLER)
        self.assertIn("catch (\\Throwable)", CONTROLLER)
        self.assertIn("BanquetFunction::with('event.company', 'hall', 'package', 'items.service.department')", CONTROLLER)
        self.assertIn("whereIn('status', ['tentative', 'definite', 'completed'])", CONTROLLER)
        self.assertIn("->orderBy('start_time')->get()", CONTROLLER)
        self.assertIn("Route::get('/function-sheet', 'sheet')->middleware('perm:banquet.view')->name('sheet')", ROUTES)


if __name__ == "__main__":
    unittest.main(verbosity=2)
