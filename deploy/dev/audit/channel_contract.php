<?php

/**
 * Channel / OTA adapter contract harness (v7.42).
 *
 * docs/CHANNEL-ADAPTER.md used to promise a tests/Feature contract test that this repository does not have — there is
 * no tests/ tree and no PHPUnit install. This script is that harness, runnable on the same WASM PHP that serves the
 * app, and it checks every driver the registry knows about, live, against the simulators in deploy/dev/:
 *
 *   TINKER='require "/app/deploy/dev/audit/channel_contract.php";' deploy/dev/bin/php -f deploy/dev/tinker.php
 *   (start the sims first: bash deploy/dev/sims-up.sh)
 *
 * Contract rules enforced (docs/CHANNEL-ADAPTER.md):
 *   1. id()/label() are non-empty and unique per driver.
 *   2. test() on an unconfigured connection returns ok=false with a message and never throws.
 *   3. test() does not mutate channel state.
 *   4. pushAri() acknowledges exactly the chunks the channel accepted — on a mid-stream failure it throws and the
 *      unsent chunks are NOT acked, so the push log keeps them for retry.
 *   5. pullBookings() returns rows ChannelService::canonicalRow() can apply (event, booking_id, guest, rooms, dates).
 *   6. cancelBooking() is idempotent.
 *   7. every registered driver is selectable and valid in the Channel Manager form
 *      (ChannelConnection::providerOptions() — the list the provider validation rule is built from).
 */

use App\Models\ChannelConnection;
use App\Services\Interfaces\ChannelDriver;
use App\Services\Interfaces\ChannelDriverRegistry;
use Illuminate\Support\Facades\Http;

$PASS = 0;
$FAIL = 0;
$SKIP = 0;

/** @param  bool|null  $ok  null = skipped (sandbox not reachable) */
function check(string $name, ?bool $ok, string $detail = ''): void
{
    global $PASS, $FAIL, $SKIP;
    if ($ok === null) {
        $SKIP++;
        echo str_pad('SKIP', 6).$name.($detail !== '' ? ' :: '.$detail : '').PHP_EOL;

        return;
    }
    $ok ? $PASS++ : $FAIL++;
    echo str_pad($ok ? 'PASS' : 'FAIL', 6).$name.($detail !== '' ? ' :: '.mb_substr($detail, 0, 180) : '').PHP_EOL;
}

/** Two ARI chunks in the shape ChannelService::chunks() produces: [typeId => [date => row]]. */
function contractChunks(string $std, string $twn): array
{
    $today = \Illuminate\Support\Carbon::today();
    $row = fn (string $code, int $avail, array $rates, bool $stop = false, int $minStay = 1, bool $cta = false) => [
        'code' => $code, 'available' => $avail, 'stop_sell' => $stop, 'min_stay' => $minStay, 'cta' => $cta,
        'rates' => $rates, 'bar' => (float) (reset($rates) ?: 0),
    ];
    $d0 = $today->toDateString();
    $d1 = $today->copy()->addDay()->toDateString();

    return [
        [1 => [$d0 => $row($std, 7, ['BAR' => 1150.00, 'NRFLEX' => 1090.00]), $d1 => $row($std, 0, ['BAR' => 1250.00], true)]],
        [2 => [$d0 => $row($twn, 4, ['BAR' => 980.50], false, 2, true), $d1 => $row($twn, 4, ['BAR' => 980.50])]],
    ];
}

function simStatus(string $base): ?array
{
    try {
        $r = Http::timeout(5)->get(rtrim($base, '/').'/status');

        return $r->successful() ? (array) $r->json() : null;
    } catch (\Throwable) {
        return null;
    }
}

/**
 * Three of the four simulators drain their reservation queue on pull (real channels deliver a message once), so the
 * harness seeds one known row before every pull to stay re-runnable. channel-sim serves a static fixture instead.
 */
function seedRows(string $id): array
{
    $d = fn (int $n) => \Illuminate\Support\Carbon::today()->addDays($n)->toDateString();
    $guest = ['first_name' => 'Contract', 'last_name' => 'Harness', 'email' => 'contract@example.gh', 'phone' => '+233 24 000 0000'];

    return match ($id) {
        'booking' => ['/__queue', ['reservation_id' => 'BDC-CONTRACT-9001', 'status' => 'new', 'checkin' => $d(4), 'checkout' => $d(6),
            'room_code' => 'STD', 'rate_code' => 'BAR', 'adults' => 2, 'children' => 0, 'total' => 1850.0, 'currency' => 'GHS',
            'guest' => $guest + ['country' => 'Ghana'], 'special_requests' => 'seeded by the adapter contract harness']],
        'expedia' => ['/__bookings', ['itinerary_id' => 'EXP-CONTRACT-9001', 'property_id' => '300452', 'checkin' => $d(4), 'checkout' => $d(6),
            'modified' => false, 'cancel' => false, 'guest' => $guest + ['country' => 'GH'], 'room_id' => '100021', 'rate_plan_id' => '200033',
            'number_of_adults' => 2, 'number_of_children' => 0, 'total' => ['amount' => '1850.00', 'currency' => 'USD'],
            'special_request' => 'seeded by the adapter contract harness']],
        'siteminder' => ['/__bookings', ['ref' => 'SM-CONTRACT-9001', 'modify' => 'New', 'arrival' => $d(4), 'departure' => $d(6),
            'first' => $guest['first_name'], 'last' => $guest['last_name'], 'email' => $guest['email'], 'phone' => $guest['phone'], 'country' => 'GH',
            'room_code' => 'SM-STD', 'rate_code' => 'SM-BAR', 'adults' => 2, 'children' => 0, 'amount' => '1850.00', 'currency' => 'USD',
            'comments' => ['seeded by the adapter contract harness']]],
        default => [null, null],
    };
}

