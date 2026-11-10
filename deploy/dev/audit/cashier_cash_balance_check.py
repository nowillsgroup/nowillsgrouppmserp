#!/usr/bin/env python3
"""Static checks for cashier drawer balance sources, live shift display and close snapshots."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
CONTROLLER = (ROOT / 'app/Http/Controllers/ShiftController.php').read_text()
SERVICE = (ROOT / 'app/Services/ShiftService.php').read_text()
VIEW = (ROOT / 'resources/views/shifts/index.blade.php').read_text()
SHIFT_REPORT_VIEW = (ROOT / 'resources/views/shifts/show.blade.php').read_text()
# v10.x the report library was split out of ReportController into per-module trait files under app/Http/Controllers/Reports/; the cash-flow labels live in BillingReports
REPORT_CONTROLLER = (ROOT / 'app/Http/Controllers/Reports/BillingReports.php').read_text()


class CashierCashBalanceTest(unittest.TestCase):
    def test_expected_drawer_cash_uses_only_cash_net_plus_opening_float(self):
        self.assertIn("$cashIn = collect($rows)->where('is_cash', true)->sum('in');", SERVICE)
        self.assertIn("$cashOut = collect($rows)->where('is_cash', true)->sum('out');", SERVICE)
        self.assertIn("'expected_cash' => round((float) $shift->opening_float + $cashIn - $cashOut, 2)", SERVICE)
        self.assertIn('<b>Expected cash in drawer</b>', VIEW)
        self.assertIn("money($summary['expected_cash'])", VIEW)

    def test_cash_movements_and_payment_sources_are_included_without_double_counting(self):
        for marker in (
            "->where('is_void', false)",
            "->whereIn('type', ['payment', 'refund'])",
            "->where('category', '!=', 'city_ledger')",
            "PosPayment::where('received_by', $shift->user_id)",
            "$ledgerOrderIds = $posPayments->pluck('pos_order_id')->unique()",
            "->when($ledgerOrderIds->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $ledgerOrderIds))",
            "CashierMovement::where('cashier_shift_id', $shift->id)",
            "ForexExchange::withoutGlobalScopes()",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, SERVICE)

    def test_shift_report_lists_pos_payment_ledger_lines_and_avoids_legacy_duplicates(self):
        self.assertIn("PosPayment::with('order', 'paymentMode')->where('received_by', $shift->user_id)", CONTROLLER)
        self.assertIn("->whereBetween('received_at', [$from, $to])->whereIn('kind', ['payment', 'refund'])", CONTROLLER)
        self.assertIn("$ledgerOrderIds = $posPayments->pluck('pos_order_id')->unique()", CONTROLLER)
        self.assertIn("->whereNotIn('id', $ledgerOrderIds)", CONTROLLER)
        self.assertIn('@foreach($posPayments as $payment)', SHIFT_REPORT_VIEW)
        self.assertIn("$payment->kind === 'refund'", SHIFT_REPORT_VIEW)
        self.assertIn("$payment->reference ?: '—'", SHIFT_REPORT_VIEW)
        self.assertIn('$posPayments->isEmpty()', SHIFT_REPORT_VIEW)

    def test_manager_history_shows_live_expected_cash_for_open_shifts(self):
        self.assertIn("$summary = $current ? ShiftService::summary($current) : null;", CONTROLLER)
        self.assertIn("$shift->cash_balance_expected = $shift->status === 'open'", CONTROLLER)
        self.assertIn("ShiftService::summary($shift)['expected_cash']", CONTROLLER)
        self.assertIn("$shift->expected_cash ?? ShiftService::summary($shift)['expected_cash']", CONTROLLER)
        self.assertIn('<th class="num">Expected cash</th>', VIEW)
        self.assertIn("$s->cash_balance_expected !== null ? money($s->cash_balance_expected, false) : '—'", VIEW)
        self.assertIn("'expected_cash' => round((float) $shift->opening_float + $cashIn - $cashOut, 2)", SERVICE)

    def test_cash_flow_labels_do_not_misstate_drawer_movements_as_refunds(self):
        for view in (VIEW, SHIFT_REPORT_VIEW):
            self.assertIn('>In</th>', view)
            self.assertIn('>Out</th>', view)
            self.assertIn('Net movement', view)
            self.assertNotIn('Cash refunded', view)
        self.assertIn('<span>+ Cash in</span>', VIEW)
        self.assertIn('<span>− Cash out</span>', VIEW)
        self.assertIn('opening float + cash in − cash out', VIEW)
        self.assertIn("'cash_received' => 'Cash in'", REPORT_CONTROLLER)
        self.assertIn("'cash_out' => 'Cash out'", REPORT_CONTROLLER)
        self.assertIn("'total_collections' => 'Total inflows'", REPORT_CONTROLLER)
        self.assertIn("'refunds_and_out' => 'Total outflows'", REPORT_CONTROLLER)

    def test_closed_shift_balance_remains_a_snapshot(self):
        self.assertIn("if ($shift->status === 'closed' && $shift->summary)", SERVICE)
        self.assertIn("'status' => 'closed', 'summary' => $sum, 'expected_cash' => $sum['expected_cash']", SERVICE)


if __name__ == '__main__':
    unittest.main(verbosity=2)
