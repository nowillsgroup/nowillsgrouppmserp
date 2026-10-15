<?php
/**
 * v7.50 — a channel booking in a foreign currency: converted at the forex board, or held for a human.
 *
 * Before this, ChannelService::applyBooking() threw when the currency the OTA sent was not the hotel currency, so a
 * hotel in Accra with a USD rate plan lost the booking into the channel inbox as a hard 422 and somebody re-keyed it by
 * hand at whatever rate they chose. What is proven here:
 *
 *   1. ForexService::boardRate() — the rate used, on which basis, from which board, and when there is none
 *   2. a USD booking through the real receive() → process() → applyBooking() pipeline lands in cedis, with the
 *      original currency, amount, rate and board date kept on the reservation and the conversion in the warnings
 *   3. no rate on the board → held in the inbox as `no_fx_rate` and reprocessable, not rejected
 *   4. conversion switched off → the old rejection, now saying where the switch is
 *   5. a booking in the hotel currency → byte for byte the behaviour it always had
 *   6. the sandbox simulator quotes in the chosen currency and the round trip lands within a pesewa of what we publish
 *
 * Everything runs inside a transaction that is rolled back, so the demo database is untouched.
 *
 * TINKER='require "/app/deploy/dev/audit/channel_fx.php";' deploy/dev/bin/php -f deploy/dev/tinker.php
 */

use App\Models\ChannelConnection;
use App\Models\ChannelMessage;
use App\Models\Currency;
use App\Models\ForexRate;
use App\Models\Reservation;
use App\Services\ChannelService;
use App\Services\ForexService;
use App\Services\RateService;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;

$pass = 0;
$fail = 0;
$ok = function (string $what, $got, $want) use (&$pass, &$fail) {
    $same = is_float($got) && is_float($want) ? abs($got - $want) < 0.005 : $got === $want;
    if ($same) {
        $pass++;
        printf("  ok    %-62s %s%s", $what, is_scalar($got) ? (is_float($got) ? number_format($got, 4) : (string) $got) : json_encode($got), PHP_EOL);
    } else {
        $fail++;
        printf("  FAIL  %-62s got %s, wanted %s%s", $what, is_scalar($got) ? var_export($got, true) : json_encode($got),
            is_scalar($want) ? var_export($want, true) : json_encode($want), PHP_EOL);
    }
};

$c = ChannelConnection::where('provider', 'simulator')->where('is_active', true)->first();
if (! $c) {
    exit("no sandbox simulator connection to push through\n");
}
$map = $c->roomMaps()->where('is_active', true)->with('roomType')->first();
$hotelCcy = strtoupper((string) (Tenant::hotel()->currency ?: 'GHS'));
$today = bdate()->toDateString();
// Every push below books the same room type through the same connection, so they compete for one pool of inventory.
// A date that had four free rooms when this harness was written may not have them after any reseed, and a push that
// finds no inventory comes back 202 "attention" rather than 200 — so ask AvailabilityService, the same question the
// channel writer asks, and use the first window that can hold the whole run.
$pushCount = 4;
$window = null;
foreach ([20, 25, 31, 38, 45, 52, 60, 75, 90] as $d) {
    $arr = bdate()->copy()->addDays($d);
    $dep = bdate()->copy()->addDays($d + 3);
    if (\App\Services\AvailabilityService::typeAvailable((int) $map->roomType->id, $arr, $dep) >= $pushCount) {
        $window = [$arr->toDateString(), $dep->toDateString(), $d];
        break;
    }
}
$window = $window ?: [bdate()->copy()->addDays(20)->toDateString(), bdate()->copy()->addDays(23)->toDateString(), 20];
printf("Sandbox connection #%d %s · room %s · hotel currency %s · business date %s · pushing into %s–%s (+%d days)%s%s",
    $c->id, $c->name, $map->channel_room_code, $hotelCcy, $today, $window[0], $window[1], $window[2], PHP_EOL, PHP_EOL);

$seq = 0;
$ref = fn (string $tag) => 'FX-'.strtoupper($tag).'-'.str_pad((string) (++$seq), 3, '0', STR_PAD_LEFT);
$push = function (string $ccy, float $amount, ?float $total = null, string $tag = 'usd') use ($c, $map, $ref, $window) {
    $r = $ref($tag);
    [$code, $body] = ChannelService::receive($c, [
        'event' => 'new', 'booking_id' => $r,
        'arrival' => $window[0], 'departure' => $window[1],
        'currency' => $ccy,
        'guest' => ['first_name' => 'Fx', 'last_name' => strtoupper($tag), 'email' => null, 'phone' => null, 'country' => 'US'],
        'rooms' => [['room_code' => $map->channel_room_code, 'rate_code' => null, 'adults' => 2, 'children' => 0, 'amount' => $amount]],
        'total' => $total ?? $amount, 'payment' => ['model' => 'hotel_collect'],
        'remarks' => 'FX harness '.$tag, 'created_at' => now()->toIso8601String(),
    ]);

    return [$r, $code, $body, ChannelMessage::where('channel_connection_id', $c->id)->where('channel_ref', $r)->first()];
};

