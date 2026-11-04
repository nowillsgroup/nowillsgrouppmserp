<?php

namespace App\Http\Middleware;

use App\Services\DepartmentShiftService;
use Closure;
use Illuminate\Http\Request;

class RequireWaiterServiceShift
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (! DepartmentShiftService::requiresPersonalShift($user)
            || DepartmentShiftService::activeForUser((int) $user->id)) {
            return $next($request);
        }

        $message = 'Open your Food & Beverage service shift before using the POS terminal.';
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'shift_url' => route('department-shifts.index'),
            ], 423);
        }

        return redirect()->route('department-shifts.index')->with('error', $message);
    }
}
