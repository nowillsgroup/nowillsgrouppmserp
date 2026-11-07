<?php

namespace App\Http\Middleware;

use App\Services\LicenceService;
use App\Support\LicenceSurfaces;
use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * v10.4 – the licence reaches the guest.
 *
 * Runs on every request (it is in the global stack), but it only acts on the paths in
 * config/licence.php — the pages and endpoints served to somebody who is not signed in, which
 * RequirePermission can never see because there is no user and no permission.
 *
 * Three outcomes:
 *
 *   · nothing claims the path          → the request goes through untouched (every signed-in screen)
 *   · the property is known            → an unlicensed module answers 404 before the controller runs,
 *                                       so a POST never executes its side effect
 *   · the property is not known yet    → for a read surface marked `after`, the check is repeated once
 *                                       the controller has resolved the stay/device/kiosk and the page
 *                                       is refused instead of returned; a write surface is left to
 *                                       the audit (a POST must not run and then be regretted)
 *
 * 404, not 403: an unlicensed module should not even confirm that the outlet, device or voucher
 * exists for this property.
 */
class LicenceSurface
{
    public function handle(Request $request, Closure $next): Response
    {
        $surface = LicenceSurfaces::surfaceFor('/'.$request->path());
        if ($surface === null) {
            return $next($request);
        }

        // A surface a licence may never refuse (sign-in, the health probe, the offline shell).
        if (($surface['module'] ?? null) === null) {
            return $next($request);
        }

        // /api/v1 is enforced by ApiAuthenticate, which knows the hotel from the bearer key.
        if (! empty($surface['api'])) {
            return $next($request);
        }

        $path = '/'.$request->path();
        if (! LicenceSurfaces::looksLikeGuest($surface, $path)) {
            return $next($request); // the same prefix, but this is a signed-in screen (see looksLikeGuest)
        }

        $hotel = LicenceSurfaces::propertyFor($surface, $path);

        if ($hotel !== null) {
            return LicenceService::allowsModule((string) $surface['module'], $hotel)
                ? $next($request)
                : $this->refuse();
        }

        $response = $next($request);

        if (! empty($surface['after']) && $request->isMethod('GET')) {
            $resolved = Tenant::hotel();
            if ($resolved && ! LicenceService::allowsModule((string) $surface['module'], $resolved)) {
                return $this->refuse();
            }
        }

        return $response;
    }

    protected function refuse(): Response
    {
        abort(404);
    }
}
