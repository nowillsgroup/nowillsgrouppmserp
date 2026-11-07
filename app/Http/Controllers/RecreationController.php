<?php

namespace App\Http\Controllers;

use App\Models\ClubTicketSale;
use App\Models\ClubTicketType;
use App\Models\PaymentMode;
use App\Models\Setting;
use App\Services\ActivityLogger;
use App\Services\RecreationService;
use App\Services\RecreationShiftService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * v7.38 Recreation ticketing — POS-style outlet under Wellness & Recreation.
 * Walk-ins buy tickets/rentals/services here; club membership is untouched.
 */
class RecreationController extends Controller
{
    private function modes()
    {
        return PaymentMode::where('is_active', true)->whereIn('type', ['cash', 'card', 'bank', 'wallet'])->orderBy('id')->get();
    }

    // ───────────────────────── ticket outlet (POS) ─────────────────────────

    public function outlet()
    {
        RecreationService::ensureTypes();
        $shift = RecreationShiftService::active();

        $modes = $this->modes();

        return view('recreation.outlet', [
            'types' => ClubTicketType::where('is_active', true)->orderBy('sort')->orderBy('id')->get()->groupBy('category'),
            'modesJson' => $modes->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'type' => $m->type])->values()->all(),
            'stats' => RecreationService::todayStats(),
            'rate' => \App\Services\ClubBillingService::tax(100)['rate'],
            'shift' => $shift,
            'shiftSummary' => $shift ? RecreationShiftService::summary($shift) : null,
            'shiftRequired' => setting('recreation_shift_required', '0') === '1',
        ]);
    }

    // ───────────────────────── outlet shift (X / Z) ─────────────────────────

    public function shift(Request $request)
    {
        $mine = RecreationShiftService::active();
        // A manager on duty can pull up a cashier's open shift (?shift_id=) and close it for
        // them — the count, the variance and the audit trail stay identical.
        $target = $mine;
        if ($request->filled('shift_id') && (int) $request->query('shift_id') !== (int) ($mine?->id)) {
            abort_unless($request->user()->hasPermission('club.manage'), 403);
            $target = \App\Models\DepartmentShiftSession::with('user')->where('department', RecreationShiftService::DEPARTMENT)
                ->where('status', 'open')->findOrFail((int) $request->query('shift_id'));
        }
        $latest = \App\Models\DepartmentShiftSession::with('user')->where('department', RecreationShiftService::DEPARTMENT)
            ->orderByDesc('opened_at')->limit(12)->get();

        // The history shows a closed shift's frozen snapshot; an open one is summarised live.
        $live = $latest->where('status', 'open')->mapWithKeys(fn ($s) => [$s->id => RecreationShiftService::summary($s)]);

        return view('recreation.shift', [
            'shift' => $target,
            'summary' => $target ? RecreationShiftService::summary($target) : null,
            'recent' => $latest,
            'live' => $live,
            'denominations' => RecreationShiftService::DENOMINATIONS,
            'dayTotal' => RecreationShiftService::dayReport(),
            'shiftRequired' => setting('recreation_shift_required', '0') === '1',
        ]);
    }

    public function openShift(Request $request)
    {
        $d = $request->validate(['opening_float' => 'required|numeric|min:0|max:1000000', 'note' => 'nullable|string|max:500']);
        try {
            RecreationShiftService::open($request->user(), (float) $d['opening_float'], $d['note'] ?? null);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('recreation.shift')->with('success', 'Outlet shift opened with a float of '.money((float) $d['opening_float']).'.');
    }

    public function closeShift(Request $request)
    {
        $shift = RecreationShiftService::active();
        if ($request->filled('shift_id') && (int) $request->input('shift_id') !== (int) ($shift?->id)) {
            abort_unless($request->user()->hasPermission('club.manage'), 403);
            $shift = \App\Models\DepartmentShiftSession::where('department', RecreationShiftService::DEPARTMENT)
                ->where('status', 'open')->findOrFail((int) $request->input('shift_id'));
        }
        if (! $shift) {
            return back()->with('error', 'You have no open outlet shift to close.');
        }
        $d = $request->validate([
            'counted_cash' => 'required|numeric|min:0|max:10000000', 'note' => 'nullable|string|max:1000',
            'denominations' => 'nullable|array', 'denominations.*' => 'nullable|integer|min:0|max:9999',
        ]);
        try {
            $closed = RecreationShiftService::close($shift, $request->user(), (float) $d['counted_cash'], $d['note'] ?? null, $d['denominations'] ?? []);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        $message = 'Shift closed for '.($closed->user?->name ?? 'the cashier')
            .'. Expected '.money($closed->expected_cash).', counted '.money($closed->counted_cash).', variance '.money($closed->variance).'.';
        if ($request->boolean('print')) {
            return redirect()->route('recreation.day.print', ['date' => $closed->business_date?->toDateString() ?? bdate()->toDateString()])
                ->with('success', $message);
        }

        return redirect()->route('recreation.shift')->with('success', $message);
    }

    /** Outlet counter policy: is an open shift compulsory before money is taken? */
    public function saveSettings(Request $request)
    {
        abort_unless($request->user()->hasPermission('club.manage'), 403);
        Setting::updateOrCreate(['key' => 'recreation_shift_required'], ['value' => $request->boolean('recreation_shift_required') ? '1' : '0']);
        ActivityLogger::log('recreation', 'settings', $request->boolean('recreation_shift_required')
            ? 'A cashier shift is now required before the outlet will take money'
            : 'The outlet may now take money without an open shift');

        return back()->with('success', 'Outlet counter settings saved.'.($request->boolean('recreation_shift_required')
            ? ' A shift must now be open before the till will take money.' : ' Cashiers may now sell without opening a shift.'));
    }

    // ───────────────────────── the gate (admissions) ─────────────────────────

    public function gate(Request $request)
    {
        $board = RecreationService::gateBoard($request->query('date'));

        return view('recreation.gate', [
            'board' => $board,
            'recent' => $board['passes']->take(25),
            'stats' => RecreationService::todayStats(),
        ]);
    }

    public function redeem(Request $request)
    {
        $d = $request->validate([
            'code' => 'required|string|max:20',
            'pax' => 'nullable|integer|min:1|max:20',
            'note' => 'nullable|string|max:120',
        ]);
        try {
            $pass = RecreationService::redeem($d['code'], $request->user(), isset($d['pax']) ? (int) $d['pax'] : null, $d['note'] ?? null);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first())->withInput();
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('recreation.gate')->with('success', 'Admitted '.$pass->pass_no.' – '
            .($pass->redeemed_pax ?: $pass->pax()).' guest(s)'.($pass->name ? ' for '.$pass->name : '').'.');
    }

    public function reverseAdmission(Request $request, \App\Models\ClubDayPass $daypass)
    {
        abort_unless($request->user()->hasPermission('club.manage'), 403);
        $d = $request->validate(['reason' => 'required|string|max:120']);
        try {
            $pass = RecreationService::reverseAdmission($daypass, $d['reason'], $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('recreation.gate')->with('success', 'Admission of '.$pass->pass_no.' reversed — the ticket is valid at the gate again.');
    }

    // ───────────────────────── day report (Z) ─────────────────────────

    public function day(Request $request)
    {
        $date = $request->query('date');

        return view('recreation.day', ['report' => RecreationShiftService::dayReport($date)]);
    }

    public function dayPrint(Request $request)
    {
        $date = $request->query('date');

        return view('recreation.day-print', ['report' => RecreationShiftService::dayReport($date)]);
    }

    public function sell(Request $request)
    {
        try {
            $sale = RecreationService::sell($request->all(), $request->user());

            return response()->json([
                'ok' => true,
                'sale_id' => $sale->id,
                'sale_no' => $sale->sale_no,
                'total' => money($sale->total),
                'change' => money($sale->change_due),
                'tickets' => $sale->passes->map(fn ($p) => ['pass_no' => $p->pass_no, 'name' => $p->name, 'pax' => $p->adults + $p->children])->values(),
                'receipt_url' => route('recreation.receipt', $sale),
            ]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'message' => collect($e->errors())->flatten()->first()], 422);
        } catch (RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    // ───────────────────────── sales & receipts ─────────────────────────

    public function sales(Request $request)
    {
        $date = $request->query('date');
        $rows = ClubTicketSale::with(['items.type', 'payments.paymentMode', 'payments.receiver', 'user', 'stay.room', 'shift'])
            ->when($date, fn ($q) => $q->whereDate('sold_at', $date))
            ->latest('sold_at')->latest('id')->paginate(40)->withQueryString();
        $scope = ClubTicketSale::when($date, fn ($q) => $q->whereDate('sold_at', $date));

        return view('recreation.sales', [
            'rows' => $rows,
            'date' => $date,
            'sumTotal' => (clone $scope)->where('status', 'paid')->sum('total'),
            'sumTax' => (clone $scope)->where('status', 'paid')->sum('tax'),
            'sumCount' => (clone $scope)->where('status', 'paid')->count(),
            'sumTickets' => (clone $scope)->where('status', 'paid')->withSum('passes as pax', \DB::raw('adults + children'))->get()->sum('pax'),
        ]);
    }

    public function receipt(ClubTicketSale $sale)
    {
        $sale->load(['items.type', 'payments.paymentMode', 'passes', 'host', 'stay.room', 'user']);

        return view('recreation.receipt', ['sale' => $sale]);
    }

    public function void(Request $request, ClubTicketSale $sale)
    {
        try {
            RecreationService::void($sale, (string) $request->input('reason', ''), $request->user());

            return back()->with('success', "Sale {$sale->sale_no} voided. Refund the visitor if payment was taken.");
        } catch (ValidationException $e) {
            return back()->withInput()->with('error', collect($e->errors())->flatten()->first());
        }
    }

    // ───────────────────────── ticket type master ─────────────────────────

    public function types()
    {
        return view('recreation.types', [
            'rows' => ClubTicketType::orderBy('sort')->orderBy('id')->get(),
            'rate' => \App\Services\ClubBillingService::tax(100)['rate'],
        ]);
    }

    public function saveType(Request $request, ?ClubTicketType $type = null)
    {
        $d = $request->validate([
            'code' => ['required', 'string', 'max:16', 'alpha_dash', Rule::unique('club_ticket_types')->where('hotel_id', \App\Support\Tenant::id())->ignore($type?->id)],
            'name' => 'required|string|max:80',
            'category' => ['required', Rule::in(array_keys(ClubTicketType::CATEGORIES))],
            'pax_type' => ['required', Rule::in(array_keys(ClubTicketType::PAX_TYPES))],
            'price' => 'required|numeric|min:0|max:99999',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'issues_pass' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'sort' => 'nullable|integer|min:0|max:9999',
        ]);
        $d['code'] = strtoupper($d['code']);
        $d['issues_pass'] = $request->boolean('issues_pass');
        $d['is_active'] = $request->boolean('is_active');
        $d['sort'] = (int) ($d['sort'] ?? 0);
        $t = $type ? tap($type)->update($d) : ClubTicketType::create($d);
        ActivityLogger::log('club', 'ticket_type', "Ticket type {$t->code} – {$t->name} (".money($t->price).') saved', $t);

        return back()->with('success', "Ticket type {$t->name} saved.");
    }
}
