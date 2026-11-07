<?php

namespace App\Http\Middleware;

use App\Exceptions\ModuleNotLicensed;
use App\Services\LicenceService;
use App\Support\Tenant;
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
                if ($module === null) {
                    break;   // a permission outside the catalogue is never refused for a licence
                }

                // v10.6 an explained refusal: the module, the property, and whether the group still
                // holds it — because those two fixes belong to two different people.
                throw new ModuleNotLicensed($module, Tenant::hotel(), LicenceService::groupHolds($module), $p);
            }
        }

        abort(403, 'You do not have permission to access this function.');
    }
}
