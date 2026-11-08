<?php
/** Run with PHP 8.3+. Isolated checks with service doubles, not a Laravel/DB integration test. */
namespace App\Services {
    class SpaBookingService { public static function slots(): array { return ['09:00']; } }
    class ProcurementService { public static function limits(): array { return [100, 200]; } }
    class RosterService { public static function minRest(): int { return 12; } }
    class LedgerService { public static function lockDate(): ?string { return null; } }
    class RentalService { public static function freeKmPerDay(): int { return 100; } public static function mileagePerKm(): int { return 1; } }
    class MessageService { public static function secret($key): ?string { return null; } }
    class OnlinePaymentService { public static bool $isEnabled = false; public static function provider(): string { return 'demo'; } public static function configured(): bool { return false; } public static function enabled(): bool { return self::$isEnabled; } }
}
namespace {
    $values = [];
    function setting($key, $default = null) { global $values; return $values[$key] ?? $default; }
    function hotel() { return null; }
    function bdate() { return new class { public function toDateString(): string { return '2026-10-26'; } public function format($format): string { return '2026-10'; } }; }
    require __DIR__.'/../../../app/Services/LaundryService.php';
    require __DIR__.'/../../../app/Support/ConfigurationChecks.php';
    use App\Support\ConfigurationChecks;
    $count = 0;
    function assertRule($id, $input, $expected, $module) {
        global $values, $count;
        $values = $input;
        \App\Services\OnlinePaymentService::$isEnabled = (bool) ($input['_payment_enabled'] ?? false);
        $rows = array_values(array_filter(ConfigurationChecks::run(), fn ($row) => $row['id'] === $id));
        ++$count;
        if (count($rows) !== ($expected ? 1 : 0)) throw new \RuntimeException("Unexpected $id result: ".json_encode($input));
        if ($expected && ($rows[0]['module'] !== $module || !$rows[0]['keys'])) throw new \RuntimeException("Wrong $id module/keys");
    }
    $cases = [
        ['cro_follow_up_after_expiry', 'cro', [['cro_follow_up_hours'=>'49', 'cro_expire_days'=>'2'], true], [['cro_follow_up_hours'=>'48', 'cro_expire_days'=>'2'], false], [[], false]],
        ['fleet_speed_marked_parked', 'fleet', [['transport_speed_moving_kph'=>'81', 'transport_speed_limit_kph'=>'80'], true], [['transport_speed_moving_kph'=>'80', 'transport_speed_limit_kph'=>'80'], false], [[], false]],
        ['inbox_chat_under_disabled_inbox', 'inbox', [['inbox_enabled'=>'0'], true], [['inbox_enabled'=>'0', 'inbox_portal_chat'=>'0'], false], [[], false]],
        ['tv_rental_limit_zero', 'iptv', [['tv_max_rentals'=>'0'], true], [['tv_max_rentals'=>'1'], false], [['tv_max_rentals'=>'0', 'tv_vod_enabled'=>'0'], false], [['tv_max_rentals'=>'0', 'tv_enabled'=>'0'], false], [[], false]],
        ['api_http_endpoints_allowed', 'developers', [['api_allow_http'=>'1'], true], [['api_allow_http'=>'0'], false], [[], false]],
        ['api_private_endpoints_allowed', 'developers', [['api_allow_private'=>'1'], true], [['api_allow_private'=>'0'], false], [[], false]],
        ['pbx_tcp_endpoint_missing', 'interfaces', [['pbx_driver'=>'tcp'], true], [['pbx_driver'=>'tcp', 'pbx_tcp_host'=>'pbx', 'pbx_tcp_port'=>'65535'], false], [['pbx_driver'=>'tcp', 'pbx_tcp_host'=>'pbx', 'pbx_tcp_port'=>'65536'], true], [['pbx_enabled'=>'0', 'pbx_driver'=>'tcp'], false], [[], false]],
        ['lock_tcp_endpoint_missing', 'interfaces', [['lock_driver'=>'tcp'], true], [['lock_driver'=>'tcp', 'lock_tcp_host'=>'encoder', 'lock_tcp_port'=>'1'], false], [['lock_driver'=>'tcp', 'lock_tcp_host'=>'encoder', 'lock_tcp_port'=>'0'], true], [['lock_enabled'=>'0', 'lock_driver'=>'tcp'], false], [[], false]],
        ['kiosk_walkin_without_payment', 'kiosks', [[], true], [['_payment_enabled'=>true], false], [['kiosk_enabled'=>'0'], false], [['kiosk_walkin'=>'0'], false]],
        ['organiser_pay_without_gateway', 'organiser', [[], true], [['_payment_enabled'=>true], false], [['organiser_enabled'=>'0'], false], [['organiser_pay'=>'0'], false]],
        ['upsell_email_with_upsell_off', 'upsell', [['upsell_enabled'=>'0'], true], [['upsell_enabled'=>'0', 'upsell_auto_email'=>'0'], false], [[], false]],
        ['bankrec_same_person_finalises', 'bankrec', [[], true], [['bankrec_segregation'=>'1'], false]],
        ['income_audit_four_eyes_off', 'audit', [['ia_four_eyes'=>'0'], true], [['ia_four_eyes'=>'1'], false], [[], false]],
        ['hse_permit_self_issue', 'hse', [['hse_issuer_differs'=>'0'], true], [['hse_issuer_differs'=>'1'], false], [[], false]],
        ['privacy_scheduled_retention_off', 'privacy', [['retention_enabled'=>'0'], true], [['retention_enabled'=>'1'], false], [[], false]],
        ['formc_no_advance_alert', 'fg', [['formc_alert_hours'=>'0'], true], [['formc_alert_hours'=>'1'], false], [['formc_enabled'=>'0', 'formc_alert_hours'=>'0'], false], [[], false]],
        ['robita_no_auto_revoke', 'robita', [['robita_auto_revoke'=>'0'], true], [['robita_auto_revoke'=>'1'], false], [[], false]],
        ['budget_capex_no_prefix', 'budgets', [['budget_capex_prefixes'=>' , ; - '], true], [['budget_capex_prefixes'=>'15'], false], [['budget_capex_control'=>'off', 'budget_capex_prefixes'=>''], false], [['budget_capex_control'=>'invalid', 'budget_capex_prefixes'=>''], true], [[], false]],
        ['voucher_min_above_max', 'vouchers', [['gv_min_value'=>'501', 'gv_max_value'=>'500'], true], [['gv_min_value'=>'500', 'gv_max_value'=>'500'], false], [['gv_enabled'=>'0', 'gv_min_value'=>'501', 'gv_max_value'=>'500'], false], [[], false]],
        ['laundry_express_slower_than_normal', 'laundry', [['laundry_express_hours'=>'25', 'laundry_normal_hours'=>'24'], true], [['laundry_express_hours'=>'24', 'laundry_normal_hours'=>'24'], false], [['laundry_express_hours'=>'0', 'laundry_normal_hours'=>'0'], false], [[], false]],
        ['sales_stage_probabilities_unordered', 'sales', [['sales_prob_qualified'=>'10'], true], [['sales_prob_proposal'=>'20'], true], [['sales_prob_negotiation'=>'49'], true], [[], false]],
    ];
    foreach ($cases as $case) {
        $id = array_shift($case); $module = array_shift($case);
        foreach ($case as [$input, $expected]) assertRule($id, $input, $expected, $module);
    }
    echo "configuration rule runtime checks: $count passed\n";
}
