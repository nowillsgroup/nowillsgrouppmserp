<?php

namespace Database\Seeders\Concerns;

use App\Models;
use App\Services\FolioService;
use App\Services\HotelProvisioner;
use App\Services\ApService;
use App\Services\ForexService;
use App\Services\LedgerService;
use App\Services\StayService;
use App\Services\TaxService;
use App\Support\Numbering;
use App\Support\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Demo-data seeders — split out of DatabaseSeeder (one file per domain).
 * Composed into DatabaseSeeder via `use`; every method keeps its original visibility and body.
 */
trait SeedFinanceConcern
{
    protected function seedBankRec(Carbon $today): void
    {
        $Rec = \App\Services\BankRecService::class;
        $Rec::ensureDefaults();
        \App\Services\LedgerService::sync();
        $bank = \App\Models\AccountHead::where('system_key', 'bank')->first();
        $momo = \App\Models\AccountHead::where('system_key', 'wallet_clearing')->first();
        if (! $bank || ! $momo) {
            return;
        }
        $to2 = $today->copy()->subDays(3);
        $from2 = $to2->copy()->startOfMonth();
        // The demo adds three transit days. During the first few days of a month,
        // today - 3 can leave a current-month statement shorter than those lines.
        // Keep the sample period valid without ever ending it in the future.
        if ($to2->lt($from2->copy()->addDays(2))) {
            $to2 = $today->copy()->subDay();
        }
        $from1 = $from2->copy()->subMonthNoOverflow();
        $to1 = $from1->copy()->endOfMonth();
        $gl = fn ($acc, $a, $b) => \App\Models\GlLine::with('journal')->where('account_head_id', $acc)->whereBetween('date', [$a, $b])->orderBy('date')->orderBy('id')->get();
        $signed = fn ($g) => round((float) $g->debit - (float) $g->credit, 2);
        $desc = function ($g) use ($signed) {
            $j = $g->journal;
            $n = strtoupper(\Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', $j->narration), 34, ''));

            return match ($j->source_type) {
                'stat_remit' => 'TRF '.$n,
                'payroll_pay' => 'BULK SALARY TRF '.$j->reference,
                'ap_pay' => preg_match('/CHQ\s*(\d+)/i', $j->reference.' '.$j->narration, $m) ? 'CHQ '.$m[1].' PRESENTED' : 'GIP TRF '.$n,
                'folio_tx', 'advance' => ($signed($g) > 0 ? 'DEPOSIT ' : 'TRF ').preg_replace('/^(DEPOSIT|PAYMENT RECEIVED) – /u', '', $n),
                'gw_payout' => 'PAYSTACK SETTLEMENT '.$j->reference,
                'fx_surr' => 'FX PURCHASE CREDIT',
                default => ($signed($g) > 0 ? 'CR ' : 'DR ').$n,
            };
        };
        $ref = fn ($g) => $g->journal->source_type === 'stat_remit' ? $g->journal->reference : (preg_match('/CHQ\s*(\d+)/i', $g->journal->reference.' '.$g->journal->narration, $m) ? $m[1] : null);
        $withBalance = function (array $lines, float $open) {
            usort($lines, fn ($x, $y) => [$x['date'], $x['o'] ?? 0] <=> [$y['date'], $y['o'] ?? 0]);
            $b = $open;
            foreach ($lines as &$l) {
                $b = round($b + $l['amount'], 2);
                $l['balance'] = $b;
                unset($l['o']);
            }

            return [$lines, $b];
        };

        // ---- bank, last month: reconciled
        $f1 = $from1->toDateString();
        $open1 = $Rec::glBalance($bank->id, $from1->copy()->subDay()->toDateString());
        $transit = collect();
        $lines = [];
        $all1 = $gl($bank->id, $f1, $to1->toDateString());
        $late = $all1->count() >= 3 ? $all1->slice(-2)->pluck('id')->flip() : collect(); // the month's last two entries reach the bank next month
        foreach ($all1 as $i => $g) {
            if (isset($late[$g->id])) {
                $transit->push($g);

                continue;
            }
            $lines[] = ['date' => min($g->date->copy()->addDay(), $to1)->toDateString(), 'description' => $desc($g), 'reference' => $ref($g), 'amount' => $signed($g), 'o' => $i];
        }
        $lines[] = ['date' => $to1->toDateString(), 'description' => 'ACCOUNT MAINTENANCE FEE', 'reference' => null, 'amount' => -45.00, 'o' => 999];
        [$lines, $close1] = $withBalance($lines, $open1);
        $s1 = $Rec::create($bank->id, $f1, $to1->toDateString(), $open1, $close1, $lines, 'csv', 'GCB_statement_'.$from1->format('M_Y').'.csv', null);
        $Rec::autoMatch($s1);
        $fee = $s1->lines()->where('amount', -45)->where('status', 'unmatched')->first();
        $fee && $Rec::createEntry($fee, 'bank_charges', null, 'Monthly account maintenance fee');
        try {
            $Rec::finalise($s1->fresh());
        } catch (\RuntimeException $e) {
            // leave it in progress rather than fail the seed
        }

        // ---- bank, this month: in progress
        $lines = [];
        foreach ($transit as $i => $g) {
            $lines[] = ['date' => $from2->copy()->addDays(min($i, 2))->toDateString(), 'description' => $desc($g), 'reference' => $ref($g), 'amount' => $signed($g), 'o' => $i];
        }
        $cur = $gl($bank->id, $from2->toDateString(), $to2->copy()->subDay()->toDateString());
        $unpresented = $cur->filter(fn ($g) => $signed($g) < 0)->sortByDesc(fn ($g) => $g->date->toDateString().sprintf('%08d', $g->id))->take(2)->pluck('id')->flip();
        foreach ($cur as $i => $g) {
            if (isset($unpresented[$g->id])) {
                continue;
            }
            $lines[] = ['date' => min($g->date->copy()->addDay(), $to2)->toDateString(), 'description' => $desc($g), 'reference' => $ref($g), 'amount' => $signed($g), 'o' => 100 + $i];
        }
        $mid = $from2->copy()->addDays(min(13, $from2->diffInDays($to2)))->toDateString();
        $lines[] = ['date' => $mid, 'description' => 'TRF FROM 0244 581 902 KWAME ADJEI', 'reference' => 'MMT7781402', 'amount' => 1250.00, 'o' => 900];
        $lines[] = ['date' => $to2->toDateString(), 'description' => 'SMS ALERT & COT CHARGES', 'reference' => null, 'amount' => -38.50, 'o' => 901];
        $lines[] = ['date' => $to2->toDateString(), 'description' => 'CREDIT INTEREST', 'reference' => null, 'amount' => 112.40, 'o' => 902];
        [$lines, $close2] = $withBalance($lines, (float) ($s1->fresh()->closing_balance));
        $s2 = $Rec::create($bank->id, $from2->toDateString(), $to2->toDateString(), (float) $s1->fresh()->closing_balance, $close2, $lines, 'csv', 'GCB_statement_'.$from2->format('M_Y').'.csv', null);
        $Rec::autoMatch($s2);

        // ---- Mobile Money merchant wallet, this month: one collections line and one fee line per day
        $open3 = $Rec::glBalance($momo->id, $from2->copy()->subDay()->toDateString());
        $lines = [];
        foreach ($gl($momo->id, $from2->toDateString(), $to2->toDateString())->filter(fn ($g) => $signed($g) > 0)->groupBy(fn ($g) => $g->date->toDateString()) as $d => $day) {
            $sum = round($day->sum($signed), 2);
            $lines[] = ['date' => $d, 'description' => 'MERCHANT COLLECTIONS ('.$day->count().' TXN)', 'reference' => 'MTN'.str_replace('-', '', $d), 'amount' => $sum, 'o' => 1];
            $lines[] = ['date' => $d, 'description' => 'MERCHANT SERVICE CHARGE 1%', 'reference' => null, 'amount' => -round($sum * 0.01, 2), 'o' => 2];
        }
        if ($lines) {
            [$lines, $close3] = $withBalance($lines, $open3);
            $s3 = $Rec::create($momo->id, $from2->toDateString(), $to2->toDateString(), $open3, $close3, $lines, 'csv', 'MTN_MoMo_merchant_'.$from2->format('M_Y').'.csv', null);
            $Rec::autoMatch($s3);
            // fees of all but the last three days already booked
            foreach ($s3->lines()->where('amount', '<', 0)->where('status', 'unmatched')->where('date', '<', $to2->copy()->subDays(2)->toDateString())->get() as $l) {
                $Rec::createEntry($l, 'momo_fees', null, null);
            }
        }
    }

    protected function seedPetty(Carbon $today): void
    {
        $P = \App\Services\PettyCashService::class;
        $C = \App\Services\ExpenseClaimService::class;
        $P::ensureDefaults();
        $C::ensureDefaults();
        $hid = \App\Support\Tenant::id();
        $u = fn ($n) => \App\Models\User::where('hotel_id', $hid)->where('username', $n)->first();
        [$acct, $fom, $chef, $admin] = [$u('accounts'), $u('fom'), $u('chef'), $u('admin')];
        $cash = \App\Models\PaymentMode::where('type', 'cash')->where('is_active', true)->first();
        if (! $acct || ! $fom || ! $chef || ! $cash || \App\Models\PettyFloat::exists()) {
            return;
        }
        $emp = fn ($name) => \App\Models\Employee::where('name', $name)->first();
        $head = fn ($code) => \App\Models\AccountHead::where('code', $code)->value('id');
        $sys = fn ($k) => \App\Models\AccountHead::sys($k);
        $D = fn (int $n) => $today->copy()->subDays($n);
        $dept = fn ($n) => \App\Models\Department::where('name', $n)->value('id');
        $prevUser = auth()->user();
        $as = fn ($user) => auth()->setUser($user);
        $v = function ($f, int $ago, string $payee, string $what, $headId, float $amt, array $x = []) use ($P, $D) {
            $rc = ! empty($x['png']) ? $this->receiptPng($payee, $what, $amt, $D($ago)) : null;

            return $P::expense($f, ['date' => $D($ago)->toDateString(), 'payee' => $payee, 'description' => $what, 'expense_head_id' => $headId, 'amount' => $amt,
                'vat_invoice' => ! empty($x['vat']), 'receipt_ref' => $x['ref'] ?? null, 'no_receipt_reason' => $x['why'] ?? null], $rc);
        };
        $denoms = function (float $amount) use ($P) {
            $left = (int) round($amount * 100);
            $q = [];
            foreach ($P::DENOMS as $k => [$val]) {
                $c = (int) round($val * 100);
                if ($k === 'n2' || $k === 'n1') {
                    continue; // coins for GH₵2 / GH₵1
                }
                if ($n = intdiv($left, $c)) {
                    $q[$k] = $n;
                    $left -= $n * $c;
                }
            }

            return $q;
        };

        // ── floats, funded from the main cash 25 days ago
        $as($acct);
        $fo = $P::createFloat(['code' => 'FO', 'name' => 'Front Office Petty Cash', 'custodian_id' => $fom->id, 'department_id' => $dept('Front Office'), 'imprest_amount' => 3000,
            'voucher_limit' => 500, 'payment_mode_id' => $cash->id, 'date' => $D(25)->toDateString(), 'notes' => 'Kept in the front office safe; key with the duty manager']);
        $kit = $P::createFloat(['code' => 'KIT', 'name' => 'Kitchen & Stores Petty Cash', 'custodian_id' => $chef->id, 'department_id' => $dept('Kitchen'), 'imprest_amount' => 2000,
            'voucher_limit' => 400, 'payment_mode_id' => $cash->id, 'date' => $D(25)->toDateString(), 'notes' => 'Market purchases – fresh fish, vegetables, charcoal']);

        // ── front office
        $as($fom);
        $v($fo, 24, 'Accra Printing Press', 'Registration cards & folio paper', $sys('stationery'), 180.00, ['vat' => true, 'png' => true, 'ref' => 'APP-20931']);
        $v($fo, 22, 'Taxi – Kwesi (GR 4471-19)', "Guest's forgotten passport to Kotoka airport", $sys('travel'), 85.00, ['ref' => 'MoMo 55120934']);
        $v($fo, 20, 'Telecel Ghana', 'Airtime for the duty manager phone', $sys('telephone_exp'), 50.00);
        $iou1 = $P::issueIou($fo, ['date' => $D(18)->toDateString(), 'employee_id' => $emp('Deepa Nair')?->id, 'amount' => 300, 'description' => 'Flowers & welcome fruit for the VIP arrival', 'due_date' => $D(15)->toDateString()]);
        $P::settleIou($iou1->fresh('float'), ['date' => $D(16)->toDateString(), 'spent' => 262.50, 'expense_head_id' => $head('6070'), 'description' => 'Flowers (Osu florist) and fruit basket', 'receipt_ref' => 'OF-118'],
            $this->receiptPng('Osu Florist', 'Flowers & fruit basket', 262.50, $D(16)));
        $v($fo, 12, 'Makola Electricals', 'Bulbs & extension boards for the lobby', $sys('repairs'), 240.00, ['png' => true]);
        $v($fo, 8, 'Auntie Muni Chop Bar', 'Lunch for the night audit team (stock count overtime)', $sys('staff_welfare'), 120.00, ['png' => true]);

        // ── expense claims paid so far
        $joseph = $emp('Joseph Thomas');
        $ramesh = $emp('Ramesh Gowda');
        $eng = $u('engineer');
        if ($joseph && $eng) {
            $as($eng);
            $k = $C::create(null, 'Kumasi trip – chiller spare parts supplier visit', 'Drove my own car; supplier Adum Refrigeration');
            $C::addLine($k, ['date' => $D(20)->toDateString(), 'category' => 'mileage', 'km' => 500, 'description' => 'Accra – Kumasi – Accra (500 km)']);
            $C::addLine($k, ['date' => $D(20)->toDateString(), 'category' => 'accommodation', 'amount' => 450, 'vat_amount' => 75, 'description' => 'Guest house in Kumasi, 1 night', 'receipt_ref' => 'KGH-5521'],
                $this->receiptPng('Kumasi Guest House', '1 night room', 450, $D(20)));
            $C::addLine($k, ['date' => $D(19)->toDateString(), 'category' => 'meals', 'amount' => 85, 'description' => 'Dinner on the road (Nkawkaw)', 'receipt_ref' => 'Chop bar – handwritten bill']);
            $C::submit($k);
            $k->forceFill(['submitted_at' => $D(18)->setTime(9, 20)])->save();
            $as($acct);
            $C::approve($k);
            $k->forceFill(['approved_on' => $D(17)->toDateString()])->save();
            $C::pay($k->fresh(), 'mode', $cash->id, null, $D(14)->toDateString(), 'Cash – signed for');
        }
        if ($ramesh && ($att = $u('attendant'))) {
            $as($att);
            $r = $C::create(null, 'Taxi – urgent linen to the laundry contractor');
            $C::addLine($r, ['date' => $D(9)->toDateString(), 'category' => 'transport', 'amount' => 45, 'description' => 'Taxi to Spintex laundry and back with the banquet linen']);
            $C::submit($r);
            $as($acct);
            $C::approve($r);
            $r->forceFill(['approved_on' => $D(9)->toDateString()])->save();
            $C::pay($r->fresh(), 'petty', null, $fo->id, $D(8)->toDateString());
        }

        // ── front office, last week: overdue IOU, a count 5.00 short (approved), an over-limit repair waiting, an open IOU
        $as($fom);
        $P::issueIou($fo, ['date' => $D(7)->toDateString(), 'employee_id' => $emp('Sunil Yadav')?->id, 'amount' => 150, 'description' => 'Diesel for the shuttle van', 'due_date' => $D(4)->toDateString()]);
        $bal = $P::balance($fo);
        $c1 = $P::count($fo, $denoms($bal - 5), 'GH₵5 short – probably change given with the taxi fare', $acct->id);
        $c1->forceFill(['date' => $D(3)->toDateString()])->save();
        $as($acct);
        $P::approveCount($c1->fresh('float'));
        \App\Models\PettyTxn::whereKey($c1->fresh()->variance_txn_id)->update(['date' => $D(3)->toDateString()]);
        $as($fom);
        $v($fo, 1, 'Nii Plumbing Works', 'Emergency: burst pipe in room 204 – plumber & fittings', $sys('repairs'), 850.00, ['png' => true, 'ref' => 'NPW-044']);
        $P::issueIou($fo, ['date' => $D(1)->toDateString(), 'employee_id' => $emp('Deepa Nair')?->id, 'amount' => 200, 'description' => 'Printer toner for the front desk', 'due_date' => $today->copy()->addDays(2)->toDateString()]);

        // ── kitchen: vouchers, an IOU settled with an overspend, a paid top-up, an agreed count, a new top-up request
        $as($chef);
        $food = $head('6050') ?: $sys('misc_expense');
        $v($kit, 23, 'Tema Fishing Harbour – Auntie Adjoa', 'Fresh red snapper & tuna', $food, 380.00, ['why' => 'Fish sellers at the harbour give no receipts']);
        $v($kit, 21, 'Charcoal seller – Kaneshie', 'Charcoal for the grill (4 bags)', $sys('utility_fuel'), 95.00);
        $v($kit, 19, 'Goil LPG station', 'Kitchen LPG cylinder refill', $sys('utility_fuel'), 260.00, ['png' => true]);
        $v($kit, 15, 'Agbogbloshie market', 'Tomatoes, peppers, onions & garden eggs', $food, 180.00, ['why' => 'Market traders – no receipts']);
        $r1 = $P::requestReplenishment($kit, 'Weekly top-up');
        $r1->forceFill(['date' => $D(14)->toDateString()])->save();
        $as($acct);
        $P::approveReplenishment($r1->fresh());
        $P::payReplenishment($r1->fresh(), $cash->id, $D(13)->toDateString(), 'Main cash');
        $as($chef);
        $iou2 = $P::issueIou($kit, ['date' => $D(11)->toDateString(), 'employee_id' => $emp('Mohan Lal')?->id, 'amount' => 250, 'description' => 'Fresh tilapia for the Friday buffet', 'due_date' => $D(9)->toDateString()]);
        $P::settleIou($iou2->fresh('float'), ['date' => $D(10)->toDateString(), 'spent' => 275, 'expense_head_id' => $food, 'description' => 'Tilapia 11 kg (price went up)', 'no_receipt_reason' => 'Bought from the Volta fish van – no receipt']);
        $v($kit, 7, 'Knife sharpener (walk-in)', 'Sharpening chef knives', $sys('repairs'), 60.00);
        $v($kit, 4, 'Melcom', 'Detergent, sponges & bin liners', $head('6070') ?: $sys('misc_expense'), 145.00, ['vat' => true, 'png' => true, 'ref' => 'MEL-883120']);
        $c2 = $P::count($kit, $denoms($P::balance($kit)), null, $u('store')?->id);
        $c2->forceFill(['date' => $D(2)->toDateString()])->save();
        $P::requestReplenishment($kit, 'Before the weekend market run');

        // ── claims still in progress
        if ($fomEmp = $emp('Ravi Kumar')) {
            $as($fom);
            $l = $C::create(null, 'Client lunch – Ghana Chamber of Mines delegation', 'Discussed the November conference block (40 rooms)');
            $C::addLine($l, ['date' => $D(5)->toDateString(), 'category' => 'entertainment', 'amount' => 690, 'vat_amount' => 115, 'description' => 'Lunch for 4 at Buka restaurant, Osu', 'receipt_ref' => 'BUKA-7731'],
                $this->receiptPng('Buka Restaurant Osu', 'Lunch for 4', 690, $D(5)));
            $C::addLine($l, ['date' => $D(5)->toDateString(), 'category' => 'transport', 'amount' => 40, 'description' => 'Uber to and from the restaurant', 'receipt_ref' => 'UBER-9Q2K']);
            $C::submit($l);
        }
        if (($grace = $u('spa')) && $emp('Grace Mensah')) {
            $as($grace);
            $g = $C::create(null, 'Spa product training – Osu');
            $C::addLine($g, ['date' => $D(2)->toDateString(), 'category' => 'transport', 'amount' => 60, 'description' => 'Trotro & taxi to the supplier training centre', 'receipt_ref' => 'MoMo 22019']);
        }
        if (($hr = $u('hr')) && $emp('Anjali Menon')) {
            $as($hr);
            $h = $C::create(null, 'Airtime – September recruitment calls');
            $C::addLine($h, ['date' => $D(12)->toDateString(), 'category' => 'airtime', 'amount' => 120, 'description' => 'MTN airtime for calling 30 shortlisted candidates', 'receipt_ref' => 'MTN self top-up']);
            $C::submit($h);
            $as($acct);
            $C::reject($h, 'Please attach the MTN top-up SMS or statement screenshot');
        }

        $prevUser ? auth()->setUser($prevUser) : Auth::forgetUser();
        \App\Services\LedgerService::sync();
    }

    protected function receiptPng(string $shop, string $item, float $amount, Carbon $date): \Illuminate\Http\UploadedFile
    {
        $im = imagecreatetruecolor(260, 150);
        imagefill($im, 0, 0, imagecolorallocate($im, 252, 252, 247));
        $ink = imagecolorallocate($im, 30, 41, 59);
        imagestring($im, 4, 12, 10, mb_strimwidth(strtoupper($shop), 0, 28), $ink);
        imagestring($im, 2, 12, 34, $date->format('d/m/Y').'  RECEIPT', $ink);
        imageline($im, 12, 54, 248, 54, $ink);
        imagestring($im, 3, 12, 64, mb_strimwidth($item, 0, 34), $ink);
        imagestring($im, 5, 12, 96, 'TOTAL GHS '.number_format($amount, 2), $ink);
        imagestring($im, 1, 12, 130, 'Thank you - Accra', $ink);
        $path = tempnam(sys_get_temp_dir(), 'rcpt').'.png';
        imagepng($im, $path);
        imagedestroy($im);

        return new \Illuminate\Http\UploadedFile($path, 'receipt.png', 'image/png', null, true);
    }

    protected function seedVouchers(bool $rich, Models\User $user): void
    {
        $G = \App\Services\GiftVoucherService::class;
        $G::ensureDefaults();
        $types = Models\GiftVoucherType::pluck('id', 'name');
        if (! isset($types['Spa Escape'])) {
            $types['Spa Escape'] = Models\GiftVoucherType::create(['name' => 'Spa Escape', 'kind' => 'single', 'face_value' => 5000, 'validity_months' => 9, 'usable_on' => ['folio'], 'color' => '#be185d',
                'terms' => 'A 90-minute signature spa treatment. Book in advance; post the treatment to your room and redeem at the front desk.'])->id;
        }
        if (! $rich) {
            return;
        }
        $modes = Models\PaymentMode::pluck('id', 'type');
        [$cash, $card, $upi] = [$modes['cash'] ?? null, $modes['card'] ?? $modes['cash'] ?? null, $modes['wallet'] ?? $modes['card'] ?? null];
        $today = bdate()->copy();
        // re-date a voucher and its transactions: issued $days ago (expiry from the issue date unless given)
        $back = function (Models\GiftVoucher $v, int $days, ?Carbon $expires = null) use ($today) {
            $issued = $today->copy()->subDays($days);
            $v->update(['issued_on' => $issued->toDateString(), 'expires_on' => ($expires ?? $issued->copy()->addMonthsNoOverflow($v->type->validity_months))->toDateString()]);
            Models\GiftVoucherTransaction::where('gift_voucher_id', $v->id)->where('type', 'issue')->update(['business_date' => $issued->toDateString(), 'created_at' => $issued->copy()->setTime(12, 0)]);

            return $v->fresh('type');
        };
        $sell = fn (string $type, float $amount, $mode, string $buyer, ?string $to = null, ?string $toEmail = null, ?string $msg = null, ?string $phone = null) => $G::sell([
            'gift_voucher_type_id' => $types[$type], 'amount' => $amount, 'payment_mode_id' => $mode, 'purchaser_name' => $buyer, 'purchaser_phone' => $phone ?? '98'.rand(10000000, 99999999),
            'purchaser_email' => strtolower(str_replace(' ', '.', $buyer)).'@example.com', 'recipient_name' => $to, 'recipient_email' => $toEmail, 'message' => $msg, 'payment_ref' => 'SEED-'.rand(1000, 9999)]);
        $dated = fn ($v, string $type, Carbon $d) => Models\GiftVoucherTransaction::where('gift_voucher_id', $v->id)->where('type', $type)->latest('id')->first()?->update(['business_date' => $d->toDateString()]);

        // 1. birthday gift card – partly spent on an in-house guest's folio today
        $v1 = $back($sell('Gift Card', 5000, $card, 'Rohan Mehta', 'Priya Mehta', 'priya.mehta@example.com', 'Happy birthday! Enjoy a weekend on us.'), 40);
        $stay = Models\ReservationRoom::with('folio')->where('status', 'checked_in')->get()->first(fn ($s) => $s->folio && $s->folio->status === 'open' && $s->folio->balance() >= 2000);
        if ($stay) {
            \App\Services\FolioService::payment($stay->folio, 2000, $G::modeId(), $v1->code);
            $v1->update(['guest_id' => $stay->guest_id ?? $stay->reservation?->guest_id]);
        }
        // 2. dinner for two, used in the restaurant five days ago (single use – the small remainder lapses)
        $v2 = $back($sell('Dinner for Two', 3500, $upi, 'Kavya Iyer', 'Arjun & Meera Nair', null, 'Congratulations on the new home!'), 32);
        $rest = Models\Outlet::where('type', 'restaurant')->orderBy('id')->first();
        if ($rest) {
            $d = $today->copy()->subDays(5);
            $o = Models\PosOrder::create(['order_no' => Numbering::next('pos_order'), 'bill_no' => Numbering::next('pos_bill'), 'outlet_id' => $rest->id, 'pos_table_id' => Models\PosTable::where('outlet_id', $rest->id)->value('id'),
                'order_type' => 'dine_in', 'covers' => 2, 'business_date' => $d, 'status' => 'open', 'user_id' => $user->id]);
            foreach (Models\MenuItem::where(fn ($q) => $q->where('outlet_id', $rest->id)->orWhereNull('outlet_id'))->where('price', '>', 0)->orderBy('price')->get() as $it) {
                $o->items()->create(['menu_item_id' => $it->id, 'item_name' => $it->name, 'qty' => 1, 'rate' => $it->price, 'tax_rate' => $it->tax_rate, 'amount' => $it->price, 'kot_no' => 1]);
                $o->recalculate();
                if ($o->total > 2600) {
                    break;
                }
            }
            if ($o->total > 3500) {
                $o->items()->latest('id')->first()->delete();
                $o->recalculate();
            }
            $o->forceFill(['status' => 'settled', 'settlement' => 'payment', 'payment_mode_id' => $G::modeId(), 'settled_at' => $d->copy()->setTime(21, 15), 'settled_by' => $user->id])->save();
            $G::posPayment($o->fresh('outlet'), $v2->code);
            Models\GiftVoucherTransaction::where('gift_voucher_id', $v2->id)->whereIn('type', ['redeem', 'expire'])->update(['business_date' => $d->toDateString()]);
        }
        // 3. untouched stay voucher and spa voucher
        $back($sell('Stay Voucher – One Night', 7500, $card, 'Sanjay Kapoor', 'Neha Kapoor', 'neha.kapoor@example.com', 'For our anniversary getaway.'), 75);
        $back($sell('Spa Escape', 5000, $cash, 'Ananya Bose', 'Ritu Bose'), 12);
        // 4. stored-value card: loaded two months ago, reloaded ten days ago
        $v4 = $back($sell('Stored-Value Card', 5000, $card, 'Vikram Singh'), 60);
        $G::reload($v4, 2000, $upi, 'MOMO-RL-2231');
        $dated($v4, 'reload', $today->copy()->subDays(10));
        // 5. stored card close to expiry, partly used – drives the "expiring soon" alert and reminder
        $v5 = $back($sell('Stored-Value Card', 4000, $cash, 'Deepa Menon', 'Deepa Menon', 'deepa.menon@example.com'), 345, $today->copy()->addDays(18));
        // 6. gift card that lapsed with part of its balance (breakage)
        $v6 = $back($sell('Gift Card', 2500, $cash, 'Farhan Qureshi', 'Zoya Qureshi'), 400, $today->copy()->subDays(35));
        $G::expireDue();
        $dated($v6, 'expire', $today->copy()->subDays(34));
        // 7. suspended – reported lost
        $v7 = $back($sell('Gift Card', 3000, $upi, 'Manish Gupta', 'Rahul Gupta'), 20);
        $G::suspend($v7, 'Reported lost by the purchaser – awaiting confirmation');
        // 8. cancelled & refunded
        $v8 = $back($sell('Gift Card', 2000, $cash, 'Pooja Sharma'), 15);
        $G::cancel($v8, 'Purchaser changed plans – refunded within 7 days', $cash);
        $dated($v8, 'cancel', $today->copy()->subDays(13));
        // 9. more sold gift cards for the register
        foreach ([['Gift Card', 10000, $card, 'Tata Consultancy HR', 'Employee of the Quarter', 50], ['Gift Card', 1500, $upi, 'Sneha Pillai', 'Aditi Pillai', 8], ['Dinner for Two', 3500, $card, 'Harish Rao', 'Lakshmi Rao', 3]] as [$t, $amt, $m, $buyer, $to, $days]) {
            $back($sell($t, $amt, $m, $buyer, $to), $days);
        }
        // 10. promotional campaign – five loyal guests, one already used on a folio; plus a service-recovery dinner
        $promo = $G::issuePromo(['gift_voucher_type_id' => $types['Gift Card'], 'amount' => 1000, 'campaign' => 'Diwali 2026', 'message' => 'Wishing you a sparkling Diwali – a little something for your next visit.'], 5,
            [['Amit Verma', 'amit.verma@example.com'], ['Sunita Reddy', 'sunita.reddy@example.com'], ['Karan Malhotra', 'karan.malhotra@example.com'], ['Isha Agarwal', 'isha.agarwal@example.com'], ['Nikhil Joshi', 'nikhil.joshi@example.com']]);
        foreach ($promo as $p) {
            $back($p, 6, $today->copy()->addMonthsNoOverflow(3));
        }
        $other = Models\ReservationRoom::with('folio')->where('status', 'checked_in')->when($stay, fn ($q) => $q->where('id', '!=', $stay->id))->get()->first(fn ($s) => $s->folio && $s->folio->status === 'open' && $s->folio->balance() >= 1000);
        if ($other) {
            \App\Services\FolioService::payment($other->folio, 1000, $G::modeId(), $promo->first()->code);
        }
        $back($G::issuePromo(['gift_voucher_type_id' => $types['Dinner for Two'], 'campaign' => 'Service recovery – noisy room 305', 'recipient_name' => 'Mr. Thomas George'])->first(), 2);
        \App\Services\LedgerService::sync(true); // back-dated voucher sales / breakage sit outside the incremental window
    }

    protected function seedLedger(bool $rich, Carbon $today): void
    {
        $sys = fn ($k) => Models\AccountHead::sys($k);
        // v2.3: fixed assets come from the asset register's opening (take-on) assets
        $opening = Models\Asset::where('acquired_via', 'opening');
        $faCost = round((float) (clone $opening)->sum('cost'), 2);
        $faAcc = round((float) (clone $opening)->sum('opening_acc_dep'), 2);
        $first = collect([Models\FolioTransaction::min('business_date'), Models\Voucher::min('date'), Models\StockMovement::min('date'), Models\SupplierInvoice::min('doc_date')])->filter()->min();
        $openDate = Carbon::parse($first ?? $today)->subDay()->toDateString();
        $k = $rich ? 1 : 0.3;
        LedgerService::post(['date' => $openDate, 'reference' => 'OB-'.substr($openDate, 0, 4), 'narration' => 'Opening balances brought forward', 'lines' => [
            [$sys('bank'), 2500000 * $k, 'Current account'], [$sys('cash'), 50000 * $k, 'Front office float'],
            [$sys('fixed_assets'), $faCost, 'Asset register take-on'], [$sys('acc_depreciation'), -$faAcc, 'Asset register take-on'],
            [$sys('capital'), -(2550000 * $k + $faCost - $faAcc), null],
        ]], 'manual', null);
        LedgerService::sync(true);
    }

    protected function seedIncomeAudit(bool $rich, Carbon $today): void
    {
        \App\Services\IncomeAuditService::ensureDefaults();
        if (! $rich) {
            return;
        }
        $IA = \App\Services\IncomeAuditService::class;
        $RA = \App\Services\RevenueAuthService::class;
        $prevUser = Auth::user();
        $u = fn (string $n) => Models\User::where('username', $n)->first();
        [$fd, $fom, $acc, $admin, $hk] = [$u('frontdesk'), $u('fom'), $u('accounts'), $u('admin'), $u('housekeeping')];
        $bd = bdate()->copy();
        $d = fn (int $n) => $bd->copy()->addDays($n)->toDateString();
        $y = $d(-1);

        // in-house guests billed to themselves, with last night's tariff posted
        $stays = Models\ReservationRoom::with('reservation', 'folio', 'room')->where('status', 'checked_in')->whereDate('arrival_date', '<=', $y)->whereDate('departure_date', '>', $d(0))
            ->whereHas('reservation', fn ($q) => $q->where('billing_instruction', 'guest')->whereNull('ota_channel_id'))->whereHas('folio', fn ($q) => $q->where('status', 'open'))
            ->orderBy('id')->get()->filter(fn ($s) => Models\FolioTransaction::where('folio_id', $s->folio->id)->where('source_type', 'night')->where('reference', $y)->where('is_void', false)->exists())->values();
        $pool = Models\ReservationRoom::with('folio')->where('status', 'checked_in')->whereNotIn('id', $stays->take(4)->pluck('id'))
            ->whereHas('reservation', fn ($q) => $q->where('billing_instruction', 'guest'))->whereHas('folio', fn ($q) => $q->where('status', 'open'))->orderBy('id')->get()->values();
        $tariff = fn ($s, $date) => Models\FolioTransaction::where('folio_id', $s->folio->id)->where('source_type', 'night')->where('reference', $date)->where('is_void', false)->first();

        // 1) a walk-in given a lower rate at check-in without approval (open rate variance yesterday)
        if ($s = $stays->get(0)) {
            $new = round((float) $s->rate * 0.7, -1);
            $tariff($s, $y)?->update(['amount' => $new, 'tax_amount' => round($new * 0.21, 2)]);
            $s->update(['rate' => $new]);
        }
        // 2) a rate below expected, authorised by the FOM (accepted automatically)
        if ($s = $stays->get(1)) {
            Auth::setUser($fd);
            $a = $RA::request(['kind' => 'rate', 'reservation_room_id' => $s->id, 'date_from' => $y, 'date_to' => $d(0), 'amount' => round((float) $s->rate * 0.8, -1), 'reason' => 'Long-stay regular guest – FOM matched the rate offered by a competitor'], $fd);
            Auth::setUser($fom);
            $a = $RA::approve($a, $fom, 'Matched – keep the guest');
            $tariff($s, $y)?->update(['amount' => $a->amount, 'tax_amount' => round((float) $a->amount * 0.21, 2)]);
        }
        // 3) a complimentary night tonight (service recovery), approved
        if ($s = $stays->get(2)) {
            Auth::setUser($fd);
            $a = $RA::request(['kind' => 'comp', 'reservation_room_id' => $s->id, 'date_from' => $d(0), 'date_to' => $d(0), 'reason' => 'Service recovery – air-conditioning failed overnight, guest moved; GM agreed one night complimentary'], $fd);
            Auth::setUser($admin); // a night's rate is above the FOM's limit – the GM approves
            $RA::approve($a, $admin, 'Approved – apologise again at check-out');
        }
        // 4) a rate request declined
        if ($s = $stays->get(3)) {
            Auth::setUser($fd);
            $a = $RA::request(['kind' => 'rate', 'reservation_room_id' => $s->id, 'amount' => round((float) $s->rate * 0.5, -1), 'reason' => 'Guest asked for half price because of street noise'], $fd);
            $RA::decline($a, $fom, 'Offer a quieter room on the courtyard side instead');
        }
        // 5) a discount waiting for approval
        if ($s = $pool->get(0) ?? $stays->get(3)) {
            Auth::setUser($fd);
            $RA::request(['kind' => 'discount', 'folio_id' => $s->folio->id, 'amount' => 350, 'reason' => 'Restaurant order served cold twice – guest asks for dinner to be written off'], $fd);
        }
        // 6) a large allowance posted yesterday without authorisation
        if ($s = $pool->get(1) ?? $stays->get(0)) {
            Auth::setUser($fd);
            $tx = \App\Services\FolioService::discount($s->folio, 450, 'Allowance – laundry damaged guest shirt');
            $tx->update(['business_date' => $y]);
        }
        // 7) a minibar charge posted yesterday – voided after the day closed (void + figures changed)
        $voidLater = null;
        if ($s = $stays->get(1)) {
            Auth::setUser($fd);
            $voidLater = \App\Services\FolioService::charge($s->folio, 'minibar', 'Minibar – 2 × Club beer, 1 × cashews', 95, true);
            $voidLater->update(['business_date' => $y]);
        }
        // a charge voided on an earlier day (explained in a closed audit)
        if ($s = $stays->get(0)) {
            Auth::setUser($fd);
            $t = \App\Services\FolioService::charge($s->folio, 'laundry', 'Laundry – 3 pieces', 60, true);
            $t->update(['business_date' => $d(-4)]);
            \App\Services\FolioService::void($t, 'Posted to the wrong room – re-posted to the correct guest');
        }

        // housekeeping room count for yesterday: one sleeper (resolved), one unregistered (open)
        $rooms = Models\Room::where('is_active', true)->orderBy('room_no')->get();
        $sheet = [];
        foreach ($rooms as $r) {
            $fo = $IA::foStatus($r);
            $sheet[$r->id] = ['hk' => ['occupied' => 'occupied', 'vacant' => 'vacant', 'out_of_order' => 'ooo'][$fo], 'luggage' => $fo === 'occupied'];
        }
        $occ = $rooms->filter(fn ($r) => $IA::foStatus($r) === 'occupied')->values();
        $vac = $rooms->filter(fn ($r) => $IA::foStatus($r) === 'vacant')->values();
        if ($occ->count() && $vac->count()) {
            $sleeper = $occ->last();
            $unreg = $vac->last();
            $sheet[$sleeper->id] = ['hk' => 'vacant', 'luggage' => false];
            $sheet[$unreg->id] = ['hk' => 'occupied', 'luggage' => true];
            $IA::saveCount($sheet, $hk, $y);
            $IA::resolveCount(Models\RoomCount::whereDate('business_date', $y)->where('room_id', $sleeper->id)->first(), 'guest_out', 'Guest confirmed by phone – on a day trip to Cape Coast, luggage in the wardrobe', $fom);
        }

        // audit days: run the checks for the last seven closed days
        $notes = ['void' => 'Posted to the wrong room and re-posted correctly – checked with the cashier', 'cash_variance' => 'Counted again with the duty manager – shortage deducted per policy',
            'einvoice' => 'GRA portal was down – re-submitted and signed on retry', 'credit_limit' => 'Card pre-authorisation taken for the balance',
            'discount' => 'Laundry damage claim – receipt on file, GM informed', 'open_pos' => 'Bill settled next morning – late function', 'rate_variance' => 'Rate agreed by the FOM – retrospective authorisation filed'];
        Auth::setUser($acc);
        for ($i = 7; $i >= 1; $i--) {
            $day = $IA::day($d(-$i));
            $IA::run($day, $acc);
            if ($i >= 2) {
                foreach (Models\AuditFinding::where('audit_day_id', $day->id)->whereIn('status', ['open', 'escalated'])->get() as $f) {
                    $IA::resolve($f, $f->check === 'rate_variance' ? 'corrected' : 'explained', $notes[$f->check] ?? 'Checked against the source documents – in order', $acc);
                }
                $IA::sign($day, $i === 2 ? 'All exceptions explained – cash shortage to be discussed with the cashier' : null, $acc);
                if ($i >= 3) {
                    Auth::setUser($admin);
                    $IA::close($day, null, $admin);
                    Auth::setUser($acc);
                }
            }
        }
        // yesterday: the auditor starts – void after the day closed, re-run, explain the void, escalate the unauthorised rate
        if ($voidLater) {
            Auth::setUser($fd);
            \App\Services\FolioService::void($voidLater, 'Guest disputes – minibar was already empty on arrival');
            Auth::setUser($acc);
            $day = Models\AuditDay::whereDate('business_date', $y)->first();
            $IA::run($day, $acc);
            $void = Models\AuditFinding::where('audit_day_id', $day->id)->where('check', 'void')->where('status', 'open')->first();
            $void && $IA::resolve($void, 'explained', 'Minibar dispute – housekeeping confirms the minibar was not restocked after the previous guest', $acc);
            $rate = Models\AuditFinding::where('audit_day_id', $day->id)->where('check', 'rate_variance')->where('status', 'open')->first();
            $rate && $IA::resolve($rate, 'escalated', 'Rate reduced at check-in with no authorisation on file – front office cannot say who agreed it', $acc);
        }
        \App\Services\LedgerService::sync(true); // the back-dated tariffs / allowance / voids above sit inside days already posted
        Auth::setUser($prevUser ?? $admin);
    }

    protected function seedBudget(bool $rich, Models\User $user): void
    {
        \App\Services\BudgetService::ensureDefaults();
        if (! $rich) {
            return;
        }
        LedgerService::sync(); // actuals for the variance snapshots
        $B = \App\Services\BudgetService::class;
        $prevUser = auth()->user();
        $h = hotel()->id;
        $accounts = Models\User::where('hotel_id', $h)->where('username', 'accounts')->first() ?? $user;
        Models\Setting::updateOrCreate(['key' => 'budget_live_from'], ['value' => bdate()->copy()->startOfMonth()->toDateString()]);
        Models\Setting::$version++;
        $fy = $B::currentFy();
        // seasonality by fiscal month (Apr … Mar)
        $season = [1 => .9, .85, .85, .9, .9, .95, 1.1, 1.2, 1.3, 1.2, 1.1, 1.0];
        // monthly amount in a "normal" month; true = follows the season. Accounts with posted activity this month are
        // re-sized from it below, so the demo variances tell a story whatever the random demo data produced.
        $plan = [
            '4020' => [160000, true], '4030' => [260000, true], '4040' => [8000, true], '4050' => [5000, true], '4060' => [12000, true],
            '4070' => [10000, false], '4090' => [3000, false], '4500' => [8000, true], '4900' => [-15000, true],
            '5010' => [60000, true], '5020' => [25000, true], '5060' => [10000, true],
            '6010' => [720000, false], '6015' => [26000, false], '6020' => [120000, false], '6030' => [10000, false], '6040' => [40000, false],
            '6070' => [10000, true], '6080' => [60000, true], '6100' => [12000, false], '6110' => [8000, false], '6120' => [8000, true],
            '6130' => [25000, true], '6140' => [5000, false], '6500' => [125000, false],
        ];
        $monthEnd = ['6010', '6015', '6500'];                    // payroll & depreciation post at month-end – keep the plan
        $calib = ['4020' => 1.6, '4030' => 0.7];                  // restaurant behind budget, banquets ahead
        $ids = Models\AccountHead::whereIn('code', array_keys($plan))->pluck('id', 'code');
        $cut = \Illuminate\Support\Carbon::parse(LedgerService::cutoff());
        $cm = $B::fyFor($cut) === $fy ? $B::monthIndex($cut) : 0;
        $elapsed = $cm ? $cut->day / $cut->daysInMonth : 0;
        $act = $cm ? $B::actuals($fy) : [];
        foreach ($plan as $code => [$amt, $seasonal]) {
            $v = (float) ($act[$ids[$code] ?? 0][$cm] ?? 0);
            if ($cm && ! in_array((string) $code, $monthEnd, true) && abs($v) >= 1000) {
                $k = $calib[$code] ?? (str_starts_with((string) $code, '4') ? 1.03 : 0.97);
                $plan[$code][0] = round($v / $elapsed / ($seasonal ? $season[$cm] : 1) * $k, -2);
            }
        }

        auth()->setUser($accounts);
        $b = $B::create(['fiscal_year' => $fy, 'name' => 'Budget '.$B::fyLabel($fy), 'method' => 'blank',
            'notes' => '24 rooms. Occupancy follows the season (monsoon low, Dec peak); ADR rises with demand; two weddings a month in season; salaries flat after the April increments.']);
        // rooms: this month's pace + 4 % occupancy at a 3 % lower rate, phased by season
        $as = $cm ? $B::actualStats($fy)[$cm] : ['sold' => 0];
        $occ0 = $as['sold'] ? $as['occupancy'] * 1.04 : 26;
        $adr0 = $as['sold'] ? $as['adr'] * .97 : 5600;
        $ref = $season[$cm ?: 6];
        $stats = [];
        foreach ($B::months($fy) as $m => $start) {
            $stats[$m] = ['rooms_available' => 24 * $start->daysInMonth, 'occupancy' => round(min(95, $occ0 * $season[$m] / $ref), 1),
                'adr' => round($adr0 * (.85 + .15 * $season[$m]) / (.85 + .15 * $ref))];
        }
        $B::saveStats($b, $stats, true);
        $grid = [];
        foreach ($plan as $code => [$amt, $seasonal]) {
            if (! isset($ids[$code])) {
                continue;
            }
            foreach (range(1, 12) as $m) {
                $grid[$ids[$code]][$m] = round($amt * ($seasonal ? $season[$m] : 1) + ($code === '6020' && $m <= 3 ? 20000 : 0));  // summer power load
            }
        }
        $B::saveGrid($b, $grid, [$ids['6010'] ?? 0 => 'Headcount 38 · 8 % increment from April', $ids['4030'] ?? 0 => 'Weddings Oct–Feb, conferences off-season']);
        $B::submit($b, 'Budget for the year – ready for review');
        auth()->setUser($user);
        $B::approve($b, 'Approved by the board');

        // this month's variances explained
        $period = bdate()->format('Y-m');
        $bva = $B::bva($b->fresh(), $B::monthIndex(bdate()));
        $row = fn ($code) => $bva['rows']->firstWhere('code', $code);
        auth()->setUser($accounts);
        foreach (['4020' => 'Restaurant covers are down while the kitchen refit runs; the à-la-carte menu relaunches on 5 Oct.',
            '4030' => 'Two corporate conferences and a wedding reception confirmed after the budget was set – banquets are ahead for the quarter.',
            '6010' => 'Salaries, PF / ESI and depreciation post at month-end with the payroll run and the depreciation run – this variance closes after month-end.'] as $code => $text) {
            if ($r = $row($code)) {
                $B::comment($b, $r['id'], $period, $text, $r['actual'], $r['budget']);
            }
        }
        auth()->setUser($user);
        $rev = $row('4010');
        $k = $bva['kpis']['month'];
        $B::comment($b, null, $period, sprintf('Room revenue %s budget (%s): occupancy %.1f %% vs %.1f %% budgeted, ADR %s vs %s. Watch F&B – the refit is costing covers.',
            ($rev['var'] ?? 0) >= 0 ? 'ahead of' : 'behind', ($rev['var_pct'] ?? 0) > 0 ? '+'.($rev['var_pct'] ?? 0).' %' : ($rev['var_pct'] ?? 0).' %',
            $k['actual']['occupancy'], $k['budget']['occupancy'], money($k['actual']['adr']), money($k['budget']['adr'])));

        // re-forecast after August, awaiting approval: banquets up, restaurant down for the rest of the year
        auth()->setUser($accounts);
        $through = bdate()->copy()->startOfMonth()->subDay();
        if ($through->gte($B::months($fy)[1])) {
            $f = $B::forecast($b->fresh(), $through->toDateString(), 'Re-forecast after '.$through->format('M Y'));
            if (isset($ids['4030'])) {
                $B::adjust($f, 'account', 15, $ids['4030']);
            }
            if (isset($ids['4020'])) {
                $B::adjust($f, 'account', -10, $ids['4020']);
            }
            $B::submit($f, 'Banquet pipeline +15 %, restaurant −10 % during the refit');
        }

        // next year's first draft
        $B::create(['fiscal_year' => $fy + 1, 'name' => 'Budget '.$B::fyLabel($fy + 1).' – first cut', 'method' => 'copy', 'source_id' => $b->id,
            'growth_income' => 8, 'growth_expense' => 6, 'notes' => 'Copied from this year: rooms +8 % (rate), costs +6 % (inflation). To be refined.']);
        $prevUser ? auth()->setUser($prevUser) : null;
    }

    protected function seedTreasury(bool $rich): void
    {
        if (! $rich) {
            return;
        }
        $TS = \App\Services\TreasuryService::class;
        $prevUser = Auth::user();
        $u = fn (string $n) => Models\User::where('username', $n)->first();
        [$admin, $acc, $fom] = [$u('admin'), $u('accounts'), $u('fom')];
        \App\Services\ChartOfAccounts::ensure();
        $TS::ensureDefaults();
        $sys = fn (string $k) => Models\AccountHead::sys($k);
        $T = bdate()->toDateString();
        $m0 = bdate()->copy()->subMonthNoOverflow()->startOfMonth(); // e.g. 2026-08-01
        Auth::setUser($acc);

        // the register: banks, wallet, card acquirer terms
        $acct = fn (string $key) => Models\TreasuryAccount::where('account_head_id', $sys($key))->first();
        $gcb = $acct('bank');
        $gcb->forceFill(['name' => 'GCB – Operating', 'bank_name' => 'GCB Bank', 'branch' => 'High Street, Accra', 'account_no' => '1011130004528', 'swift' => 'GHCBGHAC', 'min_balance' => 250000, 'overdraft_limit' => 500000])->save();
        $cash = $acct('cash');
        $cash->forceFill(['name' => 'Main Cashier – Cash in Hand', 'min_balance' => 0])->save();
        $card = $acct('card_clearing');
        $card->forceFill(['name' => 'Card Settlements (Visa / Mastercard – Ecobank acquiring)', 'bank_name' => 'Ecobank Ghana', 'settle_days' => 2, 'fee_rate' => 1.5])->save();
        $momo = $acct('wallet_clearing');
        $momo->forceFill(['name' => 'MTN MoMo Merchant Wallet', 'bank_name' => 'MTN Mobile Money', 'account_no' => '0244123987', 'fee_rate' => 1.0, 'min_balance' => 20000])->save();
        $eco = $TS::createAccount(['kind' => 'bank', 'name' => 'Ecobank – Collections', 'bank_name' => 'Ecobank Ghana', 'branch' => 'Ridge, Accra', 'account_no' => '1441000523187', 'swift' => 'ECOCGHAC',
            'currency' => 'GHS', 'min_balance' => 50000, 'overdraft_limit' => 0, 'in_position' => 1, 'is_active' => 1, 'notes' => 'Daily cash & cheque bankings, card settlements, term loan']);
        $ecoHead = $eco->account_head_id;

        // the lowest balance an account reaches from $d to today – a transfer may never take it below zero on any day
        $minFrom = function (int $head, string $d) use ($T): float {
            $bal = (float) Models\GlLine::where("account_head_id", $head)->where("date", "<", $d)->sum(\Illuminate\Support\Facades\DB::raw('debit - credit'));
            $min = PHP_FLOAT_MAX;
            $days = Models\GlLine::where('account_head_id', $head)->whereBetween('date', [$d, $T])->groupBy('date')->orderBy('date')->selectRaw('date, SUM(debit - credit) m')->pluck('m', 'date');
            $min = $bal;
            foreach ($days as $m) {
                $bal += (float) $m;
                $min = min($min, $bal);
            }

            return max(0, round($min, 2));
        };
        $send = function (Models\TreasuryTransfer $t, string $sent, ?string $credited = null, ?float $received = null, ?string $why = null) use ($TS, $acc, $admin, $fom) {
            if ($t->status === 'requested') {
                Auth::setUser($admin);
                $TS::approve($t, $admin);
                Auth::setUser($acc);
            }
            $TS::dispatch($t->fresh(), $fom, $sent);
            if ($credited && $t->fresh()->status === 'in_transit') {
                $TS::complete($t->fresh(), $acc, $credited, $received, null, $why);
            }

            return $t->fresh();
        };

        // an Ecobank term loan for the kitchen & laundry refit, drawn on the 3rd
        \App\Services\LedgerService::post(['date' => $m0->copy()->addDays(2)->toDateString(), 'reference' => 'ECO/TL/2026/118', 'narration' => 'Ecobank term loan drawdown – kitchen & laundry refit (36 months @ 24%)',
            'lines' => [[$ecoHead, 600000, 'Loan proceeds credited'], [$sys('bank_loans'), -600000, 'Ecobank term loan TL-2026-118']]], 'manual', null);

        // company cheques: one cleared, one to bounce, two in hand, two post-dated
        $cos = collect(\App\Services\CreditService::positions())->filter(fn ($p) => $p['outstanding'] > 5000 && empty($p['over_limit']))->sortByDesc('outstanding')->values(); // over-limit accounts keep their credit story
        $co = fn (int $i) => $cos[$i % max(1, $cos->count())]['company'] ?? Models\Company::orderBy('id')->skip($i)->first();
        $chq = function (int $i, string $no, string $bank, string $dated, string $recv, float $amt, ?string $drawer = null) use ($TS, $co, $acc, $sys) {
            $c = $drawer ? null : $co($i);
            $d = ['cheque_no' => $no, 'bank_name' => $bank, 'cheque_date' => $dated, 'received_on' => $recv, 'amount' => $amt];
            $d += $c ? ['company_id' => $c->id, 'drawer' => $c->name] : ['drawer' => $drawer, 'account_head_id' => $sys('rev_space_rental'), 'notes' => 'Hall hire – monthly meeting'];

            return $TS::receiveCheque($d, $acc);
        };
        $c1 = $chq(0, '004417', 'Stanbic Bank Ghana', $m0->copy()->addDays(10)->toDateString(), $m0->copy()->addDays(11)->toDateString(), 18500);
        $c2 = $chq(1, '118203', 'CalBank', $m0->copy()->addDays(19)->toDateString(), $m0->copy()->addDays(20)->toDateString(), 12600);

        // weekly cash bankings to Ecobank: ~45% of the cash above the float, credited the next day
        $float = (float) $TS::cfg('treasury_cash_float');
        $weeks = [];
        for ($d = $m0->copy()->addDays(2); $d->lt(bdate()->copy()->subDays(2)); $d->addDays(7)) {
            $weeks[] = $d->toDateString();
        }
        foreach ($weeks as $i => $d) {
            $avail = $minFrom($cash->account_head_id, $d) - $float;
            $amt = floor(max(0, $avail) * 0.45 / 10) * 10;
            $ids = [];
            if ($d > $c1->received_on->toDateString() && $c1->fresh()->status === 'in_hand') {
                $ids[] = $c1->id;
            }
            if ($d > $c2->received_on->toDateString() && $c2->fresh()->status === 'in_hand') {
                $ids[] = $c2->id;
            }
            if ($amt < 100 && ! $ids) {
                continue;
            }
            $t = $TS::createTransfer(['kind' => 'deposit', 'from_account_id' => $cash->id, 'to_account_id' => $eco->id, 'cash_amount' => $amt, 'date' => $d, 'slip_no' => 'ECO-DS-'.str_pad((string) (4410 + $i), 6, '0', STR_PAD_LEFT),
                'cheque_ids' => $ids, 'via_transit' => true, 'notes' => 'Weekly banking – night safe to Ecobank Ridge'], $fom);
            $short = $i === 2 && $amt > 500;
            $send($t, $d, Carbon::parse($d)->addDay()->toDateString(), $short ? (float) $t->amount - 50 : null, $short ? 'GH₵50 note found counterfeit at the bank count and retained by Ecobank' : null);
        }
        // the cheque from the second company comes back unpaid
        if ($c2->fresh()->status === 'deposited') {
            $TS::bounce($c2->fresh(), $acc, 'Refer to drawer', 50, Carbon::parse($c2->fresh()->left_on)->addDays(3)->toDateString());
        }
        // yesterday's banking is still in transit
        $y = bdate()->copy()->subDay()->toDateString();
        $amt = floor(max(0, $minFrom($cash->account_head_id, $y) - $float) * 0.3 / 10) * 10;
        if ($amt >= 100) {
            $t = $TS::createTransfer(['kind' => 'deposit', 'from_account_id' => $cash->id, 'to_account_id' => $eco->id, 'cash_amount' => $amt, 'date' => $y, 'slip_no' => 'ECO-DS-004499', 'via_transit' => true, 'notes' => 'Weekend takings'], $fom);
            $send($t, $y);
        }

        // card settlements swept fortnightly, 1.5% merchant discount withheld by the acquirer
        foreach ([4, 18, 32, 46] as $k) {
            $d = $m0->copy()->addDays($k);
            if ($d->gt(bdate()->copy()->subDays(2))) {
                break;
            }
            $gross = round($minFrom($card->account_head_id, $d->toDateString()) * 0.3, 2);
            if ($gross < 1000) {
                continue;
            }
            $t = $TS::createTransfer(['kind' => 'sweep', 'from_account_id' => $card->id, 'to_account_id' => $eco->id, 'cash_amount' => $gross, 'fee' => round($gross * 0.015, 2), 'date' => $d->toDateString(),
                'slip_no' => 'ECO-MSC-'.$d->format('md'), 'notes' => 'Card settlement batch'], $acc);
            $send($t, $d->toDateString());
        }
        // waiting for approval: the MoMo sweep and a top-up of the operating account
        $TS::createTransfer(['kind' => 'sweep', 'from_account_id' => $momo->id, 'to_account_id' => $eco->id, 'cash_amount' => min(120000, floor($minFrom($momo->account_head_id, $T) * 0.5)), 'fee' => 0, 'date' => $T, 'notes' => 'Monthly wallet sweep – MTN merchant settlement'], $acc);
        $TS::createTransfer(['kind' => 'transfer', 'from_account_id' => $eco->id, 'to_account_id' => $gcb->id, 'cash_amount' => 300000, 'date' => $T, 'slip_no' => 'ECO-RTGS-0931', 'via_transit' => true, 'notes' => 'Top up GCB operating account before payroll & GRA'], $acc);

        // cheques still in hand and post-dated
        $chq(2, '220981', 'Absa Bank Ghana', bdate()->copy()->subDays(3)->toDateString(), bdate()->copy()->subDays(2)->toDateString(), 7850);
        $chq(0, '000356', 'Fidelity Bank Ghana', bdate()->copy()->subDays(1)->toDateString(), bdate()->copy()->subDay()->toDateString(), 2400, 'Rotary Club of Accra-Airport');
        $chq(3, '731002', 'Zenith Bank Ghana', bdate()->copy()->addDays(3)->toDateString(), bdate()->copy()->subDays(4)->toDateString(), 25000);
        $chq(1, '118240', 'CalBank', bdate()->copy()->addDays(16)->toDateString(), bdate()->copy()->subDays(1)->toDateString(), 12650); // the replacement for the bounced cheque

        // cheque books: GCB (supplier payments already made by bank) and Ecobank (a council permit)
        $bGcb = $TS::addBook($gcb, 100201, 100250, 6, $m0->copy()->subDays(20)->toDateString());
        $pays = Models\SupplierPayment::whereNull('cancelled_at')->orderBy('date')->get()->filter(fn ($p) => \App\Services\LedgerService::modeAccount($p->payment_mode_id) === (int) $gcb->account_head_id)->values();
        foreach ($pays->take(3) as $i => $p) {
            $TS::issueCheque(['cheque_book_id' => $bGcb->id, 'supplier_payment_id' => $p->id, 'memo' => 'Supplier payment'], $acc);
            if ($i === 0) {
                $TS::spoilLeaf($bGcb->fresh(), $acc, 'Amount in words misspelt – leaf cancelled');
            }
        }
        $bEco = $TS::addBook($eco, 5501, 5525, 6, $m0->copy()->addDays(3)->toDateString());
        $TS::issueCheque(['cheque_book_id' => $bEco->id, 'payee' => 'Accra Metropolitan Assembly', 'amount' => 4800, 'account_head_id' => $sys('misc_expense'), 'cheque_date' => bdate()->copy()->subDays(6)->toDateString(),
            'memo' => 'Business operating permit '.bdate()->year.' – hotel & restaurant'], $acc);

        // expected flows the ledger does not know about yet
        $TS::saveLine(['description' => 'Ecobank term loan TL-2026-118 – monthly instalment', 'direction' => 'out', 'category' => 'Financing', 'amount' => 23540,
            'first_date' => bdate()->copy()->addMonthNoOverflow()->day(5)->toDateString(), 'frequency' => 'monthly', 'until_date' => bdate()->copy()->addMonthsNoOverflow(36)->day(5)->toDateString()], $acc);
        $TS::saveLine(['description' => 'Kitchen extraction hood replacement (balance to contractor)', 'direction' => 'out', 'category' => 'Capital expenditure', 'amount' => 85000,
            'first_date' => bdate()->copy()->addWeeks(5)->toDateString(), 'frequency' => 'once'], $acc);
        $TS::saveLine(['description' => 'MTN Ghana conference – 50% deposit', 'direction' => 'in', 'category' => 'Operating', 'amount' => 45000,
            'first_date' => bdate()->copy()->addWeeks(3)->toDateString(), 'frequency' => 'once'], $acc);

        $TS::daily(); // clear the banked cheque, pick up cheques presented in the reconciled statement
        Auth::setUser($prevUser ?? $admin);
    }

    protected function seedClose(bool $rich): void
    {
        if (! $rich) {
            return;
        }
        $CS = \App\Services\CloseService::class;
        $B = \App\Services\BudgetService::class;
        $prevUser = Auth::user();
        $u = fn (string $n) => Models\User::where('username', $n)->first();
        [$admin, $acc] = [$u('admin'), $u('accounts')];
        $sys = fn (string $k) => Models\AccountHead::sys($k);
        \App\Services\ChartOfAccounts::ensure();

        $cur = bdate()->format('Y-m');
        $fy = $B::currentFy();
        $start = Carbon::parse($CS::fyStart($fy - 1))->format('Y-m');          // e.g. 2025-04
        $lastClosed = Carbon::parse($cur.'-01')->subMonthsNoOverflow(2)->format('Y-m'); // e.g. 2026-07
        $prevMonth = $CS::prev($cur);                                             // e.g. 2026-08 – close in progress
        $fyStartM = Carbon::parse($CS::fyStart($fy))->format('Y-m');              // e.g. 2026-04
        $yeMonth = Carbon::parse($CS::fyEnd($fy - 1))->format('Y-m');             // e.g. 2026-03
        Models\Setting::updateOrCreate(['key' => 'close_start'], ['value' => $start]);
        Models\Setting::$version++;

        Auth::setUser($acc);
        $mk = function (array $d) use ($CS, $acc, $admin) {
            $a = $CS::createAccrual($d, $acc);
            if (($d['_approve'] ?? true)) {
                Auth::setUser($admin);
                $CS::decideAccrual($a, $admin, true, $d['_note'] ?? null);
                Auth::setUser($acc);
            }

            return $a->fresh();
        };
        // the annual insurance premium, paid by the owners on the hotel's behalf, sits in prepaid expenses
        \App\Services\LedgerService::post(['date' => $fyStartM.'-02', 'reference' => 'EIC/FB/2026/0417', 'narration' => 'Fire & burglary insurance premium '.$B::fyLabel($fy).' – paid by the owners (capital contribution)',
            'lines' => [[$sys('prepayments'), 48000, 'Enterprise Insurance – policy FB-2026-0417'], [$sys('capital'), -48000, 'Owners – premium paid on the hotel\'s behalf']]], 'manual', null);
        $mk(['type' => 'prepayment', 'description' => 'Fire & burglary insurance – Enterprise Insurance ('.Carbon::parse($fyStartM.'-01')->format('M Y').' – '.Carbon::parse($fyStartM.'-01')->addMonths(11)->format('M Y').')',
            'pl_account_id' => $sys('insurance'), 'amount' => 48000, 'start_period' => $fyStartM, 'months' => 12, 'reference' => 'FB-2026-0417', '_note' => 'Agreed to the policy schedule']);
        $mk(['type' => 'accrual', 'description' => 'External audit fee '.$B::fyLabel($fy - 1).' – KPMG Ghana (engagement letter)', 'pl_account_id' => $sys('professional_fees'),
            'amount' => 30000, 'start_period' => $yeMonth, 'reference' => 'EL-2026-03']);
        $mk(['type' => 'accrual', 'description' => 'ECG electricity – '.Carbon::parse($prevMonth.'-01')->format('F').' consumption, bill due mid-month', 'pl_account_id' => $sys('utility_power'),
            'supplier_id' => Models\Supplier::where('name', 'like', 'City Power%')->value('id'), 'amount' => 18500, 'start_period' => $prevMonth, 'reference' => 'meter 0412-7781', 'notes' => 'Estimate from the daily meter readings (Utilities › Readings)']);
        $mk(['type' => 'deferral', 'description' => 'Ecobank ATM lobby space – 12 months\' rent received in advance', 'pl_account_id' => $sys('rev_space_rental'),
            'amount' => 36000, 'start_period' => $CS::next($cur), 'months' => 12, 'reference' => 'LIC-ATM-2026', '_approve' => false]);

        $rj = function (array $d, bool $approve = true) use ($CS, $acc, $admin) {
            $r = $CS::createRecurring($d, $acc);
            if ($approve) {
                Auth::setUser($admin);
                $CS::decideRecurring($r, $admin, true);
                Auth::setUser($acc);
            }

            return $r;
        };
        $rj(['name' => 'Head-office management fee – 2% of budgeted revenue', 'frequency' => 'monthly', 'start_period' => $fyStartM,
            'lines' => [['account_head_id' => $sys('professional_fees'), 'debit' => 12000, 'memo' => 'Management fee'], ['account_head_id' => $sys('accrued_expenses'), 'credit' => 12000, 'memo' => 'Due to Nowills Hospitality (head office)']]]);
        $rj(['name' => 'Standing accrual – guarding services billed a month in arrears', 'frequency' => 'monthly', 'start_period' => Carbon::parse($cur.'-01')->subMonthsNoOverflow(3)->format('Y-m'), 'auto_reverse' => true,
            'lines' => [['account_head_id' => $sys('misc_expense'), 'debit' => 9800, 'memo' => 'Guarding services'], ['account_head_id' => $sys('accrued_expenses'), 'credit' => 9800, 'memo' => 'Accrued – invoice in arrears']]]);
        $rj(['name' => 'GNFS fire certificate & extinguisher servicing', 'frequency' => 'quarterly', 'start_period' => $CS::next($cur),
            'lines' => [['account_head_id' => $sys('repairs'), 'debit' => 1500], ['account_head_id' => $sys('accrued_expenses'), 'credit' => 1500]]], false);

        $waive = [
            'depreciation' => 'Asset register taken on in June 2026 – depreciation for earlier months is in the opening NBV.',
            'payroll' => 'Payroll processed by the external bureau until July 2026 – journal on file.',
            'statutory' => 'PAYE / SSNIT remitted through the payroll bureau – GRA and SSNIT receipts on file.',
            'bank_rec' => 'Bank reconciled manually in Excel before the module went live – signed copy on file.',
            'controls' => 'Opening-balance timing difference – cleared in the following month.',
            'suspense' => 'Unidentified MoMo receipt – cleared the following month.',
            'claims' => 'Claims approved after month end – immaterial.',
            'income_audit' => 'Income audit adopted in September 2026 – day checks done on paper.',
            'gl_posted' => 'Accepted by the financial controller – see working papers.',
            'period_end' => 'Accepted by the financial controller – see working papers.',
        ];
        $manualNotes = ['Ageing reviewed – no provision needed', 'VAT / NHIL / GETFund and tourism levy agreed to the returns filed', 'GRNI listing reviewed – nothing material', 'Pack reviewed with the GM'];
        $times = [];
        $closeMonth = function (string $m, bool $reopenAfter = false) use ($CS, $acc, $admin, $waive, $manualNotes, &$times) {
            $p = $CS::period($m);
            Auth::setUser($acc);
            $CS::runPostings($p, $acc);
            $CS::runChecks($p, $acc);
            foreach ($p->tasks()->get() as $i => $t) {
                if ($t->kind === 'manual') {
                    $CS::completeTask($t, $acc, $manualNotes[$i % 4] ?? null);
                } elseif (in_array($t->status, ['fail', 'pending'], true)) {
                    $CS::waiveTask($t, $acc, $waive[$t->key] ?? 'Accepted by the financial controller – see working papers.');
                }
            }
            $CS::submit($p->fresh(), $acc, $p->isYearEnd() ? 'Year-end close – audit adjustments posted' : null);
            Auth::setUser($admin);
            $CS::approve($p->fresh(), $admin, $p->isYearEnd() ? 'Year end approved after the external audit fieldwork' : null);
            $times[$m] = ($times[$m] ?? 0) + 1;
            if ($reopenAfter) {
                Auth::setUser($acc);
                $CS::requestReopen($p->fresh(), $acc, 'ECG credit note CN-0511 for May received late – to be posted in May');
                Auth::setUser($admin);
                $CS::approveReopen($p->fresh(), $admin);
                Auth::setUser($acc);
                // the late credit note, dated in the reopened month
                \App\Services\LedgerService::post(['date' => Carbon::parse($m.'-01')->endOfMonth()->toDateString(), 'reference' => 'ECG CN-0511', 'narration' => 'ECG credit note – meter overbilling ('.Carbon::parse($m.'-01')->format('M Y').')',
                    'lines' => [[Models\AccountHead::sys('accrued_expenses'), 2350, 'ECG credit note'], [Models\AccountHead::sys('utility_power'), -2350, 'Electricity – credit note']]], 'manual', null);
            }
        };
        $reopenMonth = Carbon::parse($lastClosed.'-01')->subMonthsNoOverflow(2)->format('Y-m'); // e.g. 2026-05
        for ($m = $start; $m <= $lastClosed; $m = $CS::next($m)) {
            $closeMonth($m, $m === $reopenMonth);
            if ($m === $reopenMonth) {
                $closeMonth($m); // re-closed with the credit note in it
            }
        }

        // back-date the workflow: prepared ~5 days after month end, approved ~8 days after (the year end ~3 weeks, after the audit)
        foreach (Models\ClosePeriod::where('status', 'closed')->get() as $p) {
            $end = $p->end();
            $prep = $end->copy()->addDays($p->isYearEnd() ? 16 : 4 + ($end->month % 3))->setTime(16, 30);
            $closed = $prep->copy()->addDays($p->isYearEnd() ? 5 : 2 + ($end->month % 2))->setTime(10, 15);
            $p->forceFill(['prepared_at' => $prep, 'closed_at' => $closed, 'checked_at' => $prep])->save();
            $evs = $p->events()->reorder('id')->get();
            $n = $evs->count();
            foreach ($evs as $i => $ev) {
                // spread the trail from the first check to the final approval
                $t = $prep->copy()->subHours(3)->addMinutes((int) round(($closed->getTimestamp() - $prep->copy()->subHours(3)->getTimestamp()) / 60 * ($n > 1 ? $i / ($n - 1) : 1)));
                $ev->forceFill(['created_at' => $t])->save();
            }
            $p->tasks()->whereNotNull('done_at')->update(['done_at' => $prep->copy()->subHours(1), 'checked_at' => $prep->copy()->subHours(2)]);
        }
        foreach (Models\FiscalYearClose::all() as $y) {
            $y->forceFill(['closed_at' => Models\ClosePeriod::where('period', Carbon::parse($y->closing_date)->format('Y-m'))->value('closed_at')])->save();
        }

        // last month: checks run, two manual tasks signed off; depreciation not run yet and four period-end postings due
        Auth::setUser($acc);
        $p = $CS::period($prevMonth);
        $CS::runChecks($p, $acc);
        $tasks = $p->tasks()->where('kind', 'manual')->orderBy('sort')->get();
        $CS::completeTask($tasks[0], $acc, 'Ageing reviewed – Asante Events 75 days, chased; no provision yet');
        $CS::completeTask($tasks[1], $acc, 'Agreed to the GRA VAT return and the GTA levy return');
        $CS::period($cur);
        $prevUser ? Auth::setUser($prevUser) : Auth::forgetUser();
    }

    protected function seedReportAutomation(bool $rich): void
    {
        if (! $rich) {
            return;
        }
        $RS = \App\Services\ReportScheduleService::class;
        $K = \App\Services\KpiService::class;
        $prevUser = Auth::user();
        $u = fn (string $n) => Models\User::where('username', $n)->first();
        [$admin, $fom, $acc] = [$u('admin'), $u('fom'), $u('accounts')];

        // saved views
        Auth::setUser($admin);
        $RS::saveView(['report_key' => 'flash', 'name' => "Yesterday's flash", 'range' => 'yesterday', 'is_pinned' => true], $admin);
        $RS::saveView(['report_key' => 'revenue', 'name' => 'Revenue – last 7 days', 'range' => 'last_7', 'is_shared' => true], $admin);
        $RS::saveView(['report_key' => 'collection', 'name' => 'MTD collections', 'range' => 'mtd', 'is_pinned' => true], $acc);
        $arr = $RS::saveView(['report_key' => 'arrivals', 'name' => 'Arrivals – next 7 days', 'range' => 'next_7', 'columns' => ['res', 'guest', 'phone', 'room', 'type', 'arr', 'nights', 'company'],
            'is_shared' => true, 'is_pinned' => true], $fom);

        // schedules
        $flash = $RS::save(['name' => 'Morning flash – GM & owner', 'report_key' => 'flash', 'range' => 'yesterday', 'frequency' => 'daily', 'send_time' => '07:00',
            'recipient_users' => [$admin->id], 'recipient_emails' => 'gm@nowills.example', 'format' => 'both',
            'message' => 'Good morning – yesterday\'s flash. Call the duty manager with any questions.'], $admin);
        $RS::save(['name' => 'Weekly revenue review', 'report_key' => 'revenue', 'range' => 'last_7', 'frequency' => 'weekly', 'weekday' => 1, 'send_time' => '08:00',
            'recipient_users' => [$admin->id], 'recipient_emails' => 'finance@nowills.example', 'format' => 'html'], $admin);
        $RS::save(['name' => 'Month-end city ledger ageing', 'report_key' => 'ar_aging', 'range' => 'last_month', 'frequency' => 'monthly', 'month_day' => 0, 'send_time' => '18:00',
            'recipient_emails' => 'accounts@nowills.example, credit@nowills.example', 'format' => 'csv'], $acc);
        $ia = $RS::save(['name' => 'Income audit exceptions', 'report_key' => 'ia_findings', 'range' => 'yesterday', 'frequency' => 'after_audit',
            'recipient_users' => [$admin->id], 'recipient_emails' => 'controller@nowills.example', 'skip_empty' => true], $admin);
        $arrS = $RS::save(['name' => 'Arrivals for the week (front office)', 'report_view_id' => $arr->id, 'frequency' => 'after_audit',
            'recipient_emails' => 'frontoffice@nowills.example', 'format' => 'both'], $fom);
        $RS::run($flash->fresh(), 'manual', $admin);
        $RS::run($arrS->fresh(), 'after_audit');
        $RS::run($ia->fresh(), 'after_audit');
        // an old schedule to a private mailbox – blocked once the domain allow-list is switched on, auto-paused after 5 failures
        $old = Models\ReportSchedule::create(['user_id' => $admin->id, 'name' => "Owner's daily pack (old)", 'report_key' => 'flash', 'range' => 'yesterday',
            'frequency' => 'daily', 'send_time' => '09:00', 'format' => 'both', 'recipient_emails' => 'hotelowner@hotmail.com', 'is_active' => true]);
        $RS::saveSettings(['rpt_email_domains' => 'nowills.example, example.com']);
        for ($i = 0; $i < 5; $i++) {
            $RS::run($old->fresh());
        }

        // KPI alerts
        $alerts = [
            ['name' => 'Low occupancy', 'metric' => 'occ', 'operator' => 'below', 'threshold' => 60, 'severity' => 'critical', 'channels' => ['email']],
            ['name' => 'ADR under target', 'metric' => 'adr', 'operator' => 'below', 'threshold' => 3000, 'severity' => 'warning', 'channels' => ['email']],
            ['name' => 'Weak daily collections', 'metric' => 'collect', 'operator' => 'below', 'threshold' => 20000, 'severity' => 'warning', 'channels' => ['email', 'sms'], 'recipient_phones' => '+233244000111'],
            ['name' => 'Income audit backlog', 'metric' => 'open_exceptions', 'operator' => 'above', 'threshold' => 5, 'severity' => 'warning', 'channels' => []],
            ['name' => 'Soft tomorrow', 'metric' => 'otb_tomorrow', 'operator' => 'below', 'threshold' => 40, 'severity' => 'info', 'channels' => []],
            ['name' => 'Rooms out of order', 'metric' => 'ooo_rooms', 'operator' => 'above', 'threshold' => 1, 'severity' => 'info', 'channels' => []],
            ['name' => 'City ledger over 60 days', 'metric' => 'ar_over_60', 'operator' => 'above', 'threshold' => 50000, 'severity' => 'critical', 'channels' => ['email'], 'recipient_emails' => 'credit@nowills.example'],
            ['name' => 'Tenant arrears', 'metric' => 'lease_arrears', 'operator' => 'above', 'threshold' => 10000, 'severity' => 'warning', 'channels' => []],
        ];
        foreach ($alerts as $a) {
            $K::save($a + ['recipient_users' => [$admin->id]], $admin);
        }
        $K::evaluate(bdate()->copy()->subDays(2));
        $K::evaluate(bdate()->copy()->subDay());
        if ($ev = Models\KpiAlertEvent::where('business_date', bdate()->copy()->subDays(2)->toDateString())->orderBy('id')->first()) {
            $K::acknowledge($ev, $fom, 'Discussed at the morning briefing – sales team calling corporate accounts.');
        }
        Auth::setUser($prevUser ?? $admin);
    }

    protected function seedPayables(Models\Hotel $hotel, bool $rich, Carbon $today, Models\User $user): void
    {
        $heads = Models\AccountHead::pluck('id', 'name');
        $stores = Models\Store::pluck('id', 'type');
        $bank = Models\PaymentMode::where('type', 'bank')->value('id');
        $cash = Models\PaymentMode::where('type', 'cash')->value('id');
        $sup = Models\Supplier::pluck('id', 'name');
        Models\Supplier::whereKey($sup['Metro Cash & Carry'])->update(['payment_terms_days' => 15, 'expense_head_id' => $heads['Purchases – Food'] ?? null, 'bank_details' => "GCB Bank · A/c 1011130012345 · Sort code 040101"]);
        Models\Supplier::whereKey($sup['FreshFarm Foods'])->update(['payment_terms_days' => 7, 'expense_head_id' => $heads['Purchases – Food'] ?? null, 'bank_details' => "MoMo: 024 412 3456 (FreshFarm Foods)"]);
        Models\Supplier::whereKey($sup['CleanPro Supplies'])->update(['payment_terms_days' => 30, 'expense_head_id' => $heads['Housekeeping Supplies'] ?? null]);
        $power = Models\Supplier::create(['wht_category' => 'none', 'name' => 'City Power Distribution Co.', 'contact_person' => 'Billing desk', 'phone' => '1912', 'payment_terms_days' => 15, 'expense_head_id' => $heads['Electricity'] ?? null]);
        $items = Models\InventoryItem::orderBy('id')->get()->keyBy('name');

        $receive = function (string $supplier, string $store, int $daysAgo, array $lines) use ($today, $user, $stores, $sup, $items) {
            $date = $today->copy()->subDays($daysAgo);
            $po = Models\PurchaseOrder::create(['po_no' => Numbering::next('po'), 'supplier_id' => $sup[$supplier], 'store_id' => $stores[$store], 'po_date' => $date->copy()->subDays(2), 'expected_date' => $date, 'status' => 'received', 'created_by' => $user->id]);
            foreach ($lines as $name => [$qty, $rate]) {
                $it = $items[$name];
                $po->items()->create(['inventory_item_id' => $it->id, 'qty' => $qty, 'rate' => $rate, 'tax_rate' => 20, 'amount' => $qty * $rate]);
                Models\StockMovement::create(['inventory_item_id' => $it->id, 'store_id' => $stores[$store], 'date' => $date, 'type' => 'purchase', 'qty' => $qty, 'rate' => $rate, 'reference' => $po->po_no, 'user_id' => $user->id]);
                $it->update(['last_cost' => $rate]);
            }
            $sub = (float) $po->items()->sum('amount');
            $po->forceFill(['subtotal' => $sub, 'tax' => round($sub * .20, 2), 'total' => round($sub * 1.20, 2), 'received_at' => $date->copy()->setTime(11, 30)])->save();

            return $po;
        };
        $k = $rich ? 1 : 0.5;

        // 1. matched bill, paid in full
        $po1 = $receive('FreshFarm Foods', 'kitchen', 12, ['Paneer' => [round(12 * $k), 380], 'Chicken' => [round(20 * $k), 245]]);
        $b1 = ApService::billFromReceipt($po1, ['supplier_ref' => 'FF/2026/0412', 'doc_date' => $today->copy()->subDays(12)->toDateString()]);
        ApService::pay(Models\Supplier::find($po1->supplier_id), ['date' => $today->copy()->subDays(5)->toDateString(), 'payment_mode_id' => $bank, 'amount' => $b1->total, 'reference' => 'GIP 88231'], [$b1->id => $b1->total]);

        // 2. bill with an unmatched price, uninvoiced receipt
        $po4 = $receive('Metro Cash & Carry', 'kitchen', 2, ['Basmati Rice' => [round(50 * $k), 96], 'Refined Oil' => [round(30 * $k), 158]]);
        if (! $rich) {
            return;
        }
        $accounts = Models\User::where('hotel_id', $hotel->id)->where('username', 'accounts')->first();
        Auth::setUser($accounts ?? $user);
        // 3. price variance (+1.9%): held, approved by the administrator, part-paid, then a quality debit note
        $po2 = $receive('Metro Cash & Carry', 'kitchen', 8, ['Basmati Rice' => [40, 95], 'Refined Oil' => [25, 160], 'Mineral Water 1L' => [240, 14]]);
        $b2 = ApService::billFromReceipt($po2, ['supplier_ref' => 'MCC-INV-77120', 'doc_date' => $today->copy()->subDays(8)->toDateString(), 'subtotal' => round($po2->subtotal * 1.019, 2), 'tax' => round($po2->tax * 1.019, 2)]);
        // 4. price variance (+6%): left on hold for approval
        $po3 = $receive('CleanPro Supplies', 'housekeeping', 4, ['Floor Cleaner 5L' => [10, 480], 'Bath Towel' => [20, 320]]);
        ApService::billFromReceipt($po3, ['supplier_ref' => 'CP/1187', 'doc_date' => $today->copy()->subDays(4)->toDateString(), 'subtotal' => round($po3->subtotal * 1.06, 2), 'tax' => round($po3->tax * 1.06, 2), 'notes' => 'Towel rate on invoice 340 vs PO 320']);
        // 5. overdue utility bill (25 days past due) and an older repairs bill, part-paid (≈50 days past due)
        ApService::expenseBill($power, ['supplier_ref' => 'CPD-'.$today->copy()->subDays(40)->format('ym').'-55310', 'doc_date' => $today->copy()->subDays(40)->toDateString(),
            'lines' => [['account_head_id' => $heads['Electricity'], 'description' => 'Electricity – HT connection, 18,400 kWh', 'amount' => 148600, 'tax_rate' => 0], ['account_head_id' => $heads['Electricity'], 'description' => 'Fixed / demand charges', 'amount' => 12500, 'tax_rate' => 20]]]);
        $hvac = Models\Supplier::create(['name' => 'CoolAir HVAC Services', 'contact_person' => 'Joseph', 'phone' => '+91 98450 22110', 'payment_terms_days' => 30, 'expense_head_id' => $heads['Repairs & Maintenance'] ?? null, 'tax_number' => 'C0024410812']);
        $b5 = ApService::expenseBill($hvac, ['supplier_ref' => 'CA/AMC/219', 'doc_date' => $today->copy()->subDays(80)->toDateString(),
            'lines' => [['account_head_id' => $heads['Repairs & Maintenance'], 'description' => 'Chiller service & gas top-up', 'amount' => 42000, 'tax_rate' => 20], ['account_head_id' => $heads['Repairs & Maintenance'], 'description' => 'AHU filter replacement (12 nos)', 'amount' => 9600, 'tax_rate' => 20]]]);
        Auth::setUser($user);
        ApService::approve($b2, 'Rice price increase confirmed by purchase manager');
        $b2 = ApService::debitNote(Models\Supplier::find($po2->supplier_id), ['against_invoice_id' => $b2->id, 'supplier_ref' => 'MCC-CN-3021', 'doc_date' => $today->copy()->subDays(6)->toDateString(),
            'lines' => [['description' => '2 kg basmati rice – damaged bags', 'amount' => 190, 'tax_rate' => 20]], 'notes' => 'Damaged bags returned with the driver'])->againstInvoice;
        ApService::pay(Models\Supplier::find($po2->supplier_id), ['date' => $today->copy()->subDays(3)->toDateString(), 'payment_mode_id' => $bank, 'amount' => 5000, 'reference' => 'GIP 88415'], [$b2->id => 5000]);
        ApService::pay($hvac, ['date' => $today->copy()->subDays(30)->toDateString(), 'payment_mode_id' => $bank, 'amount' => 30000, 'reference' => 'CHQ 004512'], [$b5->id => 30000]);
        // 6. advance to CleanPro while its bill is on hold
        ApService::pay(Models\Supplier::find($po3->supplier_id), ['date' => $today->copy()->subDays(1)->toDateString(), 'payment_mode_id' => $cash, 'amount' => 3000, 'reference' => 'Advance', 'notes' => 'Advance against towel order']);
        unset($po4);
    }

    protected function seedApForeign(bool $rich, Carbon $today, Models\User $user): void
    {
        if (! $rich || ! ForexService::enabled()) {
            return;
        }
        $heads = Models\AccountHead::pluck('id', 'name');
        $otaHead = $heads['Marketing & OTA Commission'] ?? $heads['Agent Commission'] ?? null;
        $feeHead = $heads['Management Fees – Base'] ?? $heads['Audit & Professional Fees'] ?? null;
        $eurDate = $today->copy()->subDays(3)->toDateString();
        $usdDate = $today->copy()->subDays(9)->toDateString();
        if (! $otaHead || ! $feeHead || ! ForexService::boardRate('EUR', $eurDate) || ! ForexService::boardRate('USD', $usdDate)) {
            return;
        }
        Auth::setUser($user);
        $bank = Models\PaymentMode::where('name', 'like', '%Bank%')->value('id');
        // v7.53–v7.54 foreign-currency AP evidence: two bills in the supplier's own currency, booked at the board for their invoice date: an OTA's
        //    commission invoice from its Amsterdam entity in euros, and an international operator's base management fee
        //    in dollars, part-paid, so Accounts payable › Ageing shows what settling the rest would cost at today's board
        $ota = Models\Supplier::create(['wht_category' => 'none', 'name' => 'Booking.com B.V.', 'contact_person' => 'Partner settlements',
            'email' => 'invoices@booking.example', 'address' => 'Keizersgracht 597, 1017 DR Amsterdam, Netherlands', 'payment_terms_days' => 14, 'is_local' => false,
            'expense_head_id' => $otaHead ?? null]);
        ApService::expenseBill($ota, ['supplier_ref' => 'BCOM-INV-'.$today->copy()->subDays(3)->format('Ym').'-88213', 'currency' => 'EUR',
            'doc_date' => $today->copy()->subDays(3)->toDateString(),
            'lines' => [['account_head_id' => $otaHead, 'description' => 'Commission on 41 reservations, Sep 2026', 'amount' => 4180.55, 'tax_rate' => 0]],
            'notes' => 'Billed in EUR by the Amsterdam entity and settled monthly through the bank.']);
        $operator = Models\Supplier::create(['wht_category' => 'none', 'name' => 'Meridian Hotels International Ltd.', 'contact_person' => 'Owner services',
            'email' => 'fees@meridianhotels.example', 'address' => '110 High Holborn, London WC1V 6QT, United Kingdom', 'payment_terms_days' => 30, 'is_local' => false,
            'expense_head_id' => $feeHead]);
        $mgmt = ApService::expenseBill($operator, ['supplier_ref' => 'MHI/BMF/2026-09', 'currency' => 'USD',
            'doc_date' => $today->copy()->subDays(9)->toDateString(),
            'lines' => [['account_head_id' => $feeHead, 'description' => 'Base management fee, 3% of gross revenue, Sep 2026', 'amount' => 6250, 'tax_rate' => 0],
                ['account_head_id' => $heads['Internet & Telephone'] ?? $feeHead, 'description' => 'Reservation platform and channel-manager licence', 'amount' => 480, 'tax_rate' => 0]],
            'notes' => 'Billed in USD under the management agreement; paid from the dollar account.']);
        ApService::pay($operator, ['date' => $today->copy()->subDays(2)->toDateString(), 'payment_mode_id' => $bank, 'amount' => 40000, 'reference' => 'TT 558214'], [$mgmt->id => 40000]);
        // v7.54 …and a slice of what is left is being settled in the dollars themselves: USD 1,000 out of the dollar
        //    account at the board for the payment date. That costs more cedis than the slice of the bill was booked at,
        //    and the difference is a realised exchange loss, posted to Exchange Gain / Loss rather than left to distort
        //    what the supplier is owed. Accounts payable › Payments shows the bank's figure beside the cedis.
        if (ForexService::apPay()) {
            $payDate = $today->copy()->subDay()->toDateString();
            $slice = 1000.0;
            $alloc = round($slice * (float) $mgmt->fx_rate, 2);
            if (ForexService::apPayRate('USD', $payDate) && $alloc <= $mgmt->fresh()->outstanding()) {
                ApService::pay($operator, ['date' => $payDate, 'payment_mode_id' => $bank, 'currency' => 'USD', 'fx_amount' => $slice,
                    'reference' => 'TT 771204', 'notes' => 'Part-settlement from the dollar account at the rate the bank used.'], [$mgmt->id => $alloc]);
            }
        }
    }

    protected function seedOnlinePayments(Models\Hotel $hotel, bool $rich, Carbon $today): void
    {
        foreach (['pay_enabled' => '1', 'pay_provider' => 'demo', 'pay_link_hours' => '72', 'pay_booking_deposit' => $rich ? 'first_night' : 'none',
            'pay_booking_hold_minutes' => '60', 'pay_portal_enabled' => '1', 'pay_receipt_email' => '1'] as $k => $v) {
            Models\Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        }
        Models\Setting::$version++;
        \App\Services\OnlinePaymentService::ensureDefaults();
        if (! $rich) {
            return;
        }
        $S = \App\Services\OnlinePaymentService::class;
        $fd = Models\User::where('username', 'frontdesk')->first();
        Auth::login($fd);
        $back = function (Models\OnlinePayment $p, Carbon $created, ?Carbon $paid = null) {
            $shift = (int) round(now()->diffInSeconds($created, false));
            $p->forceFill(['created_at' => $created, 'updated_at' => $paid ?? $created, 'sent_at' => $p->sent_at ? $created->copy()->addMinute() : null,
                'expires_at' => $p->expires_at?->copy()->addSeconds($shift)] + ($paid ? ['paid_at' => $paid] : []))->saveQuietly();
            $evs = $p->events()->orderBy('id')->get();
            foreach ($evs as $i => $e) {
                $e->forceFill(['created_at' => $i === 0 ? $created : ($paid ?? $created)->copy()->addSeconds($i)])->saveQuietly();
            }
            if ($paid && $p->folio_transaction_id) {
                Models\FolioTransaction::whereKey($p->folio_transaction_id)->update(['business_date' => $paid->toDateString(), 'created_at' => $paid, 'updated_at' => $paid]);
            }
        };
        $payDemo = function (Models\OnlinePayment $p, string $channel) use ($S) {
            $S::start($p->fresh());
            $p = $p->fresh();

            return $S::handleReturn($p, ['ref' => $p->provider_ref, 'result' => 'success', 'channel' => $channel, 'sig' => \App\Services\Payments\DemoGateway::sign($p, $p->provider_ref, 'success', $channel)]);
        };
        $upcoming = Models\Reservation::with('guest')->where('status', 'confirmed')->whereBetween('arrival_date', [$today->copy()->addDays(3)->toDateString(), $today->copy()->addDays(30)->toDateString()])
            ->whereDoesntHave('folios')->whereNull('company_id')->orderBy('arrival_date')->orderBy('id')->get()->filter(fn ($r) => $r->guest?->email)->values();

        // paid deposits 6, 4 and 2 days ago (card, mobile money, card); the last one partly refunded yesterday
        $paidPl = [];
        foreach ([[0, 6, 'card'], [1, 4, 'mobile_money'], [2, 2, 'card']] as [$i, $ago, $ch]) {
            if (! ($r = $upcoming[$i] ?? null)) {
                continue;
            }
            $p = $S::create(['amount' => $S::suggest($r, null), 'hours' => 72], $r);
            $S::send($p, ['email']);
            $p = $payDemo($p, $ch);
            $back($p, $today->copy()->subDays($ago)->setTime(11, 5), $today->copy()->subDays($ago)->setTime(13, 40 + $i));
            $paidPl[] = $p->fresh();
        }
        if ($p = $paidPl[2] ?? null) {
            $half = round((float) $p->amount / 2, 2);
            $rf = $S::refund($p, $half, 'Stay shortened by one night – guest request');
            $y = $today->copy()->subDay()->setTime(10, 15);
            $rf->forceFill(['created_at' => $y])->saveQuietly();
            Models\FolioTransaction::whereKey($rf->folio_transaction_id)->update(['business_date' => $y->toDateString(), 'created_at' => $y]);
            $p->events()->where('event', 'refunded')->update(['created_at' => $y]);
        }
        // in-house guest paid the bill balance from the mobile portal yesterday evening
        $stay = Models\ReservationRoom::where('status', 'checked_in')->whereNotNull('room_id')->orderBy('id')->get()
            ->first(fn ($s) => ($f = Models\Folio::where('reservation_room_id', $s->id)->where('status', 'open')->first()) && $f->totals()['balance'] > 1000);
        if ($stay && ($f = Models\Folio::where('reservation_room_id', $stay->id)->where('status', 'open')->first())) {
            Auth::logout();
            $p = $S::create(['amount' => min(8000, round((float) $f->totals()['balance'], 2)), 'purpose' => 'balance', 'hours' => 24, 'description' => 'Room '.$stay->room?->room_no.' – bill balance'], null, $f);
            $p = $payDemo($p, 'mobile_money');
            $back($p, $today->copy()->subDay()->setTime(19, 20), $today->copy()->subDay()->setTime(19, 23));
            Auth::login($fd);
        }
        // awaiting payment (sent by e-mail 5 hours ago), an expired and a cancelled link
        if ($r = $upcoming[3] ?? null) {
            $p = $S::create(['amount' => $S::suggest($r, null), 'hours' => 72], $r);
            $S::send($p, ['email']);
            $back($p, now()->subHours(5));
        }
        if ($r = $upcoming[4] ?? null) {
            $p = $S::create(['amount' => $S::suggest($r, null), 'hours' => 48], $r);
            $S::send($p, ['email']);
            $back($p, now()->subDays(3));
            $S::expire();
        }
        if ($r = $upcoming[5] ?? null) {
            $p = $S::create(['amount' => $S::suggest($r, null), 'hours' => 72], $r);
            $S::cancel($p, 'Guest paid the advance by bank transfer instead');
            $back($p, now()->subDays(2));
        }
        // paid a different amount – needs review (not posted)
        if ($r = $upcoming[6] ?? null) {
            $p = $S::create(['amount' => $S::suggest($r, null), 'hours' => 72], $r);
            $S::start($p->fresh());
            $p = $S::complete($p->fresh(), ['state' => 'paid', 'amount' => round((float) $p->amount - 500, 2), 'currency' => $p->currency, 'payment_id' => 'DEMO-7F31C2A9D0', 'fee' => null, 'channel' => 'card'], 'return');
            $back($p, now()->subHours(9), now()->subHours(8));
        }
        // online booking on hold, deposit link open for 45 more minutes
        $type = Models\RoomType::where('code', 'DLX')->first();
        if ($type) {
            Auth::logout();
            $arr = $today->copy()->addDays(21);
            $quote = \App\Services\RateService::quote($type, $arr, $arr->copy()->addDays(2));
            $g = Models\Guest::create(['first_name' => 'Akosua', 'last_name' => 'Frimpong', 'email' => 'akosua.frimpong@example.com', 'phone' => '+233244555019']);
            $res = Models\Reservation::create(['reservation_no' => Numbering::next('reservation'), 'guest_id' => $g->id, 'business_source_id' => Models\BusinessSource::where('type', 'booking_engine')->value('id'),
                'arrival_date' => $arr, 'departure_date' => $arr->copy()->addDays(2), 'adults' => 2, 'children' => 0, 'status' => 'tentative', 'purpose' => 'Leisure']);
            $res->rooms()->create(['room_type_id' => $type->id, 'guest_id' => $g->id, 'arrival_date' => $arr, 'departure_date' => $arr->copy()->addDays(2), 'rate' => $quote['average'], 'adults' => 2, 'status' => 'reserved']);
            $p = $S::create(['amount' => (float) $quote['nights'][0]['rate'], 'purpose' => 'booking', 'minutes' => 45], $res);
            $back($p, now()->subMinutes(15));
            Auth::login($fd);
        }
        // payout 2 days ago: the two oldest deposits, less 1.95 % fees, into the bank account
        $settled = collect($paidPl)->take(2);
        if ($settled->count()) {
            $gross = round($settled->sum(fn ($p) => $p->received()), 2);
            $S::recordPayout(['date' => $today->copy()->subDays(2)->toDateString(), 'provider' => 'demo', 'gross' => $gross, 'fees' => round($gross * 0.0195, 2),
                'payment_mode_id' => Models\PaymentMode::where('type', 'bank')->value('id'), 'reference' => 'STL-'.$today->copy()->subDays(2)->format('Ymd'), 'notes' => 'Settlement for '.$settled->pluck('pay_no')->implode(', ')]);
        }
        Auth::logout();
    }

    protected function seedGst(bool $rich, Carbon $today, Models\User $user): void
    {
        $G = \App\Services\GstService::class;
        $E = \App\Services\EInvoiceService::class;
        $G::ensureDefaults();
        if (! $rich) {
            $G::backfill();

            return;
        }
        $set = function (array $kv) {
            foreach ($kv as $k => $v) {
                Models\Setting::updateOrCreate(['key' => $k], ['value' => $v]);
            }
            Models\Setting::$version++;
        };
        $set(['gst_einvoice_enabled' => '1', 'gst_einvoice_driver' => 'sandbox', 'gst_einvoice_auto' => '1']);
        // invoices issued while seeding: date them by when they were closed, freeze the buyer
        Models\Folio::whereNotNull('invoice_no')->update(['invoice_date' => null]);
        $G::backfill();
        // past invoices were stamped by E-VAT when issued (invoices before their credit notes)
        foreach (Models\Folio::where('status', 'closed')->whereNotNull('invoice_no')->whereNull('irn_status')->orderByRaw("type = 'credit_note'")->orderBy('invoice_date')->orderBy('id')->get() as $f) {
            if ($E::generate($f, $user->id)) {
                $at = $f->invoice_date->copy()->setTime(12, 5)->addMinutes($f->id % 50);
                $f->forceFill(['irn_ack_at' => $at])->save();
                Models\EinvoiceLog::where('folio_id', $f->id)->update(['created_at' => $at]);
            }
        }
        $comp = fn (string $n) => Models\Company::where('name', $n)->first();
        $guest = fn (string $f) => Models\Guest::where('first_name', $f)->orderBy('id')->first();
        $bank = Models\PaymentMode::where('type', 'bank')->value('id');
        $cash = Models\PaymentMode::where('type', 'cash')->value('id');

        // Yatra – the hotel's own TIN was keyed on the company profile: E-VAT refuses the invoice until the profile is fixed
        $yatra = $comp('Yatra Travels');
        if ($yatra && ($g = $guest('Aisha'))) {
            $yatra->update(['tax_number' => $G::hotelTin()]);
            $f = FolioService::openStandalone(['guest_id' => $g->id, 'company_id' => $yatra->id, 'type' => 'walk_in']);
            FolioService::charge($f, 'banquet', 'Conference room – half day (Yatra partner meet)', 18000);
            FolioService::charge($f, 'fnb', 'Working lunch – 20 pax', 9000);
            FolioService::payment($f, $f->balance(), $bank, 'GHIPSS YTR-2291');
            FolioService::close($f->fresh());
        }

        // billed to the wrong company → cancelled before E-VAT stamping, folio re-opened and re-issued to TCS
        $inf = $comp('Infosys Technologies');
        $tcs = $comp('Tata Consultancy Services');
        if ($inf && $tcs && ($g = $guest('Kavita'))) {
            $set(['gst_einvoice_auto' => '0']);
            $f = FolioService::openStandalone(['guest_id' => $g->id, 'company_id' => $inf->id, 'type' => 'walk_in']);
            FolioService::charge($f, 'banquet', 'Board room – full day', 24000);
            FolioService::charge($f, 'fnb', 'High tea – 12 pax', 4800);
            FolioService::payment($f, $f->balance(), $bank, 'GHIPSS TCS-88120');
            FolioService::close($f->fresh());
            $G::cancel($f->fresh(), '2', 'Billed to Infosys instead of TCS', $user->id);
            $set(['gst_einvoice_auto' => '1']);
            $f = $f->fresh();
            $f->update(['company_id' => $tcs->id]);
            FolioService::close($f->fresh());
        }

        // B2B credit note: contract rate applied late on Infosys' latest invoice – credited to the company account
        $inv = Models\Folio::where('company_id', $inf?->id)->where('supply_type', 'B2B')->where('status', 'closed')->where('type', '!=', 'credit_note')
            ->where('irn_status', 'generated')->where('invoice_date', '>=', $today->copy()->startOfMonth()->toDateString())->orderByDesc('invoice_date')->first();
        if ($inv && ($line = $G::creditable($inv)->sortByDesc('amount')->first())) {
            $G::issueCreditNote($inv, [$line->id => round(min((float) $line->amount, 12000) * 0.1, 2)], 'rate', 'Corporate contract rate (10% off) not applied', 'city_ledger', null, $user->id);
        }

        // B2C refund credit note: service complaint on a recent guest invoice
        $b2c = Models\Folio::where('supply_type', 'B2C')->where('status', 'closed')->where('type', 'guest')->where('invoice_date', '>=', $today->copy()->startOfMonth()->toDateString())
            ->whereHas('transactions', fn ($q) => $q->where('category', 'room')->where('type', 'charge')->where('is_void', false)->where('amount', '>=', 2000))->orderByDesc('invoice_date')->orderByDesc('id')->first();
        if ($b2c) {
            $line = $G::creditable($b2c)->where('category', 'room')->sortByDesc('amount')->first();
            $G::issueCreditNote($b2c, [$line->id => 1000], 'deficiency', 'Air-conditioning fault on the second night', 'refund', $cash ?? $bank, $user->id);
        }

        // earlier months' VAT returns filed on the GRA Taxpayers' Portal
        for ($m = 4; $m >= 1; $m--) {
            $p = $today->copy()->startOfMonth()->subMonths($m);
            if (! Models\GstPeriod::where('period', $p->format('Y-m'))->exists()) {
                try { // a month the GRA checks would still block (e.g. restaurant bills not yet stamped) is left open
                    $G::file($p->format('Y-m'), 'GRA/VAT/'.$p->format('Y/m').'/'.str_pad((string) (41170 + $m * 313), 6, '0', STR_PAD_LEFT), $G::returnDue($p->format('Y-m'))->subDays(3)->toDateString(), $user->id);
                } catch (\Illuminate\Validation\ValidationException) {
                }
            }
        }
    }

    protected function seedStatutory(bool $rich, Carbon $today): void
    {
        foreach (\App\Services\StatutoryService::DEFAULTS as $k => $v) {
            Models\Setting::firstOrCreate(['key' => $k], ['value' => $v]);
        }
        if (! $rich) {
            return;
        }
        foreach (['stat_ssnit_employer_no' => '801-0419221', 'stat_tier2_trustee' => 'Coastal Pension Trustees Ltd', 'stat_tier2_scheme' => 'CPT-OPS-T2-0187'] as $k => $v) {
            Models\Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        }
        Models\Setting::$version++;
        $bank = Models\PaymentMode::where('type', 'bank')->where('is_active', true)->value('id');
        $prev = auth()->user();
        if ($u = Models\User::where('username', 'hr')->where('hotel_id', \App\Support\Tenant::id())->first()) {
            auth()->setUser($u);
        }
        $m2 = $today->copy()->startOfMonth()->subMonthsNoOverflow(2);
        $m1 = $today->copy()->startOfMonth()->subMonthNoOverflow();
        // [period, kind, paid on (day of the following month), reference]
        $plan = [[$m2, 'ssnit', 12, 'SSNIT/'.$m2->format('Ym').'/77310'], [$m2, 'tier2', 13, 'CPT/'.$m2->format('Ym').'/0187'], [$m2, 'paye', 14, 'GRA-PAYE-'.$m2->format('Ym').'-51209'],
            [$m1, 'ssnit', 11, 'SSNIT/'.$m1->format('Ym').'/80442'], [$m1, 'paye', 14, 'GRA-PAYE-'.$m1->format('Ym').'-55861']];
        foreach ($plan as [$m, $kind, $day, $ref]) {
            $paid = $m->copy()->addMonthNoOverflow()->day($day);
            if ($paid->gt($today)) {
                continue;
            }
            try {
                \App\Services\StatutoryService::remit($kind, $m->format('Y-m'), ['paid_on' => $paid->toDateString(), 'payment_mode_id' => $bank, 'reference' => $ref]);
            } catch (\RuntimeException $e) {
                // payroll month not approved on this property – nothing to remit
            }
        }
        $prev ? auth()->setUser($prev) : auth()->forgetUser();
    }

    protected function seedAssets(Models\Hotel $hotel, bool $rich, Carbon $today, Models\User $user): void
    {
        $eng = Models\User::where('hotel_id', $hotel->id)->where('username', 'engineer')->first();
        Auth::setUser($eng ?? $user);
        \App\Services\AssetService::ensureDefaults();
        $cat = Models\AssetCategory::pluck('id', 'code');
        $rooms = Models\Room::orderBy('room_no')->get();
        $emp = fn ($n) => Models\Employee::where('name', $n)->value('id');
        $goLive = Carbon::parse('2026-06-01');
        // accumulated depreciation up to the day before go-live, by the category rule
        $accTo = function (float $cost, string $code, string $bought) use ($goLive) {
            $c = Models\AssetCategory::where('code', $code)->first();
            $res = round($cost * (float) $c->residual_pct / 100, 2);
            $months = Carbon::parse($bought)->diffInMonths($goLive);
            $acc = $c->method === 'wdv' ? $cost * (1 - pow(1 - (float) $c->wdv_rate / 100, $months / 12)) : ($cost - $res) / $c->life_months * $months;

            return min(round($acc, -1), $cost - $res);
        };
        // [name, cat, cost, bought, room idx|null, location, department, make, model, serial, warranty, amc vendor, amc until]
        $list = [
            ['Guest room furniture – Deluxe (8 rooms)', 'FF', 960000, '2022-04-10', null, 'Deluxe floor', 'Rooms', 'Godrej Interio', 'Hotel series', null, null, null, null],
            ['Lobby sofas & lounge furniture', 'FF', 285000, '2021-11-05', null, 'Lobby', 'Front Office', 'Durian', null, null, null, null, null],
            ['Restaurant tables & chairs (60 covers)', 'FF', 420000, '2022-01-15', null, 'Restaurant', 'F&B – Restaurant & Bar', null, null, null, null, null, null],
            ['Banquet chairs (200) & round tables (20)', 'FF', 380000, '2023-02-01', null, 'Banquet hall', 'Banquets', null, null, null, null, null, null],
            ['Combi oven – Rational iCombi Pro 10-1/1', 'KIT', 865000, '2023-06-20', null, 'Main kitchen', 'F&B – Kitchen', 'Rational', 'iCombi Pro 10-1/1', 'E11RI2306451', '2025-06-19', 'Rational Service India', '2027-06-30'],
            ['Commercial dishwasher – Hobart AM-15', 'KIT', 410000, '2024-03-12', null, 'Main kitchen', 'F&B – Kitchen', 'Hobart', 'AM-15', 'HB-AM15-88213', '2026-11-11', null, null],
            ['Walk-in cold room 8×10 ft', 'KIT', 520000, '2022-08-01', null, 'Main kitchen', 'F&B – Kitchen', 'Blue Star', null, null, null, null, null],
            ['Washer-extractor 25 kg', 'KIT', 690000, '2023-09-01', null, 'Laundry', 'Housekeeping', 'Electrolux', 'WH6-27', 'EL-WH6-27-5521', '2025-08-31', null, null],
            ['Tumble dryer 15 kg (old)', 'KIT', 240000, '2019-07-01', null, 'Laundry', 'Housekeeping', 'IFB', 'TD-15', null, null, null, null],
            ['Chiller plant 60 TR', 'HVAC', 3200000, '2021-04-01', null, 'Roof plant room', 'Engineering', 'Carrier', '30RB-060', 'CR30RB-21-0098', null, 'CoolAir HVAC Services', '2027-03-31'],
            ['DG set 250 kVA', 'HVAC', 1850000, '2021-04-01', null, 'Utility yard', 'Engineering', 'Cummins', 'C250D5', 'CUM-250-4471', null, 'Powerica Service', '2026-12-31'],
            ['Passenger lift – 8 persons', 'HVAC', 2400000, '2021-04-01', null, 'Lobby core', 'Engineering', 'Otis', 'Gen2 Life', 'OT-GL-77310', null, 'Otis Elevator Co.', '2027-03-31'],
            ['Solar water heater 3,000 LPD', 'HVAC', 360000, '2022-12-01', null, 'Roof', 'Engineering', 'Racold', null, null, null, null, null],
            ['Split AC 1.5 T', 'HVAC', 48000, '2024-05-10', 0, null, 'Rooms', 'Daikin', 'FTKF50', 'DK50-240510-01', '2027-05-09', null, null],
            ['Split AC 1.5 T', 'HVAC', 48000, '2024-05-10', 1, null, 'Rooms', 'Daikin', 'FTKF50', 'DK50-240510-02', '2027-05-09', null, null],
            ['Split AC 1.5 T', 'HVAC', 48000, '2024-05-10', 2, null, 'Rooms', 'Daikin', 'FTKF50', 'DK50-240510-03', '2027-05-09', null, null],
            ['Front-office PCs (4) & receipt printers', 'IT', 220000, '2024-01-10', null, 'Front desk', 'Front Office', 'HP', 'ProDesk 400', null, '2026-10-20', null, null],
            ['Wi-Fi network – 30 access points & controller', 'IT', 540000, '2023-07-01', null, 'Whole building', 'Administration', 'Ubiquiti', 'UniFi U6', null, null, null, null],
            ['Smart TVs 43" (24 rooms)', 'IT', 720000, '2023-10-15', null, 'Guest rooms', 'Rooms', 'Samsung', 'HG43 hospitality', null, '2026-10-14', null, null],
            ['CCTV – 32 cameras & NVR', 'IT', 310000, '2022-09-01', null, 'Security room', 'Administration', 'Hikvision', null, null, null, null, null],
            ['Airport shuttle – Toyota Innova Crysta', 'VEH', 2150000, '2023-11-20', null, 'Porch', 'Transport', 'Toyota', 'Innova Crysta 2.4', 'MH12-TX-4471', null, null, null],
            ['Staff van – Maruti Eeco (old)', 'VEH', 520000, '2019-02-15', null, 'Porch', 'Transport', 'Maruti Suzuki', 'Eeco', 'MH12-KL-1186', null, null, null],
            ['Rooftop bar fit-out', 'BLD', 1400000, '2024-02-01', null, 'Roof', 'F&B – Restaurant & Bar', null, null, null, null, null, null],
        ];
        if (! $rich) {
            $list = [$list[0], $list[4], $list[9], $list[17]];
        }
        $A = [];
        foreach ($list as $i => [$name, $code, $cost, $bought, $room, $loc, $dept, $make, $model, $serial, $warr, $amc, $amcUntil]) {
            $cost = $rich ? $cost : round($cost * .4, -3);
            $roomM = $room !== null ? ($rooms[$room] ?? null) : null;
            $A[$name.($roomM ? ' '.$roomM->room_no : '')] = \App\Services\AssetService::create([
                'name' => $name.($roomM ? ' – Room '.$roomM->room_no : ''), 'asset_category_id' => $cat[$code], 'room_id' => $roomM?->id, 'location' => $loc, 'department' => $dept,
                'make' => $make, 'model' => $model, 'serial_no' => $serial, 'acquired_via' => 'opening', 'purchase_date' => $bought, 'dep_start' => $goLive->toDateString(), 'cost' => $cost,
                'opening_acc_dep' => $accTo($cost, $code, $bought), 'warranty_until' => $warr, 'amc_vendor' => $amc, 'amc_until' => $amcUntil,
                'custodian_id' => in_array($dept, ['Engineering', 'Rooms']) ? $emp('Joseph Thomas') : ($dept === 'F&B – Kitchen' ? $emp('Mohan Lal') : null),
                'reference' => 'Take-on '.$goLive->format('M Y'),
            ]);
        }
        Models\Asset::query()->update(['created_at' => $goLive->copy()->setTime(10, 0)]);
        if (! $rich) {
            Auth::forgetUser();

            return;
        }
        $bank = Models\PaymentMode::where('type', 'bank')->value('id');

        // June depreciation, then the old staff van is sold on 10 July
        \App\Services\AssetService::run(Carbon::parse('2026-06-30'));
        \App\Services\AssetService::dispose($A['Staff van – Maruti Eeco (old)']->fresh(), ['disposal_type' => 'sold', 'disposed_date' => '2026-07-10', 'disposal_proceeds' => 185000,
            'disposal_mode_id' => $bank, 'disposal_note' => 'Sold to Sai Motors, Pune (RC transferred)']);
        // a direct purchase in July (paid by bank transfer)
        $espresso = \App\Services\AssetService::create(['name' => 'Espresso machine – La Marzocco Linea PB 2-group', 'asset_category_id' => $cat['KIT'], 'location' => 'Rooftop bar',
            'department' => 'F&B – Restaurant & Bar', 'make' => 'La Marzocco', 'model' => 'Linea PB AV 2GR', 'serial_no' => 'LM-PB2-66120', 'acquired_via' => 'direct', 'payment_mode_id' => $bank,
            'reference' => 'GIP 90112 / BrewTech inv 2207', 'purchase_date' => '2026-07-18', 'cost' => 385000, 'warranty_until' => '2027-07-17', 'custodian_id' => $emp('Sunil Yadav')]);
        $espresso->forceFill(['created_at' => Carbon::parse('2026-07-18 15:20')])->save();
        \App\Services\AssetService::run(Carbon::parse('2026-07-31'));
        // the old tumble dryer is scrapped in August (motor burnt out)
        \App\Services\AssetService::dispose($A['Tumble dryer 15 kg (old)']->fresh(), ['disposal_type' => 'scrapped', 'disposed_date' => '2026-08-05', 'disposal_note' => 'Motor burnt out – repair uneconomical, sold as scrap for nil value']);
        // a laptop bought on a supplier bill: one line capitalised, the licence expensed
        $tech = Models\Supplier::create(['wht_category' => 'goods', 'name' => 'TechWorld IT Solutions', 'contact_person' => 'Nikhil', 'phone' => '+91 98220 44310', 'payment_terms_days' => 30,
            'expense_head_id' => Models\AccountHead::where('name', 'Internet & Telephone')->value('id'), 'tax_number' => 'C0055218830']);
        Auth::setUser(Models\User::where('hotel_id', $hotel->id)->where('username', 'accounts')->first() ?? $user);
        $bill = ApService::expenseBill($tech, ['supplier_ref' => 'TW/26-27/0815', 'doc_date' => '2026-08-12', 'lines' => [
            ['account_head_id' => Models\AccountHead::sys('fixed_assets'), 'description' => 'Dell Latitude 5450 laptop – GM office', 'amount' => 98000, 'tax_rate' => 20],
            ['account_head_id' => Models\AccountHead::where('name', 'Internet & Telephone')->value('id'), 'description' => 'Microsoft 365 Business – annual licence', 'amount' => 14400, 'tax_rate' => 20],
        ]]);
        Auth::setUser($eng ?? $user);
        $laptop = \App\Services\AssetService::create(['name' => 'Laptop – Dell Latitude 5450 (GM office)', 'asset_category_id' => $cat['IT'], 'location' => 'GM office', 'department' => 'Administration',
            'make' => 'Dell', 'model' => 'Latitude 5450', 'serial_no' => 'DL5450-7GQ2K', 'acquired_via' => 'bill', 'supplier_invoice_id' => $bill->id, 'purchase_date' => '2026-08-12',
            'cost' => 98000, 'warranty_until' => '2029-08-11', 'reference' => 'TW/26-27/0815']);
        $laptop->forceFill(['created_at' => Carbon::parse('2026-08-13 11:05')])->save();

        // ---------- preventive maintenance ----------
        $d = fn (int $days) => $today->copy()->addDays($days)->toDateString();
        $sched = function (array $x) {
            return Models\PmSchedule::create($x + ['priority' => 'normal', 'lead_days' => 3, 'is_active' => true]);
        };
        $S = [
            'chiller' => $sched(['title' => 'Chiller quarterly service', 'asset_id' => $A['Chiller plant 60 TR']->id, 'category' => 'ac', 'every' => 3, 'every_unit' => 'month', 'next_due' => '2026-09-26', 'lead_days' => 7, 'vendor' => 'CoolAir HVAC Services',
                'checklist' => "Check refrigerant pressures & superheat\nClean condenser coils\nInspect compressor oil level\nTest safety cut-outs\nLog amps per phase", 'priority' => 'high']),
            'acfilter' => $sched(['title' => 'AC filter cleaning – guest rooms', 'location' => 'All guest rooms', 'category' => 'ac', 'every' => 1, 'every_unit' => 'month', 'next_due' => $d(-4), 'employee_id' => $emp('Joseph Thomas'),
                'checklist' => "Remove & wash filters in every room\nWipe indoor unit & grill\nCheck drain line for blockage\nNote any unit not cooling"]),
            'dg' => $sched(['title' => 'DG set monthly load test & oil check', 'asset_id' => $A['DG set 250 kVA']->id, 'category' => 'electrical', 'every' => 1, 'every_unit' => 'month', 'next_due' => $d(2), 'employee_id' => $emp('Joseph Thomas'),
                'checklist' => "Run on load for 30 minutes\nCheck engine oil & coolant\nCheck battery voltage\nRecord diesel level & hours meter"]),
            'oven' => $sched(['title' => 'Combi oven descaling & door seal check', 'asset_id' => $A['Combi oven – Rational iCombi Pro 10-1/1']->id, 'category' => 'general', 'every' => 2, 'every_unit' => 'week', 'next_due' => $d(10), 'lead_days' => 2,
                'checklist' => "Run CareControl descaling cycle\nInspect door gasket\nClean core-temperature probe"]),
            'fire' => $sched(['title' => 'Fire extinguisher & hydrant inspection', 'location' => 'All floors', 'category' => 'general', 'every' => 6, 'every_unit' => 'month', 'next_due' => '2026-11-15', 'lead_days' => 10, 'vendor' => 'SafeGuard Fire Services',
                'checklist' => "Check pressure gauges & seals\nRefill / replace expired units\nTest hydrant pump\nUpdate inspection tags", 'priority' => 'high']),
            'lift' => $sched(['title' => 'Lift monthly safety inspection', 'asset_id' => $A['Passenger lift – 8 persons']->id, 'category' => 'electrical', 'every' => 1, 'every_unit' => 'month', 'next_due' => $d(15), 'vendor' => 'Otis Elevator Co.',
                'checklist' => "Brake & door interlocks\nEmergency alarm & intercom\nRescue device (ARD)\nMachine room cleanliness", 'priority' => 'high']),
            'washer' => $sched(['title' => 'Washer-extractor lubrication & belt check', 'asset_id' => $A['Washer-extractor 25 kg']->id, 'category' => 'general', 'every' => 1, 'every_unit' => 'month', 'next_due' => $d(-9), 'employee_id' => $emp('Imran Ali'),
                'checklist' => "Grease bearings\nCheck drive belt tension\nClean lint & detergent lines"]),
            'pool' => $sched(['title' => 'Pool pump & filter backwash', 'location' => 'Pool plant room', 'category' => 'plumbing', 'every' => 1, 'every_unit' => 'week', 'next_due' => $d(1), 'lead_days' => 1, 'employee_id' => $emp('Imran Ali'),
                'checklist' => "Backwash sand filter\nClean pump strainer basket\nTest pH & chlorine"]),
        ];
        // service history (resolved PM tickets): [schedule, due, done, cost, note]
        $hist = [
            ['chiller', '2026-06-26', '2026-06-25', 24500, 'Quarterly service done by CoolAir – gas pressures normal, condenser coils cleaned'],
            ['acfilter', '2026-07-22', '2026-07-21', 0, 'All 24 rooms done'], ['acfilter', $d(-35), $d(-33), 0, 'Done – rooms 105 & 110 filters replaced (torn)'],
            ['dg', '2026-07-28', '2026-07-28', 0, 'Load test OK, 412 hrs'], ['dg', $d(-29), $d(-29), 0, 'Load test OK – topped up 2 L coolant'],
            ['oven', $d(-18), $d(-18), 0, 'Descaled'], ['oven', $d(-4), $d(-1), 0, 'Descaled – door gasket worn, replacement ordered'],
            ['lift', $d(-15), $d(-15), 0, 'Monthly inspection by Otis – all OK'],
            ['washer', '2026-07-17', '2026-07-19', 0, 'Bearings greased'], ['washer', $d(-40), $d(-40), 1800, 'Drive belt replaced'],
            ['pool', $d(-13), $d(-13), 0, 'Backwash done, pH 7.4'], ['pool', $d(-6), $d(-5), 0, 'Backwash done, chlorine topped up'],
        ];
        foreach ($hist as [$k, $due, $done, $cost, $note]) {
            $s = $S[$k];
            $t = Models\MaintenanceRequest::create(['ticket_no' => Numbering::next('ticket'), 'room_id' => $s->room_id ?? $s->asset?->room_id, 'asset_id' => $s->asset_id, 'pm_schedule_id' => $s->id,
                'location' => $s->location ?? $s->asset?->location, 'category' => $s->category, 'title' => 'PM: '.$s->title, 'description' => "Checklist:\n".$s->checklist, 'priority' => $s->priority,
                'status' => 'closed', 'due_date' => $due, 'employee_id' => $s->employee_id, 'reported_by' => ($eng ?? $user)->id, 'cost' => $cost, 'resolution' => $note,
                'resolved_at' => Carbon::parse($done)->setTime(16, 30)]);
            $t->forceFill(['created_at' => Carbon::parse($due)->subDays($s->lead_days)->setTime(6, 0), 'updated_at' => Carbon::parse($done)->setTime(16, 30)])->save();
            $s->forceFill(['last_done' => $done])->save();
        }
        // a breakdown on the dishwasher: asset under repair with an open ticket
        $dw = $A['Commercial dishwasher – Hobart AM-15'];
        Models\MaintenanceRequest::create(['ticket_no' => Numbering::next('ticket'), 'asset_id' => $dw->id, 'location' => 'Main kitchen', 'category' => 'electrical', 'title' => 'Dishwasher not heating – rinse below 82 °C',
            'description' => 'Rinse booster not reaching temperature; washing by hand meanwhile.', 'priority' => 'urgent', 'status' => 'in_progress', 'due_date' => $d(1), 'employee_id' => $emp('Joseph Thomas'), 'reported_by' => $user->id]);
        \App\Services\AssetService::setStatus($dw, 'in_repair', 'booster heater fault');
        // today's auto-generated PM tickets (overdue AC filters & washer, DG / chiller / pool due)
        \App\Services\PmService::generate($today);
        Models\MaintenanceRequest::where('pm_schedule_id', $S['acfilter']->id)->whereIn('status', ['open'])->update(['status' => 'in_progress', 'created_at' => $today->copy()->subDays(7)->setTime(6, 0)]);
        Models\MaintenanceRequest::where('pm_schedule_id', $S['washer']->id)->whereIn('status', ['open'])->update(['created_at' => $today->copy()->subDays(12)->setTime(6, 0)]);
        Auth::forgetUser();
    }

    protected function seedPhase6(Models\Hotel $hotel, bool $rich, Carbon $today): void
    {
        foreach ([['Mineral Water 500ml', 40], ['Soft Drink (can)', 80], ['Beer (can)', 220], ['Salted Nuts', 150], ['Chocolate Bar', 120], ['Potato Chips', 90]] as $i => [$n, $p]) {
            Models\MinibarItem::create(['name' => $n, 'price' => $p, 'sort_order' => $i + 1, 'is_active' => true]);
        }
        foreach ([['Swedish Massage (60 min)', 'spa', 60, 3500], ['Deep Tissue Massage (90 min)', 'spa', 90, 4800], ['Signature Facial', 'spa', 60, 3000], ['Hot Stone Therapy', 'spa', 75, 4200],
            ['Manicure & Pedicure', 'salon', 60, 1800], ['Haircut & Styling', 'salon', 45, 1200], ['Personal Training Session', 'fitness', 60, 1500], ['Morning Yoga (group)', 'fitness', 60, 600],
            ['City Heritage Tour', 'tour', 180, 2500], ['Sunset Kayaking', 'activity', 90, 2000]] as [$n, $c, $d, $p]) {
            Models\SpaService::create(['name' => $n, 'category' => $c, 'duration_min' => $d, 'price' => $rich ? $p : round($p * .8), 'is_active' => true]);
        }
        foreach (['Treatment Room 1', 'Treatment Room 2', 'Couples Suite', 'Salon Chair'] as $n) {
            Models\SpaRoom::create(['name' => $n, 'is_active' => true]);
        }

        // Housekeeping: today's credit-balanced assignment, then a realistic mid-morning state
        \App\Services\HousekeepingService::generate($today->toDateString());
        if ($rich) {
            $tasks = Models\HousekeepingTask::with('room')->where('task_date', $today)->whereNotNull('room_id')->orderBy('id')->get()->groupBy('employee_id')->values();
            $all = \App\Services\HousekeepingService::checklist();
            foreach ($tasks as $gi => $list) {
                foreach ($list->values() as $i => $t) {
                    if ($i === 0 || ($i === 1 && $gi === 0)) {           // done – one passed, rest waiting for inspection
                        $t->forceFill(['started_at' => now()->subMinutes(70 - $i * 25)])->save();
                        \App\Services\HousekeepingService::complete($t, $all);
                        $t->forceFill(['completed_at' => now()->subMinutes(45 - $i * 25)])->save();
                        if ($i === 1) {
                            \App\Services\HousekeepingService::inspect($t->fresh(), true, 'Good job');
                        }
                    } elseif ($i === 1 && $gi === 1) {                      // failed inspection → back to the attendant
                        $t->forceFill(['started_at' => now()->subMinutes(80)])->save();
                        \App\Services\HousekeepingService::complete($t, $all);
                        \App\Services\HousekeepingService::inspect($t->fresh(), false, 'Bathroom mirror streaky, replace hand towel');
                    } elseif ($i === 2 && $gi === 0) {
                        \App\Services\HousekeepingService::start($t);
                        $t->forceFill(['started_at' => now()->subMinutes(12)])->save();
                    } elseif ($i === 3 && $gi === 0 && $t->room->status === 'occupied') {
                        \App\Services\HousekeepingService::dnd($t);
                    }
                }
            }
            // Minibar consumption in an occupied room
            if ($room = Models\Room::where('status', 'occupied')->orderBy('room_no')->first()) {
                $mb = Models\MinibarItem::orderBy('sort_order')->pluck('id');
                \App\Services\HousekeepingService::postMinibar($room, [$mb[0] => 2, $mb[2] => 1, $mb[4] => 1]);
            }
        }

        // Spa & activities
        $therapists = \App\Services\SpaBookingService::therapists()->values();
        $svc = Models\SpaService::pluck('id', 'name');
        $srooms = Models\SpaRoom::pluck('id', 'name');
        $stays = Models\ReservationRoom::where('status', 'checked_in')->orderBy('id')->take(3)->get();
        $cash = Models\PaymentMode::where('type', 'cash')->value('id');
        $book = fn (array $d) => \App\Services\SpaBookingService::save($d + ['date' => $today->toDateString(), 'pax' => 1]);
        if ($therapists->count() >= 3) {
            $a = $book(['spa_service_id' => $svc['Swedish Massage (60 min)'], 'employee_id' => $therapists[1]->id, 'spa_room_id' => $srooms['Treatment Room 1'], 'start_time' => '10:00', 'reservation_room_id' => $stays[0]->id ?? null, 'customer_name' => 'Walk-in guest']);
            if ($a->stay) {
                \App\Services\SpaBookingService::settle($a, 'room_post');
            }
            $b = $book(['spa_service_id' => $svc['Signature Facial'], 'employee_id' => $therapists[0]->id, 'spa_room_id' => $srooms['Treatment Room 2'], 'start_time' => '11:00', 'customer_name' => 'Ama Serwaa', 'customer_phone' => '0244123456', 'discount' => 300]);
            \App\Services\SpaBookingService::settle($b, 'payment', $cash, 'Counter');
            if ($rich) {
                $c = $book(['spa_service_id' => $svc['Deep Tissue Massage (90 min)'], 'employee_id' => $therapists[1]->id, 'spa_room_id' => $srooms['Treatment Room 1'], 'start_time' => '14:30', 'reservation_room_id' => $stays[1]->id ?? null, 'customer_name' => 'Walk-in guest']);
                \App\Services\SpaBookingService::setStatus($c, 'in_progress');
                $book(['spa_service_id' => $svc['Personal Training Session'], 'employee_id' => $therapists[2]->id, 'start_time' => '07:00', 'customer_name' => 'Kwame Mensah', 'customer_phone' => '0201234567'])->update(['status' => 'no_show']);
                $book(['spa_service_id' => $svc['Hot Stone Therapy'], 'employee_id' => $therapists[0]->id, 'spa_room_id' => $srooms['Couples Suite'], 'start_time' => '16:00', 'pax' => 2, 'reservation_room_id' => $stays[2]->id ?? null, 'customer_name' => 'Walk-in guest', 'notes' => 'Anniversary – rose petals']);
                $book(['spa_service_id' => $svc['Morning Yoga (group)'], 'employee_id' => $therapists[2]->id, 'start_time' => '09:00', 'pax' => 4, 'customer_name' => 'Yoga group (4)']);
                $book(['spa_service_id' => $svc['Sunset Kayaking'], 'start_time' => '17:00', 'pax' => 2, 'reservation_room_id' => $stays[0]->id ?? null, 'customer_name' => 'Walk-in guest']);
                $x = $book(['spa_service_id' => $svc['Manicure & Pedicure'], 'employee_id' => $therapists[0]->id, 'spa_room_id' => $srooms['Salon Chair'], 'start_time' => '13:00', 'customer_name' => 'Linda Owusu']);
                \App\Services\SpaBookingService::setStatus($x, 'cancelled', 'Guest rescheduled to next week');
                foreach ([[1, '10:30', 'Swedish Massage (60 min)', 1], [1, '12:00', 'Haircut & Styling', 0], [2, '15:00', 'City Heritage Tour', null]] as [$dd, $t, $s, $ti]) {
                    \App\Services\SpaBookingService::save(['spa_service_id' => $svc[$s], 'employee_id' => $ti === null ? null : $therapists[$ti]->id, 'date' => $today->copy()->addDays($dd)->toDateString(),
                        'start_time' => $t, 'pax' => 1, 'customer_name' => ['Nana Adjei', 'Esi Mensah', 'Tour group'][$dd === 1 && $t === '12:00' ? 1 : ($dd === 2 ? 2 : 0)]]);
                }
            }
        }

        // Messaging: log driver (demo) + a little history
        foreach (['sms_enabled' => '1', 'sms_driver' => 'log', 'sms_default_channel' => 'sms', 'sms_default_country_code' => '+233', 'sms_sender' => 'NOWILLS',
            'sms_auto_confirmation' => '1', 'sms_auto_thankyou' => '1', 'sms_pre_arrival_days' => '2', 'hk_require_inspection' => '1', 'spa_open' => '07:00', 'spa_close' => '20:00'] as $k => $v) {
            Models\Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        }
        if ($rich) {
            Models\Reservation::with('guest')->whereIn('status', ['confirmed', 'checked_in'])->orderBy('id')->take(4)->get()
                ->each(fn ($r, $i) => \App\Services\MessageService::sendTemplate($i === 3 ? 'pre_arrival' : 'confirmation', $r, $i === 2 ? 'whatsapp' : 'sms'));
            \App\Services\MessageService::send('12', 'Test', 'sms', 'test'); // shows a failed entry (invalid number)
        }
    }

}
