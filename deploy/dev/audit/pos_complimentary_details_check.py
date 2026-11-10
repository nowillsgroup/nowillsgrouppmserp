#!/usr/bin/env python3
"""Checks whole-bill Complimentary / NC details and zero-value posting destinations."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
VIEW = (ROOT / 'resources/views/pos/terminal.blade.php').read_text()
CONTROLLER = (ROOT / 'app/Http/Controllers/PosController.php').read_text()
MIGRATION = (ROOT / 'database/migrations/2026_11_02_000200_add_complimentary_approval_details_to_pos_orders.php').read_text()
POST_MIGRATION = (ROOT / 'database/migrations/2026_11_02_000300_add_complimentary_posting_targets_to_pos_orders.php').read_text()
BILL = (ROOT / 'resources/views/pos/bill.blade.php').read_text()
COMPANY_LEDGER = (ROOT / 'app/Http/Controllers/AccountsController.php').read_text()
FOLIO_SERVICE = (ROOT / 'app/Services/FolioService.php').read_text()
FOLIO_CONTROLLER = (ROOT / 'app/Http/Controllers/FolioController.php').read_text()
FOLIO_VIEW = (ROOT / 'resources/views/folios/show.blade.php').read_text()


class PosComplimentaryDetailsTest(unittest.TestCase):
    def test_nc_form_collects_required_department_manager_and_receiver(self):
        expected = (
            ('Department', 'comp_department', 'stlCompDepartment'),
            ('Name of Manager', 'comp_manager_name', 'stlCompManager'),
            ('Name of Receiver', 'comp_receiver_name', 'stlCompReceiver'),
        )
        for label, name, element_id in expected:
            with self.subTest(label=label):
                self.assertIn(f'<label for="{element_id}">{label}</label>', VIEW)
                self.assertIn(f'name="{name}"', VIEW)
                self.assertIn("required @disabled(old('settlement', request('settlement')) !== 'complimentary') data-comp-input", VIEW)
        self.assertIn('comp.querySelectorAll(\'[data-comp-input]\')', VIEW)
        self.assertIn('input.required = isComplimentary', VIEW)
        self.assertIn("old('settlement', request('settlement')) === 'complimentary'", VIEW)
        for name in ('comp_reason', 'comp_department', 'comp_manager_name', 'comp_receiver_name'):
            self.assertIn(f"old('{name}')", VIEW)

    def test_controller_validates_and_persists_or_clears_approval_details(self):
        for name in ('comp_department', 'comp_manager_name', 'comp_receiver_name'):
            with self.subTest(name=name):
                self.assertIn(f"'{name}' => 'nullable|required_if:settlement,complimentary|string|min:2|max:", CONTROLLER)
                self.assertIn(f"$order->{name} = $isComplimentary ? trim($data['{name}']) : null;", CONTROLLER)

    def test_database_migration_adds_nullable_approval_fields(self):
        for name, length in (('comp_department', 100), ('comp_manager_name', 120), ('comp_receiver_name', 120)):
            with self.subTest(name=name):
                self.assertIn(f"string('{name}', {length})->nullable()", MIGRATION)
                self.assertIn(name, MIGRATION[MIGRATION.index('public function down'):])

    def test_nc_post_to_selects_company_city_ledger_or_guest_folio(self):
        for text in ('Post to', 'City Ledger / Company', 'Room (guest Folio)', 'Select company', 'Select in-house room'):
            with self.subTest(text=text):
                self.assertIn(text, VIEW)
        for name in ('comp_post_to', 'comp_post_company_id', 'comp_post_reservation_room_id'):
            self.assertIn(f'name="{name}"', VIEW)
            self.assertIn(name, POST_MIGRATION)
        self.assertIn('zero-value record only', VIEW)
        self.assertIn('comp_post_to', POST_MIGRATION[POST_MIGRATION.index('public function down'):])

    def test_selected_subledger_receives_zero_value_record_only(self):
        self.assertIn("'comp_post_to' => 'nullable|required_if:settlement,complimentary|in:city_ledger,guest_folio'", CONTROLLER)
        self.assertIn("'comp_post_company_id' => 'nullable|required_if:comp_post_to,city_ledger|integer|owned:companies'", CONTROLLER)
        self.assertIn("'comp_post_reservation_room_id' => 'nullable|required_if:comp_post_to,guest_folio|integer|owned:reservation_rooms'", CONTROLLER)
        self.assertIn("'amount' => 0, 'tax_amount' => 0, 'tax_rate' => 0", CONTROLLER)
        self.assertIn("FolioService::charge($folio, 'fnb', $memo, 0, false", CONTROLLER)
        self.assertIn("$order->folio_id = $folio->id", CONTROLLER)
        self.assertIn("$t->source_type === 'pos_complimentary' ? $t->description", COMPANY_LEDGER)

    def test_zero_value_record_is_read_only_in_the_guest_ledger(self):
        self.assertIn("$current->source_type === 'pos_complimentary'", FOLIO_SERVICE)
        self.assertIn("$line->source_type === 'pos_complimentary'", FOLIO_CONTROLLER)
        self.assertIn("$t->source_type !== 'pos_complimentary'", FOLIO_VIEW)
        self.assertIn("'online_payment', 'online_refund', 'pos_complimentary'", FOLIO_VIEW)

    def test_printed_nc_bill_shows_the_recorded_approval_details(self):
        for label, field in (
            ('Department:', 'comp_department'),
            ('Name of Manager:', 'comp_manager_name'),
            ('Name of Receiver:', 'comp_receiver_name'),
        ):
            with self.subTest(label=label):
                self.assertIn(f'<b>{label}</b> {{{{ $order->{field} }}}}', BILL)
        self.assertIn('class="pos-bill-comp-details"', BILL)


if __name__ == '__main__':
    unittest.main(verbosity=2)