$registry = ChannelDriverRegistry::options();
$selectable = array_keys(ChannelConnection::providerOptions());

/** provider => [sandbox connection attributes, sim base for state checks, fail-hook support] */
$sandbox = [
    'hub' => [['endpoint_url' => 'http://127.0.0.1:8091/ari', 'property_code' => 'NWG01', 'api_key' => 'hubkey'], 'http://127.0.0.1:8091', false, ['STD', 'TWN']],
    'mirror' => [['endpoint_url' => 'http://127.0.0.1:8091/ari', 'property_code' => 'NWG01'], 'http://127.0.0.1:8091', false, ['STD', 'TWN']],
    'booking' => [['endpoint_url' => 'http://127.0.0.1:8093', 'property_code' => '4412087', 'api_key' => 'epstest'], 'http://127.0.0.1:8093', false, ['STD', 'TWN']],
    'expedia' => [['endpoint_url' => 'http://127.0.0.1:8095', 'property_code' => '300452', 'api_key' => 'expkey', 'api_secret' => 'expsecret'], 'http://127.0.0.1:8095', false, ['100021', '100022']],
    'siteminder' => [['endpoint_url' => 'http://127.0.0.1:8096', 'property_code' => 'NWG01', 'api_key' => 'smtoken'], 'http://127.0.0.1:8096', true, ['SM-STD', 'SM-TWN']],
];

echo PHP_EOL.'== registered drivers: '.implode(', ', array_keys($registry)).' =='.PHP_EOL.PHP_EOL;

// 1 + 7 — identity, and reachability from the Channel Manager form
$seenIds = [];
$seenLabels = [];
foreach ($registry as $id => $label) {
    $drv = ChannelDriverRegistry::for(new ChannelConnection(['provider' => $id]));
    check("{$id}: implements ChannelDriver", $drv instanceof ChannelDriver, get_class($drv));
    check("{$id}: id() is stable and unique", $drv->id() === $id && ! in_array($drv->id(), $seenIds, true), $drv->id());
    check("{$id}: label() is non-empty and unique", trim($label) !== '' && ! in_array($label, $seenLabels, true), $label);
    check("{$id}: selectable in the Channel Manager form", in_array($id, $selectable, true),
        'providerOptions() = '.implode(', ', $selectable));
    $seenIds[] = $drv->id();
    $seenLabels[] = $label;

    // 2 — test() must never throw and must report a usable message when the connection is not configured.
    //     The built-in simulator is the documented exception: it has nothing to configure and always passes.
    if ($id !== 'simulator') {
        $empty = new ChannelConnection(['provider' => $id]);
        $threw = null;
        try {
            $res = $drv->test($empty);
        } catch (\Throwable $e) {
            $threw = get_class($e).': '.$e->getMessage();
            $res = null;
        }
        check("{$id}: test() on an unconfigured connection returns ok=false without throwing",
            $threw === null && is_array($res) && ($res['ok'] ?? null) === false && trim((string) ($res['message'] ?? '')) !== '',
            $threw ?? ($res['message'] ?? ''));
    } else {
        $res = $drv->test(new ChannelConnection(['provider' => $id]));
        check('simulator: test() always passes with nothing configured (documented exception)',
            ($res['ok'] ?? false) === true, $res['message'] ?? '');
    }
}

echo PHP_EOL.'== live sandbox runs =='.PHP_EOL.PHP_EOL;

