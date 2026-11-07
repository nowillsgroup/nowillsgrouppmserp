<?php

namespace App\Http\Middleware;

use App\Models\Hotel;
use App\Services\PropertyAccessService;
use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Resolves the active hotel from the session and verifies that the
 * authenticated user is entitled to operate on it.
 *
 * v10.0: entitlement is decided by PropertyAccessService (own property, group administration or an
 * explicit multi-property grant), and a property that has been suspended or retired while somebody
 * was sitting in it moves that person to another property they can work in instead of signing them
 * out of the building. Only a session with nowhere left to go is ended.
 */
class SetTenant
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        $hotelId = (int) $request->session()->get('hotel_id', $user?->hotel_id);
        $hotel = $hotelId ? Hotel::find($hotelId) : null;
        $access = app(PropertyAccessService::class);

        $allowed = $hotel && $user && $access->canEnter($user, $hotel);

        if (! $allowed) {
            $fallback = $user && $user->is_active ? $access->defaultFor($user) : null;

            // Somewhere else to work: move them there rather than throw them out mid-shift.
            if ($fallback && (! $hotel || (int) $fallback->id !== (int) $hotel->id)) {
                $request->session()->put('hotel_id', $fallback->id);
                Tenant::set($fallback);
                config(['app.timezone' => $fallback->timezone ?: 'UTC']);
                date_default_timezone_set($fallback->timezone ?: 'UTC');
                view()->share('currentHotel', $fallback);

                if (! $request->isMethod('GET')) {
                    return redirect()->route('dashboard')->with('warning', $this->movedFrom($hotel).' You have been moved to '.$fallback->name.'; please repeat the action there.');
                }

                return redirect()->route('dashboard')->with('warning', $this->movedFrom($hotel).' You have been moved to '.$fallback->name.'.');
            }

            Auth::logout();
            $request->session()->invalidate();

            return redirect()->route('login')->withErrors(['username' => $this->why($hotel)]);
        }

        Tenant::set($hotel);
        config(['app.timezone' => $hotel->timezone]);
        date_default_timezone_set($hotel->timezone ?: 'UTC');
        view()->share('currentHotel', $hotel);

        return $next($request);
    }

    protected function movedFrom(?Hotel $hotel): string
    {
        if (! $hotel) {
            return 'Your property is no longer available.';
        }

        if ($hotel->group?->isSuspended()) {
            return $hotel->group->name.' is suspended, so '.$hotel->name.' cannot be opened.';
        }

        return $hotel->isSuspended()
            ? $hotel->name.' is suspended.'
            : ($hotel->isArchived() ? $hotel->name.' has been retired.' : 'Your access to '.$hotel->name.' was removed.');
    }

    protected function why(?Hotel $hotel): string
    {
        if ($hotel && $hotel->group?->isSuspended()) {
            return 'The '.$hotel->group->name.' account is suspended. Please contact your platform administrator.';
        }
        if ($hotel && $hotel->isSuspended()) {
            return $hotel->name.' is suspended. Please contact your group administrator.';
        }
        if ($hotel && $hotel->isArchived()) {
            return $hotel->name.' has been retired. Please contact your group administrator.';
        }

        return 'Your session is not valid for this property. Please sign in again.';
    }
}
