<?php

namespace App\Http\Middleware;

use App\Models\Hotel;
use App\Models\OrganiserUser;
use App\Services\OrganiserService;
use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;

/**
 * v7.5 Organiser portal session: separate from staff sign-in (its own session key, never the 'web' guard). Resolves
 * the hotel, re-checks the login / portal switch on every request, signs out after the idle limit or when the password
 * was changed elsewhere, and keeps portal pages out of shared caches.
 */
class OrganiserAuth
{
    public function handle(Request $request, Closure $next)
    {
        $s = $request->session()->get('organiser');
        $hotel = $s ? Hotel::find($s['hotel_id'] ?? 0) : null;
        if (! $s || ! $hotel || ! $hotel->is_active) {
            $request->session()->forget('organiser');

            return redirect()->route('organiser.login')->withErrors(['email' => $s ? 'Please sign in again.' : 'Please sign in to the organiser portal.']);
        }
        Tenant::set($hotel);
        config(['app.timezone' => $hotel->timezone]);
        date_default_timezone_set($hotel->timezone ?: 'UTC');

        $u = OrganiserUser::with('company')->find($s['id'] ?? 0);
        $idle = (int) setting('organiser_idle', 30) * 60;
        $why = match (true) {
            ! $u || ! $u->is_active || $u->password === null => 'Your portal access has been disabled.',
            ! OrganiserService::eligibleUser($u) => 'Portal access for this login is disabled – please contact the hotel.',
            (int) ($s['pw'] ?? 0) !== (int) $u->password_changed_at?->timestamp => 'Your password was changed – please sign in again.',
            time() - (int) ($s['at'] ?? 0) > $idle => 'You were signed out after '.($idle / 60).' minutes without activity.',
            default => null,
        };
        if ($why) {
            $request->session()->forget('organiser');
            if ($u && str_contains($why, 'without activity')) {
                OrganiserService::audit($u, 'timeout', 'Idle sign-out');
            }

            return redirect()->route('organiser.login', ['h' => $hotel->code])->withErrors(['email' => $why]);
        }
        $request->session()->put('organiser.at', time());
        $request->attributes->set('organiser', $u);
        view()->share(['organiser' => $u, 'company' => $u->company, 'hotel' => $hotel, 'currentHotel' => $hotel]);

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
