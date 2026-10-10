<?php

namespace App\Http\Controllers;

use App\Models\Folio;
use App\Models\GatewayPayout;
use App\Models\OnlinePayment;
use App\Models\PaymentMode;
use App\Models\Reservation;
use App\Models\Setting;
use App\Services\ActivityLogger;
use App\Services\OnlinePaymentService as Svc;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

class OnlinePaymentController extends Controller
{
    protected const PLAIN = ['pay_enabled', 'pay_provider', 'pay_razorpay_key_id', 'pay_link_hours', 'pay_booking_deposit', 'pay_booking_deposit_pct',
        'pay_booking_hold_minutes', 'pay_portal_enabled', 'pay_receipt_email'];

    public function index(Request $request)
    {
        $q = OnlinePayment::with('reservation', 'folio', 'creator')->latest('id');
        $status = $request->input('status', '');
        match ($status) {
            '' => null,
            'expired' => $q->where(fn ($w) => $w->where('status', 'expired')->orWhere(fn ($x) => $x->where('status', 'pending')->where('expires_at', '<', now()))),
            'pending' => $q->where('status', 'pending')->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>=', now())),
            'refunded' => $q->where('refunded_amount', '>', 0),
            default => $q->where('status', $status),
        };
        if ($s = trim((string) $request->input('q'))) {
            $q->where(fn ($w) => $w->where('pay_no', 'like', "%{$s}%")->orWhere('payer_name', 'like', "%{$s}%")->orWhere('provider_payment_id', 'like', "%{$s}%")
                ->orWhereHas('reservation', fn ($r) => $r->where('reservation_no', 'like', "%{$s}%")));
        }
        $today = now()->startOfDay();
        $paid = fn () => OnlinePayment::whereIn('status', ['paid']);
        $stats = [
            'today' => (float) $paid()->where('paid_at', '>=', $today)->sum('amount_received'),
            'today_n' => $paid()->where('paid_at', '>=', $today)->count(),
            'month' => (float) $paid()->where('paid_at', '>=', now()->startOfMonth())->sum('amount_received'),
            'pending' => (float) OnlinePayment::where('status', 'pending')->where('expires_at', '>=', now())->sum('amount'),
            'pending_n' => OnlinePayment::where('status', 'pending')->where('expires_at', '>=', now())->count(),
            'review_n' => OnlinePayment::where('status', 'review')->count(),
            'refunds' => (float) \App\Models\OnlineRefund::where('created_at', '>=', now()->startOfMonth())->sum('amount'),
            'clearing' => Svc::clearingBalance(),
        ];

        return view('payments.index', ['list' => $q->paginate(25)->withQueryString(), 'status' => $status, 'stats' => $stats,
            'enabled' => Svc::enabled(), 'provider' => Svc::provider()]);
    }

    public function show(OnlinePayment $olp)
    {
        $olp->load('reservation.guest', 'folio', 'creator', 'folioTransaction.folio', 'events.user', 'refunds.creator');
        $opts = new \chillerlan\QRCode\QROptions(['outputType' => \chillerlan\QRCode\Output\QROutputInterface::MARKUP_SVG, 'outputBase64' => false, 'svgAddXmlHeader' => false,
            'eccLevel' => \chillerlan\QRCode\Common\EccLevel::M, 'addQuietzone' => true]);

        return view('payments.show', ['p' => $olp, 'qr' => $olp->isPayable() ? (new \chillerlan\QRCode\QRCode($opts))->render($olp->url()) : null]);
    }

