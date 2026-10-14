<?php

namespace App\Services;

use App\Models\AccountHead;
use App\Models\Currency;
use App\Models\ForexExchange;
use App\Models\ForexRate;
use App\Models\ForexSurrender;
use App\Models\ForexSurrenderLine;
use App\Models\Guest;
use App\Models\GuestDocument;
use App\Models\PaymentMode;
use App\Models\ReservationRoom;
use App\Models\Setting;
use App\Support\Numbering;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * v3.4 forex desk – purchase of foreign currency from in-house foreign guests (v4.3: Ghana).
 *
 * Bank of Ghana (Foreign Exchange Act 2006, Act 723 and the BoG rules for forex bureaux): buying foreign currency
 * notes is a licensed activity – the hotel buys as a licensed bureau or as agent of a bank / bureau (licence and
 * principal printed on the certificate). The desk only BUYS; a buy rate is set every business day close to the
 * Bank of Ghana reference rate; cash paid out in cedis is capped per guest by a house limit counted in US dollars
 * (purchases within N days count as one transaction – anti-structuring, Anti-Money Laundering Act 2020, Act 1044);
 * a passport copy is kept from a configurable cedi value; an encashment certificate is issued; the currency is
 * surrendered to the principal / bank within N working days.
 *
 * Tax: money changing is an exempt financial service under the VAT Act 2025 (Act 1151) – no VAT / levies are
 * deducted (the old India GST fields stay on historic records only). An optional service fee is income.
 * Credit to the room bill converts at the day's rate with no charge.
 *
 * Ledger: cash encashment Dr 1060 Foreign Currency on Hand (gross) / Cr Cash (net) / Cr 4085 fee; bill credit
 * is a folio payment in the forex payment mode (Dr 1060 / Cr Guest Ledger); surrender Dr Bank (realised) /
 * Cr 1060 (average cost) / ±4986 Exchange Gain / Loss.
 * (Column / key names ending in _inr hold the amount in the property's base currency – GH₵.)
 */
class ForexService
{
    public const DEFAULTS = [
        'forex_enabled' => '1',
        'forex_cash_cap_usd' => '3000',       // house limit: cash to foreign visitors per guest within the window
        'forex_resident_cap_usd' => '1000',   // house limit: cash to residents
        'forex_allow_residents' => '0',       // hotels normally encash for foreign guests only
        'forex_aggregate_days' => '30',       // purchases within N days = one transaction
        'forex_id_copy_inr' => '5000',        // passport copy on file from this cedi value
        'forex_max_margin' => '5',            // buy rate at most N % below the reference rate
        'forex_fee_pct' => '0',               // optional service fee on cash encashment (VAT-exempt financial service)
        'forex_surrender_days' => '7',
        'forex_carry_rates' => '1',           // night audit copies rates to the new day (flagged "carried")
        'forex_channel_convert' => '1',       // v7.50 an OTA booking in a foreign currency is converted, not rejected
        'forex_channel_basis' => 'reference', // v7.50 …at the reference rate, or at the buy rate the desk would give
        'forex_channel_stale_days' => '7',    // v7.50 …using a board no older than this many days
        'forex_ap_convert' => '1',            // v7.53 a supplier invoice in a foreign currency is converted, not refused
        'forex_ap_basis' => 'reference',      // v7.53 …at the reference rate, or at the buy rate the desk would give
        'forex_ap_stale_days' => '7',         // v7.53 …using a board no older than this many days
        'forex_ap_rate_date' => 'document',   // v7.53 …read at the invoice date, which is what accounting wants, or at today's board
        'forex_licence' => '',                // BoG bureau licence / agency no. printed on the certificate
        'forex_franchiser' => '',             // bank / licensed bureau the hotel buys for
    ];

    public const CURRENCIES = [
        // code, name, symbol, unit, reference, buy
        // GH₵ per unit – indicative Bank of Ghana interbank levels, late Sep 2026 (USD ≈ 11.62)
        ['USD', 'US Dollar', '$', 1, 11.62, 11.45], ['EUR', 'Euro', '€', 1, 13.55, 13.33], ['GBP', 'Pound Sterling', '£', 1, 15.65, 15.40],
        ['XOF', 'West African CFA Franc', 'CFA', 1000, 20.66, 20.20], ['NGN', 'Nigerian Naira', '₦', 1000, 7.60, 7.30], ['AED', 'UAE Dirham', 'AED', 1, 3.16, 3.10],
        ['CAD', 'Canadian Dollar', 'C$', 1, 8.40, 8.25], ['CHF', 'Swiss Franc', 'CHF', 1, 14.50, 14.25], ['CNY', 'Chinese Yuan', '¥', 1, 1.63, 1.59],
        ['ZAR', 'South African Rand', 'R', 1, 0.66, 0.64], ['JPY', 'Japanese Yen', '¥', 100, 7.80, 7.62], ['INR', 'Indian Rupee', '₹', 100, 13.20, 12.80],
    ];

    public static function ensureDefaults(): void
    {
        foreach (self::DEFAULTS as $k => $v) {
            if ($k === 'forex_enabled') {
                $v = '1';
            }
            Setting::firstOrCreate(['key' => $k], ['value' => $v]);
        }
        Setting::$version++;
        if (! Currency::exists()) {
            foreach (self::CURRENCIES as $i => [$code, $name, $sym, $unit]) {
                Currency::create(['code' => $code, 'name' => $name, 'symbol' => $sym, 'unit' => $unit, 'sort_order' => $i + 1]);
            }
        }
        ChartOfAccounts::ensureLight();
        self::modeId();
    }

    /** The folio payment mode used for bill credits – inactive so it never shows in normal payment lists. */
    public static function modeId(): int
    {
        $m = PaymentMode::where('type', 'forex')->first();
        if (! $m) {
            ChartOfAccounts::ensureLight();
            $m = PaymentMode::create(['name' => 'Foreign Currency (forex desk)', 'type' => 'forex', 'is_active' => false, 'account_head_id' => AccountHead::sys('forex_on_hand')]);
        }

        return $m->id;
    }

    public static function cfg(): array
    {
        $s = [];
        foreach (self::DEFAULTS as $k => $v) {
            $s[substr($k, 6)] = (string) setting($k, $v);
        }
        foreach (['cash_cap_usd', 'resident_cap_usd', 'aggregate_days', 'id_copy_inr', 'surrender_days'] as $k) {
            $s[$k] = max(0, (int) $s[$k]);
        }
        // v7.50 the channel-conversion policy: only two bases exist, and the staleness window is a small number of days
        $s['channel_basis'] = in_array($s['channel_basis'] ?? '', ['reference', 'buy'], true) ? $s['channel_basis'] : 'reference';
        $s['channel_stale_days'] = max(0, min(90, (int) ($s['channel_stale_days'] ?? self::BOARD_STALE_DAYS)));
        // v7.53 the payables policy: the same two bases, the same kind of window, and a choice of which date to read
        $s['ap_basis'] = in_array($s['ap_basis'] ?? '', ['reference', 'buy'], true) ? $s['ap_basis'] : 'reference';
        $s['ap_stale_days'] = max(0, min(90, (int) ($s['ap_stale_days'] ?? self::BOARD_STALE_DAYS)));
        $s['ap_rate_date'] = in_array($s['ap_rate_date'] ?? '', ['document', 'business'], true) ? $s['ap_rate_date'] : 'document';
        foreach (['max_margin', 'fee_pct'] as $k) {
            $s[$k] = max(0, (float) $s[$k]);
        }

        return $s;
    }

    public static function enabled(): bool
    {
        return setting('forex_enabled', '1') === '1';
    }

    public static function saveSettings(array $d): void
    {
        foreach (self::DEFAULTS as $k => $v) {
            $short = substr($k, 6);
            if (array_key_exists($short, $d)) {
                Setting::updateOrCreate(['key' => $k], ['value' => is_bool($d[$short]) ? ($d[$short] ? '1' : '0') : trim((string) $d[$short])]);
            }
        }
        Setting::$version++;
        ActivityLogger::log('forex', 'settings', 'Forex desk settings updated');
    }

    // ───────────────────────── rates ─────────────────────────

    public static function rate(int $currencyId, ?string $date = null): ?ForexRate
    {
        // whereDate, never where('date', …): the column is a DATE but the cast value
        // is written as "Y-m-d 00:00:00", so an equality match on the bare date
        // string finds nothing on SQLite and the desk reports a rate that exists as
        // missing (which then blocks every exchange).
        return ForexRate::where('currency_id', $currencyId)->whereDate('date', $date ?? bdate()->toDateString())->first();
    }

    /** Today's rates keyed by currency id. */
    public static function todayRates(?string $date = null): Collection
    {
        return ForexRate::whereDate('date', $date ?? bdate()->toDateString())->get()->keyBy('currency_id');
    }

    /** @param array $rows currency_id => ['reference' => , 'buy' => ] (blank rows ignored) */
    public static function saveRates(array $rows): int
    {
        $max = self::cfg()['max_margin'];
        $date = bdate()->toDateString();
        $currencies = Currency::whereIn('id', array_keys($rows))->get()->keyBy('id');
        $errors = [];
        $clean = [];
        foreach ($rows as $id => $r) {
            $ref = trim((string) ($r['reference'] ?? ''));
            $buy = trim((string) ($r['buy'] ?? ''));
            if ($ref === '' && $buy === '') {
                continue;
            }
            $c = $currencies[$id] ?? null;
            if (! $c) {
                $errors["rates.$id.buy"] = 'Unknown currency.';

                continue;
            }
            if (! is_numeric($ref) || (float) $ref <= 0 || ! is_numeric($buy) || (float) $buy <= 0) {
                $errors["rates.$id.buy"] = "{$c->code}: enter both the reference and the buy rate (cedis per {$c->unit}).";

                continue;
            }
            if ((float) $buy > (float) $ref) {
                $errors["rates.$id.buy"] = "{$c->code}: the buy rate can't be above the reference rate.";

                continue;
            }
            if ((1 - (float) $buy / (float) $ref) * 100 > $max + 0.0001) {
                $errors["rates.$id.buy"] = "{$c->code}: the buy rate is more than {$max}% below the reference rate.";

                continue;
            }
            $clean[$id] = [round((float) $ref, 4), round((float) $buy, 4)];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        foreach ($clean as $id => [$ref, $buy]) {
            // Match on the date with whereDate: updateOrCreate() would compare the
            // bare date string against a stored "Y-m-d 00:00:00", never find the row
            // and then break the (hotel, currency, date) unique index with a 500.
            $values = ['reference_rate' => $ref, 'buy_rate' => $buy, 'carried' => false, 'set_by' => auth()->id()];
            $existing = ForexRate::where('currency_id', $id)->whereDate('date', $date)->first();
            $existing ? $existing->update($values) : ForexRate::create(['currency_id' => $id, 'date' => $date] + $values);
        }
        if ($clean) {
            ActivityLogger::log('forex', 'rates', 'Exchange rates for '.bdate()->format('d M Y').' set: '.collect($clean)->map(fn ($r, $id) => $currencies[$id]->code.' '.$r[1])->implode(', '));
        }

        return count($clean);
    }

    /** Copy the latest earlier rate to today for active currencies that have none. $carried marks automatic night-audit copies. */
    public static function copyPrevious(bool $carried = false): int
    {
        $date = bdate()->toDateString();
        $have = ForexRate::whereDate('date', $date)->pluck('currency_id')->all();
        $n = 0;
        foreach (Currency::where('is_active', true)->whereNotIn('id', $have)->get() as $c) {
            $prev = ForexRate::where('currency_id', $c->id)->whereDate('date', '<', $date)->orderByDesc('date')->first();
            if ($prev) {
                ForexRate::create(['currency_id' => $c->id, 'date' => $date, 'reference_rate' => $prev->reference_rate, 'buy_rate' => $prev->buy_rate,
                    'carried' => $carried, 'set_by' => $carried ? null : auth()->id()]);
                $n++;
            }
        }
        if ($n) {
            ActivityLogger::log('forex', 'rates', ($carried ? 'Night audit carried forward ' : 'Copied previous ')."{$n} exchange rate(s) to ".bdate()->format('d M Y'));
        }

        return $n;
    }

    /** Night audit: the new business day starts with yesterday's rates (flagged) unless switched off. */
    public static function carryForward(): int
    {
        return self::enabled() && self::cfg()['carry_rates'] === '1' ? self::copyPrevious(true) : 0;
    }

    // ───────────────────────── quoting & limits ─────────────────────────

    /** How far back a board rate may be used for a channel booking before the booking is held instead. */
    public const BOARD_STALE_DAYS = 7;

    /**
     * v7.50 The rate a foreign-currency document is converted at, and where it came from.
     *
     * The board for the date asked for is used; if the desk has not set that day's rates, the most recent board within
     * the staleness window is used instead and flagged, because the night audit normally carries rates forward and a
     * booking that arrives before the desk opens — or an invoice dated on a Sunday — should not be lost over it.
     * Beyond that window there is no honest rate to use and the caller holds the document for a human.
     *
     * `$policy` lets a caller bring its own basis and window: a channel booking uses the channel settings, a supplier
     * invoice the payables ones (v7.53). With no policy the channel settings apply, exactly as before.
     *
     * @param  array{basis?: string, stale_days?: int}|null  $policy
     * @return array{code: string, currency_id: int, rate: float, basis: string, date: string, carried: bool, stale: bool}|null
     */
    public static function boardRate(string $code, ?string $on = null, ?array $policy = null): ?array
    {
        $cur = Currency::where('code', strtoupper(trim($code)))->first();
        if (! $cur) {
            return null;
        }
        $on = $on ?: bdate()->toDateString();
        $cfg = self::cfg();
        $policy = $policy ?: [];
        $staleDays = max(0, min(90, (int) ($policy['stale_days'] ?? $cfg['channel_stale_days'])));
        $r = self::rate($cur->id, $on);
        if (! $r) {
            $floor = Carbon::parse($on)->subDays($staleDays)->toDateString();
            $r = ForexRate::where('currency_id', $cur->id)
                ->whereDate('date', '<=', $on)->whereDate('date', '>=', $floor)
                ->orderByDesc('date')->first();
        }
        if (! $r) {
            return null;
        }
        $basis = ($policy['basis'] ?? $cfg['channel_basis']) === 'buy' ? 'buy' : 'reference';
        $rate = round((float) ($basis === 'buy' ? $r->buy_rate : $r->reference_rate), 4);
        if ($rate <= 0) {
            return null;
        }

        return ['code' => strtoupper((string) $cur->code), 'currency_id' => (int) $cur->id, 'rate' => $rate, 'basis' => $basis,
            'date' => $r->date->toDateString(), 'carried' => (bool) $r->carried, 'stale' => $r->date->toDateString() !== $on];
    }

    /** v7.50 Whether a channel booking in a foreign currency is converted at the board or held for a human. */
    public static function channelConvert(): bool
    {
        return self::enabled() && setting('forex_channel_convert', self::DEFAULTS['forex_channel_convert']) === '1';
    }

    /** v7.53 Whether a supplier invoice in a foreign currency is converted at the board or refused. */
    public static function apConvert(): bool
    {
        return self::enabled() && setting('forex_ap_convert', self::DEFAULTS['forex_ap_convert']) === '1';
    }

    /**
     * v7.53 The payables side of the board: its own basis, its own staleness window, and which date the rate is read at.
     *
     * The default reads the board for the invoice date, because that is the transaction date an invoice is translated
     * at; a property that would rather book everything at the desk's board for today can say so in the forex settings.
     */
    public static function apPolicy(?string $docDate = null): array
    {
        $cfg = self::cfg();

        return ['basis' => $cfg['ap_basis'], 'stale_days' => $cfg['ap_stale_days'],
            'rate_date' => $cfg['ap_rate_date'],
            'date' => $cfg['ap_rate_date'] === 'business' ? bdate()->toDateString() : ($docDate ?: bdate()->toDateString())];
    }

    /**
     * v7.53 Every currency with a rate inside the payables staleness window, on the payables basis.
     *
     * The bill entry form uses it to quote the rate before the bill is saved, so whoever is keying a dollar invoice can
     * see the cedi total it will book at — and can see that a currency with no rate on the board cannot be used at all.
     *
     * @return array<string, array{rate: float, basis: string, date: string, stale: bool, carried: bool, name: string, symbol: string}>
     */
    public static function apBoard(?string $date = null): array
    {
        $policy = self::apPolicy($date);
        $out = [];
        foreach (Currency::orderBy('sort_order')->orderBy('code')->get() as $c) {
            $b = self::boardRate((string) $c->code, $policy['date'], ['basis' => $policy['basis'], 'stale_days' => $policy['stale_days']]);
            if ($b) {
                $out[$b['code']] = ['rate' => $b['rate'], 'basis' => $b['basis'], 'date' => $b['date'], 'stale' => $b['stale'],
                    'carried' => $b['carried'], 'name' => (string) $c->name, 'symbol' => (string) $c->symbol];
            }
        }

        return $out;
    }

    public static function usdRate(): ?float
    {
        $usd = Currency::where('code', 'USD')->first();
        $r = $usd ? self::rate($usd->id) : null;

        return $r ? (float) $r->reference_rate : null;
    }

    public static function passportOnFile(?Guest $g): bool
    {
        if (! $g) {
            return false;
        }

        return GuestDocument::where('guest_id', $g->id)->where(fn ($q) => $q->where('type', 'passport')
            ->orWhere(fn ($w) => $w->where('type', 'id_front')->whereRaw('? = ?', [(string) $g->id_type, 'passport'])))->exists();
    }

    /** USD-equivalent of cash encashments for the same guest / passport in the aggregation window (excluding $exceptId). */
    public static function aggregateUsed(?Guest $g, ?string $passport, ?int $exceptId = null): float
    {
        if (! $g && ! $passport) {
            return 0.0;
        }
        $days = self::cfg()['aggregate_days'];
        $from = bdate()->copy()->subDays(max(0, $days - 1))->toDateString();

        return (float) ForexExchange::whereNull('voided_at')->where('payout', 'cash')->whereDate('business_date', '>=', $from)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->where(fn ($q) => $q->when($g, fn ($w) => $w->orWhere('guest_id', $g->id))->when($passport, fn ($w) => $w->orWhere('passport_no', $passport)))
            ->sum('usd_equiv');
    }

    /** Price an exchange and check the limits. Returns figures + errors[] (never throws). */
    public static function quote(?Currency $c, float $fc, string $payout, ?ReservationRoom $stay = null, ?string $passport = null): array
    {
        $cfg = self::cfg();
        $q = ['currency' => $c?->code, 'unit' => $c?->unit ?? 1, 'rate' => null, 'reference' => null, 'carried' => false, 'inr' => 0.0, 'fee' => 0.0, 'gst_value' => 0.0, 'gst' => 0.0, // gst* stay 0 – money changing is VAT-exempt
            'net' => 0.0, 'usd' => 0.0, 'cap' => null, 'used' => 0.0, 'remaining' => null, 'id_copy_needed' => false, 'id_copy_on_file' => false, 'balance' => null,
            'resident' => false, 'errors' => []];
        if (! self::enabled()) {
            $q['errors'][] = 'The forex desk is switched off (Foreign Guests › Settings).';

            return $q;
        }
        if (! $c || ! $c->is_active) {
            $q['errors'][] = 'Choose a currency the desk buys.';

            return $q;
        }
        $r = self::rate($c->id);
        if (! $r) {
            $q['errors'][] = "No {$c->code} rate has been set for ".bdate()->format('d M Y').' – set today’s rates first.';

            return $q;
        }
        $q['rate'] = (float) $r->buy_rate;
        $q['reference'] = (float) $r->reference_rate;
        $q['carried'] = $r->carried;
        if ($fc <= 0) {
            $q['errors'][] = 'Enter the foreign-currency amount.';

            return $q;
        }
        $inr = round($fc * $q['rate'] / max(1, $c->unit), 2);
        $q['inr'] = $inr;
        if ($payout === 'cash') {
            $q['fee'] = round($inr * $cfg['fee_pct'] / 100, 2);
        }
        $q['net'] = round($inr - $q['fee'], 2);
        if ($q['net'] <= 0) {
            $q['errors'][] = 'The amount is too small to cover the conversion charges.';
        }
        $usdRate = $c->code === 'USD' ? null : self::usdRate();
        $q['usd'] = $c->code === 'USD' ? round($fc, 2) : ($usdRate ? round($inr / $usdRate, 2) : 0.0);

        if ($stay) {
            $g = FormCService::stayGuest($stay);
            $passport = $passport ?: ($g?->passport_no ?: ($g?->id_type === 'passport' ? $g->id_number : null));
            $passport = $passport ? strtoupper(preg_replace('/\s+/', '', $passport)) : null;
            $q['resident'] = FormCService::isForeignNationality($g?->nationality) === false;
            if ($stay->status !== 'checked_in') {
                $q['errors'][] = 'Currency can only be exchanged for guests who are in house.';
            }
            if ($q['resident'] && $cfg['allow_residents'] !== '1') {
                $q['errors'][] = 'The desk only buys foreign currency from foreign nationals (residents: Foreign Guests › Settings).';
            }
            if (FormCService::isForeignNationality($g?->nationality) === null) {
                $q['errors'][] = 'Record the guest’s nationality first.';
            }
            if (! $passport) {
                $q['errors'][] = 'Record the guest’s passport number.';
            }
            if ($payout === 'cash') {
                $q['cap'] = (float) ($q['resident'] ? $cfg['resident_cap_usd'] : $cfg['cash_cap_usd']);
                $q['used'] = round(self::aggregateUsed($g, $passport), 2);
                $q['remaining'] = round(max(0, $q['cap'] - $q['used']), 2);
                if ($c->code !== 'USD' && ! $usdRate) {
                    $q['errors'][] = 'Set today’s USD rate – the cash limit is counted in US dollars.';
                } elseif ($q['usd'] > $q['remaining'] + 0.004) {
                    $q['errors'][] = 'Cash is limited to USD '.number_format($q['cap']).' per guest within '.$cfg['aggregate_days'].' days (house limit) – USD '
                        .number_format($q['used'], 2).' already paid, USD '.number_format($q['remaining'], 2).' left. Credit the rest to the bill instead.';
                }
                $q['id_copy_needed'] = $cfg['id_copy_inr'] > 0 && $inr >= $cfg['id_copy_inr'];
                $q['id_copy_on_file'] = self::passportOnFile($g);
                if ($q['id_copy_needed'] && ! $q['id_copy_on_file']) {
                    $q['errors'][] = 'From '.money($cfg['id_copy_inr']).' a copy of the passport must be kept – upload the passport scan to the guest profile first.';
                }
            } else {
                $folio = $stay->folio()->where('status', 'open')->first();
                $q['balance'] = $folio ? round($folio->balance(), 2) : 0.0;
                if (! $folio || $q['balance'] <= 0) {
                    $q['errors'][] = 'There is nothing to pay on the room bill – pay the guest in cedis instead.';
                } elseif ($q['net'] > $q['balance'] + 0.009) {
                    $q['errors'][] = 'The credit ('.money($q['net']).') can’t exceed the balance due on the bill ('.money($q['balance']).').';
                }
            }
        }

        return $q;
    }

    // ───────────────────────── exchange ─────────────────────────

    public static function exchange(array $d): ForexExchange
    {
        $stay = ReservationRoom::with('reservation.guest', 'guest', 'room')->findOrFail($d['reservation_room_id']);
        $c = Currency::find($d['currency_id']);
        $payout = in_array($d['payout'] ?? '', ['cash', 'folio']) ? $d['payout'] : 'cash';
        $fc = round((float) $d['fc_amount'], 2);
        $g = FormCService::stayGuest($stay);
        $passport = trim((string) ($d['passport_no'] ?? '')) ?: null;
        $q = self::quote($c, $fc, $payout, $stay, $passport);
        if ($q['errors']) {
            throw new RuntimeException($q['errors'][0]);
        }
        $passport = strtoupper(preg_replace('/\s+/', '', $passport ?: ($g->passport_no ?: $g->id_number)));
        if ($payout === 'cash') {
            ShiftService::assertOpen();
        }

        return DB::transaction(function () use ($stay, $c, $payout, $fc, $g, $passport, $q, $d) {
            if ($passport && ! $g->passport_no && ! $g->isPrivacyLocked()) {
                $g->update(['passport_no' => $passport]);
            }
            $x = ForexExchange::create([
                'exchange_no' => Numbering::next('forex'), 'business_date' => bdate()->toDateString(), 'reservation_room_id' => $stay->id, 'guest_id' => $g->id,
                'guest_name' => trim($g->first_name.' '.$g->last_name), 'passport_no' => $passport, 'nationality' => $g->nationality, 'currency_id' => $c->id,
                'instrument' => ($d['instrument'] ?? 'notes') === 'tc' ? 'tc' : 'notes', 'fc_amount' => $fc, 'rate' => $q['rate'], 'unit' => $c->unit,
                'inr_amount' => $q['inr'], 'fee' => $q['fee'], 'gst_value' => $q['gst_value'], 'gst' => $q['gst'], 'net_amount' => $q['net'], 'usd_equiv' => $q['usd'],
                'payout' => $payout, 'certificate' => (bool) ($d['certificate'] ?? true), 'notes' => $d['notes'] ?? null, 'created_by' => auth()->id(),
            ]);
            if ($payout === 'folio') {
                $folio = FolioService::openForStay($stay);
                $tx = FolioService::payment($folio, $q['net'], self::modeId(), "{$x->exchange_no} {$c->code} ".number_format($fc, 2).' @ '.rtrim(rtrim(number_format($q['rate'], 4), '0'), '.'));
                $tx->update(['description' => "Paid in foreign currency – {$c->code} ".number_format($fc, 2), 'source_type' => 'forex', 'source_id' => $x->id]);
                $x->update(['folio_id' => $folio->id, 'folio_transaction_id' => $tx->id]);
            }
            ActivityLogger::log('forex', 'exchange', "{$x->exchange_no}: bought {$c->code} ".number_format($fc, 2)." from {$x->guest_name} @ {$q['rate']} = ".money($q['inr'])
                .($payout === 'cash' ? ' – paid '.money($q['net']).' in cash' : ' – credited to the room bill'), $x);

            return $x->fresh(['currency']);
        });
    }

    public static function void(ForexExchange $x, string $reason): ForexExchange
    {
        if ($x->voided_at) {
            throw new RuntimeException('This exchange is already void.');
        }
        if ($x->business_date->toDateString() !== bdate()->toDateString()) {
            throw new RuntimeException('Only exchanges of the current business day can be voided – the day has been closed.');
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw new RuntimeException('Give the reason for voiding the exchange.');
        }
        $pos = self::position()->firstWhere('currency_id', $x->currency_id);
        if ($pos && $pos['held'] + 0.005 < (float) $x->fc_amount) {
            throw new RuntimeException('This currency has already been surrendered to the bank – it can’t be voided.');
        }

        return DB::transaction(function () use ($x, $reason) {
            if ($x->payout === 'folio' && $x->folioTransaction) {
                if ($x->folioTransaction->folio?->status !== 'open') {
                    throw new RuntimeException('The bill has been closed – refund the guest from the bill instead.');
                }
                if (! $x->folioTransaction->is_void) {
                    FolioService::void($x->folioTransaction, 'Forex exchange '.$x->exchange_no.' voided: '.trim($reason));
                }
            }
            $x->update(['voided_at' => now(), 'voided_by' => auth()->id(), 'void_reason' => trim($reason)]);
            ActivityLogger::log('forex', 'void', "{$x->exchange_no} voided: ".trim($reason), $x);

            return $x->fresh();
        });
    }

    public static function printedCertificate(ForexExchange $x): void
    {
        $x->increment('certificate_prints');
        ActivityLogger::log('forex', 'certificate', "Encashment certificate {$x->exchange_no} printed".($x->certificate_prints > 1 ? " (copy {$x->certificate_prints})" : ''), $x);
    }

    // ───────────────────────── position & surrender ─────────────────────────

    /** Currency on hand per currency: held, cost value, average cost, oldest unsurrendered purchase (FIFO) and its age. */
    public static function position(): Collection
    {
        $buys = ForexExchange::whereNull('voided_at')->orderBy('business_date')->orderBy('id')->get(['id', 'currency_id', 'business_date', 'fc_amount', 'inr_amount'])->groupBy('currency_id');
        $outs = ForexSurrenderLine::whereHas('surrender', fn ($q) => $q->where('hotel_id', \App\Support\Tenant::id()))
            ->selectRaw('currency_id, SUM(fc_amount) fc, SUM(cost) cost, SUM(realised) realised')->groupBy('currency_id')->get()->keyBy('currency_id');
        $rates = self::todayRates();
        $days = self::cfg()['surrender_days'];
        $today = bdate()->copy()->startOfDay();

        return Currency::orderBy('sort_order')->orderBy('code')->get()->map(function ($c) use ($buys, $outs, $rates, $days, $today) {
            $b = $buys[$c->id] ?? collect();
            $o = $outs[$c->id] ?? null;
            if ($b->isEmpty() && ! $o && ! $c->is_active) {
                return null;
            }
            $bought = (float) $b->sum('fc_amount');
            $out = (float) ($o->fc ?? 0);
            $held = round($bought - $out, 2);
            $cost = round((float) $b->sum('inr_amount') - (float) ($o->cost ?? 0), 2);
            $left = $out;
            $oldest = null;
            foreach ($b as $row) {
                $left -= (float) $row->fc_amount;
                if ($left < -0.004) {
                    $oldest = $row->business_date->copy();
                    break;
                }
            }
            $age = $oldest ? (int) $oldest->diffInDays($today) : null;
            $r = $rates[$c->id] ?? null;

            return ['currency_id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'unit' => $c->unit, 'bought' => round($bought, 2), 'surrendered' => round($out, 2),
                'held' => $held, 'cost' => $held > 0.004 ? $cost : 0.0, 'avg' => $held > 0.004 ? round($cost / $held * $c->unit, 4) : 0.0,
                'realised' => round((float) ($o->realised ?? 0), 2), 'gain' => round((float) ($o->realised ?? 0) - (float) ($o->cost ?? 0), 2),
                'oldest' => $held > 0.004 ? $oldest : null, 'age' => $held > 0.004 ? $age : null, 'overdue' => $held > 0.004 && $age !== null && $days > 0 && $age > $days,
                'reference' => $r ? (float) $r->reference_rate : null, 'market' => $r && $held > 0 ? round($held * (float) $r->reference_rate / $c->unit, 2) : null];
        })->filter()->values();
    }

    /** @param array $lines currency_id => ['fc_amount' => , 'rate' => ] */
    public static function surrender(array $d, array $lines): ForexSurrender
    {
        $date = Carbon::parse($d['date'] ?? bdate())->toDateString();
        if ($date > bdate()->toDateString()) {
            throw new RuntimeException('The surrender date can’t be after the business date.');
        }
        if (($lock = LedgerService::lockDate()) && $date <= $lock) {
            throw new RuntimeException('The books are locked up to '.Carbon::parse($lock)->format('d M Y').'.');
        }
        $mode = PaymentMode::find($d['payment_mode_id'] ?? 0);
        if (! $mode || in_array($mode->type, ['forex', 'city_ledger', 'gift_voucher'])) {
            throw new RuntimeException('Choose where the cedis were received (bank or cash).');
        }
        $pos = self::position()->keyBy('currency_id');
        $rows = [];
        foreach ($lines as $cid => $l) {
            $fc = round((float) ($l['fc_amount'] ?? 0), 2);
            if ($fc <= 0) {
                continue;
            }
            $p = $pos[$cid] ?? null;
            if (! $p) {
                throw new RuntimeException('Unknown currency.');
            }
            if ($fc > $p['held'] + 0.004) {
                throw new RuntimeException("Only {$p['code']} ".number_format($p['held'], 2).' is on hand.');
            }
            $rate = (float) ($l['rate'] ?? 0);
            if ($rate <= 0) {
                throw new RuntimeException("Enter the rate the bank paid for {$p['code']}.");
            }
            if ($p['reference'] && abs($rate / $p['reference'] - 1) > 0.15) {
                throw new RuntimeException("The {$p['code']} rate is more than 15% away from today’s reference rate (".$p['reference'].') – check it.');
            }
            $cost = abs($fc - $p['held']) < 0.005 ? $p['cost'] : round($p['cost'] * $fc / $p['held'], 2);
            $rows[] = ['currency_id' => $cid, 'fc_amount' => $fc, 'cost' => $cost, 'cost_rate' => round($cost / $fc * $p['unit'], 4), 'rate' => round($rate, 4),
                'realised' => round($fc * $rate / $p['unit'], 2)];
        }
        if (! $rows) {
            throw new RuntimeException('Enter the amount surrendered for at least one currency.');
        }
        if (mb_strlen(trim((string) ($d['counterparty'] ?? ''))) < 3) {
            throw new RuntimeException('Enter the bank / authorised dealer the currency was handed to.');
        }

        return DB::transaction(function () use ($d, $date, $mode, $rows) {
            $cost = round(array_sum(array_column($rows, 'cost')), 2);
            $real = round(array_sum(array_column($rows, 'realised')), 2);
            $s = ForexSurrender::create(['surrender_no' => Numbering::next('forex_surrender'), 'date' => $date, 'counterparty' => trim($d['counterparty']),
                'reference' => $d['reference'] ?? null, 'payment_mode_id' => $mode->id, 'total_cost' => $cost, 'total_realised' => $real, 'gain' => round($real - $cost, 2),
                'notes' => $d['notes'] ?? null, 'created_by' => auth()->id()]);
            foreach ($rows as $r) {
                $s->lines()->create($r);
            }
            ActivityLogger::log('forex', 'surrender', "{$s->surrender_no}: currency surrendered to {$s->counterparty} – realised ".money($real).', '.($real >= $cost ? 'gain ' : 'loss ').money(abs($real - $cost)), $s);

            return $s->fresh(['lines.currency']);
        });
    }

    // ───────────────────────── ledger ─────────────────────────

    public static function forExchange(ForexExchange $x): ?array
    {
        if ($x->voided_at || $x->payout !== 'cash') {
            return null; // bill credits post through the folio payment
        }

        return ['date' => $x->business_date->toDateString(), 'reference' => $x->exchange_no,
            'narration' => 'Currency exchange – '.$x->currency?->code.' '.number_format((float) $x->fc_amount, 2).' from '.$x->guest_name,
            'lines' => [[AccountHead::sys('forex_on_hand'), (float) $x->inr_amount, $x->currency?->code], [AccountHead::sys('cash'), -(float) $x->net_amount, 'Cedis paid'],
                [AccountHead::sys('forex_income'), -(float) $x->fee, null], [AccountHead::sys('tax_payable'), -(float) $x->gst, 'Tax on currency conversion (pre-v4.3 records)']]];
    }

    public static function forSurrender(ForexSurrender $s): ?array
    {
        return ['date' => $s->date->toDateString(), 'reference' => $s->surrender_no, 'narration' => 'Foreign currency surrendered – '.$s->counterparty,
            'lines' => [[LedgerService::modeAccount($s->payment_mode_id, 'bank'), (float) $s->total_realised, $s->reference],
                [AccountHead::sys('forex_on_hand'), -(float) $s->total_cost, null], [AccountHead::sys('forex_gain'), (float) $s->total_cost - (float) $s->total_realised, null]]];
    }

    // ───────────────────────── alerts ─────────────────────────

    public static function alerts(): array
    {
        $cfg = self::cfg();
        if (! self::enabled()) {
            return ['rates_missing' => 0, 'carried' => 0, 'surrender_overdue' => 0, 'surrender_days' => $cfg['surrender_days'], 'held_value' => 0.0, 'today' => 0];
        }
        $active = Currency::where('is_active', true)->pluck('id');
        $today = self::todayRates()->toBase()->only($active->all());
        $pos = self::position();

        return ['rates_missing' => $active->count() - $today->count(), 'carried' => $today->where('carried', true)->count(),
            'surrender_overdue' => $pos->where('overdue', true)->count(), 'surrender_days' => $cfg['surrender_days'], 'held_value' => round($pos->sum('cost'), 2),
            'today' => ForexExchange::whereNull('voided_at')->whereDate('business_date', bdate()->toDateString())->count()];
    }

    public static function attentionCount(): int
    {
        $a = self::alerts();

        return ($a['rates_missing'] ? 1 : 0) + $a['surrender_overdue'];
    }
}
