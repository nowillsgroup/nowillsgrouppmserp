<?php

namespace App\Http\Controllers;

use App\Models\Hotel;
use App\Models\MarketingMessage;
use App\Services\CrmService;
use App\Support\Tenant;
use Illuminate\Http\Request;

/**
 * Public, tokenised marketing links: the tracked booking link and the unsubscribe / preferences page.
 * The token identifies one marketing message (and so one guest) – it is random and never reveals an id.
 */
class MarketingLinkController extends Controller
{
    protected function message(string $token): MarketingMessage
    {
        $m = MarketingMessage::withoutGlobalScope('hotel')->where('token', $token)->firstOrFail();
        Tenant::set(Hotel::findOrFail($m->hotel_id));

        return $m->setRelation('guest', $m->guest()->first());
    }

    public function click(string $token)
    {
        $m = $this->message($token);
        MarketingMessage::whereKey($m->id)->update(['click_count' => $m->click_count + 1, 'clicked_at' => $m->clicked_at ?? now()]);
        $promo = $m->campaign?->promo ?? $m->journey?->promo;

        return redirect()->away(CrmService::bookUrl($promo, $m->token));
    }

    public function preferences(string $token)
    {
        if ($token === 'preview') { // link shown in previews and test sends
            abort_unless(auth()->check(), 404);
            Tenant::check() || Tenant::set(Hotel::findOrFail(session('hotel_id') ?? auth()->user()->hotel_id ?? Hotel::min('id')));

            return view('public.unsubscribe', ['hotel' => hotel(), 'guest' => null, 'token' => null]);
        }
        $m = $this->message($token);

        return view('public.unsubscribe', ['hotel' => hotel(), 'guest' => $m->guest, 'token' => $token]);
    }

    public function save(Request $request, string $token)
    {
        $m = $this->message($token);
        abort_unless($m->guest, 404);
        $keep = $request->input('action') === 'all' ? [] : (array) $request->input('keep', []);
        $wanted = collect(CrmService::CHANNELS)->map(fn ($l, $ch) => in_array($ch, $keep, true) && $m->guest->{$ch.'_opt_in'})->all(); // can only switch OFF here
        $n = CrmService::setConsent($m->guest, $wanted, 'unsubscribe', $request->ip());
        if ($n) {
            MarketingMessage::whereKey($m->id)->whereNull('unsubscribed_at')->update(['unsubscribed_at' => now()]);
        }

        return back()->with('success', $n ? 'Your preferences are saved. You will no longer receive the messages you switched off.' : 'No changes – your preferences are as before.');
    }

    public function oneClick(Request $request, string $token)
    {
        $m = $this->message($token);
        if ($m->guest && CrmService::setConsent($m->guest, array_fill_keys(array_keys(CrmService::CHANNELS), false), 'unsubscribe', $request->ip())) {
            MarketingMessage::whereKey($m->id)->whereNull('unsubscribed_at')->update(['unsubscribed_at' => now()]);
        }

        return response('Unsubscribed.', 200, ['Content-Type' => 'text/plain']);
    }
}
