<?php

namespace App\Http\Controllers;

use App\Models\CashierShift;
use App\Models\FolioTransaction;
use App\Models\PosOrder;
use App\Models\User;
use App\Services\ShiftService;
use Illuminate\Http\Request;

class ShiftController extends Controller
{
    protected function authorizeShift(CashierShift $shift): void
    {
        abort_unless($shift->user_id === auth()->id() || can_do('folio.shift_all'), 403);
    }

    public function index(Request $request)
    {
        $all = can_do('folio.shift_all');
        $current = ShiftService::current();
        $history = CashierShift::with('user')->when(! $all, fn ($q) => $q->where('user_id', auth()->id()))
            ->when($all && $request->filled('user'), fn ($q) => $q->where('user_id', $request->integer('user')))
            ->latest('opened_at')->paginate(20)->withQueryString();

        return view('shifts.index', [
            'current' => $current, 'summary' => $current ? ShiftService::summary($current) : null, 'history' => $history, 'all' => $all,
            'movements' => $current ? $current->movements()->with('user')->latest('id')->limit(20)->get() : collect(),
            'openShifts' => $all ? CashierShift::with('user')->where('status', 'open')->where('user_id', '!=', auth()->id())->get() : collect(),
            'users' => $all ? User::where('hotel_id', hotel()->id)->orderBy('name')->pluck('name', 'id') : collect(),
            'required' => ShiftService::required(),
        ]);
    }

    public function open(Request $request)
    {
        $data = $request->validate(['opening_float' => 'required|numeric|min:0|max:10000000', 'notes' => 'nullable|string|max:250']);
        ShiftService::open((float) $data['opening_float'], $data['notes'] ?? null);

        return redirect()->route('shifts.index')->with('success', 'Shift opened. All payments you receive are now recorded on this shift.');
    }

    public function cashIn(Request $request)
    {
        $data = $request->validate(['amount' => 'required|numeric|min:0.01|max:10000000', 'reason' => 'required|string|max:190', 'reference' => 'nullable|string|max:80']);
        ShiftService::cashIn((float) $data['amount'], $data['reason'], $data['reference'] ?? null);

        return back()->with('success', 'Cash added to the drawer.');
    }

    public function cashOut(Request $request)
    {
        $data = $request->validate(['amount' => 'required|numeric|min:0.01|max:10000000', 'reason' => 'required|string|max:190', 'reference' => 'nullable|string|max:80']);
        ShiftService::cashOut((float) $data['amount'], $data['reason'], $data['reference'] ?? null);

        return back()->with('success', 'Cash removed from the drawer.');
    }

    public function close(Request $request, CashierShift $shift)
    {
        $this->authorizeShift($shift);
        $data = $request->validate([
            'counted_cash' => 'required|numeric|min:0|max:100000000', 'notes' => 'nullable|string|max:250',
            'denominations' => 'nullable|array', 'denominations.*' => 'nullable|integer|min:0|max:100000',
        ]);
        $shift = ShiftService::close($shift, (float) $data['counted_cash'], $data['notes'] ?? null, $data['denominations'] ?? []);
        $msg = abs((float) $shift->variance) < 0.01 ? 'Shift closed – cash tallies exactly.' : 'Shift closed with a variance of '.money($shift->variance).'.';

        return redirect()->route('shifts.index')->with(abs((float) $shift->variance) < 0.01 ? 'success' : 'error', $msg);
    }

    public function show(CashierShift $shift)
    {
        $this->authorizeShift($shift);

        $from = $shift->opened_at;
        $to = $shift->closed_at ?? now();
        $folioTransactions = FolioTransaction::with('paymentMode', 'folio.guest')->where('posted_by', $shift->user_id)
            ->whereBetween('created_at', [$from, $to])->where('is_void', false)->whereIn('type', ['payment', 'refund'])
            ->latest('created_at')->get();
        $posSettlements = PosOrder::with('paymentMode')->where('settled_by', $shift->user_id)->whereBetween('settled_at', [$from, $to])
            ->where('status', 'settled')->where('settlement', 'payment')->latest('settled_at')->get();

        return view('shifts.show', ['shift' => $shift->load('user', 'closedBy', 'movements.user'), 'summary' => ShiftService::summary($shift),
            'folioTransactions' => $folioTransactions, 'posSettlements' => $posSettlements]);
    }
}
