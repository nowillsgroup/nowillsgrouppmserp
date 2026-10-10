<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** v4.0 – `api.scope:reservations:write` – the key must hold that scope. */
class ApiScope
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $client = $request->attributes->get('api_client');
        if (! $client || ! $client->hasScope($scope)) {
            return ApiAuthenticate::error('insufficient_scope', "This API key lacks the {$scope} permission.", 403, [], ['required_scope' => $scope]);
        }

        return $next($request);
    }
}
