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

    /** The board date is strict YYYY-MM-DD and bounded around the hotel's business date. */
    protected function boardDate(Request $request): Carbon
    {
        $businessDate = bdate()->copy()->startOfDay();
        $queryDate = $request->query('date', '');
        $raw = is_string($queryDate) ? trim($queryDate) : '';
        if ($raw === '') {
            return $businessDate;
        }

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $raw);
            if (! $date || $date->format('Y-m-d') !== $raw) {
                return $businessDate;
            }
        } catch (\Throwable) {
            return $businessDate;
        }

        $min = $businessDate->copy()->subDays(62);
        $max = $businessDate->copy()->addDays(62);

        return ($date->gte($min) && $date->lte($max)) ? $date : $businessDate;
    }

    /** v7.41 — the printable Day Report for the workspace board. */
    public function report(Request $request)
    {
        $snap = ReservationWorkspaceService::snapshot($this->boardDate($request));

        return view('reservations.workspace-report', $snap);
    }
}
