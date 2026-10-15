<?php

namespace App\Http\Controllers;

use App\Services\ReservationWorkspaceService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** v7.41 Reservation Workspace — the room board (KPIs, room cards, right rail). */
class ReservationWorkspaceController extends Controller
{
    public function index(Request $request)
    {
        $snap = ReservationWorkspaceService::snapshot($this->boardDate($request));

        return view('reservations.workspace', $snap);
    }

    /** v7.41 — the board date: ?date=YYYY-MM-DD, else today (clamped ±62 days). */
    protected function boardDate(Request $request): Carbon
    {
        $raw = (string) $request->query('date', '');
        try {
            $date = Carbon::parse($raw ?: 'today')->startOfDay();
        } catch (\Throwable) {
            return Carbon::today();
        }

        $min = Carbon::today()->subDays(62);
        $max = Carbon::today()->addDays(62);

        return ($date->gte($min) && $date->lte($max)) ? $date : Carbon::today();
    }

    /** v7.41 — the printable Day Report for the workspace board. */
    public function report(Request $request)
    {
        $snap = ReservationWorkspaceService::snapshot($this->boardDate($request));

        return view('reservations.workspace-report', $snap);
    }
}
