<?php

namespace App\Http\Controllers;

use App\Models\Hotel;
use App\Models\OnlinePayment;
use App\Services\OnlinePaymentService;
use App\Services\Payments\DemoGateway;
use App\Support\Tenant;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Public payment pages – /pay/{token}. The 40-character token is the only credential and exposes one
 * payment request (payer name, description, amount). Card / wallet details are entered on the
 * gateway's hosted page, never here. Webhooks: POST /webhooks/pay/{provider}/{HOTELCODE}.
 */
class PayController extends Controller
{
    protected function payment(string $token): OnlinePayment
    {
        $p = OnlinePayment::withoutGlobalScopes()->where('token', $token)->firstOrFail();
        $hotel = Hotel::find($p->hotel_id);
        abort_unless($hotel?->is_active, 404);
        Tenant::set($hotel);

        return OnlinePayment::with('reservation.rooms.roomType', 'folio')->findOrFail($p->id);
    }

    protected function page(OnlinePayment $p, array $data = [])
    {
        $r = $p->reservation;

        return response()->view('pay.show', $data + ['p' => $p, 'hotel' => hotel(), 'r' => $r, 'test' => $p->provider === 'demo',
            'bookingDone' => $p->purpose === 'booking' && $r && $p->status === 'paid' ? route('booking.done', [hotel()->code, $r->reservation_no]) : null])
            ->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow')->header('Cache-Control', 'no-store, private');
    }

    public function show(string $token)
    {
        return $this->page($this->payment($token));
    }

    public function start(string $token)
    {
        $p = $this->payment($token);
        try {
            return redirect()->away(OnlinePaymentService::start($p));
        } catch (RuntimeException $e) {
            report($e);

            return redirect()->route('pay.show', $token)->withErrors(['pay' => str_contains($e->getMessage(), 'failed:') ? 'The payment page could not be opened – please try again in a moment.' : $e->getMessage()]);
        }
    }

    public function back(Request $request, string $token)
    {
        $p = OnlinePaymentService::handleReturn($this->payment($token), $request->query());
        if ($p->status === 'pending' && $p->last_error) {
            return redirect()->route('pay.show', $token)->withErrors(['pay' => $p->last_error.' You can try again.']);
        }

        return redirect()->route('pay.show', $token);
    }

    /** The built-in test gateway's "hosted checkout" page. */
    public function demo(Request $request, string $token)
    {
        $p = $this->payment($token);
        abort_unless($p->provider === 'demo' && $p->isPayable() && $request->query('ref') === $p->provider_ref, 404);

        return response()->view('pay.demo', ['p' => $p, 'hotel' => hotel(), 'ref' => $p->provider_ref])->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function demoSubmit(Request $request, string $token)
    {
        $p = $this->payment($token);
        abort_unless($p->provider === 'demo' && $p->isPayable(), 404);
        $data = $request->validate(['ref' => 'required|string', 'result' => 'required|in:success,declined,insufficient', 'channel' => 'required|in:card,mobile_money,upi']);
        abort_unless($data['ref'] === $p->provider_ref, 404);

        return redirect()->route('pay.return', ['token' => $token, 'ref' => $data['ref'], 'result' => $data['result'], 'channel' => $data['channel'],
            'sig' => DemoGateway::sign($p, $data['ref'], $data['result'], $data['channel'])]);
    }

    /** Server-to-server notification from the gateway (no session, no CSRF, signature-verified). */
    public function webhook(Request $request, string $provider, string $code)
    {
        abort_unless(in_array($provider, ['paystack', 'stripe', 'razorpay']), 404);
        $hotel = Hotel::where('code', strtoupper($code))->where('is_active', true)->first();
        abort_unless($hotel, 404);
        Tenant::set($hotel);
        [$status, $msg] = OnlinePaymentService::webhook($provider, $request);

        return response()->json(['status' => $msg], $status);
    }
}
