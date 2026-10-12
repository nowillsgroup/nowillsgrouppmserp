<?php

namespace App\Http\Controllers;

use App\Services\ReservationWorkspaceService;
use Illuminate\Support\Carbon;

/** v7.41 Reservation Workspace — the room board (KPIs, room cards, right rail). */
class ReservationWorkspaceController extends Controller
{
    public function index()
    {
        $snap = ReservationWorkspaceService::snapshot(Carbon::today());

        return view('reservations.workspace', $snap);
    }
}
