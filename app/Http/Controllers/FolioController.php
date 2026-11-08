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
        $reservationDeskScope = $request->input('scope') === 'reservation_desk';
        $rawStatus = $request->query('status', 'open');
        $status = is_string($rawStatus) && in_array($rawStatus, ['open', 'closed', 'all'], true) ? $rawStatus : 'open';
        $request->merge(['status' => $status]);
        $rawSearch = $request->query('q');
        $search = is_string($rawSearch) ? mb_substr(trim($rawSearch), 0, 160) : '';
        $request->merge(['q' => $search]);
        $folios = Folio::withListBalance()->with(['guest', 'stay.room', 'stay.guest', 'stay.reservation.guest', 'stay.reservation.company', 'reservation.guest', 'reservation.company', 'company'])
            ->when($reservationDeskScope, fn ($q) => $q->where(fn ($w) => $w->whereNotNull('reservation_id')->orWhereNotNull('reservation_room_id')))
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($search !== '', fn ($q) => $q->where(function ($w) use ($search) {
                $pattern = "%{$search}%";
                $reservationMatch = fn ($reservation) => $reservation->where('reservation_no', 'like', $pattern)
                    ->orWhere('group_name', 'like', $pattern)
                    ->orWhereHas('company', fn ($company) => $company->where('name', 'like', $pattern));

                $w->where('folio_no', 'like', $pattern)
                    ->orWhere('invoice_no', 'like', $pattern)
                    ->orWhereHas('guest', fn ($guest) => $guest->search($search))
                    ->orWhereHas('company', fn ($company) => $company->where('name', 'like', $pattern))
                    ->orWhereHas('reservation', $reservationMatch)
                    ->orWhereHas('stay.reservation', $reservationMatch);
            }))
            ->latest()->paginate(25)->withQueryString();

        return view('folios.index', compact('folios', 'reservationDeskScope'));
    }

    public function transactions(Request $request)
    {
        $rawDate = $request->query('date');
        $date = is_string($rawDate) && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $rawDate)
            && checkdate((int) substr($rawDate, 5, 2), (int) substr($rawDate, 8, 2), (int) substr($rawDate, 0, 4))
            ? $rawDate
            : bdate()->toDateString();
        $rawType = $request->query('type');
        $type = is_string($rawType) && in_array($rawType, ['charge', 'discount', 'allowance', 'payment', 'refund', 'opening_balance'], true) ? $rawType : 'all';
        $categoryOptions = array_merge(config('pms.charge_categories', []), [
            'advance' => 'Advance payment', 'city_ledger' => 'City ledger', 'settlement' => 'Settlement',
            'opening_ar' => 'Opening balance', 'opening_balance' => 'Opening balance',
        ]);
        $rawCategory = $request->query('category');
        $category = is_string($rawCategory) && array_key_exists($rawCategory, $categoryOptions) ? $rawCategory : '';
        $query = FolioTransaction::with(['folio.guest', 'folio.company', 'folio.stay.room', 'folio.stay.guest', 'folio.stay.reservation.guest', 'folio.stay.reservation.company', 'folio.reservation.guest', 'folio.reservation.company', 'paymentMode', 'poster'])
            ->whereDate('business_date', $date)->where('is_void', false)
            ->when($type !== 'all', fn ($q) => $q->where('type', $type))
            ->when($category !== '', fn ($q) => $q->where('category', $category));
        $summary = (clone $query)->selectRaw("count(*) as entries,
            coalesce(sum(case type when 'charge' then amount when 'discount' then -amount when 'allowance' then -amount when 'refund' then -amount else amount end), 0) as amount,
            coalesce(sum(case when type = 'charge' then tax_amount else 0 end), 0) as tax,
            coalesce(sum(case type when 'charge' then amount + tax_amount when 'discount' then -amount when 'allowance' then -amount when 'refund' then -amount else amount end), 0) as total_posted")
            ->first();
        $transactions = $query->orderBy('business_date')->orderBy('id')->paginate(50)->withQueryString();
        $transactions->getCollection()->transform(function ($transaction) {
            $transaction->display_amount = match ($transaction->type) {
                'discount', 'allowance', 'refund' => -(float) $transaction->amount,
                default => (float) $transaction->amount,
            };
            $transaction->display_tax = $transaction->type === 'charge' ? (float) $transaction->tax_amount : 0.0;
            $transaction->display_total = $transaction->type === 'charge'
                ? (float) $transaction->amount + (float) $transaction->tax_amount
                : $transaction->display_amount;

            return $transaction;
        });

        return view('folios.transactions', compact('transactions', 'summary', 'date', 'type', 'category', 'categoryOptions'));
    }

    public function invoices(Request $request)
    {
        $defaultTo = bdate()->toDateString();
        $defaultFrom = bdate()->copy()->startOfMonth()->toDateString();
        $validDate = static fn ($value) => is_string($value)
            && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $value)
            && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4));
        $rawFrom = $request->query('from');
        $rawTo = $request->query('to');
        $from = $validDate($rawFrom) ? $rawFrom : $defaultFrom;
        $to = $validDate($rawTo) ? $rawTo : $defaultTo;
        if ($from > $to) {
            $from = $defaultFrom;
            $to = $defaultTo;
        }
        $rawType = $request->query('type');
        $type = is_string($rawType) && in_array($rawType, ['invoice', 'credit_note'], true) ? $rawType : '';
        $rawSupply = $request->query('supply');
        $supply = is_string($rawSupply) && in_array($rawSupply, ['B2B', 'B2C'], true) ? $rawSupply : '';
        $rawSearch = $request->query('q');
        $search = is_string($rawSearch) ? mb_substr(trim($rawSearch), 0, 160) : '';
        $request->merge(['from' => $from, 'to' => $to, 'type' => $type, 'supply' => $supply, 'q' => $search]);
        $folios = Folio::with(['guest', 'company', 'stay.room', 'stay.guest', 'stay.reservation.guest', 'stay.reservation.company', 'reservation.guest', 'reservation.company', 'original:id,invoice_no'])->whereNotNull('invoice_no')
            // a plain BETWEEN drops the last day: invoice_date is stored as a datetime
            // string, so it is never <= the range's date-only upper bound
            ->whereDate(DB::raw('COALESCE(invoice_date, closed_at)'), '>=', $from)
            ->whereDate(DB::raw('COALESCE(invoice_date, closed_at)'), '<=', $to)
            ->when($type === 'invoice', fn ($q) => $q->where('type', '!=', 'credit_note'))->when($type === 'credit_note', fn ($q) => $q->where('type', 'credit_note'))
            ->when($supply !== '', fn ($q) => $q->where('supply_type', $supply))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('invoice_no', 'like', "%{$search}%")->orWhere('buyer_gstin', 'like', "%{$search}%")->orWhere('buyer_name', 'like', "%{$search}%")->orWhereHas('guest', fn ($g) => $g->search($search))))
            ->latest('closed_at')->paginate(30)->withQueryString();

        return view('folios.invoices', compact('folios', 'from', 'to'));
    }

    public function show(Folio $folio)
    {
        $folio->load(['guest', 'company', 'stay.room', 'stay.roomType', 'stay.guest', 'stay.reservation.guest', 'stay.reservation.company', 'reservation.guest', 'reservation.company']);
        $transactions = $folio->transactions()->with('paymentMode', 'poster', 'company')->orderBy('business_date')->orderBy('id')->get();
        $totals = $folio->totals($transactions);
        $canPost = $folio->status === 'open' && can_do('folio.post');
        $canPay = $folio->status === 'open' && can_do('folio.payment');
        $canSplit = $folio->status === 'open' && can_do('folio.split');

        return view('folios.show', [
            'folio' => $folio,
            'transactions' => $transactions,
            'totals' => $totals,
            'paymentModes' => $canPay ? PaymentMode::where('is_active', true)->where('type', '!=', 'city_ledger')->pluck('name', 'id') : collect(),
            'companies' => $canPay && $totals['balance'] > 0 ? Company::where('is_active', true)->pluck('name', 'id') : collect(),
            'categories' => $canPost ? collect(config('pms.charge_categories'))->except(['room']) : collect(),
            // other open folios this folio's lines may be split / routed to
            'fixedCharges' => $folio->stay ? $folio->stay->fixedCharges()->latest('id')->get() : collect(),
            'targets' => $canSplit
                ? Folio::with('guest', 'stay.room')->where('status', 'open')->where('id', '!=', $folio->id)
                    ->where(fn ($q) => $q->when($folio->reservation_id, fn ($w) => $w->orWhere('reservation_id', $folio->reservation_id))
                        ->when($folio->guest_id, fn ($w) => $w->orWhere('guest_id', $folio->guest_id))
                        ->orWhereIn('type', ['master']))->orderByDesc('id')->limit(50)->get()
                : collect(),
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
            'amount' => 'nullable|numeric|min:0.01', 'payment_mode_id' => 'nullable|integer|owned:payment_modes',
            'reference' => 'nullable|string|max:80', 'type' => 'required|in:payment,refund',
            'momo_network' => 'nullable|string|max:20', 'momo_phone' => 'nullable|string|max:30',
            'payments' => 'nullable|array|min:2|max:10',
            'payments.*.amount' => 'required|numeric|min:0.01',
            'payments.*.payment_mode_id' => 'required|integer|owned:payment_modes',
            'payments.*.reference' => 'nullable|string|max:80',
            'payments.*.momo_network' => 'nullable|string|max:20',
            'payments.*.momo_phone' => 'nullable|string|max:30',
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
        $usingSplit = ! empty($data['payments']);
        $allocations = $usingSplit ? array_values($data['payments']) : [[
            'amount' => $data['amount'] ?? null,
            'payment_mode_id' => $data['payment_mode_id'] ?? null,
            'reference' => $data['reference'] ?? null,
            'momo_network' => $data['momo_network'] ?? null,
            'momo_phone' => $data['momo_phone'] ?? null,
        ]];
        if (! $usingSplit && (empty($allocations[0]['amount']) || empty($allocations[0]['payment_mode_id']))) {
            throw \Illuminate\Validation\ValidationException::withMessages(['payment_mode_id' => 'Enter an amount and choose a payment mode.']);
        }
        $amount = 0.0;
        $modes = [];
        $references = [];
        $voucherLines = 0;
        foreach ($allocations as $i => $line) {
            $mode = PaymentMode::find((int) ($line['payment_mode_id'] ?? 0));
            if (! $mode || ! $mode->is_active || $mode->type === 'city_ledger') {
                throw \Illuminate\Validation\ValidationException::withMessages([$usingSplit ? "payments.{$i}.payment_mode_id" : 'payment_mode_id' => 'Choose an active direct payment mode.']);
            }
            $amount += round((float) $line['amount'], 2);
            $modes[] = $mode->name;
            $lineReference = trim((string) ($line['reference'] ?? ''));
            if (filled($line['momo_network'] ?? null) || filled($line['momo_phone'] ?? null)) {
                $lineReference = trim(implode(' · ', array_filter([$line['momo_network'] ?? null, $line['momo_phone'] ?? null, $lineReference])));
            }
            if ($lineReference !== '') {
                $references[] = $mode->name.': '.$lineReference;
            }
            if (GiftVoucherService::isVoucherMode((int) $mode->id)) {
                $voucherLines++;
            }
        }
        $amount = round($amount, 2);
        if ($voucherLines && count($allocations) !== 1) {
            throw \Illuminate\Validation\ValidationException::withMessages(['payments' => 'A gift voucher must be used as a single tender.']);
        }
        $reference = $usingSplit ? implode('; ', $references) : $this->momoReference($request, $data['reference'] ?? null);
        $next = $data['type'] === 'refund' ? $folio->balance() + $amount : $folio->balance() - $amount;
        $modeSummary = implode(' + ', array_unique($modes));
        $msg = ucfirst($data['type']).' of '.money($amount).' by '.$modeSummary
            .($reference ? ' · '.$reference : '')
            .' would leave '.money($next).' on '.$folio->folio_no.'.';
        if ($voucherLines) {
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
            'amount' => 'nullable|numeric|min:0.01', 'payment_mode_id' => 'nullable|integer|owned:payment_modes',
            'reference' => 'nullable|string|max:80', 'type' => 'required|in:payment,refund',
            'momo_network' => 'nullable|string|max:20', 'momo_phone' => 'nullable|string|max:30',
            'cheque_bank_name' => 'nullable|string|max:100', 'cheque_number' => 'nullable|string|max:20', 'cheque_bank_branch' => 'nullable|string|max:100',
            'payments' => 'nullable|array|min:2|max:10',
            'payments.*.amount' => 'required|numeric|min:0.01',
            'payments.*.payment_mode_id' => 'required|integer|owned:payment_modes',
            'payments.*.reference' => 'nullable|string|max:80',
            'payments.*.momo_network' => 'nullable|string|max:20',
            'payments.*.momo_phone' => 'nullable|string|max:30',
            'payments.*.cheque_bank_name' => 'nullable|string|max:100',
            'payments.*.cheque_number' => 'nullable|string|max:20',
            'payments.*.cheque_bank_branch' => 'nullable|string|max:100',
        ]);

        $usingSplit = ! empty($data['payments']);
        $payments = $usingSplit ? array_values($data['payments']) : [[
            'amount' => $data['amount'] ?? null,
            'payment_mode_id' => $data['payment_mode_id'] ?? null,
            'reference' => $data['reference'] ?? null,
            'cheque_bank_name' => $data['cheque_bank_name'] ?? null,
            'cheque_number' => $data['cheque_number'] ?? null,
            'cheque_bank_branch' => $data['cheque_bank_branch'] ?? null,
        ]];
        if (! $usingSplit) {
            if (empty($payments[0]['amount']) || empty($payments[0]['payment_mode_id'])) {
                throw \Illuminate\Validation\ValidationException::withMessages(['payment_mode_id' => 'Enter an amount and choose a payment mode.']);
            }
            $payments[0]['reference'] = $this->momoReference($request, $payments[0]['reference'] ?? null);
        } else {
            foreach ($payments as &$payment) {
                if (filled($payment['momo_network'] ?? null) || filled($payment['momo_phone'] ?? null)) {
                    $payment['reference'] = mb_substr(trim(implode(' · ', array_filter([
                        $payment['momo_network'] ?? null, $payment['momo_phone'] ?? null, $payment['reference'] ?? null,
                    ]))), 0, 80);
                }
            }
            unset($payment);
        }

        $voucherLines = 0;
        foreach ($payments as $i => $payment) {
            $mode = PaymentMode::find((int) ($payment['payment_mode_id'] ?? 0));
            if (! $mode || ! $mode->is_active || $mode->type === 'city_ledger') {
                throw \Illuminate\Validation\ValidationException::withMessages([$usingSplit ? "payments.{$i}.payment_mode_id" : 'payment_mode_id' => 'Choose an active direct payment mode.']);
            }
            if (GiftVoucherService::isVoucherMode((int) $mode->id)) {
                $voucherLines++;
            }
        }
        if ($voucherLines && count($payments) !== 1) {
            throw \Illuminate\Validation\ValidationException::withMessages(['payments' => 'A gift voucher must be redeemed as a single tender.']);
        }

        DB::transaction(function () use ($payments, $data, $folio) {
            foreach ($payments as $payment) {
                $amount = round((float) $payment['amount'], 2);
                $reference = trim((string) ($payment['reference'] ?? '')) ?: null;
                $cheque = [
                    'bank_name' => $payment['cheque_bank_name'] ?? null,
                    'number' => $payment['cheque_number'] ?? null,
                    'bank_branch' => $payment['cheque_bank_branch'] ?? null,
                ];
                FolioService::payment($folio, $amount, (int) $payment['payment_mode_id'], $reference, 'settlement', $data['type'], $cheque);
                ActivityLogger::log('folio', $data['type'], ucfirst($data['type']).' '.money($amount)." on {$folio->folio_no}", $folio);
            }
        });

        return back()->with('success', count($payments) > 1 ? count($payments).' tender entries recorded.' : ucfirst($data['type']).' recorded.');
    }

    public function discount(Request $request, Folio $folio)
    {
        $data = $request->validate(['mode' => 'required|in:amount,percent', 'value' => 'required|numeric|min:0.01', 'reason' => 'required|string|max:200']);
        $tx = $data['mode'] === 'percent'
            ? FolioService::discountPercent($folio, (float) $data['value'], $data['reason'])
            : FolioService::discount($folio, (float) $data['value'], $data['reason']);
        $amount = (float) $tx->amount;
        ActivityLogger::log('folio', 'discount', 'Discount '.money($amount)." on {$folio->folio_no}: {$data['reason']}", $folio);

        return back()->with('success', 'Discount applied.');
    }

    public function transfer(Request $request, Folio $folio)
    {
        $data = $request->validate(['company_id' => 'required|integer|owned:companies', 'override_reason' => 'nullable|string|max:200']);
        FolioService::transferToCityLedger($folio, (int) $data['company_id'], $data['override_reason'] ?? null);
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
        $folio = FolioService::close($folio);
        ActivityLogger::log('folio', 'close', "Closed {$folio->folio_no}, invoice {$folio->invoice_no}", $folio);
        if (! $folio->invoice_no) {
            return redirect()->route('folios.show', $folio)->with('success', 'Opening receivable settled. No new tax invoice was issued for the historical balance.');
        }

        return redirect()->route('folios.invoice', $folio)->with('success', 'Folio settled. Invoice '.$folio->invoice_no);
    }

    public function reopen(Folio $folio)
    {
        $why = DB::transaction(function () use ($folio) {
            // Recheck after locking, so a stale reopen request cannot reopen a
            // folio that was consolidated while the request was waiting.
            $folio = Folio::whereKey($folio->id)->lockForUpdate()->firstOrFail();
            abort_unless($folio->status === 'closed', 422);
            if ($folio->consolidated_into_folio_id !== null) {
                return 'This folio was consolidated into another reservation bill and cannot be reopened.';
            }
            if ($why = \App\Services\GstService::reopenBlocker($folio)) {
                return $why;
            }
            $folio->update(['status' => 'open']);
            ActivityLogger::log('folio', 'reopen', "Re-opened {$folio->folio_no} (invoice {$folio->invoice_no})", $folio);

            return null;
        });
        if ($why) {
            return back()->with('error', $why);
        }

        return back()->with('success', 'Folio re-opened. Invoice number is retained.');
    }

    public function receipt(FolioTransaction $tx)
    {
        abort_unless(in_array($tx->type, ['payment', 'refund'], true), 404);
        $tx->load(['folio.guest', 'folio.company', 'folio.stay.room', 'folio.stay.guest', 'folio.stay.reservation.guest', 'folio.stay.reservation.company', 'folio.reservation.guest', 'folio.reservation.company', 'paymentMode', 'poster']);

        return view('folios.receipt', ['tx' => $tx, 'folio' => $tx->folio]);
    }

    public function invoice(Folio $folio)
    {
        $folio->load('guest', 'company', 'closedBy', 'stay.room', 'stay.roomType', 'stay.ratePlan', 'stay.guest', 'stay.reservation.guest', 'stay.reservation.company', 'reservation.guest', 'reservation.company', 'reservation.rooms.room', 'reservation.rooms.roomType', 'reservation.rooms.ratePlan', 'original');
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
        $moved = DB::transaction(function () use ($data, $folio) {
            $sourceId = (int) $folio->id;
            $targetId = $data['target'] === 'new' ? null : (int) $data['target'];
            if ($targetId === $sourceId) {
                throw new \RuntimeException('Target folio must be a different, open folio.');
            }
            // Lock both existing folios in a stable order before their lines, so
            // opposite-direction moves and concurrent close/post cannot cross.
            $ids = $targetId === null ? [$sourceId] : [$sourceId, $targetId];
            sort($ids, SORT_NUMERIC);
            $locked = [];
            foreach ($ids as $id) {
                $locked[$id] = Folio::whereKey($id)->lockForUpdate()->firstOrFail();
            }
            $folio = $locked[$sourceId];
            if ($folio->status !== 'open') {
                throw new \RuntimeException('Source folio is closed.');
            }
            if ($targetId === null) {
                $target = FolioService::openStandalone(['type' => 'guest', 'guest_id' => $folio->guest_id, 'company_id' => $folio->company_id,
                    'reservation_id' => $folio->reservation_id, 'reservation_room_id' => $folio->reservation_room_id]);
            } else {
                $target = $locked[$targetId];
                if ($target->status !== 'open') {
                    throw new \RuntimeException('Target folio must be a different, open folio.');
                }
            }
            $lines = $folio->transactions()->whereIn('id', $data['tx'])->where('is_void', false)->orderBy('id')->lockForUpdate()->get();
            if ($lines->count() !== count(array_unique($data['tx']))) {
                throw new \RuntimeException('Some selected lines are void or do not belong to this folio.');
            }
            if ($lines->contains(fn ($line) => $line->isOpening())) {
                throw new \RuntimeException('Opening receivable balances cannot be split or moved; correct the unapplied source row in an opening_ar batch before posting the opening trial balance.');
            }
            if ($lines->contains(fn ($line) => $line->source_type === 'pos_complimentary')) {
                throw new \RuntimeException('Complimentary information records are read-only and cannot be moved.');
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
