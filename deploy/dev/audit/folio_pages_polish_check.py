#!/usr/bin/env python3
"""Regression checks for the folio detail transaction table and date clarity."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
VIEW = (ROOT / "resources/views/folios/show.blade.php").read_text()
INDEX_VIEW = (ROOT / "resources/views/folios/index.blade.php").read_text()
TRANSACTIONS_VIEW = (ROOT / "resources/views/folios/transactions.blade.php").read_text()
INVOICES_VIEW = (ROOT / "resources/views/folios/invoices.blade.php").read_text()
INVOICE_VIEW = (ROOT / "resources/views/folios/invoice.blade.php").read_text()
RECEIPT_VIEW = (ROOT / "resources/views/folios/receipt.blade.php").read_text()
PRINT_LAYOUT = (ROOT / "resources/views/layouts/print.blade.php").read_text()
CONTROLLER = (ROOT / "app/Http/Controllers/FolioController.php").read_text()
FOLIO_MODEL = (ROOT / "app/Models/Folio.php").read_text()
GST_SERVICE = (ROOT / "app/Services/GstService.php").read_text()


class FolioShowPolishTest(unittest.TestCase):
    def test_transaction_table_has_accessible_caption_and_column_headers(self):
        table_start = VIEW.index('<table class="table">')
        table_end = VIEW.index('</table>', table_start)
        table = VIEW[table_start:table_end]
        self.assertIn('<caption class="sr-only">Transactions on folio {{ $folio->folio_no }}</caption>', table)
        self.assertEqual(table.count('scope="col"'), 10)
        self.assertIn('<th scope="col" class="num">Amount (excl. tax)</th>', table)
        self.assertIn('<span class="sr-only">Actions</span>', table)

    def test_empty_transaction_row_spans_optional_selection_column(self):
        self.assertIn(
            '@empty <tr><td colspan="{{ $folio->status === \'open\' && can_do(\'folio.split\') ? 10 : 9 }}">',
            VIEW,
        )

    def test_transaction_dates_include_year_and_machine_readable_value(self):
        self.assertIn('<time datetime="{{ $t->business_date->toDateString() }}">{{ $t->business_date->format(\'d M Y\') }}</time>', VIEW)

    def test_folio_rows_distinguish_business_date_from_posting_time_and_name_poster(self):
        self.assertIn('<th scope="col">Business date</th>', VIEW)
        self.assertIn('<th scope="col">Posted by</th>', VIEW)
        self.assertIn('$t->created_at->toIso8601String()', VIEW)
        self.assertIn("$t->created_at->format('d M Y')", VIEW)
        self.assertIn("$t->created_at->format('H:i')", VIEW)
        self.assertIn('$t->poster?->name ?? $t->poster?->username', VIEW)

    def test_closed_timestamp_is_machine_readable_and_independent_of_invoice_number(self):
        self.assertIn('@if($folio->closed_at)<dt>Closed</dt>', VIEW)
        self.assertIn('$folio->closed_at->toIso8601String()', VIEW)
        self.assertIn("$folio->closed_at->format('d M Y H:i')", VIEW)

    def test_stay_and_fixed_charge_dates_include_year(self):
        for fragment in (
            "$folio->stay->arrival_date->format('d M Y')",
            "$folio->stay->departure_date->format('d M Y')",
            "$c->start_date->format('d M Y')",
            "$c->end_date->format('d M Y')",
        ):
            with self.subTest(fragment=fragment):
                self.assertIn(fragment, VIEW)

    def test_transaction_selection_controls_have_accessible_names(self):
        self.assertIn('aria-label="Select all transactions"', VIEW)
        self.assertIn('aria-label="Select {{ $t->description }} transaction dated {{ $t->business_date->format(\'d M Y\') }}"', VIEW)

    def test_close_action_is_offered_only_for_zero_balance_non_inhouse_folios(self):
        self.assertIn("can_do('folio.payment') && !($folio->stay && $folio->stay->status === 'checked_in') && abs((float) $totals['balance']) <= 0.009", VIEW)
        self.assertIn('data-confirm="Close this zero-balance folio?"', VIEW)
        self.assertIn('>Close Folio</button>', VIEW)
        self.assertNotIn('Settle & Close', VIEW)

    def test_summary_labels_explain_net_receipts_and_adjustments(self):
        self.assertIn('label="Discounts and allowances"', VIEW)
        self.assertIn('label="Net payments"', VIEW)
        self.assertIn('sub="Payments received less refunds"', VIEW)

    def test_refund_entry_does_not_inherit_the_due_balance_as_its_amount(self):
        self.assertIn("old('type', 'payment') === 'payment' && $totals['balance'] > 0 ? number_format", VIEW)
        self.assertIn('Refunds increase the folio balance. Enter only the approved refund amount.', VIEW)
        self.assertIn("if (isRefund && paymentAmount) paymentAmount.value = '';", VIEW)

    def test_split_payment_keeps_the_shared_entry_type_enabled(self):
        self.assertIn("input.disabled = (enabled && !input.matches('[data-folio-payment-type]')) || !!input.closest('[data-cheque-fields]')?.hidden", VIEW)
        self.assertIn('data-folio-payment-type', VIEW)

    def test_dynamic_split_payment_fields_have_associated_labels(self):
        for field, label in (
            ('folioPaymentMode', 'Payment method'),
            ('folioPaymentAmount', 'Amount'),
            ('folioPaymentNetwork', 'MoMo network'),
            ('folioPaymentPhone', 'MoMo number'),
            ('folioPaymentReference', 'Reference / transaction ID'),
            ('folioChequeBank', 'Bank name'),
            ('folioChequeNumber', 'Cheque number'),
            ('folioChequeBranch', 'Bank branch'),
        ):
            with self.subTest(field=field):
                self.assertIn(f'<label for="{field}${{i}}">{label}</label>', VIEW)
                self.assertIn(f'id="{field}${{i}}"', VIEW)

    def test_move_and_fixed_charge_controls_have_associated_labels(self):
        self.assertIn('<label for="moveTarget">Move selected lines to', VIEW)
        self.assertIn('<select id="moveTarget" name="target"', VIEW)
        self.assertIn('<label for="fixedChargeDescription">Description', VIEW)
        self.assertIn('<input id="fixedChargeDescription" name="description"', VIEW)

    def test_folio_modal_close_buttons_have_accessible_names(self):
        close_button = 'class="btn btn-ghost btn-icon" data-close aria-label="Close dialog" title="Close dialog"'
        self.assertEqual(VIEW.count(close_button), 10)
        self.assertIn(close_button, (ROOT / "resources/views/folios/_gst-cancel.blade.php").read_text())

    def test_icon_only_transaction_and_fixed_charge_actions_have_names(self):
        self.assertIn('title="Void transaction" aria-label="Void transaction: {{ $t->description }}"', VIEW)
        self.assertIn('title="Stop fixed charge" aria-label="Stop fixed charge: {{ $c->description }}"', VIEW)

    def test_stay_only_modals_are_hidden_when_stay_is_not_actionable(self):
        self.assertIn("@if($folio->stay && $folio->status === 'open' && in_array($folio->stay->status, ['reserved', 'checked_in']))", VIEW)

    def test_refund_submission_requires_explicit_confirmation(self):
        self.assertIn("paymentType?.value === 'refund' && !window.confirm('Record this refund? It will increase the folio balance.')", VIEW)
        self.assertIn('event.preventDefault();', VIEW)

    def test_detail_reuses_loaded_transactions_for_totals(self):
        self.assertIn('public function totals(?Collection $transactions = null): array', FOLIO_MODEL)
        self.assertIn("($transactions ?? $this->transactions()->get())->where('is_void', false)", FOLIO_MODEL)
        self.assertIn("$transactions = $folio->transactions()->with('paymentMode', 'poster', 'company')", CONTROLLER)
        self.assertIn('$totals = $folio->totals($transactions);', CONTROLLER)
        self.assertIn("'transactions' => $transactions,", CONTROLLER)

    def test_sensitive_form_options_are_loaded_only_for_permitted_actions(self):
        for fragment in (
            "'paymentModes' => $canPay ? PaymentMode::where('is_active', true)",
            "'companies' => $canPay && $totals['balance'] > 0 ? Company::where('is_active', true)",
            "'categories' => $canPost ? collect(config('pms.charge_categories'))",
            "'targets' => $canSplit\n                ? Folio::with('guest', 'stay.room')",
        ):
            with self.subTest(fragment=fragment):
                self.assertIn(fragment, CONTROLLER)

    def test_detail_resolves_guest_company_and_reservation_through_linked_stay(self):
        for fragment in (
            "$folio->guest ?? $folio->stay?->guest ?? $linkedReservation?->guest",
            "$folio->company ?? $linkedReservation?->company",
            "@if($linkedReservation)<dt>Reservation</dt>",
            "route('reservations.show', $linkedReservation)",
            ':value="$folioGuest?->email ?? $folioCompany?->email"',
            ':value="$folioGuest?->phone"',
            "'payGuest' => $folioGuest",
            "'value' => $folioCompany?->id",
        ):
            with self.subTest(fragment=fragment):
                self.assertIn(fragment, VIEW)
        self.assertIn("'stay.guest', 'stay.reservation.guest', 'stay.reservation.company', 'reservation.guest', 'reservation.company'", CONTROLLER)

    def test_folio_register_uses_preaggregated_balances_and_marks_credits(self):
        self.assertIn('Folio::withListBalance()', CONTROLLER)
        self.assertIn('$f->listing_balance', INDEX_VIEW)
        self.assertNotIn('$f->balance()', INDEX_VIEW)
        self.assertIn("$balance < 0 ? 'text-green'", INDEX_VIEW)
        self.assertIn('public function scopeWithListBalance(Builder $query)', FOLIO_MODEL)
        self.assertIn("type IN ('discount', 'allowance', 'payment') THEN -amount", FOLIO_MODEL)
        self.assertIn("type = 'refund' THEN amount", FOLIO_MODEL)

    def test_folio_register_has_accessible_table_headers_and_actions(self):
        self.assertIn('<caption class="sr-only">Folio register results</caption>', INDEX_VIEW)
        self.assertEqual(INDEX_VIEW.count('scope="col"'), 9)
        self.assertIn('aria-label="Open folio {{ $f->folio_no }}"', INDEX_VIEW)

    def test_folio_register_filters_have_programmatically_associated_labels(self):
        self.assertIn('<label for="folioSearch">Search</label>', INDEX_VIEW)
        self.assertIn('<input id="folioSearch" name="q"', INDEX_VIEW)
        self.assertIn('<label for="folioStatus">Status</label>', INDEX_VIEW)
        self.assertIn('<select id="folioStatus" name="status"', INDEX_VIEW)

    def test_invoice_register_filters_and_table_have_accessible_labels(self):
        for control in ('invoiceFrom', 'invoiceTo', 'invoiceType', 'invoiceSupply', 'invoiceSearch'):
            with self.subTest(control=control):
                self.assertIn(f'<label for="{control}">', INVOICES_VIEW)
                self.assertIn(f'id="{control}"', INVOICES_VIEW)
        self.assertIn('<caption class="sr-only">Invoices and credit notes from {{ $from }} to {{ $to }}</caption>', INVOICES_VIEW)
        self.assertEqual(INVOICES_VIEW.count('scope="col"'), 9)

    def test_invoice_filters_sanitize_dates_and_bound_search(self):
        for fragment in (
            "$validDate = static fn ($value) => is_string($value)",
            "checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))",
            "if ($from > $to)",
            "in_array($rawType, ['invoice', 'credit_note'], true)",
            "in_array($rawSupply, ['B2B', 'B2C'], true)",
            "mb_substr(trim($rawSearch), 0, 160)",
        ):
            with self.subTest(fragment=fragment):
                self.assertIn(fragment, CONTROLLER)

    def test_invoice_register_icon_actions_are_named_and_new_tabs_are_safe(self):
        self.assertIn('aria-label="Open invoice {{ $f->invoice_no }} for printing"', INVOICES_VIEW)
        self.assertIn('aria-label="Open folio for invoice {{ $f->invoice_no }}"', INVOICES_VIEW)
        self.assertIn('target="_blank" rel="noopener"', INVOICES_VIEW)
        self.assertIn('target="_blank" rel="noopener"', VIEW)
        self.assertIn('aria-label="View GRA E-VAT request JSON for invoice {{ $folio->invoice_no }}"', (ROOT / "resources/views/folios/_gst.blade.php").read_text())

    def test_invoice_document_date_is_machine_readable(self):
        self.assertIn('<time datetime="{{ $documentDate->toDateString() }}">{{ $documentDate->format(\'d M Y\') }}</time>', INVOICES_VIEW)

    def test_printed_invoice_date_is_machine_readable(self):
        self.assertIn('$docDate->toDateString()', INVOICE_VIEW)
        self.assertIn("$docDate->format('d M Y')", INVOICE_VIEW)

    def test_invoice_register_resolves_buyer_context_through_linked_stay(self):
        self.assertIn("'stay.guest', 'stay.reservation.guest', 'stay.reservation.company', 'reservation.guest', 'reservation.company'", CONTROLLER)
        self.assertIn('$f->guest ?? $f->stay?->guest ?? $rowReservation?->guest', INVOICES_VIEW)
        self.assertIn('$f->company ?? $rowReservation?->company', INVOICES_VIEW)
        self.assertIn("$f->buyer_name ?: ($rowCompany?->name ?? $rowGuest?->full_name ?? '—')", INVOICES_VIEW)

    def test_evat_buyer_snapshot_resolves_stay_and_reservation_context(self):
        self.assertIn("$reservation = $f->reservation ?? $f->stay?->reservation;", GST_SERVICE)
        self.assertIn("$c = $f->company ?? $reservation?->company;", GST_SERVICE)
        self.assertIn("$g = $f->guest ?? $f->stay?->guest ?? $reservation?->guest;", GST_SERVICE)
        self.assertIn("'stay.guest', 'stay.reservation.guest', 'stay.reservation.company'", (ROOT / "app/Http/Controllers/GstController.php").read_text())

    def test_receipt_print_design_and_dates_are_semantic(self):
        for fragment in (
            "@section('pageclass', 'folio-payment-receipt')",
            '.print-page.folio-payment-receipt',
            '.folio-payment-receipt .print-head',
            '.receipt-highlight',
            '.folio-payment-receipt .receipt-details dd { min-width:0; overflow-wrap:anywhere;',
            'grid-template-columns:minmax(84px,100px) minmax(0,1fr)',
            'class="small receipt-meta"',
            '$tx->business_date->toDateString()',
            '$tx->created_at->toIso8601String()',
        ):
            with self.subTest(fragment=fragment):
                self.assertIn(fragment, RECEIPT_VIEW)

    def test_invoice_print_design_has_branded_party_cards_and_adaptive_tables(self):
        for fragment in (
            "@section('pageclass', 'folio-invoice')",
            '.folio-invoice .print-head',
            '.folio-invoice .invoice-panel',
            '.print-page.folio-invoice { position:relative;',
            '.folio-invoice .invoice-watermark { position:absolute;',
            '.folio-invoice .invoice-watermark { position:fixed; }',
            '.folio-invoice .invoice-watermark span { display:block; transform:rotate(-32deg);',
            '.invoice-header-balance',
            '.invoice-panel--full',
            '.invoice-table-wrap',
            '#invLines tbody td:nth-child(2), .folio-invoice #gstSummary tbody td:first-child { overflow-wrap:anywhere; }',
            '#invLines thead th',
            '.folio-invoice #gstSummary thead { display: table-header-group; }',
            '@page { size: A4',
            'page-break-inside: avoid',
        ):
            with self.subTest(fragment=fragment):
                self.assertIn(fragment, INVOICE_VIEW)
        self.assertIn("{{ '@'.(float) $t->tax_rate }}%", INVOICE_VIEW)
        self.assertIn('@if($currentHotel->website)<div class="small text-2">Website: {{ $currentHotel->website }}</div>@endif', PRINT_LAYOUT)
        self.assertIn('.print-head>div{min-width:0}.print-head .small{overflow-wrap:anywhere}', PRINT_LAYOUT)

    def test_invoice_carries_reservation_snapshot_daily_totals_and_amount_in_words(self):
        for fragment in (
            "$invoiceHasStayContext = (bool) ($folio->stay || $invoiceReservation);",
            "$invoiceStays = $folio->stay ? collect([$folio->stay]) : ($invoiceReservation?->rooms ?? collect());",
            '->countBy()->map(fn ($count, $label)',
            "$invoiceArrivalDate = $invoiceArrivalAt ?? $firstInvoiceStay?->arrival_date ?? $invoiceReservation?->arrival_date;",
            "'reservation.rooms.roomType', 'reservation.rooms.ratePlan'",
            '<h4 class="invoice-panel-title" id="reservationDetailsTitle">Reservation details</h4>',
            'can_do(\'reservations.view\')',
            'route(\'reservations.show\', $invoiceReservation)',
            'target="_blank" rel="noopener" aria-label="Open reservation',
            '<dt>Plan type</dt>',
            'Day total for {{ $dayTransactions->first()->business_date->format(\'d M Y\') }}',
            'Net movement {{ money($dayNetMovement, false) }}',
            '<th scope="col" class="num">Running balance</th>',
            '$runningFolioBalance = round($runningFolioBalance + $runningDelta, 2);',
            "where('is_void', false)",
            'Invoice amount in words',
            'amount_words(abs((float) $gst[\'value\']))',
            'Original for recipient',
            '@if($issued && ! $openingOnly)<div class="invoice-watermark" aria-hidden="true"><span>ORIGINAL</span></div>@endif',
            '@if($folio->closedBy)',
            'Prepared by <b>{{ $folio->closedBy->name }}</b>',
        ):
            with self.subTest(fragment=fragment):
                self.assertIn(fragment, INVOICE_VIEW if fragment != "'reservation.rooms.roomType', 'reservation.rooms.ratePlan'" else CONTROLLER)
        self.assertIn('public function closedBy()', FOLIO_MODEL)
        self.assertIn("$folio->load('guest', 'company', 'closedBy'", CONTROLLER)

    def test_print_invoice_tables_have_captions_scoped_headers_and_full_dates(self):
        self.assertIn('<caption class="sr-only">Folio ledger for document {{ $folio->invoice_no ?? $folio->folio_no }}</caption>', INVOICE_VIEW)
        self.assertEqual(INVOICE_VIEW.count('scope="col"'), 13)
        self.assertIn('<time datetime="{{ $t->business_date->toDateString() }}">{{ $t->business_date->format(\'d/m/Y\') }}</time>', INVOICE_VIEW)
        self.assertIn('Business date / recorded', INVOICE_VIEW)
        self.assertIn('$t->created_at?->toIso8601String()', INVOICE_VIEW)
        self.assertIn('Tax breakdown for document {{ $folio->invoice_no ?? $folio->folio_no }}', INVOICE_VIEW)

    def test_payment_invoice_reconciles_document_value_payments_refunds_and_balance(self):
        self.assertIn('id="paymentSummary"', INVOICE_VIEW)
        self.assertIn('Payment and balance summary', INVOICE_VIEW)
        self.assertIn('id="settlementStatus"', INVOICE_VIEW)
        self.assertIn('$t->isOpening() && ! $t->is_void', INVOICE_VIEW)
        self.assertIn("$folio->status === 'cancelled' ? 'Balance at cancellation'", INVOICE_VIEW)
        self.assertIn("money($totals['payments'])", INVOICE_VIEW)
        self.assertIn("money($totals['refunds'])", INVOICE_VIEW)
        self.assertIn('$folioBalance', INVOICE_VIEW)
        self.assertIn("$isCn ? 'Credit note value' : 'Invoice value'", INVOICE_VIEW)
        self.assertIn('Payments and refunds shown are those recorded on this folio', INVOICE_VIEW)
        self.assertIn('Reference: {{ $t->reference }}', INVOICE_VIEW)
        self.assertIn("route('folios.receipt', $t)", INVOICE_VIEW)
        self.assertIn('target="_blank" rel="noopener"', INVOICE_VIEW)
        self.assertIn('title="Excluded from invoice totals">VOIDED</span>', INVOICE_VIEW)
        self.assertIn("<tr class=\"{{ $t->is_void ? 'void' : '' }}\">", INVOICE_VIEW)

    def test_invoice_and_receipt_back_actions_return_to_the_same_folio(self):
        self.assertIn("@section('backurl', route('folios.show', $folio))", INVOICE_VIEW)
        self.assertIn("@section('backurl', route('folios.show', $folio))", RECEIPT_VIEW)

    def test_payment_receipt_identifies_invoice_context_time_and_validity(self):
        self.assertIn('Recorded: <time datetime="{{ $tx->created_at->toIso8601String() }}">', RECEIPT_VIEW)
        self.assertIn('Invoice: {{ $folio->invoice_no ?: \'Not yet issued\' }}', RECEIPT_VIEW)
        self.assertIn('$receiptCompany = $folio->company ?? $receiptReservation?->company;', RECEIPT_VIEW)
        self.assertIn('This confirms a refund recorded on the folio.', RECEIPT_VIEW)
        self.assertIn('This confirms a payment recorded on the folio.', RECEIPT_VIEW)
        self.assertIn("$tx->type === 'refund' ? 'Refund amount' : 'Payment amount'", RECEIPT_VIEW)
        self.assertIn('is voided.', RECEIPT_VIEW)
        self.assertIn('not valid proof of payment or refund', RECEIPT_VIEW)

    def test_printed_invoice_and_receipt_resolve_guest_company_and_reservation_via_stay(self):
        for fragment in (
            "$folio->guest ?? $folio->stay?->guest ?? $invoiceReservation?->guest",
            "$folio->company ?? $invoiceReservation?->company",
            "'stay.guest', 'stay.reservation.guest', 'stay.reservation.company'",
        ):
            with self.subTest(fragment=fragment):
                self.assertIn(fragment, INVOICE_VIEW if fragment.startswith('$folio') else CONTROLLER)
        self.assertIn('$invoiceReservation = $folio->reservation ?? $folio->stay?->reservation;', INVOICE_VIEW)
        self.assertIn('$folio->guest ?? $folio->stay?->guest ?? $receiptReservation?->guest', RECEIPT_VIEW)
        self.assertIn('$folio->reservation ?? $folio->stay?->reservation', RECEIPT_VIEW)
        self.assertIn("'folio.stay.reservation.guest', 'folio.stay.reservation.company'", CONTROLLER)

    def test_transaction_summary_uses_four_even_responsive_columns(self):
        self.assertIn('<div class="grid g-4">', TRANSACTIONS_VIEW)
        for label in ('Matching entries', 'Net amount', 'Tax', 'Filtered total'):
            with self.subTest(label=label):
                self.assertIn(f'>{label}', TRANSACTIONS_VIEW)
        self.assertIn('.g-4 { grid-template-columns: repeat(4, minmax(0, 1fr)); }', (ROOT / 'public/css/app.css').read_text())

    def test_transaction_register_resolves_guest_and_company_via_linked_stay(self):
        for fragment in (
            "$entryFolio?->guest ?? $entryFolio?->stay?->guest ?? $entryReservation?->guest",
            "$entryFolio?->company ?? $entryReservation?->company",
            '{{ $entryCompany->name }}',
            "'folio.stay.guest', 'folio.stay.reservation.guest', 'folio.stay.reservation.company'",
        ):
            with self.subTest(fragment=fragment):
                self.assertIn(fragment, TRANSACTIONS_VIEW if fragment.startswith('$entry') or fragment.startswith('{{') else CONTROLLER)


if __name__ == "__main__":
    unittest.main(verbosity=2)
