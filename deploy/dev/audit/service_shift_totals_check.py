#!/usr/bin/env python3
"""The live service-shift sheet shows payments by method, a total, and prints."""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
SERVICE = (ROOT / "app/Services/DepartmentShiftService.php").read_text()
CONTROLLER = (ROOT / "app/Http/Controllers/DepartmentShiftController.php").read_text()
ROUTES = (ROOT / "routes/web.php").read_text()
SHOW = (ROOT / "resources/views/department-shifts/show.blade.php").read_text()
INDEX = (ROOT / "resources/views/department-shifts/index.blade.php").read_text()
PRINT = (ROOT / "resources/views/department-shifts/print.blade.php").read_text()


class ServiceShiftTotalsTest(unittest.TestCase):
    def test_summary_breaks_collections_down_by_payment_method(self):
        for marker in (
            "use App\\Models\\PaymentMode;",
            "use App\\Models\\PosPayment;",
            "protected static function paymentsByMode(DepartmentShiftSession $session): array",
            "$modes = PaymentMode::withoutGlobalScopes()->where('hotel_id', $session->hotel_id)->get()->keyBy('id');",
            "$payments = PosPayment::whereIn('pos_order_id', $session->posOrders()->select('id'))",
            "->get(['pos_order_id', 'payment_mode_id', 'kind', 'amount']);",
            "$p->kind === 'payment' ? (float) $p->amount : 0,",
            "$p->kind === 'refund' ? (float) $p->amount : 0));",
            # Orders settled before the payment ledger existed still appear once.
            "PosOrder::where('department_shift_session_id', $session->id)->where('status', 'settled')->where('settlement', 'payment')",
            "->when($ledgerOrderIds->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $ledgerOrderIds))",
            "'payments' => $rows,",
            "'payments_in' => round(collect($rows)->sum('collected'), 2),",
            "'payments_out' => round(collect($rows)->sum('refunded'), 2),",
            "'payments_total' => round(collect($rows)->sum('net'), 2),",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, SERVICE)

        # The live figures and the frozen snapshot must agree on shape.
        self.assertIn("], self::paymentsByMode($session));", SERVICE)
        self.assertIn("return array_key_exists('payments', $snapshot) ? $snapshot : array_merge($snapshot, self::paymentsByMode($session));", SERVICE)

        # A closed-out shift cannot be re-priced by this read: no writes, no recalculation of stored totals.
        self.assertNotIn("$session->update(", SERVICE)
        self.assertNotIn("->save()", SERVICE.split("public static function summary")[1].split("public static function workspace")[0])

    def test_the_live_totals_card_lists_methods_and_a_total(self):
        for marker in (
            "$payments = $summary['payments'] ?? [];",
            "<b class=\"small\">Payments by method</b>",
            'id="serviceShiftPayments"',
            "<thead><tr><th>Payment method</th><th class=\"num\">Collected</th><th class=\"num\">Refunds</th><th class=\"num\">Net</th></tr></thead>",
            "<tfoot><tr><td><b>Total, all methods</b></td>",
            "{{ money($row['collected']) }}",
            "{{ money($row['net']) }}",
            'id="serviceShiftPaymentNote"',
            "Total collected on this",
            "Payment reconciliation against the drawer stays on the cashier shift.",
            "No payment has been collected on this shift yet.",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, SHOW)
        self.assertIn("route('department-shifts.print', $session)", SHOW)
        # The metrics that were already there stay.
        for kept in ("Orders handled", "Covers", "Settled POS sales", "Room-posted orders", "Voided orders"):
            with self.subTest(kept=kept):
                self.assertIn(kept, SHOW)

    def test_the_totals_sheet_prints(self):
        self.assertTrue(PRINT.startswith("@extends('layouts.print')"))
        for marker in (
            "@section('doctitle', 'Service shift totals')",
            "@section('backurl', route('department-shifts.show', $session))",
            'id="serviceShiftTotalsPrint"',
            'id="serviceShiftPaymentsPrint"',
            "<tfoot><tr>",
            "<td><b>Total, all methods</b></td>",
            'id="serviceShiftPaymentTotal"',
            "Total collected on this shift, all payment methods:",
            'id="serviceShiftOrdersPrint"',
            "Provisional live sheet.",
            "Saved close-out.",
            "Handed over by",
            "Received by (supervisor)",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, PRINT)
        self.assertNotIn("<form", PRINT, "the printable sheet must not post anything")
        self.assertNotRegex(PRINT, r"<script\b", "the sheet is plain HTML behind the print layout's own button")

    def test_the_print_route_is_permission_gated_like_the_report(self):
        start = ROUTES.index("Route::prefix('service-shifts')")
        body = ROUTES[start:ROUTES.index("Route::prefix('shifts')", start)]
        self.assertIn("->middleware('perm:pos.order,pos.shift_view_all')", body, 'the service-shift route group stays permission gated')
        self.assertIn("Route::get('/{session}/print', 'print')->whereNumber('session')->name('print');", body)
        self.assertIn("Route::get('/{session}', 'show')->whereNumber('session')->name('show');", body)

        self.assertIn("public function print(Request $request, DepartmentShiftSession $session)", CONTROLLER)
        gate = CONTROLLER.split("public function print(Request $request, DepartmentShiftSession $session)")[1].split("public function")[0]
        self.assertIn("$session->department === DepartmentShiftService::FOOD_BEVERAGE, 404", gate)
        self.assertIn("$session->user_id === (int) $request->user()->id || $request->user()->hasPermission('pos.shift_view_all'), 403", gate)
        self.assertIn("return view('department-shifts.print', ['session' => $session, 'summary' => DepartmentShiftService::summary($session)]);", gate)

        self.assertIn("route('department-shifts.print', $session)", INDEX)

    def test_the_shift_workspace_shows_collected_money_and_the_total(self):
        # The waiter lands here, so the open shift shows what each method collected, one total and the print command.
        for marker in (
            "$livePayments = $liveSummary['payments'] ?? [];",
            "{{ $row['name'] }} {{ money($row['net']) }}",
            "<b>Total, all methods: {{ money($liveSummary['payments_total'] ?? 0) }}</b>",
            "posted to rooms",
            "Print totals sheet",
            "No payment has been collected on this shift yet",
            # The day report adds a collected column and a total row across the shift buckets.
            '<th class="num">Collected by method</th>',
            "<tfoot><tr><td><b>All shifts</b></td>",
            "{{ money($serviceDaySummary->sum('payments_total')) }}",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, INDEX)
        self.assertIn("'payments_total' => round($sessions->sum(fn ($s) => (float) ($s->report_summary['payments_total'] ?? 0)), 2),", SERVICE)

    def test_the_whole_day_prints_as_one_sheet(self):
        day = (ROOT / "resources/views/department-shifts/day-print.blade.php").read_text()
        self.assertTrue(day.startswith("@extends('layouts.print')"))
        for marker in (
            "@section('doctitle', 'Service shift day report')",
            'id="serviceShiftDayShiftsPrint"',
            'id="serviceShiftDaySessionsPrint"',
            "Collected by method",
            "<td><b>All shifts</b></td>",
            "<td colspan=\"5\"><b>All sessions</b></td>",
            'id="serviceShiftDayCollected"',
            "Total collected on this date, all shifts and all payment methods:",
            "Prepared by",
            "Checked by (manager)",
            "Payment reconciliation against the drawer stays on the cashier shift.",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, day)
        self.assertNotIn("<form", day)
        self.assertNotRegex(day, r"<script\b")

        self.assertIn("Route::get('/day/print', 'printDay')->name('day-print');", ROUTES)
        self.assertIn("public function printDay(Request $request)", CONTROLLER)
        self.assertIn("return view('department-shifts.day-print', DepartmentShiftService::workspace($request->user(), $data['date'] ?? null));", CONTROLLER)
        self.assertIn("route('department-shifts.day-print', ['date' => $serviceShiftDate])", INDEX)

    def test_the_ledger_check_that_runs_on_a_live_database_is_read_only(self):
        runtime = (ROOT / "deploy/dev/audit/service_shift_totals_ledger_check.php").read_text()
        for marker in (
            "DepartmentShiftService::summary($session)",
            "$ledger = $orderIds->isEmpty() ? collect() : PosPayment::whereIn('pos_order_id', $orderIds)",
            "The total for all methods equals the sum of the method lines",
            "Room posts stay out of the collected total",
            "The saved close-out keeps the payment breakdown for reprints",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, runtime)
        for forbidden in ("::create(", "->save(", "->update(", "->delete(", "DB::transaction", "->forceFill("):
            with self.subTest(forbidden=forbidden):
                self.assertNotIn(forbidden, runtime, "the ledger check must not write to a live database")

    def test_preview_shows_the_card_and_the_printed_sheet(self):
        preview = (ROOT / "deploy/dev/previews/service-shift-totals.html").read_text()
        for marker in (
            'UI-only preview', 'Live shift totals',
            'Payments by method', 'Total, all methods',
            'Service shift totals', 'Payments by method',
            'Total collected on this shift, all payment methods:',
            'Handed over by', 'Received by (supervisor)',
            'Provisional live sheet.',
            'Where the waiter lands',
            'Total, all methods: GH₵ 2,914.50',
            '<b>All shifts</b>',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, preview)
        self.assertNotIn('<form method="post"', preview)
        self.assertIn('/__preview/service-shift-totals', (ROOT / "deploy/dev/previews/index.html").read_text())
        server = (ROOT / "deploy/dev/management-dashboard-preview-server.mjs").read_text()
        self.assertIn("'/service-shift-totals'", server)
        # A second instance can land straight on this screen; the default root stays the fleet dashboard.
        self.assertIn("const LANDING_SLUG = /^[a-z0-9-]+$/.test(process.env.PREVIEW_LANDING ?? '') ? process.env.PREVIEW_LANDING : 'fleet-overview';", server)
        self.assertIn("file = resolve(ROOT, `deploy/dev/previews/${LANDING_SLUG}.html`);", server)


if __name__ == "__main__":
    unittest.main(verbosity=2)