    /** Create a link from a reservation (deposit) or a folio (balance). */
    public function store(Request $request)
    {
        $data = $request->validate([
            'reservation_id' => 'nullable|integer|owned:reservations', 'folio_id' => 'nullable|integer|owned:folios',
            'amount' => 'required|numeric|min:1|max:10000000', 'description' => 'nullable|string|max:190', 'hours' => 'nullable|integer|min:1|max:720',
            'email' => 'nullable|email|max:150', 'phone' => 'nullable|string|max:30', 'send' => 'nullable|array', 'send.*' => 'in:email,sms,whatsapp',
        ]);
        $r = ! empty($data['reservation_id']) ? Reservation::find($data['reservation_id']) : null;
        $f = ! empty($data['folio_id']) ? Folio::find($data['folio_id']) : null;
        if (in_array('email', $data['send'] ?? []) && ! (($data['email'] ?? null) ?: ($f?->guest ?? $r?->guest)?->email)) {
            return back()->withInput()->with('error', 'No e-mail address – enter one to send the link by e-mail.');
        }
        $p = Svc::create($data, $r, $f);
        $msg = "Payment link {$p->pay_no} created for ".money($p->amount).'.';
        $flash = 'success';
        if (! empty($data['send'])) {
            $res = Svc::send($p, $data['send']);
            $msg .= ' '.collect($res)->map(fn ($x) => ucfirst($x[1]))->implode(' · ').'.';
            $flash = collect($res)->every(fn ($x) => $x[0]) ? 'success' : 'warning';
        }

        return redirect()->route('payments.show', $p)->with($flash, $msg);
    }

    public function send(Request $request, OnlinePayment $olp)
    {
        $data = $request->validate(['send' => 'required|array|min:1', 'send.*' => 'in:email,sms,whatsapp', 'email' => 'nullable|email|max:150', 'phone' => 'nullable|string|max:30']);
        abort_unless($olp->isPayable(), 422, 'This link can no longer be paid.');
        $olp->forceFill(array_filter(['payer_email' => $data['email'] ?? null, 'payer_phone' => $data['phone'] ?? null]))->save();
        $res = Svc::send($olp, $data['send']);

        return back()->with(collect($res)->every(fn ($x) => $x[0]) ? 'success' : 'warning', collect($res)->map(fn ($x) => ucfirst($x[1]))->implode(' · ').'.');
    }

    public function cancel(Request $request, OnlinePayment $olp)
    {
        $data = $request->validate(['reason' => 'required|string|max:190']);
        Svc::cancel($olp, $data['reason']);

        return back()->with('success', "Payment link {$olp->pay_no} cancelled.");
    }

    public function refund(Request $request, OnlinePayment $olp)
    {
        $data = $request->validate(['amount' => 'required|numeric|min:0.01', 'reason' => 'required|string|max:190']);
        $rf = Svc::refund($olp, (float) $data['amount'], $data['reason']);

        return back()->with('success', 'Refunded '.money($rf->amount).($rf->status === 'pending' ? ' – the gateway is processing it.' : '.').' The refund is posted to the folio.');
    }

    public function accept(Request $request, OnlinePayment $olp)
    {
        $data = $request->validate(['note' => 'required|string|max:190']);
        Svc::accept($olp, $data['note']);

        return back()->with('success', 'Payment accepted and posted to the folio.');
    }

    /** Ask the gateway for the latest status (e.g. a missed webhook). */
    public function check(OnlinePayment $olp)
    {
        abort_unless($olp->status === 'pending' && $olp->provider_ref, 422, 'Nothing to check.');
        $p = Svc::handleReturn($olp, []);

        return back()->with($p->status === 'paid' ? 'success' : 'warning', $p->status === 'paid' ? 'The gateway confirmed the payment – posted to the folio.' : 'No completed payment at the gateway yet'.($p->last_error ? ': '.$p->last_error : '.'));
    }

    // ------------------------------------------------------------------ payouts

    public function payouts()
    {
        return view('payments.payouts', [
            'list' => GatewayPayout::with('paymentMode', 'creator')->latest('date')->latest('id')->paginate(25),
            'clearing' => Svc::clearingBalance(), 'modes' => PaymentMode::where('is_active', true)->whereIn('type', ['bank', 'cash'])->orderBy('name')->pluck('name', 'id'),
            'provider' => Svc::provider(), 'lock' => \App\Services\LedgerService::lockDate(),
            'month' => GatewayPayout::where('date', '>=', now()->startOfMonth()->toDateString())->selectRaw('COALESCE(SUM(gross), 0) g, COALESCE(SUM(fees), 0) f')->first(),
        ]);
    }

