<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Folio;
use App\Models\FolioTransaction;
use App\Models\Guest;
use App\Models\PaymentMode;
use App\Services\ActivityLogger;
use App\Services\FolioService;
use App\Services\GiftVoucherService;
use App\Services\ShiftService;
use App\Services\TaxService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FolioController extends Controller
{
    public function index(Request $request)
    {
        $folios = Folio::with(['guest', 'stay.room', 'reservation', 'company'])
            ->when($request->input('status', 'open') !== 'all', fn ($q) => $q->where('status', $request->input('status', 'open')))
            ->when($request->q, fn ($q, $t) => $q->where(fn ($w) => $w->where('folio_no', 'like', "%$t%")->orWhere('invoice_no', 'like', "%$t%")->orWhereHas('guest', fn ($g) => $g->search($t))))
            ->latest()->paginate(25)->withQueryString();

        return view('folios.index', compact('folios'));
    }

    public function invoices(Request $request)
    {
        $from = $request->input('from', bdate()->copy()->startOfMonth()->toDateString());
        $to = $request->input('to', bdate()->toDateString());
        $folios = Folio::with(['guest', 'company', 'stay.room', 'original:id,invoice_no'])->whereNotNull('invoice_no')
            // a plain BETWEEN drops the last day: invoice_date is stored as a datetime
            // string, so it is never <= the range's date-only upper bound
            ->whereDate(DB::raw('COALESCE(invoice_date, closed_at)'), '>=', $from)
            ->whereDate(DB::raw('COALESCE(invoice_date, closed_at)'), '<=', $to)
            ->when($request->type === 'invoice', fn ($q) => $q->where('type', '!=', 'credit_note'))->when($request->type === 'credit_note', fn ($q) => $q->where('type', 'credit_note'))
            ->when($request->supply, fn ($q, $s) => $q->where('supply_type', $s))
            ->when($request->q, fn ($q, $t) => $q->where(fn ($w) => $w->where('invoice_no', 'like', "%$t%")->orWhere('buyer_gstin', 'like', "%$t%")->orWhere('buyer_name', 'like', "%$t%")->orWhereHas('guest', fn ($g) => $g->search($t))))
            ->latest('closed_at')->paginate(30)->withQueryString();

        return view('folios.invoices', compact('folios', 'from', 'to'));
    }

    public function show(Folio $folio)
    {
        $folio->load(['guest', 'company', 'stay.room', 'stay.roomType', 'reservation']);

        return view('folios.show', [
            'folio' => $folio,
            'transactions' => $folio->transactions()->with('paymentMode', 'poster', 'company')->orderBy('business_date')->orderBy('id')->get(),
            'totals' => $folio->totals(),
            'paymentModes' => PaymentMode::where('is_active', true)->where('type', '!=', 'city_ledger')->pluck('name', 'id'),
            'companies' => Company::where('is_active', true)->pluck('name', 'id'),
            'categories' => collect(config('pms.charge_categories'))->except(['room']),
            // other open folios this folio's lines may be split / routed to
            'fixedCharges' => $folio->stay ? $folio->stay->fixedCharges()->latest('id')->get() : collect(),
            'targets' => Folio::with('guest', 'stay.room')->where('status', 'open')->where('id', '!=', $folio->id)
                ->where(fn ($q) => $q->when($folio->reservation_id, fn ($w) => $w->orWhere('reservation_id', $folio->reservation_id))
                    ->when($folio->guest_id, fn ($w) => $w->orWhere('guest_id', $folio->guest_id))
                    ->orWhereIn('type', ['master']))->orderByDesc('id')->limit(50)->get(),
        ]);
    }

    public function previewCharge(Request $request, Folio $folio)
    {
        $data = $request->validate([
            'category' => 'required|in:'.implode(',', array_keys(config('pms.charge_categories'))),
            'description' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0.01|max:9999999',
            'apply_tax' => 'nullable|boolean',
        ]);
        if ($folio->status !== 'open') {
            $msg = 'Folio '.$folio->folio_no.' is closed. Nothing was posted.';
            return $request->expectsJson()
                ? response()->json(['ok' => false, 'message' => $msg], 422)
                : back()->with('error', $msg);
        }
        $amount = round((float) $data['amount'], 2);
        $tax = $request->boolean('apply_tax', true) ? TaxService::forCategory($data['category'], $amount) : ['rate' => 0, 'amount' => 0];
        $msg = $data['description'].' would post '.money($amount).' plus tax '.money($tax['amount']).' at '.$tax['rate'].'%. Total '.money($amount + $tax['amount']).'. Nothing is saved until you confirm.';
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => $msg]);
        }

        return back()->with('success', $msg);
    }

    public function charge(Request $request, Folio $folio)
    {
        $data = $request->validate([
            'category' => 'required|in:'.implode(',', array_keys(config('pms.charge_categories'))),
            'description' => 'required|string|max:255', 'amount' => 'required|numeric|min:0.01|max:9999999',
            'qty' => 'nullable|numeric|min:0.01', 'apply_tax' => 'nullable|boolean',
        ]);
        $amount = (float) $data['amount'] * (float) ($data['qty'] ?? 1);
        $desc = $data['description'].(($data['qty'] ?? 1) != 1 ? " × {$data['qty']}" : '');
        FolioService::charge($folio, $data['category'], $desc, $amount, $request->boolean('apply_tax', true));
        ActivityLogger::log('folio', 'charge', "Posted {$desc} ".money($amount)." to {$folio->folio_no}", $folio);

        return back()->with('success', 'Charge posted.');
    }

    public function previewPayment(Request $request, Folio $folio)
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01', 'payment_mode_id' => 'required|integer|owned:payment_modes',
            'reference' => 'nullable|string|max:80', 'type' => 'required|in:payment,refund',
            'momo_network' => 'nullable|string|max:20', 'momo_phone' => 'nullable|string|max:30',
        ]);
        if ($folio->status !== 'open') {
            $msg = 'Folio '.$folio->folio_no.' is closed. Nothing was posted.';

            return $request->expectsJson()
                ? response()->json(['ok' => false, 'message' => $msg], 422)
                : back()->with('error', $msg);
        }
        try {
            ShiftService::assertOpen();
        } catch (RuntimeException $e) {
            $msg = $e->getMessage().' Nothing was posted.';

            return $request->expectsJson()
                ? response()->json(['ok' => false, 'message' => $msg], 422)
                : back()->with('error', $msg);
        }
        $amount = round((float) $data['amount'], 2);
        $reference = $this->momoReference($request, $data['reference'] ?? null);
        $mode = PaymentMode::find($data['payment_mode_id']);
        $next = $data['type'] === 'refund' ? $folio->balance() + $amount : $folio->balance() - $amount;
        $msg = ucfirst($data['type']).' of '.money($amount).' by '.($mode->name ?? 'the selected mode')
            .($reference ? ' · '.$reference : '')
            .' would leave '.money($next).' on '.$folio->folio_no.'.';
        if (GiftVoucherService::isVoucherMode((int) $data['payment_mode_id'])) {
            $msg .= ' Confirm redeems the voucher code in the reference. It is not redeemed now.';
        }
        $msg .= ' Nothing is saved until you confirm.';
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => $msg]);
        }

        return back()->with('success', $msg);
    }

    public function payment(Request $request, Folio $folio)
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01', 'payment_mode_id' => 'required|integer|owned:payment_modes',
            'reference' => 'nullable|string|max:80', 'type' => 'required|in:payment,refund',
            'momo_network' => 'nullable|string|max:20', 'momo_phone' => 'nullable|string|max:30',
            'cheque_bank_name' => 'nullable|string|max:100', 'cheque_number' => 'nullable|string|max:20', 'cheque_bank_branch' => 'nullable|string|max:100',
        ]);
        $data['reference'] = $this->momoReference($request, $data['reference'] ?? null);
        FolioService::payment($folio, (float) $data['amount'], (int) $data['payment_mode_id'], $data['reference'] ?? null, 'settlement', $data['type'], [
            'bank_name' => $data['cheque_bank_name'] ?? null, 'number' => $data['cheque_number'] ?? null, 'bank_branch' => $data['cheque_bank_branch'] ?? null,
        ]);
        ActivityLogger::log('folio', $data['type'], ucfirst($data['type']).' '.money($data['amount'])." on {$folio->folio_no}", $folio);

        return back()->with('success', ucfirst($data['type']).' recorded.');
    }

    public function discount(Request $request, Folio $folio)
    {
        $data = $request->validate(['mode' => 'required|in:amount,percent', 'value' => 'required|numeric|min:0.01', 'reason' => 'required|string|max:200']);
        $amount = $data['mode'] === 'percent' ? round($folio->totals()['charges'] * min(100, $data['value']) / 100, 2) : (float) $data['value'];
        FolioService::discount($folio, $amount, $data['reason']);
        ActivityLogger::log('folio', 'discount', 'Discount '.money($amount)." on {$folio->folio_no}: {$data['reason']}", $folio);

        return back()->with('success', 'Discount applied.');
    }

    public function transfer(Request $request, Folio $folio)
    {
        $data = $request->validate(['company_id' => 'required|integer|owned:companies', 'override_reason' => 'nullable|string|max:200']);
        FolioService::transferToCityLedger($folio, (int) $data['company_id'], $data['override_reason'] ?? null);
        $folio->update(['company_id' => $data['company_id']]);
        ActivityLogger::log('folio', 'transfer', "Transferred {$folio->folio_no} balance to city ledger", $folio);

        return back()->with('success', 'Balance transferred to city ledger.');
    }

    public function void(Request $request, FolioTransaction $tx)
    {
        $data = $request->validate(['reason' => 'required|string|max:200']);
        FolioService::void($tx, $data['reason']);
        ActivityLogger::log('folio', 'void', "Voided '{$tx->description}' ".money($tx->total)." on {$tx->folio->folio_no}: {$data['reason']}", $tx->folio);

        return back()->with('success', 'Transaction voided.');
    }

    public function close(Folio $folio)
    {
        abort_if($folio->stay && $folio->stay->status === 'checked_in', 422, 'Use check-out to close an in-house folio.');
        FolioService::close($folio);
        ActivityLogger::log('folio', 'close', "Closed {$folio->folio_no}, invoice {$folio->invoice_no}", $folio);

        return redirect()->route('folios.invoice', $folio)->with('success', 'Folio settled. Invoice '.$folio->invoice_no);
    }

    public function reopen(Folio $folio)
    {
        abort_unless($folio->status === 'closed', 422);
        if ($why = \App\Services\GstService::reopenBlocker($folio)) {
            return back()->with('error', $why);
        }
        $folio->update(['status' => 'open']);
        ActivityLogger::log('folio', 'reopen', "Re-opened {$folio->folio_no} (invoice {$folio->invoice_no})", $folio);

        return back()->with('success', 'Folio re-opened. Invoice number is retained.');
    }

    public function receipt(FolioTransaction $tx)
    {
        abort_unless(in_array($tx->type, ['payment', 'refund'], true), 404);
        $tx->load(['folio.guest', 'folio.stay.room', 'folio.reservation', 'paymentMode', 'poster']);

        return view('folios.receipt', ['tx' => $tx, 'folio' => $tx->folio]);
    }

    public function invoice(Folio $folio)
    {
        $folio->load('guest', 'company', 'stay.room', 'stay.roomType', 'reservation', 'original');
        $tx = $folio->status === 'cancelled' ? $folio->transactions()->with('paymentMode')->orderBy('business_date')->orderBy('id')->get()
            : $folio->activeTransactions()->with('paymentMode')->orderBy('business_date')->orderBy('id')->get();

        // v4.3: tax summary by category and rate (VAT / NHIL / GETFund / Tourism Levy), same grouping as E-VAT and the VAT return
        return view('folios.invoice', ['folio' => $folio, 'tx' => $tx, 'totals' => $folio->totals(), 'lines' => \App\Services\GstService::lines($folio), 'gst' => $folio->gstTotals()]);
    }

    public function walkIn()
    {
        return view('folios.walkin', ['companies' => Company::where('is_active', true)->pluck('name', 'id')]);
    }

    public function storeWalkIn(Request $request)
    {
        $data = $request->validate(['first_name' => 'required|string|max:80', 'last_name' => 'nullable|string|max:80', 'phone' => 'nullable|string|max:30', 'company_id' => 'nullable|integer|owned:companies', 'tax_number' => 'nullable|string|max:40|tin']);
        $guest = Guest::create(collect($data)->only(['first_name', 'last_name', 'phone', 'tax_number', 'company_id'])->all());
        $folio = FolioService::openStandalone(['guest_id' => $guest->id, 'company_id' => $data['company_id'] ?? null, 'type' => 'walk_in']);
        ActivityLogger::log('folio', 'create', "Opened non-resident folio {$folio->folio_no}", $folio);

        return redirect()->route('folios.show', $folio)->with('success', 'Non-resident folio opened.');
    }

    protected function momoReference(Request $request, ?string $reference): ?string
    {
        if (! $request->filled('momo_network') && ! $request->filled('momo_phone')) {
            return $reference;
        }

        return mb_substr(trim(implode(' · ', array_filter([$request->input('momo_network'), $request->input('momo_phone'), $reference]))), 0, 80);
    }

    /**
     * Split / route selected transactions to another open folio (or a new split folio for the same guest & stay).
     * Lines keep their original dates, tax and poster; the move is written to the activity log on both folios.
     */
    public function move(Request $request, Folio $folio)
    {
        $data = $request->validate([
            'tx' => 'required|array|min:1', 'tx.*' => 'integer|owned:folio_transactions',
            'target' => ['required', 'regex:/^(new|\d+)$/'], 'reason' => 'required|string|max:200',
        ]);
        abort_unless($folio->status === 'open', 422, 'Folio is closed.');
        $moved = DB::transaction(function () use ($data, $folio) {
            if ($data['target'] === 'new') {
                $target = FolioService::openStandalone(['type' => 'guest', 'guest_id' => $folio->guest_id, 'company_id' => $folio->company_id,
                    'reservation_id' => $folio->reservation_id, 'reservation_room_id' => $folio->reservation_room_id]);
            } else {
                $target = Folio::lockForUpdate()->findOrFail((int) $data['target']);
                if ($target->id === $folio->id || $target->status !== 'open') {
                    throw new \RuntimeException('Target folio must be a different, open folio.');
                }
            }
            $lines = $folio->transactions()->whereIn('id', $data['tx'])->where('is_void', false)->lockForUpdate()->get();
            if ($lines->count() !== count(array_unique($data['tx']))) {
                throw new \RuntimeException('Some selected lines are void or do not belong to this folio.');
            }
            $folio->transactions()->whereIn('id', $lines->pluck('id'))->update(['folio_id' => $target->id]);
            $desc = $lines->count().' line(s) ('.money($lines->sum(fn ($t) => $t->type === 'charge' ? $t->amount + $t->tax_amount : 0)).' charges)';
            ActivityLogger::log('folio', 'split', "Moved {$desc} from {$folio->folio_no} to {$target->folio_no}: {$data['reason']}", $folio);
            ActivityLogger::log('folio', 'split', "Received {$desc} from {$folio->folio_no}: {$data['reason']}", $target);

            return $target;
        });

        return redirect()->route('folios.show', $folio)->with('success', "Selected lines moved to folio {$moved->folio_no}.");
    }

    public function addFixed(Request $request, Folio $folio)
    {
        abort_unless($folio->status === 'open' && $folio->stay && in_array($folio->stay->status, ['reserved', 'checked_in']), 422, 'Fixed charges need an open folio of an active stay.');
        $stay = $folio->stay;
        $data = $request->validate([
            'description' => 'required|string|max:120', 'category' => 'required|in:'.implode(',', array_keys(config('pms.charge_categories'))),
            'amount' => 'required|numeric|min:0.01|max:1000000', 'apply_tax' => 'nullable|boolean', 'frequency' => 'required|in:daily,once',
            'start_date' => 'required|date|after_or_equal:'.$stay->arrival_date->toDateString().'|'.($request->input('frequency') === 'once' ? 'before_or_equal:' : 'before:').$stay->departure_date->toDateString(),
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);
        $c = \App\Models\StayFixedCharge::create($data + ['reservation_room_id' => $stay->id, 'apply_tax' => $request->boolean('apply_tax'), 'created_by' => auth()->id()]);
        ActivityLogger::log('folio', 'fixed_charge', "Added {$c->frequency} fixed charge '{$c->description}' ".money($c->amount)." to {$folio->folio_no}", $folio);

        return back()->with('success', 'Fixed charge added. It posts automatically with each night'.($c->frequency === 'once' ? ' (once).' : '.'));
    }

    public function removeFixed(\App\Models\StayFixedCharge $fixedCharge)
    {
        $fixedCharge->update(['is_active' => false]);
        ActivityLogger::log('folio', 'fixed_charge', "Stopped fixed charge '{$fixedCharge->description}'", $fixedCharge->stay?->folio);

        return back()->with('success', 'Fixed charge stopped. Charges already posted stay on the folio (void them if needed).');
    }
}
