<?php

namespace App\Http\Middleware;

use App\Models\Hotel;
use App\Models\PartnerUser;
use App\Services\PartnerService;
use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;

/**
 * v7.4 Partner portal session: separate from staff sign-in (its own session key, never the 'web' guard). Resolves the hotel,
 * re-checks the login / company / portal switch on every request, signs out after the idle limit or when the password was
 * changed elsewhere, and keeps portal pages out of shared caches.
 */
class PartnerAuth
{
    public function handle(Request $request, Closure $next)
    {
        $s = $request->session()->get('partner');
        $hotel = $s ? Hotel::find($s['hotel_id'] ?? 0) : null;
        if (! $s || ! $hotel || ! $hotel->is_active) {
            $request->session()->forget('partner');

            return redirect()->route('partner.login')->withErrors(['email' => $s ? 'Please sign in again.' : 'Please sign in to the partner portal.']);
        }
        Tenant::set($hotel);
        config(['app.timezone' => $hotel->timezone]);
        date_default_timezone_set($hotel->timezone ?: 'UTC');

        $u = PartnerUser::with('company')->find($s['id'] ?? 0);
        $idle = (int) setting('partner_idle', 30) * 60;
        $why = match (true) {
            ! $u || ! $u->is_active || $u->password === null => 'Your portal access has been disabled.',
            ! PartnerService::enabled() || ! PartnerService::eligible($u->company) => 'Portal access for your company is disabled – please contact the hotel.',
            (int) ($s['pw'] ?? 0) !== (int) $u->password_changed_at?->timestamp => 'Your password was changed – please sign in again.',
            time() - (int) ($s['at'] ?? 0) > $idle => 'You were signed out after '.($idle / 60).' minutes without activity.',
            default => null,
        };
        if ($why) {
            $request->session()->forget('partner');
            if ($u && str_contains($why, 'without activity')) {
                PartnerService::audit($u, 'timeout', 'Idle sign-out');
            }

            return redirect()->route('partner.login', ['h' => $hotel->code])->withErrors(['email' => $why]);
        }
        $request->session()->put('partner.at', time());
        $request->attributes->set('partner', $u);
        view()->share(['partner' => $u, 'company' => $u->company, 'hotel' => $hotel, 'currentHotel' => $hotel]);

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
