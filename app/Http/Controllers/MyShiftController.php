<?php

namespace App\Http\Controllers;

use App\Services\DepartmentShiftService;
use App\Services\RecreationShiftService;
use App\Services\FrontDeskShiftSessionService;
use App\Services\ShiftService;
use Illuminate\Http\Request;

class MyShiftController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && collect(['logbook.view', 'folio.shift', 'pos.order', 'pos.shift_view_all', 'club.desk'])->contains(fn ($p) => $user->hasPermission($p)), 403);
        $cashierShift = $user->hasPermission('folio.shift') ? ShiftService::current($user) : null;
        $outletShift = $user->hasPermission('club.desk') ? RecreationShiftService::active((int) $user->id) : null;

        return view('my-shift.index', [
            'canFrontDesk' => $user->hasPermission('logbook.view'),
            'canManageFrontDesk' => $user->hasPermission('logbook.write'),
            'frontDeskSession' => $user->hasPermission('logbook.view') ? FrontDeskShiftSessionService::activeForUser((int) $user->id) : null,
            'frontDeskPending' => $user->hasPermission('logbook.view') ? FrontDeskShiftSessionService::pendingCount((int) $user->id) : 0,
            'canCashier' => $user->hasPermission('folio.shift'),
            'cashierShift' => $cashierShift,
            'cashierSummary' => $cashierShift ? ShiftService::summary($cashierShift) : null,
            'canOutlet' => $user->hasPermission('club.desk'),
            'outletShift' => $outletShift,
            'outletSummary' => $outletShift ? RecreationShiftService::summary($outletShift) : null,
            'canService' => $user->hasPermission('pos.order'),
            'serviceShift' => $user->hasPermission('pos.order') ? DepartmentShiftService::activeForUser((int) $user->id) : null,
            'canViewServiceTeam' => $user->hasPermission('pos.shift_view_all'),
            'date' => bdate()->toDateString(),
        ]);
    }
}