foreach ($sandbox as $id => [$attrs, $base, $canFail, [$std, $twn]]) {
    if (! isset($registry[$id])) {
        check("{$id}: driver registered", false, 'missing from ChannelDriverRegistry');

        continue;
    }
    $drv = ChannelDriverRegistry::for(new ChannelConnection(['provider' => $id]));
    $c = new ChannelConnection($attrs + ['provider' => $id, 'code' => strtoupper(substr($id, 0, 6)), 'name' => 'Contract '.$id]);
    $c->forceFill(['secret' => 'inbound-hmac-secret-not-the-vendor-one']);

    $up = false;
    foreach ([$base.'/status', rtrim($base, '/').'/'] as $probeUrl) {
        try {
            Http::timeout(4)->get($probeUrl);
            $up = true;

            break;
        } catch (\Throwable) {
            // try the next probe path
        }
    }
    if (! $up) {
        check("{$id}: simulator reachable at {$base}", null, 'start it with bash deploy/dev/sims-up.sh');

        continue;
    }

    // 3 — test() must not change channel state
    $before = simStatus($base);
    $res = $drv->test($c);
    $after = simStatus($base);
    check("{$id}: test() reaches the channel", ($res['ok'] ?? false) === true, $res['message'] ?? '');
    if ($before !== null && $after !== null) {
        check("{$id}: test() did not mutate channel state", ($before['ari'] ?? null) === ($after['ari'] ?? null),
            'ari pushes before/after: '.($before['ari'] ?? '?').'/'.($after['ari'] ?? '?'));
    }

    // 4 — pushAri acks exactly what the channel accepted
    $chunks = contractChunks($std, $twn);
    $acked = 0;
    try {
        $out = $drv->pushAri($c, $chunks, 'rates', function () use (&$acked) {
            $acked++;
        });
        check("{$id}: pushAri() delivered every chunk and acked only those",
            $acked === count($chunks) && ($out['requests'] ?? 0) === count($chunks),
            'requests='.($out['requests'] ?? '?').' acked='.$acked.' http='.($out['http'] ?? '?'));
    } catch (\Throwable $e) {
        check("{$id}: pushAri() delivered every chunk and acked only those", false, get_class($e).': '.$e->getMessage());
    }

    // 4b — a mid-stream failure must throw and leave the unsent chunks unacked
    if ($canFail) {
        try {
            Http::timeout(5)->post($base.'/__fail?n=9');
        } catch (\Throwable) {
        }
        $ackedFail = 0;
        $threw = null;
        try {
            $drv->pushAri($c, contractChunks($std, $twn), 'availability', function () use (&$ackedFail) {
                $ackedFail++;
            });
        } catch (\Throwable $e) {
            $threw = $e->getMessage();
        }
        check("{$id}: pushAri() throws mid-stream and acks nothing it did not deliver",
            $threw !== null && $ackedFail === 0, 'acked='.$ackedFail.' '.($threw ?? 'no exception thrown'));
        try {
            Http::timeout(5)->post($base.'/__fail?n=0');
        } catch (\Throwable) {
        }
    }

    [$seedPath, $seedRow] = seedRows($id);
    if ($seedPath !== null) {
        try {
            Http::timeout(5)->post($base.$seedPath, $seedRow);
        } catch (\Throwable) {
            // a simulator without the hook still has its own fixture
        }
    }

    // 5 — pullBookings returns rows the mapping pipeline can apply. Drivers may return either the canonical shape or
    //     the loose one (ref / guest string / room_type / rate); production runs both through canonicalRow() first,
    //     so that is exactly what this check does.
    try {
        $rows = array_map([\App\Services\ChannelService::class, 'canonicalRow'], $drv->pullBookings($c, new DateTimeImmutable('-30 days')));
        $okShape = count($rows) > 0;
        $bad = '';
        foreach ($rows as $r) {
            $ev = $r['event'] ?? null;
            $bid = trim((string) ($r['booking_id'] ?? ''));
            $guest = is_array($r['guest'] ?? null) ? $r['guest'] : [];
            $rooms = is_array($r['rooms'] ?? null) ? $r['rooms'] : [];
            if (! in_array($ev, ['new', 'modify', 'cancel'], true) || $bid === '' || ($guest['first_name'] ?? '') === '' || $rooms === []) {
                $okShape = false;
                $bad = 'shape: '.json_encode(array_map(fn ($x) => is_scalar($x) ? $x : gettype($x), $r));

                break;
            }
            if ($ev !== 'cancel' && (strtotime((string) ($r['arrival'] ?? '')) === false || strtotime((string) ($r['departure'] ?? '')) === false)) {
                $okShape = false;
                $bad = 'unparseable dates on '.$bid;

                break;
            }
        }
        $list = implode(', ', array_map(fn ($r) => ($r['booking_id'] ?? '?').'/'.($r['event'] ?? '?'), $rows));
        check("{$id}: pullBookings() returns rows the mapping pipeline can apply", $okShape, count($rows).' row(s): '.$list.($bad !== '' ? ' BAD '.$bad : ''));
    } catch (\Throwable $e) {
        check("{$id}: pullBookings() returns rows the mapping pipeline can apply", false, get_class($e).': '.$e->getMessage());
    }

    // 6 — cancel is idempotent
    $ref = 'CONTRACT-'.strtoupper($id).'-1';
    try {
        $one = $drv->cancelBooking($c, $ref);
        $two = $drv->cancelBooking($c, $ref);
        check("{$id}: cancelBooking() is idempotent", ($one['ok'] ?? false) === true && ($two['ok'] ?? false) === true,
            ($one['message'] ?? '').' | second: '.($two['message'] ?? ''));
    } catch (\Throwable $e) {
        check("{$id}: cancelBooking() is idempotent", false, get_class($e).': '.$e->getMessage());
    }
}

echo PHP_EOL.$PASS.' passed, '.$FAIL.' failed, '.$SKIP.' skipped'.PHP_EOL;
if ($FAIL > 0) {
    echo 'CONTRACT_FAILED'.PHP_EOL;
} else {
    echo 'CONTRACT_OK'.PHP_EOL;
}
