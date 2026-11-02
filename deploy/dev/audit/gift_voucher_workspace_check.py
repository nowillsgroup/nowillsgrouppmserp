#!/usr/bin/env python3
"""Regression checks for the real Gift Vouchers workspace and bearer-code handling."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
INDEX = (ROOT / "resources/views/vouchers/index.blade.php").read_text()
DETAIL = (ROOT / "resources/views/vouchers/show.blade.php").read_text()
PRINT = (ROOT / "resources/views/vouchers/print.blade.php").read_text()
TYPE_FORM = (ROOT / "resources/views/vouchers/_type_form.blade.php").read_text()
CONTROLLER = (ROOT / "app/Http/Controllers/GiftVoucherController.php").read_text()
SERVICE = (ROOT / "app/Services/GiftVoucherService.php").read_text()


class GiftVoucherWorkspaceTest(unittest.TestCase):
    def test_bearer_code_and_print_card_require_sales_or_management_access(self):
        self.assertIn("$canRevealCode = can_do('vouchers.sell') || can_do('vouchers.manage')", DETAIL)
        self.assertIn("$canRevealCode ? $v->code : $v->maskedCode()", DETAIL)
        self.assertIn("@if($canRevealCode)<a class=\"btn\"", DETAIL)
        self.assertIn("@if($canRevealCode)<a class=\"small\"", DETAIL)
        self.assertIn("abort_unless(can_do('vouchers.sell') || can_do('vouchers.manage'), 403)", CONTROLLER)

    def test_public_bearer_page_is_not_cached_indexed_or_sent_as_a_referrer(self):
        for header in ("Referrer-Policy', 'no-referrer'", "X-Robots-Tag', 'noindex, nofollow'", "Cache-Control', 'no-store, private'"):
            with self.subTest(header=header):
                self.assertIn(header, CONTROLLER[CONTROLLER.index('public function publicPage'):])

    def test_printed_amount_and_state_match_the_current_voucher(self):
        self.assertIn("{{ money($v->balance) }}", PRINT)
        self.assertIn("$v->status === 'active' && $v->isExpired() ? 'Expired'", PRINT)
        self.assertIn("@unless($v->usable())", PRINT)
        self.assertIn("NOT VALID FOR REDEMPTION", PRINT)

    def test_amount_input_tracks_fixed_and_open_amount_types(self):
        self.assertIn('data-face="{{ $t->face_value ?? \'\' }}"', INDEX)
        self.assertIn('data-min="{{ max((float) $cfg[\'min\'], (float) $t->min_value) }}"', INDEX)
        self.assertIn("const fixed = Number(option?.dataset.face || 0) > 0", INDEX)
        self.assertIn("amountField.hidden = fixed", INDEX)
        self.assertIn("amount.max = Number(option?.dataset.max", INDEX)

    def test_filtering_tabs_and_empty_state_are_accessible(self):
        self.assertIn('aria-label="Gift voucher workspace"', INDEX)
        self.assertIn('aria-current="page"', INDEX)
        self.assertIn('for="gvQuery"', INDEX)
        self.assertIn('for="gvStatus"', INDEX)
        self.assertIn('id="clearVoucherFilters"', INDEX)
        self.assertIn('No vouchers match these filters.', INDEX)

    def test_liability_report_link_matches_both_route_permissions(self):
        self.assertIn("can_do('vouchers.view') && (can_do('reports.view') || can_do('folio.shift_all'))", INDEX)

    def test_promotion_and_voucher_action_modals_reopen_after_validation(self):
        self.assertIn("old('_voucher_form') === 'promo'", INDEX)
        self.assertIn('id="promo_voucher_type"', INDEX)
        self.assertIn('id="promo_amount"', INDEX)
        self.assertIn("old('_voucher_action') === 'email'", DETAIL)
        self.assertIn("old('_voucher_action') === 'reload'", DETAIL)
        self.assertIn("$actionForm === 'redeem'", DETAIL)
        self.assertIn("$actionForm === 'cancel' ? old('reason') : ''", DETAIL)
        self.assertIn("$actionForm === 'suspend' ? old('reason') : ''", DETAIL)
        self.assertIn('$reloadRoom > 0.004', DETAIL)
        self.assertIn('data-max=', INDEX)

    def test_custom_expiry_inputs_match_the_five_year_service_limit(self):
        self.assertIn("addYears(5)->toDateString()", INDEX)
        self.assertIn('custom dates are limited to 5 years ahead', INDEX)

    def test_reminder_copy_matches_night_audit_and_scheduled_job_behavior(self):
        self.assertIn('Expiry is processed during night audit.', INDEX)
        self.assertIn('Reminder checks are scheduled daily at 06:30 (pms:vouchers)', INDEX)
        self.assertIn('delivery requires a holder e-mail and configured mail service', INDEX)
        self.assertIn("if($cfg['reminder_days'])", INDEX)
        self.assertIn('attempts reminders when a holder e-mail is on file', INDEX)
        self.assertIn('expiry reminders are disabled in Settings', INDEX)

    def test_type_validation_restores_only_the_form_that_was_submitted(self):
        self.assertIn("old('_voucher_type_form', '') === $formKey", TYPE_FORM)
        self.assertIn('name="_voucher_type_form"', TYPE_FORM)
        self.assertIn("$restoreForm ? old('name', $t?->name)", TYPE_FORM)
        self.assertIn("$restoreForm && $errors->has('name')", TYPE_FORM)

    def test_reload_rechecks_status_and_balance_limit_under_the_voucher_lock(self):
        reload_service = SERVICE[SERVICE.index('public static function reload'):SERVICE.index('// ───────────────────────── redemption')]
        locked = reload_service.index("GiftVoucher::with('type')->lockForUpdate()")
        status_check = reload_service.index("if (! in_array($v->status, ['active', 'redeemed'], true) || $v->isExpired())", locked)
        limit_check = reload_service.index('if ($v->balance + $amount > $max + 0.004)', locked)
        update = reload_service.index("$v->update(['balance' => round($v->balance + $amount, 2)", locked)
        self.assertLess(locked, status_check)
        self.assertLess(status_check, limit_check)
        self.assertLess(limit_check, update)

    def test_requested_voucher_emails_report_missing_addresses_or_delivery_failures(self):
        self.assertIn('no e-mail address was provided, so no e-mail was sent', CONTROLLER)
        self.assertIn('its e-mail could not be sent', CONTROLLER)
        self.assertIn('recipient(s) had no e-mail address', CONTROLLER)
        self.assertIn('e-mail(s) could not be sent', CONTROLLER)

    def test_sale_and_tax_copy_is_neutral_and_uses_mobile_money_language(self):
        self.assertIn('mobile money', SERVICE.lower())
        self.assertIn('Tax treatment depends on local rules and voucher terms', INDEX)
        self.assertIn('confirm with Finance', INDEX)
        self.assertNotIn('UPI', SERVICE)
        self.assertNotIn('Diwali', SERVICE + INDEX)

    def test_reload_expiry_message_matches_later_of_current_and_new_expiry(self):
        self.assertIn("->max($v->expires_on)->format('d M Y')", DETAIL)
        self.assertIn('the current expiry will not be shortened', DETAIL)


if __name__ == "__main__":
    unittest.main(verbosity=2)
