#!/usr/bin/env python3
"""Static checks for the cashier POS unsettled-invoice worklist."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
CONTROLLER = (ROOT / 'app/Http/Controllers/PosController.php').read_text()
ROUTES = (ROOT / 'routes/web.php').read_text()
VIEW = (ROOT / 'resources/views/pos/unsettled-invoices.blade.php').read_text()
NAV = (ROOT / 'resources/views/pos/_workspace-nav.blade.php').read_text()
CHEQUE_FIELDS = (ROOT / 'resources/views/components/cheque-fields.blade.php').read_text()
MOMO_FIELDS = (ROOT / 'resources/views/components/payment-momo-fields.blade.php').read_text()


def method_body(name: str, next_method: str) -> str:
    start = CONTROLLER.index(f'public function {name}(')
    end = CONTROLLER.index(f'public function {next_method}(', start)
    return CONTROLLER[start:end]


class PosUnsettledInvoicesTest(unittest.TestCase):
    def test_cashier_worklist_is_settlement_permission_gated_and_in_pos_nav(self):
        self.assertIn("Route::get('/unsettled-invoices', 'unsettledInvoices')->middleware('perm:pos.settle')->name('unsettled')", ROUTES)
        self.assertIn("route('pos.unsettled', [], false)", NAV)
        self.assertIn("request()->routeIs('pos.unsettled')", NAV)
        self.assertIn("'show' => can_do('pos.settle')", NAV)

    def test_worklist_covers_all_open_and_billed_business_dates_and_exposes_waiter_balance(self):
        body = method_body('unsettledInvoices', 'orders')
        self.assertIn("whereIn('status', ['open', 'billed'])", body)
        self.assertEqual(body.count("orWhere('customer_name', 'not like', 'DEMO PREVIEW ·%')"), 2)
        self.assertNotIn('whereDate(', body)
        self.assertIn("where('steward', $filters['waiter'])", body)
        self.assertIn("orWhere('steward', 'like', $like)", body)
        self.assertIn("'payments as paid_amount'", body)
        self.assertIn("'payments as refunded_amount'", body)
        self.assertIn("'items as pending_items_count'", body)
        self.assertIn("'items as accepted_items_count'", body)
        self.assertIn('as waiter_count', body)
        self.assertIn('$waiterSummary = (clone $query)', body)
        self.assertIn("groupBy('pos_orders.steward')", body)
        self.assertIn("$filters['waiter'] === '__unassigned'", body)
        self.assertIn('Outstanding by waiter', VIEW)
        self.assertIn('value="__unassigned"', VIEW)
        self.assertIn('pos.unsettled.auto-refresh', VIEW)
        self.assertIn("document.visibilityState !== 'visible'", VIEW)
        self.assertIn('Every 60 seconds when idle', VIEW)
        self.assertIn('paymentOpen', VIEW)
        self.assertIn('if (filterDirty || editing || paymentOpen', VIEW)
        self.assertIn('oldest(\'created_at\')', body)

    def test_cashier_can_generate_bill_and_record_payment_from_list_and_return(self):
        self.assertIn('data-unsettled-open-payment', VIEW)
        self.assertIn("$canGenerateBill = $o->status === 'open' && $o->accepted_items_count > 0 && $o->pending_items_count === 0", VIEW)
        self.assertIn("route('pos.bill', $o, false)", VIEW)
        bill_form = VIEW.split('class="pos-unsettled-bill-form"', 1)[1].split('</form>', 1)[0]
        for marker in ('name="return_to" value="unsettled"', 'name="return_outlet"', 'name="return_waiter"', 'name="return_q"', 'name="return_stage"'):
            self.assertIn(marker, bill_form)
        self.assertIn('aria-label="Generate bill for {{ $o->order_no }}"', VIEW)
        self.assertIn('<x-icon name="receipt" size="sm"/> Bill</button>', VIEW)
        self.assertIn('.pos-unsettled-actions{display:flex;flex-wrap:nowrap;', VIEW)
        self.assertIn("'return_to' => 'nullable|in:unsettled'", method_body('bill', 'settle'))
        bill_method = method_body('bill', 'settle')
        self.assertIn("route('pos.unsettled', $returnFilters, false)", bill_method)
        self.assertIn("'stage' => ($data['return_stage'] ?? null) === 'open' ? 'billed'", bill_method)
        self.assertIn('id="unsettledSettleModal"', VIEW)
        self.assertIn('Take payment', VIEW)
        self.assertIn('data-currency="{{ hotel()?->currency_symbol ?? \'GH₵\' }}"', VIEW)
        self.assertIn('mode?.dispatchEvent(new Event(\'change\', {bubbles:true}))', VIEW)
        for category in ('cash', 'momo', 'card'):
            self.assertIn(f'.pos-payment-tab[data-mode-tab="{category}"]', VIEW)
        self.assertIn('data-unsettled-settle-form', VIEW)
        self.assertIn("route('pos.settle', $o, false)", VIEW)
        self.assertIn('name="return_to" value="unsettled"', VIEW)
        self.assertIn('name="return_order"', VIEW)
        self.assertIn("'return_order' => 'nullable|integer|owned:pos_orders'", CONTROLLER)
        self.assertIn('@if($reopenSettleFor) open @endif', VIEW)
        self.assertIn('name="payment_mode_id" required', VIEW)
        self.assertIn('name="amount" type="number"', VIEW)
        self.assertIn('Settle &amp; Print', VIEW)
        self.assertIn("'return_to' => 'nullable|in:unsettled'", CONTROLLER)
        self.assertIn("route('pos.unsettled', $returnFilters, false)", CONTROLLER)
        self.assertIn("$order->settled_by = auth()->id()", CONTROLLER)

    def test_unsafe_or_incomplete_bills_are_not_quick_settled(self):
        self.assertIn('$o->pending_items_count === 0', VIEW)
        self.assertIn('$o->accepted_items_count > 0', VIEW)
        self.assertIn('Accept or remove pending items before settling.', VIEW)
        self.assertIn('Room posting, split tenders and Complimentary / NC remain available from order details.', VIEW)
        self.assertIn('$this->assertNoPending($order);', CONTROLLER)

    def test_payment_metadata_and_preview_safe_relative_actions_are_available(self):
        payment_fields = VIEW + MOMO_FIELDS + CHEQUE_FIELDS
        for name in ('mobile_network', 'mobile_phone', 'cheque_bank_name', 'cheque_number', 'cheque_bank_branch', 'card_authorisation', 'reference', 'voucher_code', 'buyer_tin', 'buyer_name'):
            self.assertIn(name, payment_fields)
        for route in ("route('pos.terminal', $o, false)", "route('pos.unsettled', [], false)"):
            self.assertIn(route, VIEW)
        self.assertIn("route('pos.print', $order, false)", CONTROLLER)
        self.assertIn('data-mode-tab="cash"', VIEW)
        self.assertIn('data-mode-tab="momo"', VIEW)
        self.assertIn('data-mode-tab="card"', VIEW)


if __name__ == '__main__':
    unittest.main(verbosity=2)