DB::beginTransaction();
try {
    // ── 1. the board rate ──
    printf("%s — ForexService::boardRate()%s", 'The rate a conversion uses', PHP_EOL);
    $usd = Currency::where('code', 'USD')->firstOrFail();
    $row = ForexRate::where('currency_id', $usd->id)->whereDate('date', $today)->firstOrFail();
    $b = ForexService::boardRate('USD');
    $ok('  today’s board, reference basis', $b['rate'], round((float) $row->reference_rate, 4));
    $ok('  …from today, not carried, not stale', [$b['date'], $b['carried'], $b['stale']], [$today, (bool) $row->carried, false]);
    $ok('  …in lower case, as an OTA might send it', ForexService::boardRate('usd')['rate'], $b['rate']);

    ForexService::saveSettings(['channel_basis' => 'buy']);
    $ok('  the buy basis when the setting says so', ForexService::boardRate('USD')['rate'], round((float) $row->buy_rate, 4));
    ForexService::saveSettings(['channel_basis' => 'reference']);

    // no board today: the most recent one inside the window is used and flagged
    $chf = Currency::where('code', 'CHF')->firstOrFail();
    $todayRow = ForexRate::where('currency_id', $chf->id)->whereDate('date', $today)->first();
    $earlier = ForexRate::where('currency_id', $chf->id)->whereDate('date', '<', $today)->orderByDesc('date')->first();
    if ($todayRow && $earlier) {
        $todayRow->delete();
        $s = ForexService::boardRate('CHF');
        $ok('  falls back to the last board inside the window', $s['date'], $earlier->date->toDateString());
        $ok('  …and says it is stale', $s['stale'], true);
        ForexService::saveSettings(['channel_stale_days' => 0]);
        $ok('  a zero-day window refuses an old board', ForexService::boardRate('CHF'), null);
        ForexService::saveSettings(['channel_stale_days' => 7]);
        $back = $todayRow->getAttributes();
        unset($back['id'], $back['created_at'], $back['updated_at']);
        ForexRate::create($back);   // put today's board back exactly as it was
        $ok('  today’s board restored', ForexRate::where('currency_id', $chf->id)->whereDate('date', $today)->count(), 1);
    } else {
        printf("  (no earlier CHF board to fall back to — skipped the staleness checks)%s", PHP_EOL);
    }
    $ok('  a currency the hotel has never heard of', ForexService::boardRate('ZZZ'), null);
    printf("%s", PHP_EOL);

    // ── 2. a USD booking through the real pipeline ──
    printf("%s — a booking the OTA sends in USD%s", 'The pipeline', PHP_EOL);
    $rate = ForexService::boardRate('USD')['rate'];
    $usdTotal = 1180.50;
    [$r1, $code, $body, $msg] = $push('USD', $usdTotal, $usdTotal, 'usd');
    $ok('  accepted', [$code, $body['status']], [200, 'accepted']);
    $res = Reservation::where('channel_connection_id', $c->id)->where('ota_reference', $r1)->firstOrFail();
    $ok('  the reservation is in cedis', (float) $res->channel_amount, round($usdTotal * $rate, 2));
    $ok('  …and keeps what the channel sent', [$res->channel_currency, (float) $res->channel_amount_fx], ['USD', $usdTotal]);
    $ok('  …the rate and the board it came from', [(float) $res->channel_fx_rate, $res->channel_fx_date->toDateString()], [$rate, $today]);
    $ok('  the room rate was converted too', (float) $res->rooms()->first()->rate, round($usdTotal * $rate / 3, 2));
    $ok('  commission is a percentage of the cedi total', (float) $res->channel_commission, round($res->channel_amount * (float) $c->commission_pct / 100, 2));
    $ok('  the message says how it was converted', (bool) preg_match('/channel sent USD 1,180\.50 at the reference rate '.number_format($rate, 4).'/', implode(' ', $msg->warnings ?? [])), true);
    printf("        “%s”%s", implode(' | ', $msg->warnings ?? []), PHP_EOL);
    $ok('  the activity log records the booking', DB::table('activity_logs')->where('description', 'like', '%'.$res->reservation_no.'%')->exists(), true);
    printf("%s", PHP_EOL);

    // ── 3. no rate on the board ──
    printf("%s — a currency with no rate%s", 'Held, not lost', PHP_EOL);
    [$r2, $code, $body, $msg] = $push('ZZZ', 900.00, 900.00, 'zzz');
    $ok('  held in the inbox', [$code, $body['status'], $body['reason']], [202, 'attention', 'no_fx_rate']);
    $ok('  no reservation was created', Reservation::where('channel_connection_id', $c->id)->where('ota_reference', $r2)->exists(), false);
    $ok('  the reason names the currency and the board', (bool) preg_match('/no ZZZ rate for/', $msg->error), true);
    printf("        “%s”%s", $msg->error, PHP_EOL);
    printf("%s", PHP_EOL);

    // ── 4. conversion switched off ──
    printf("%s — conversion off%s", 'The old behaviour, on request', PHP_EOL);
    ForexService::saveSettings(['channel_convert' => false]);
    [$r3, $code, $body] = $push('EUR', 700.00, 700.00, 'eur');
    $ok('  rejected as it was before', [$code, $body['status']], [422, 'rejected']);
    $ok('  …and says where the switch is', (bool) preg_match('/Foreign Guests.*Forex desk/', $body['error']), true);
    printf("        “%s”%s", $body['error'], PHP_EOL);
    $ok('  no reservation was created', Reservation::where('channel_connection_id', $c->id)->where('ota_reference', $r3)->exists(), false);
    ForexService::saveSettings(['channel_convert' => true]);
    printf("%s", PHP_EOL);

    // ── 5. the hotel currency is untouched ──
    printf("%s — a booking in %s%s", 'No conversion', $hotelCcy, PHP_EOL);
    [$r4, $code, $body] = $push($hotelCcy, 4200.00, 4200.00, 'ghs');
    $res4 = Reservation::where('channel_connection_id', $c->id)->where('ota_reference', $r4)->firstOrFail();
    $ok('  accepted at the amount sent', [$code, (float) $res4->channel_amount], [200, 4200.00]);
    $ok('  no conversion recorded', [$res4->channel_currency, $res4->channel_amount_fx, $res4->channel_fx_rate, $res4->channel_fx_date], [null, null, null, null]);
    printf("%s", PHP_EOL);

    // ── 6. the sandbox simulator round trip ──
    printf("%s — the simulator quotes in the currency chosen%s", 'Round trip', PHP_EOL);
    $quote = RateService::quote($map->roomType, bdate()->copy()->addDays(30)->toDateString(), bdate()->copy()->addDays(32)->toDateString());
    $published = round(collect($quote['nights'])->sum(fn ($n) => ChannelService::price($c, $map->roomType, null, (float) $n['rate'])), 2);
    $simRef = 'FX-SIM-'.random_int(100000, 999999);
    [$scode, $sbody] = ChannelService::simulate($c, ['event' => 'new', 'booking_id' => $simRef, 'room_map_id' => $map->id,
        'arrival' => bdate()->copy()->addDays(30)->toDateString(), 'departure' => bdate()->copy()->addDays(32)->toDateString(),
        'rooms' => 1, 'adults' => 2, 'first_name' => 'Fx', 'last_name' => 'Simulator', 'currency' => 'USD']);
    $sim = Reservation::where('channel_connection_id', $c->id)->where('ota_reference', $simRef)->first();
    $ok('  the simulated USD booking landed', [$scode, $sim !== null], [200, true]);
    if ($sim) {
        $sent = (float) $sim->channel_amount_fx;
        printf("        published %s %s → the channel sent USD %s → booked %s at %s%s", $hotelCcy, number_format($published, 2),
            number_format($sent, 2), money($sim->channel_amount), number_format((float) $sim->channel_fx_rate, 4), PHP_EOL);
        // the simulator rounds the quoted figure to a cent of the foreign currency, so that much rounding is expected
        $bound = 0.005 * (float) $sim->channel_fx_rate + 0.01;
        $ok('  …within the rounding a quoted cent carries', abs((float) $sim->channel_amount - $published) <= $bound, true);
        $ok('  …converted from the figure the simulator sent', (float) $sim->channel_amount, round($sent * (float) $sim->channel_fx_rate, 2));
    }
    printf("%s", PHP_EOL);
} finally {
    DB::rollBack();
    printf("(transaction rolled back — the demo database is untouched)%s%s", PHP_EOL, PHP_EOL);
}

$ok('nothing was left behind', Reservation::where('ota_reference', 'like', 'FX-%')->count(), 0);
$ok('the forex settings are back', [ForexService::cfg()['channel_basis'], ForexService::cfg()['channel_stale_days'], ForexService::channelConvert()], ['reference', 7, true]);
$ok('today’s CHF board is intact', ForexRate::where('currency_id', Currency::where('code', 'CHF')->firstOrFail()->id)->whereDate('date', $today)->count(), 1);

printf("%s%d passed, %d failed%s", PHP_EOL, $pass, $fail, PHP_EOL);
exit($fail ? 1 : 0);
