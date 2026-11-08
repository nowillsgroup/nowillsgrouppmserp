<?php
/** Run with PHP 8.3+. Isolated checks with service doubles, not a Laravel/DB integration test. */
namespace App\Services {
    class SpaBookingService { public static function slots(): array { return ['09:00']; } }
    class ProcurementService { public static function limits(): array { return [100, 200]; } }
    class RosterService { public static function minRest(): int { return 12; } }
    class LedgerService { public static function lockDate(): ?string { return null; } }
    class RentalService { public static function freeKmPerDay(): int { return 100; } public static function mileagePerKm(): int { return 1; } }
    class MessageService { public static function secret($key): ?string { return null; } }
    class OnlinePaymentService { public static function provider(): string { return 'demo'; } public static function configured(): bool { return false; } }
}
namespace {
    $values = [];
    function setting($key, $default = null) { global $values; return $values[$key] ?? $default; }
    function hotel() { return null; }
    function bdate() { return new class { public function toDateString(): string { return '2026-10-26'; } public function format($format): string { return '2026-10'; } }; }
    require __DIR__.'/../../../app/Support/ConfigurationChecks.php';
    use App\Support\ConfigurationChecks;
    $count = 0;
    function assertRule($id, $input, $expected, $module) {
        global $values, $count;
        $values = $input;
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
    ];
    foreach ($cases as $case) {
        $id = array_shift($case); $module = array_shift($case);
        foreach ($case as [$input, $expected]) assertRule($id, $input, $expected, $module);
    }
    echo "configuration rule runtime checks: $count passed\n";
}
