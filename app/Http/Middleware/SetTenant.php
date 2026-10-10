<?php

namespace App\Http\Middleware;

use App\Models\Hotel;
use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Resolves the active hotel from the session and verifies that the
 * authenticated user is entitled to operate on it.
 */
class SetTenant
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        $hotelId = (int) $request->session()->get('hotel_id', $user?->hotel_id);
        $hotel = $hotelId ? Hotel::find($hotelId) : null;

        $allowed = $hotel && $hotel->is_active && $user && $user->is_active && (
            $hotel->id === $user->hotel_id ||
            ($user->is_group_admin && $user->hotel_group_id && $hotel->hotel_group_id === $user->hotel_group_id)
        );

        if (! $allowed) {
            Auth::logout();
            $request->session()->invalidate();

            return redirect()->route('login')->withErrors(['username' => 'Your session is not valid for this property. Please sign in again.']);
        }

        Tenant::set($hotel);
        config(['app.timezone' => $hotel->timezone]);
        date_default_timezone_set($hotel->timezone ?: 'UTC');
        view()->share('currentHotel', $hotel);

        return $next($request);
    }
}
