<?php

namespace App\Http\Controllers;

use App\Models\ClubTicketSale;
use App\Models\ClubTicketType;
use App\Models\PaymentMode;
use App\Services\ActivityLogger;
use App\Services\RecreationService;
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

        return view('recreation.outlet', [
            'types' => ClubTicketType::where('is_active', true)->orderBy('sort')->orderBy('id')->get()->groupBy('category'),
            'modes' => $this->modes(),
            'stats' => RecreationService::todayStats(),
            'rate' => \App\Services\ClubBillingService::tax(100)['rate'],
        ]);
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
        $rows = ClubTicketSale::with(['items.type', 'payments.paymentMode', 'user', 'stay.room'])
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
