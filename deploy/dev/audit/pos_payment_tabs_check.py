#!/usr/bin/env python3
"""Static checks for the live POS settlement payment-type tabs."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
VIEW = (ROOT / 'resources/views/pos/terminal.blade.php').read_text()


class PosPaymentTabsTest(unittest.TestCase):
    def test_cash_momo_card_controls_are_accessible_and_visibly_distinct(self):
        self.assertIn('role="group" aria-label="Quick select payment type"', VIEW)
        for category, label in [('cash', 'Cash'), ('momo', 'MoMo'), ('card', 'Card')]:
            self.assertIn(f'data-pos-payment-tab="{category}"', VIEW)
            self.assertIn(f'aria-pressed="false">{label}</button>', VIEW)
            self.assertIn(f'.pos-payment-tab[data-pos-payment-tab="{category}"]', VIEW)
        self.assertIn('.pos-payment-tab[aria-pressed="true"]', VIEW)

    def test_tabs_select_live_payment_modes_without_removing_other_methods(self):
        for marker in (
            'const posPaymentTabs = [...document.querySelectorAll(\'[data-pos-payment-tab]\')]',
            "text.includes('momo') || text.includes('mobile money')",
            "text.includes('card')",
            "text.includes('cash')",
            "posSingleMode.options",
            "posSingleMode.value = mode.value",
            "posSingleMode.dispatchEvent(new Event('change', {bubbles: true}))",
            'or select another configured method below',
            '<x-field name="payment_mode_id" label="Payment mode" type="select" :options="$paymentModes" data-payment-mode/>',
            'function bindPosAllocationPaymentTabs(row)',
            'aria-label="Payment type for allocation ${i}"',
            'bindPosAllocationPaymentTabs(allocationRows.lastElementChild)',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW)

    def test_momo_fields_continue_to_follow_the_selected_payment_mode(self):
        self.assertIn("const momo = category === 'momo';", VIEW)
        self.assertIn("control.disabled = !momo; control.required = momo;", VIEW)
        self.assertIn('posSingleMode?.addEventListener(\'change\', syncPosSingleMomo)', VIEW)

    def test_settlement_modal_can_be_opened_directly_for_a_payment_preview(self):
        self.assertIn("if (posRequestedModal === 'settle') openModal('settleModal');", VIEW)
        self.assertIn("<div class=\"modal{{ request('modal') === 'settle' ? ' show' : '' }}\" id=\"settleModal\">", VIEW)


if __name__ == '__main__':
    unittest.main(verbosity=2)
