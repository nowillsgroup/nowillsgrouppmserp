<?php

namespace App\Http\Middleware;

use App\Services\LicenceService;
use Closure;
use Illuminate\Http\Request;

/**
 * The route gate: the person must hold one of the permissions, *and* the tenant must have bought the
 * module that permission belongs to (v10.3).
 *
 * The order matters. Checking the licence first would tell a user who has no right to a screen that
 * the screen exists and whose fault its absence is; checking permission first keeps an unlicensed
 * module indistinguishable from a forbidden one for somebody who was never allowed in.
 */
class RequirePermission
{
    public function handle(Request $request, Closure $next, string ...$perms)
    {
        $user = $request->user();
        foreach ($perms as $p) {
            if ($user && $user->hasPermission($p) && LicenceService::licensed($p)) {
                return $next($request);
            }
        }

        // Only say "not on the licence" when the person would otherwise have been allowed in.
        foreach ($perms as $p) {
            if ($user && $user->hasPermission($p) && ! LicenceService::licensed($p)) {
                $module = LicenceService::moduleOf($p);
                abort(403, ($module ? 'The '.LicenceService::moduleLabel($module).' module' : 'That function').' is not on this group\'s licence. Ask your group administrator.');
            }
        }

        abort(403, 'You do not have permission to access this function.');
    }
}
