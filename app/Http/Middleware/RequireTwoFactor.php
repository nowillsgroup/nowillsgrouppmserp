<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** When "require_2fa_admins" is on, administrators without 2FA can only reach the enrolment page. */
class RequireTwoFactor
{
    public const ALLOWED = ['profile', 'profile.update', 'profile.2fa', 'profile.2fa.enable', 'logout', 'switch.hotel'];

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user && ! $user->hasTwoFactor() && $user->isAdministrator() && setting('require_2fa_admins', '0') === '1'
            && ! in_array($request->route()?->getName(), self::ALLOWED, true)) {
            if ($request->expectsJson()) {
                abort(403, 'Two-factor authentication must be enabled first.');
            }

            return redirect()->route('profile.2fa')->with('error', 'Your property requires administrators to use two-factor authentication. Set it up to continue.');
        }

        return $next($request);
    }
}
