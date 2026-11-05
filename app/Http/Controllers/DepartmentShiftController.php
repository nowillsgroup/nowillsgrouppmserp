<?php

namespace App\Http\Controllers;

use App\Models\DepartmentShiftSession;
use App\Services\DepartmentShiftService;
use Illuminate\Http\Request;
use RuntimeException;

class DepartmentShiftController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->hasPermission('pos.order') || $request->user()->hasPermission('pos.shift_view_all'), 403);
        $data = $request->validate(['date' => 'nullable|date']);

        return view('department-shifts.index', DepartmentShiftService::workspace($request->user(), $data['date'] ?? null));
    }

    public function start(Request $request)
    {
        abort_unless($request->user()->hasPermission('pos.order'), 403);
        $data = $request->validate(['opening_note' => 'nullable|string|max:500']);
        try {
            DepartmentShiftService::startFoodService($request->user(), $data['opening_note'] ?? null);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $message = DepartmentShiftService::requiresPersonalShift($request->user())
            ? 'Your Food & Beverage service shift is open. The full-page table terminal is ready; tap a free table to start an order. Orders will be linked to your close-out.'
            : 'Your Food & Beverage service shift is open. POS order entry is unlocked, and new orders will be linked to your close-out.';

        return redirect()->route('pos.index')->with('success', $message);
    }

    public function close(Request $request, DepartmentShiftSession $session)
    {
        abort_unless($request->user()->hasPermission('pos.order'), 403);
        $data = $request->validate(['closing_note' => 'nullable|string|max:1000']);
        try {
            DepartmentShiftService::close($session, $request->user(), $data['closing_note'] ?? null);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('department-shifts.show', $session)->with('success', 'Your service shift is closed and its close-out report is saved.');
    }

    public function show(Request $request, DepartmentShiftSession $session)
    {
        abort_unless($session->department === DepartmentShiftService::FOOD_BEVERAGE, 404);
        abort_unless((int) $session->user_id === (int) $request->user()->id || $request->user()->hasPermission('pos.shift_view_all'), 403);
        $session->load(['user', 'posOrders.outlet', 'posOrders.table']);

        return view('department-shifts.show', ['session' => $session, 'summary' => DepartmentShiftService::summary($session)]);
    }

    /** Printable report for a whole business date: every service shift and what each collected. */
    public function printDay(Request $request)
    {
        abort_unless($request->user()->hasPermission('pos.order') || $request->user()->hasPermission('pos.shift_view_all'), 403);
        $data = $request->validate(['date' => 'nullable|date']);

        return view('department-shifts.day-print', DepartmentShiftService::workspace($request->user(), $data['date'] ?? null));
    }

    /** Printable live totals sheet the waiter can hand over with the bill folder. */
    public function print(Request $request, DepartmentShiftSession $session)
    {
        abort_unless($session->department === DepartmentShiftService::FOOD_BEVERAGE, 404);
        abort_unless((int) $session->user_id === (int) $request->user()->id || $request->user()->hasPermission('pos.shift_view_all'), 403);
        $session->load(['user', 'posOrders.outlet', 'posOrders.table']);

        return view('department-shifts.print', ['session' => $session, 'summary' => DepartmentShiftService::summary($session)]);
    }
}
