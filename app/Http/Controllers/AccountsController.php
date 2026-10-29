<?php

namespace App\Http\Controllers;

use App\Models\AccountHead;
use App\Models\Company;
use App\Models\FolioTransaction;
use App\Models\PaymentMode;
use App\Models\PosOrder;
use App\Models\Supplier;
use App\Models\Voucher;
use App\Services\ActivityLogger;
use App\Support\Numbering;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountsController extends Controller
{
    public function vouchers(Request $request)
    {
        $from = $request->input('from', bdate()->copy()->startOfMonth()->toDateString());
        $to = $request->input('to', bdate()->toDateString());
        $vouchers = Voucher::with('head', 'paymentMode', 'supplier', 'user')->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
            ->when($request->type, fn ($q, $t) => $q->where('type', $t))
            ->when($request->head, fn ($q, $h) => $q->where('account_head_id', $h))
            ->latest('date')->latest('id')->get();

        return view('accounts.vouchers', [
            'vouchers' => $vouchers, 'from' => $from, 'to' => $to,
            'heads' => AccountHead::orderBy('type')->orderBy('code')->get(),
            'paymentModes' => PaymentMode::where('is_active', true)->whereNotIn('type', ['city_ledger', 'gift_voucher'])->pluck('name', 'id'),
            'suppliers' => Supplier::pluck('name', 'id'),
        ]);
    }

    public function storeVoucher(Request $request)
    {
        $data = $request->validate([
            'type' => 'required|in:payment,receipt', 'date' => 'required|date', 'account_head_id' => 'required|integer|owned:account_heads',
            'payment_mode_id' => 'nullable|integer|owned:payment_modes', 'supplier_id' => 'nullable|integer|owned:suppliers',
            'party_name' => 'nullable|string|max:150', 'amount' => 'required|numeric|min:0.01', 'reference' => 'nullable|string|max:60', 'narration' => 'nullable|string|max:500',
        ]);
        $v = Voucher::create($data + ['voucher_no' => Numbering::next('voucher'), 'user_id' => auth()->id()]);
        ActivityLogger::log('accounts', 'voucher', ucfirst($v->type)." voucher {$v->voucher_no} ".money($v->amount), $v);

        return back()->with('success', "Voucher {$v->voucher_no} saved.");
    }

    public function deleteVoucher(Voucher $voucher)
    {
        ActivityLogger::log('accounts', 'voucher_delete', "Deleted voucher {$voucher->voucher_no} ".money($voucher->amount), $voucher);
        // deleting a commission payment puts those commissions back to "due"
        \App\Models\AgentCommission::where('voucher_id', $voucher->id)->update(['status' => 'due', 'voucher_id' => null, 'paid_at' => null]);
        $voucher->delete();

        return back()->with('success', 'Voucher deleted.');
    }

    /** Company receivables: city-ledger transfers minus receipts. */
    protected function companyBalances()
    {
        $debits = FolioTransaction::where('category', 'city_ledger')->where('is_void', false)->select('company_id', DB::raw('SUM(amount) as amt'))->groupBy('company_id')->pluck('amt', 'company_id');
        $credits = Voucher::where('type', 'receipt')->whereNotNull('company_id')->select('company_id', DB::raw('SUM(amount) as amt'))->groupBy('company_id')->pluck('amt', 'company_id');

        return [$debits, $credits];
    }

    public function cityLedger()
    {
        [$debits, $credits] = $this->companyBalances();
        $companies = Company::orderBy('name')->get()->each(function ($c) use ($debits, $credits) {
            $c->billed = (float) ($debits[$c->id] ?? 0);
            $c->received = (float) ($credits[$c->id] ?? 0);
            $c->outstanding = $c->billed - $c->received;
        });

        return view('accounts.cityledger', compact('companies'));
    }

    public function companyLedger(Company $company)
    {
        $debits = FolioTransaction::with('folio.guest')->where('category', 'city_ledger')->where('company_id', $company->id)->where('is_void', false)->get()
            ->map(fn ($t) => ['date' => $t->business_date, 'due' => $t->due_date, 'ref' => $t->reference ?: ($t->folio->invoice_no ?? $t->folio->folio_no),
                'desc' => $t->isOpening() ? $t->description : 'Bill – '.($t->folio->guest?->full_name),
                'debit' => (float) $t->amount, 'credit' => 0, 'folio' => $t->folio]);
        $credits = Voucher::where('type', 'receipt')->where('company_id', $company->id)->get()
            ->map(fn ($v) => ['date' => $v->date, 'due' => null, 'ref' => $v->voucher_no, 'desc' => 'Receipt '.($v->reference ? '#'.$v->reference : ''), 'debit' => 0, 'credit' => (float) $v->amount, 'folio' => null]);
        $rows = $debits->concat($credits)->sortBy(fn ($r) => $r['date']->format('Y-m-d'))->values();

        $paymentModes = PaymentMode::where('is_active', true)->whereNotIn('type', ['city_ledger', 'gift_voucher'])->pluck('name', 'id');
        $splitModes = PaymentMode::where('is_active', true)->whereIn('type', ['cash', 'card', 'bank', 'wallet'])->orderBy('id')->pluck('name', 'id');

        return view('accounts.company', compact('company', 'rows', 'paymentModes', 'splitModes'));
    }

    public function companyReceipt(Request $request, Company $company)
    {
        $data = $request->validate([
            'amount' => 'nullable|numeric|min:0.01', 'payment_mode_id' => 'nullable|integer|owned:payment_modes',
            'date' => 'required|date', 'reference' => 'nullable|string|max:60',
            'payments' => 'nullable|array|min:2|max:10', 'payments.*.amount' => 'required|numeric|min:0.01',
            'payments.*.payment_mode_id' => 'required|integer|owned:payment_modes', 'payments.*.reference' => 'nullable|string|max:60',
            'payments.*.momo_network' => 'nullable|string|max:20', 'payments.*.momo_phone' => 'nullable|string|max:30',
        ]);
        $lines = array_values($data['payments'] ?? []);
        if ($lines && ((float) ($data['amount'] ?? 0) > 0 || ! empty($data['payment_mode_id']))) {
            throw ValidationException::withMessages(['payments' => 'Use either one receipt tender or split tender rows, not both.']);
        }
        if (! $lines) {
            if (empty($data['amount']) || empty($data['payment_mode_id'])) {
                throw ValidationException::withMessages(['payment_mode_id' => 'Enter a receipt amount and choose a payment mode, or add split tender rows.']);
            }
            $lines = [[
                'amount' => $data['amount'], 'payment_mode_id' => $data['payment_mode_id'], 'reference' => $data['reference'] ?? null,
            ]];
        }
        foreach ($lines as $index => &$line) {
            $mode = PaymentMode::where('is_active', true)->whereIn('type', ['cash', 'card', 'bank', 'wallet'])
                ->find((int) ($line['payment_mode_id'] ?? 0));
            if (! $mode && count($lines) > 1) {
                throw ValidationException::withMessages(["payments.{$index}.payment_mode_id" => 'Split receipts require an active cash, card, bank or mobile-money mode.']);
            }
            if (filled($line['momo_network'] ?? null) || filled($line['momo_phone'] ?? null)) {
                $line['reference'] = mb_substr(trim(implode(' · ', array_filter([
                    $line['momo_network'] ?? null, $line['momo_phone'] ?? null, $line['reference'] ?? null,
                ]))), 0, 60);
            }
        }
        unset($line);
        $head = AccountHead::find(AccountHead::sys('city_ledger')); // receipts settle the receivable, not income
        $receipts = DB::transaction(function () use ($lines, $data, $company, $head) {
            $rows = collect();
            foreach ($lines as $line) {
                $amount = round((float) $line['amount'], 2);
                if ($amount <= 0) {
                    throw ValidationException::withMessages(['amount' => 'Receipt tender amounts must be positive.']);
                }
                $mode = PaymentMode::find((int) ($line['payment_mode_id'] ?? 0));
                if (! $mode || ! $mode->is_active || in_array($mode->type, ['city_ledger', 'online', 'gift_voucher'], true)) {
                    throw ValidationException::withMessages(['payment_mode_id' => 'Choose an active direct payment mode for the receipt.']);
                }
                $rows->push(Voucher::create([
                    'voucher_no' => Numbering::next('voucher'), 'type' => 'receipt', 'date' => $data['date'],
                    'account_head_id' => $head->id, 'company_id' => $company->id, 'party_name' => $company->name,
                    'amount' => $amount, 'payment_mode_id' => $mode->id, 'reference' => $line['reference'] ?? null,
                    'narration' => 'Payment against city ledger', 'user_id' => auth()->id(),
                ]));
            }
            ActivityLogger::log('accounts', 'cl_receipt', 'City ledger receipt '.money($rows->sum('amount'))." from {$company->name} (".$rows->count().' tender(s))', $company);

            return $rows;
        });

        return back()->with('success', $receipts->count().' receipt tender(s) totalling '.money($receipts->sum('amount')).' recorded.');
    }

    public function cashBook(Request $request)
    {
        $date = $request->input('date', bdate()->toDateString());
        $folioPay = FolioTransaction::with('folio.guest', 'paymentMode')->whereDate('business_date', $date)->whereIn('type', ['payment', 'refund'])->where('category', '!=', 'city_ledger')->where('is_void', false)->get();
        $pos = PosOrder::with('paymentMode', 'outlet')->whereDate('business_date', $date)->where('status', 'settled')->where('settlement', 'payment')->get();
        $vouchers = Voucher::with('head', 'paymentMode')->whereDate('date', $date)->get();

        $rows = collect()
            ->concat($folioPay->map(fn ($t) => ['src' => 'Front Office', 'ref' => $t->folio->folio_no, 'desc' => $t->description.' – '.($t->folio->guest?->full_name), 'mode' => $t->paymentMode?->name ?? '—', 'in' => $t->type === 'payment' ? (float) $t->amount : 0, 'out' => $t->type === 'refund' ? (float) $t->amount : 0]))
            ->concat($pos->map(fn ($o) => ['src' => 'POS', 'ref' => $o->bill_no, 'desc' => $o->outlet->name, 'mode' => $o->paymentMode?->name ?? '—', 'in' => (float) $o->total, 'out' => 0]))
            ->concat($vouchers->map(fn ($v) => ['src' => 'Voucher', 'ref' => $v->voucher_no, 'desc' => $v->head->name.' '.$v->narration, 'mode' => $v->paymentMode?->name ?? '—', 'in' => $v->type === 'receipt' ? (float) $v->amount : 0, 'out' => $v->type === 'payment' ? (float) $v->amount : 0]));

        return view('accounts.cashbook', ['rows' => $rows, 'date' => $date, 'byMode' => $rows->groupBy('mode')->map(fn ($g) => $g->sum('in') - $g->sum('out'))]);
    }

    public function pnl(Request $request)
    {
        $from = $request->input('from', bdate()->copy()->startOfMonth()->toDateString());
        $to = $request->input('to', bdate()->toDateString());
        $tx = FolioTransaction::whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to)->where('is_void', false)->get();
        $charges = $tx->where('type', 'charge');
        $income = collect(config('pms.charge_categories'))->map(fn ($label, $cat) => $charges->where('category', $cat)->sum('amount'))->filter();
        $income['discounts'] = -$tx->whereIn('type', ['discount', 'allowance'])->sum('amount');
        $posDirect = PosOrder::whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to)->where('status', 'settled')->where('settlement', 'payment')->get();
        $income['pos_direct'] = $posDirect->sum(fn ($o) => $o->total - $o->tax);
        $otherIncome = Voucher::with('head')->where('type', 'receipt')->whereNull('company_id')->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->get()->groupBy('head.name')->map->sum('amount');
        $expenses = Voucher::with('head')->where('type', 'payment')->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->get()->groupBy('head.name')->map->sum('amount');
        $totalIncome = $income->sum() + $otherIncome->sum();

        return view('accounts.pnl', compact('from', 'to', 'income', 'otherIncome', 'expenses', 'totalIncome') + ['taxCollected' => $charges->sum('tax_amount') + $posDirect->sum('tax')]);
    }
}