    public function storePayout(Request $request)
    {
        $data = $request->validate([
            'date' => 'required|date|before_or_equal:today', 'provider' => ['required', Rule::in(array_keys(Svc::PROVIDERS))],
            'gross' => 'required|numeric|min:0.01', 'fees' => 'nullable|numeric|min:0', 'payment_mode_id' => 'required|integer|owned:payment_modes',
            'reference' => 'nullable|string|max:120', 'notes' => 'nullable|string|max:255',
        ]);
        abort_unless(PaymentMode::whereKey($data['payment_mode_id'])->whereIn('type', ['bank', 'cash'])->exists(), 422, 'Choose the bank account the payout landed in.');
        $po = Svc::recordPayout($data);

        return back()->with('success', "Payout {$po->payout_no} recorded – ".money($po->net()).' to '.$po->paymentMode->name.'.');
    }

    public function destroyPayout(GatewayPayout $gpo)
    {
        Svc::deletePayout($gpo);

        return back()->with('success', "Payout {$gpo->payout_no} deleted.");
    }

    // ------------------------------------------------------------------ settings

    public function settings()
    {
        $v = Setting::whereIn('key', array_merge(self::PLAIN, Svc::SECRETS))->pluck('value', 'key');

        return view('payments.settings', ['v' => $v, 'secretSet' => collect(Svc::SECRETS)->mapWithKeys(fn ($k) => [$k => ! empty($v[$k])]),
            'hooks' => collect(['paystack', 'stripe', 'razorpay'])->mapWithKeys(fn ($p) => [$p => route('pay.webhook', [$p, hotel()->code])]),
            'configured' => collect(array_keys(Svc::PROVIDERS))->mapWithKeys(fn ($p) => [$p => Svc::configured($p)])]);
    }

    public function saveSettings(Request $request)
    {
        $data = $request->validate([
            'pay_provider' => ['required', Rule::in(array_keys(Svc::PROVIDERS))], 'pay_razorpay_key_id' => 'nullable|string|max:64',
            'pay_link_hours' => 'required|integer|min:1|max:720', 'pay_booking_deposit' => ['required', Rule::in(array_keys(Svc::DEPOSIT_POLICIES))],
            'pay_booking_deposit_pct' => 'nullable|required_if:pay_booking_deposit,percent|numeric|min:1|max:100', 'pay_booking_hold_minutes' => 'required|integer|min:15|max:1440',
            'pay_paystack_secret' => 'nullable|string|max:200', 'pay_stripe_secret' => 'nullable|string|max:200', 'pay_stripe_webhook_secret' => 'nullable|string|max:200',
            'pay_razorpay_key_secret' => 'nullable|string|max:200', 'pay_razorpay_webhook_secret' => 'nullable|string|max:200', 'clear_secrets' => 'nullable|array',
        ]);
        foreach (self::PLAIN as $k) {
            $val = in_array($k, ['pay_enabled', 'pay_portal_enabled', 'pay_receipt_email']) ? ($request->boolean($k) ? '1' : '0') : ($data[$k] ?? null);
            Setting::updateOrCreate(['key' => $k], ['value' => $val]);
        }
        Svc::saveSecrets($data, $data['clear_secrets'] ?? []);
        Svc::ensureDefaults();
        $warn = $request->boolean('pay_enabled') && ! Svc::configured($data['pay_provider']);
        ActivityLogger::log('settings', 'payments', 'Updated online payment settings (provider '.$data['pay_provider'].', '.($request->boolean('pay_enabled') ? 'on' : 'off').')');

        return back()->with($warn ? 'warning' : 'success', $warn ? 'Saved – but '.ucfirst($data['pay_provider']).' is missing its keys, so online payments stay unavailable.' : 'Online payment settings saved.');
    }
}
