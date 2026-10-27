<?php

namespace App\Http\Controllers;

use App\Models\Folio;
use App\Models\GiftVoucher;
use App\Models\GiftVoucherTransaction;
use App\Models\GiftVoucherType;
use App\Models\Hotel;
use App\Models\PaymentMode;
use App\Services\FolioService;
use App\Services\GiftVoucherService as Gv;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** v3.8 – gift vouchers & stored-value cards: sell, look up, redeem, reload, promotional campaigns, life-cycle, settings. */
class GiftVoucherController extends Controller
{
    public const TABS = ['list' => 'Vouchers', 'sell' => 'Sell', 'lookup' => 'Check / redeem', 'campaigns' => 'Campaigns', 'types' => 'Voucher types', 'settings' => 'Settings'];

    protected const TAB_PERM = ['list' => 'vouchers.view', 'sell' => 'vouchers.sell', 'lookup' => 'vouchers.view', 'campaigns' => 'vouchers.view', 'types' => 'vouchers.manage', 'settings' => 'vouchers.manage'];

    protected function fail(RuntimeException $e)
    {
        return back()->withInput()->with('error', $e->getMessage());
    }

    /** payment modes a voucher can be bought / refunded with */
    protected function saleModes()
    {
        return PaymentMode::where('is_active', true)->whereNotIn('type', ['city_ledger', 'gift_voucher', 'forex'])->orderBy('id')->pluck('name', 'id');
    }

    public function index(Request $request)
    {
        $tab = array_key_exists($request->query('tab'), self::TABS) ? $request->query('tab') : 'list';
        if (! can_do(self::TAB_PERM[$tab])) {
            $tab = 'list';
        }
        $vouchers = $campaigns = null;
        if ($tab === 'list') {
            $st = $request->query('status');
            $vouchers = GiftVoucher::with('type')
                ->when($st === 'expiring', fn ($q) => $q->where('status', 'active')->where('balance', '>', 0)->whereDate('expires_on', '>=', bdate()->toDateString())->whereDate('expires_on', '<=', bdate()->copy()->addDays(Gv::cfg()['reminder_days'] ?: 30)->toDateString()))
                ->when($st && $st !== 'expiring' && isset(GiftVoucher::STATUSES[$st]), fn ($q) => $q->where('status', $st))
                ->when($request->query('type'), fn ($q, $t) => $q->where('gift_voucher_type_id', (int) $t))
                ->when($request->query('source'), fn ($q, $s) => $q->where('source', $s))
                ->when(trim((string) $request->query('q')), function ($q, $t) {
                    $code = Gv::normalise($t);
                    $q->where(fn ($w) => $w->when($code, fn ($c) => $c->orWhere('code', $code))->orWhere('code', 'like', '%'.strtoupper($t).'%')
                        ->orWhere('purchaser_name', 'like', "%{$t}%")->orWhere('recipient_name', 'like', "%{$t}%")->orWhere('recipient_email', 'like', "%{$t}%")
                        ->orWhere('purchaser_phone', 'like', "%{$t}%")->orWhere('campaign', 'like', "%{$t}%"));
                })
                ->orderByDesc('id')->paginate(50)->withQueryString();
        } elseif ($tab === 'campaigns') {
            $campaigns = GiftVoucher::where('source', 'promo')->whereNotNull('campaign')
                ->selectRaw("campaign, COUNT(*) n, SUM(face_value) issued, SUM(balance) outstanding, SUM(status = 'redeemed') used, SUM(face_value - balance) spent_or_lapsed, MIN(issued_on) first_on, MAX(expires_on) expires")
                ->groupBy('campaign')->orderByDesc('first_on')->get();
        }

        return view('vouchers.index', [
            'tab' => $tab, 'vouchers' => $vouchers, 'campaigns' => $campaigns, 'cfg' => Gv::cfg(), 'snap' => Gv::snapshot(),
            'types' => GiftVoucherType::withCount('vouchers')->orderBy('name')->get(), 'modes' => $this->saleModes(),
            'found' => session('gv_lookup_miss'),
        ]);
    }

    public function show(GiftVoucher $gvoucher)
    {
        $gvoucher->load('type', 'guest', 'paymentMode', 'issuer', 'transactions.user', 'transactions.paymentMode', 'transactions.folioTransaction.folio', 'transactions.posOrder');
        $folios = collect();
        if (can_do('vouchers.redeem') && $gvoucher->usable() && $gvoucher->type->usableOn('folio')) {
            $folios = Folio::with('stay.room', 'guest')->where('status', 'open')->whereHas('stay', fn ($q) => $q->where('status', 'checked_in'))->get()
                ->map(fn ($f) => ['id' => $f->id, 'label' => ($f->stay?->room?->room_no ?? '—').' · '.($f->guest?->full_name ?? $f->folio_no).' · '.$f->folio_no.' · balance '.money($f->balance()), 'balance' => $f->balance()])
                ->sortBy('label')->values();
        }

        return view('vouchers.show', ['v' => $gvoucher, 'folios' => $folios, 'modes' => $this->saleModes(), 'cfg' => Gv::cfg(),
            'emails' => \App\Models\EmailLog::where('related_type', 'GiftVoucher')->where('related_id', $gvoucher->id)->latest('id')->limit(5)->get()]);
    }

