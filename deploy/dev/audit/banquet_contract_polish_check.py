#!/usr/bin/env python3
"""Static regression checks for the Banquet proposal / printable contract."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
VIEW = (ROOT / "resources/views/banquet/quote.blade.php").read_text()
DOCSTYLE = (ROOT / "resources/views/banquet/_docstyle.blade.php").read_text()
CONTROLLER = (ROOT / "app/Http/Controllers/BanquetController.php").read_text()
ROUTES = (ROOT / "routes/web.php").read_text()
SHOW = (ROOT / "resources/views/banquet/show.blade.php").read_text()


class BanquetContractPolishTest(unittest.TestCase):
    def test_contract_and_proposal_remain_status_sensitive(self):
        for expected in (
            "in_array($e->status, ['definite', 'completed'], true)",
            "$documentTitle = $isContract ? 'Banquet Contract' : 'Event Proposal'",
            "BanquetEvent::STATUSES[$e->status]",
            "This contract confirms the arrangements",
            "Thank you for considering",
            "Venue option:",
            "$e->option_date->format('l d F Y')",
        ):
            with self.subTest(expected=expected):
                self.assertIn(expected, VIEW)

    def test_event_record_action_names_match_proposal_or_contract_status(self):
        for expected in (
            "in_array($e->status, ['definite', 'completed'], true) ? 'Banquet contract' : 'Event proposal'",
            "route('banquet.quote', $e)",
            'target="_blank" rel="noopener" id="quoteBtn"',
            'aria-label="Open {{ strtolower($quoteActionLabel) }} for {{ $e->event_no }} in a new tab"',
            '{{ $quoteActionLabel }}',
        ):
            with self.subTest(expected=expected):
                self.assertIn(expected, SHOW)

    def test_charge_calculations_and_existing_financial_workflow_are_preserved(self):
        for expected in (
            "$f->billablePax()",
            "Food &amp; beverage charges use the higher of the guaranteed count",
            "$f->fnbAmount()",
            "$f->hours()",
            "$f->hall_rate",
            "$i->amount()",
            "$e->net()",
            "$e->tax()",
            "$e->total()",
            "$e->deposit_due > 0",
            "setting('banquet_deposit_pct', '25')",
            "$deposits > 0",
            "setting('banquet_cancel_policy'",
            "$terms",
        ):
            with self.subTest(expected=expected):
                self.assertIn(expected, VIEW)
        self.assertIn("'functions.items', 'functions.hall', 'functions.package', 'functions.barPackage', 'company', 'salesUser'", CONTROLLER)
        self.assertIn("Route::get('/{event}/quote', 'quote')->middleware('perm:banquet.view')->name('quote')", ROUTES)

    def test_contract_document_has_accessible_details_and_safe_empty_state(self):
        for expected in (
            'aria-label="Print or save {{ strtolower($documentTitle) }} as PDF"',
            'aria-label="Client and event details"',
            'quote-field-label">Bill to',
            'Attention: {{ $e->contact_name }}',
            'scope="col"',
            'Billable guests',
            'caption class="sr-only"',
            'quote-table-hint',
            'No function details have been added to this event.',
            'aria-label="Payment and cancellation terms"',
            'aria-label="Contract signatures"',
            'quote-signatures',
            'quote-signing-space',
            'Authorised signature and date',
        ):
            with self.subTest(expected=expected):
                self.assertIn(expected, VIEW)
        self.assertNotIn("{!!", VIEW)

    def test_shared_print_styles_support_screen_print_and_a4(self):
        for expected in (
            '@page{size:A4 portrait;margin:13mm 12mm 14mm}',
            '@media(max-width:700px)',
            '@media print',
            '.document-actions,button,.noprint{display:none!important}',
            '.quote-table thead{display:table-header-group}',
            '.quote-table tr{break-inside:avoid-page',
            '.quote-support-card',
            '.quote-total-row',
            'print-color-adjust:exact',
        ):
            with self.subTest(expected=expected):
                self.assertIn(expected, DOCSTYLE)


if __name__ == "__main__":
    unittest.main(verbosity=2)
