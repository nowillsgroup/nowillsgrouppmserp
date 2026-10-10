<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequirePermission
{
    public function handle(Request $request, Closure $next, string ...$perms)
    {
        $user = $request->user();
        foreach ($perms as $p) {
            if ($user && $user->hasPermission($p)) {
                return $next($request);
            }
        }
        abort(403, 'You do not have permission to access this function.');
    }
}