    public function printCard(GiftVoucher $gvoucher)
    {
        return view('vouchers.print', ['v' => $gvoucher->load('type'), 'cfg' => Gv::cfg()]);
    }

    public function sell(Request $request)
    {
        $data = $request->validate(['gift_voucher_type_id' => 'required|integer|owned:gift_voucher_types', 'amount' => 'nullable|numeric|min:0|max:10000000',
            'payment_mode_id' => 'required|integer|owned:payment_modes', 'payment_ref' => 'nullable|string|max:80', 'purchaser_name' => 'required|string|max:100',
            'purchaser_email' => 'nullable|string|max:150', 'purchaser_phone' => 'nullable|string|max:30', 'recipient_name' => 'nullable|string|max:100',
            'recipient_email' => 'nullable|string|max:150', 'message' => 'nullable|string|max:300', 'send_email' => 'nullable|boolean', 'expires_on' => 'nullable|date']);
        try {
            $v = Gv::sell($data);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return redirect()->route('vouchers.show', $v)->with('success', "Voucher {$v->code} sold – ".money($v->face_value).'.'.($v->emailed_at ? ' E-mailed to '.($v->recipient_email ?: $v->purchaser_email).'.' : ''));
    }

    public function lookup(Request $request)
    {
        $data = $request->validate(['code' => 'required|string|max:20']);
        $v = Gv::find($data['code']);
        if (! $v) {
            return redirect()->route('vouchers.index', ['tab' => 'lookup'])->withInput()->with('error', 'No voucher with code “'.$data['code'].'” at this hotel. Check the characters (no 0/O or 1/I are used in codes).');
        }

        return redirect()->route('vouchers.show', $v);
    }

    public function redeemFolio(Request $request, GiftVoucher $gvoucher)
    {
        $data = $request->validate(['folio_id' => 'required|integer|owned:folios', 'amount' => 'required|numeric|min:0.01|max:10000000']);
        try {
            $folio = Folio::findOrFail($data['folio_id']);
            $tx = FolioService::payment($folio, (float) $data['amount'], Gv::modeId(), $gvoucher->code);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return back()->with('success', money($tx->amount).' from '.$gvoucher->code.' paid on folio '.$folio->folio_no.'. Remaining on the voucher: '.money($gvoucher->fresh()->balance).'.');
    }

    public function reload(Request $request, GiftVoucher $gvoucher)
    {
        $data = $request->validate(['amount' => 'required|numeric|min:0.01|max:10000000', 'payment_mode_id' => 'required|integer|owned:payment_modes', 'reference' => 'nullable|string|max:80']);
        try {
            Gv::reload($gvoucher, (float) $data['amount'], (int) $data['payment_mode_id'], $data['reference'] ?? null);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return back()->with('success', "{$gvoucher->code} reloaded with ".money($data['amount']).' – balance '.money($gvoucher->fresh()->balance).'.');
    }

    public function email(Request $request, GiftVoucher $gvoucher)
    {
        $data = $request->validate(['to' => 'nullable|email|max:150']);
        $log = Gv::sendEmail($gvoucher, $data['to'] ?? null);

        return back()->with($log->status === 'sent' ? 'success' : 'error', $log->status === 'sent' ? "Voucher e-mailed to {$log->to_email}." : 'Could not e-mail the voucher: '.$log->error);
    }

    public function suspend(Request $request, GiftVoucher $gvoucher)
    {
        $data = $request->validate(['reason' => 'required|string|max:200']);
        try {
            Gv::suspend($gvoucher, $data['reason']);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return back()->with('success', "{$gvoucher->code} suspended – it cannot be redeemed until re-activated.");
    }

    public function unsuspend(GiftVoucher $gvoucher)
    {
        try {
            Gv::unsuspend($gvoucher);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return back()->with('success', "{$gvoucher->code} re-activated.");
    }

    public function cancel(Request $request, GiftVoucher $gvoucher)
    {
        $data = $request->validate(['reason' => 'required|string|max:200', 'payment_mode_id' => 'nullable|integer|owned:payment_modes']);
        try {
            $t = Gv::cancel($gvoucher, $data['reason'], isset($data['payment_mode_id']) ? (int) $data['payment_mode_id'] : null);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return back()->with('success', "{$gvoucher->code} cancelled – ".money($t->amount).($t->payment_mode_id ? ' refunded by '.$t->paymentMode?->name : ' withdrawn').'.');
    }

    public function promo(Request $request)
    {
        $data = $request->validate(['gift_voucher_type_id' => 'required|integer|owned:gift_voucher_types', 'amount' => 'nullable|numeric|min:0|max:10000000', 'campaign' => 'required|string|max:60',
            'count' => 'nullable|integer|min:1|max:500', 'recipients' => 'nullable|string|max:20000', 'message' => 'nullable|string|max:300', 'expires_on' => 'nullable|date', 'recipient_name' => 'nullable|string|max:100']);
        // one recipient per line: "Name, email" or just an e-mail address
        $recipients = collect(preg_split('/\r\n|\r|\n/', (string) ($data['recipients'] ?? '')))->map('trim')->filter()->map(function ($line) {
            $parts = array_map('trim', str_getcsv($line));
            $email = collect($parts)->first(fn ($p) => str_contains($p, '@'));
            $name = collect($parts)->first(fn ($p) => ! str_contains($p, '@') && $p !== '');

            return [$name ?? '', $email ?? ''];
        })->values()->all();
        try {
            $out = Gv::issuePromo($data, (int) ($data['count'] ?? 1), $recipients);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }
        $mailed = $out->whereNotNull('emailed_at')->count();

        return $out->count() === 1 ? redirect()->route('vouchers.show', $out->first())->with('success', 'Promotional voucher '.$out->first()->code.' issued.'.($mailed ? ' E-mailed to the recipient.' : ''))
            : redirect()->route('vouchers.index', ['tab' => 'list', 'q' => $out->first()->campaign])->with('success', $out->count().' promotional vouchers issued for “'.$out->first()->campaign.'”'.($mailed ? " – {$mailed} e-mailed" : '').'.');
    }

    public function saveType(Request $request, ?GiftVoucherType $gvtype = null)
    {
        $data = $request->validate(['name' => 'required|string|max:80', 'kind' => 'required|in:'.implode(',', array_keys(GiftVoucherType::KINDS)), 'face_value' => 'nullable|numeric|min:0|max:10000000',
            'min_value' => 'nullable|numeric|min:0|max:10000000', 'max_value' => 'nullable|numeric|min:0|max:10000000', 'validity_months' => 'required|integer|min:1|max:120',
            'usable_on' => 'required|array|min:1', 'usable_on.*' => 'in:folio,pos', 'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'], 'terms' => 'nullable|string|max:1000', 'is_active' => 'nullable|boolean']);
        $data['face_value'] = ! empty($data['face_value']) ? round((float) $data['face_value'], 2) : null;
        $data['min_value'] = (float) ($data['min_value'] ?? 0);
        $data['max_value'] = (float) ($data['max_value'] ?? 0);
        if ($data['max_value'] > 0 && $data['min_value'] > $data['max_value']) {
            return back()->withInput()->with('error', 'The minimum value cannot exceed the maximum.');
        }
        if ($data['kind'] === 'stored' && $data['face_value']) {
            return back()->withInput()->with('error', 'Stored-value cards are loaded with any amount – leave the fixed value empty.');
        }
        $data['usable_on'] = array_values(array_unique($data['usable_on']));
        $data['color'] = $data['color'] ?? '#166534';
        $data['is_active'] = $request->boolean('is_active', ! $gvtype);
        $gvtype ? $gvtype->update($data) : $gvtype = GiftVoucherType::create($data);
        \App\Services\ActivityLogger::log('vouchers', 'type', "Voucher type “{$gvtype->name}” saved", $gvtype);

        return redirect()->route('vouchers.index', ['tab' => 'types'])->with('success', "Voucher type “{$gvtype->name}” saved.");
    }

    public function saveSettings(Request $request)
    {
        $data = $request->validate(['enabled' => 'nullable|boolean', 'validity' => 'required|integer|min:1|max:120', 'min' => 'required|numeric|min:0', 'max' => 'required|numeric|min:1',
            'reminder_days' => 'required|integer|min:0|max:180', 'terms' => 'nullable|string|max:1000']);
        try {
            Gv::saveSettings($data);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return redirect()->route('vouchers.index', ['tab' => 'settings'])->with('success', 'Gift voucher settings saved.');
    }

    /** run the daily job now: expire overdue vouchers and send expiry reminders */
    public function runNow()
    {
        $e = Gv::expireDue();
        $r = Gv::remindExpiring();

        return back()->with('success', "{$e['count']} voucher(s) expired (".money($e['amount']).' breakage) · '.$r.' expiry reminder(s) e-mailed.');
    }

    /** public voucher page – the code itself is the bearer secret (40 random bits, throttled) */
    public function publicPage(string $code)
    {
        $v = GiftVoucher::withoutGlobalScopes()->where('code', strtoupper($code))->first();
        abort_unless($v, 404);
        $hotel = Hotel::findOrFail($v->hotel_id);
        Tenant::set($hotel);
        $v->load('type');
        $recent = GiftVoucherTransaction::where('gift_voucher_id', $v->id)->whereIn('type', ['redeem', 'reversal', 'reload', 'expire'])->orderByDesc('id')->limit(8)->get();

        return view('public.voucher', ['hotel' => $hotel, 'v' => $v, 'recent' => $recent, 'cfg' => Gv::cfg(),
            'qr' => \App\Services\EInvoiceService::qrSvg(route('voucher.public', $v->code))]);
    }
}
