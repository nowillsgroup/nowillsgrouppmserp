<?php

namespace App\Http\Controllers;

use App\Models\AccountHead;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PaymentMode;
use App\Models\PettyCount;
use App\Models\PettyFloat;
use App\Models\PettyReplenishment;
use App\Models\PettyTxn;
use App\Models\Setting;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\PettyCashService as Petty;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** v4.7 Accounts › Petty Cash: floats, vouchers, IOUs, counts, top-ups and approvals. */
class PettyCashController extends Controller
{
    protected function attempt(callable $fn, ?string $ok = null)
    {
        try {
            $r = $fn();
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return $r instanceof \Symfony\Component\HttpFoundation\Response ? $r : back()->with('success', $ok);
    }

    protected function lookups(): array
    {
        return [
            'heads' => AccountHead::where('type', 'expense')->where('is_active', true)->orderBy('code')->get()->mapWithKeys(fn ($h) => [$h->id => $h->label])->all(),
            'modes' => PaymentMode::where('is_active', true)->whereIn('type', ['cash', 'bank', 'wallet'])->orderBy('name')->pluck('name', 'id')->all(),
            'employees' => Employee::where('is_active', true)->orderBy('name')->get()->mapWithKeys(fn ($e) => [$e->id => $e->name.' ('.$e->emp_code.')'])->all(),
            'users' => User::visibleToHotel()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(),
            'departments' => Department::orderBy('name')->pluck('name', 'id')->all(),
        ];
    }

    public function index()
    {
        $floats = Petty::floats();

        return view('petty.index', $this->lookups() + [
            'floats' => $floats, 'stats' => $floats->mapWithKeys(fn ($f) => [$f->id => Petty::stats($f)]),
            'approvals' => Petty::approvals(),
            'overdue' => PettyTxn::with('float', 'employee')->where('type', 'advance')->where('status', 'posted')->whereNull('settled_at')->whereDate('due_date', '<', bdate()->toDateString())->orderBy('due_date')->get(),
            'cfg' => collect(Petty::DEFAULTS)->map(fn ($v, $k) => Petty::cfg($k))->all(),
        ]);
    }

    public function show(Request $request, PettyFloat $pfloat)
    {
        $to = $request->date('to')?->toDateString() ?? bdate()->toDateString();
        $from = $request->date('from')?->toDateString() ?? Carbon::parse($to)->startOfMonth()->toDateString();
        [$open, $rows, $close] = Petty::book($pfloat, $from, $to);

        return view('petty.show', $this->lookups() + [
            'f' => $pfloat->load('custodian', 'account', 'department'), 'st' => Petty::stats($pfloat), 'can' => Petty::canOperate($pfloat),
            'from' => $from, 'to' => $to, 'open' => $open, 'rows' => $rows, 'close' => $close,
            'pending' => PettyTxn::with('employee', 'head', 'creator')->where('petty_float_id', $pfloat->id)->where('status', 'pending')->orderBy('date')->get(),
            'ious' => PettyTxn::with('employee', 'settlement')->where('petty_float_id', $pfloat->id)->where('type', 'advance')->whereIn('status', ['posted'])->orderByRaw('settled_at is not null')->orderByDesc('date')->limit(60)->get(),
            'counts' => PettyCount::with('counter', 'witness', 'approver')->where('petty_float_id', $pfloat->id)->orderByDesc('date')->orderByDesc('id')->limit(30)->get(),
            'repls' => PettyReplenishment::with('requester', 'approver', 'paymentMode')->withCount('txns')->where('petty_float_id', $pfloat->id)->orderByDesc('id')->limit(30)->get(),
            'cancelled' => PettyTxn::with('employee', 'head')->where('petty_float_id', $pfloat->id)->whereIn('status', ['void', 'rejected'])->orderByDesc('updated_at')->limit(30)->get(),
            'unrepl' => Petty::unreplenished($pfloat),
            'denoms' => Petty::DENOMS, 'cfg' => collect(Petty::DEFAULTS)->map(fn ($v, $k) => Petty::cfg($k))->all(),
        ]);
    }

    protected function floatRules(bool $create): array
    {
        return array_filter([
            'code' => $create ? 'required|string|max:20|alpha_dash' : null, 'name' => 'required|string|max:60',
            'custodian_id' => 'nullable|integer|owned:users', 'department_id' => 'nullable|integer|owned:departments',
            'imprest_amount' => 'required|numeric|min:1|max:1000000', 'voucher_limit' => 'required|numeric|min:1|max:1000000', 'notes' => 'nullable|string|max:255',
            'payment_mode_id' => $create ? 'required|integer|owned:payment_modes' : null, 'date' => $create ? 'nullable|date' : null,
        ]);
    }

    public function storeFloat(Request $request)
    {
        $d = $request->validate($this->floatRules(true));
        if (PettyFloat::where('code', strtoupper($d['code']))->exists()) {
            return back()->withInput()->with('error', 'That float code is already used.');
        }

        return $this->attempt(fn () => redirect()->route('petty.show', Petty::createFloat($d))->with('success', 'Float opened and funded.'));
    }

    public function updateFloat(Request $request, PettyFloat $pfloat)
    {
        $d = $request->validate($this->floatRules(false));

        return $this->attempt(fn () => Petty::updateFloat($pfloat, $d) && false, 'Float updated.');
    }

    public function toggleFloat(PettyFloat $pfloat)
    {
        return $this->attempt(fn () => Petty::setActive($pfloat, ! $pfloat->is_active), $pfloat->is_active ? 'Float closed.' : 'Float reopened.');
    }

    protected function receiptRule(): string
    {
        return 'nullable|file|max:5120|mimes:jpg,jpeg,png,webp,pdf';
    }

    public function expense(Request $request, PettyFloat $pfloat)
    {
        $d = $request->validate(['date' => 'required|date', 'payee' => 'required|string|max:120', 'description' => 'required|string|max:255',
            'expense_head_id' => 'required|integer|owned:account_heads', 'amount' => 'required|numeric|min:0.01|max:1000000', 'vat_invoice' => 'nullable|boolean',
            'vat_amount' => 'nullable|numeric|min:0', 'receipt' => $this->receiptRule(), 'receipt_ref' => 'nullable|string|max:60', 'no_receipt_reason' => 'nullable|string|max:255']);

        return $this->attempt(function () use ($pfloat, $d, $request) {
            $t = Petty::expense($pfloat, array_filter($d, fn ($v) => $v !== null), $request->file('receipt'));

            return back()->with('success', $t->status === 'pending' ? "{$t->txn_no} is over the ".money($pfloat->voucher_limit).' limit – waiting for approval before paying out.' : "Voucher {$t->txn_no} recorded – pay out ".money($t->amount).'.');
        });
    }

    public function iou(Request $request, PettyFloat $pfloat)
    {
        $d = $request->validate(['date' => 'required|date', 'employee_id' => 'required|integer|owned:employees', 'amount' => 'required|numeric|min:0.01|max:1000000',
            'description' => 'required|string|max:255', 'due_date' => 'nullable|date']);

        return $this->attempt(function () use ($pfloat, $d) {
            $t = Petty::issueIou($pfloat, $d);

            return back()->with('success', $t->status === 'pending' ? "IOU {$t->txn_no} waits for approval." : "IOU {$t->txn_no} issued – due back ".dmy($t->due_date).'.');
        });
    }

    public function settle(Request $request, PettyTxn $ptxn)
    {
        $d = $request->validate(['date' => 'required|date', 'spent' => 'required|numeric|min:0|max:1000000', 'expense_head_id' => 'nullable|integer|owned:account_heads',
            'description' => 'nullable|string|max:255', 'vat_invoice' => 'nullable|boolean', 'vat_amount' => 'nullable|numeric|min:0', 'receipt' => $this->receiptRule(),
            'receipt_ref' => 'nullable|string|max:60', 'no_receipt_reason' => 'nullable|string|max:255']);

        return $this->attempt(function () use ($ptxn, $d, $request) {
            $t = Petty::settleIou($ptxn, array_filter($d, fn ($v) => $v !== null), $request->file('receipt'));
            $e = (float) $t->effect;

            return back()->with('success', "{$ptxn->txn_no} settled".($e > 0 ? ' – take back change of '.money($e) : ($e < 0 ? ' – pay out a further '.money(-$e) : '')).'.');
        });
    }

    public function approve(PettyTxn $ptxn)
    {
        return $this->attempt(fn () => Petty::approve($ptxn) && false, "{$ptxn->txn_no} approved – the cash can be paid out.");
    }

    public function reject(Request $request, PettyTxn $ptxn)
    {
        $d = $request->validate(['reason' => 'required|string|max:255']);

        return $this->attempt(fn () => Petty::reject($ptxn, $d['reason']), "{$ptxn->txn_no} rejected.");
    }

    public function void(Request $request, PettyTxn $ptxn)
    {
        $d = $request->validate(['reason' => 'required|string|max:255']);

        return $this->attempt(function () use ($ptxn, $d) {
            if (! Petty::canOperate($ptxn->float)) {
                throw new RuntimeException('Only the custodian or an approver can cancel this.');
            }
            Petty::void($ptxn, $d['reason']);
        }, "{$ptxn->txn_no} cancelled.");
    }

    public function count(Request $request, PettyFloat $pfloat)
    {
        $d = $request->validate(['qty' => 'required|array', 'qty.*' => 'nullable|integer|min:0|max:100000', 'note' => 'nullable|string|max:255', 'witness_id' => 'nullable|integer|owned:users']);

        return $this->attempt(function () use ($pfloat, $d) {
            $c = Petty::count($pfloat, $d['qty'], $d['note'] ?? null, isset($d['witness_id']) ? (int) $d['witness_id'] : null);
            $msg = match ($c->status) {
                'agreed' => "Count {$c->count_no} agrees with the book: ".money($c->counted).'.',
                'posted' => "Count {$c->count_no}: difference of ".money($c->variance).' is within the tolerance and was written off.',
                default => "Count {$c->count_no}: ".money($c->variance).' difference – waiting for approval.',
            };

            return back()->with($c->status === 'pending' ? 'error' : 'success', $msg);
        });
    }

    public function replenish(Request $request, PettyFloat $pfloat)
    {
        $d = $request->validate(['notes' => 'nullable|string|max:255']);

        return $this->attempt(function () use ($pfloat, $d) {
            $r = Petty::requestReplenishment($pfloat, $d['notes'] ?? null);

            return back()->with('success', "Top-up {$r->repl_no} for ".money($r->amount).' requested.');
        });
    }

    public function approveRepl(PettyReplenishment $prepl)
    {
        return $this->attempt(fn () => Petty::approveReplenishment($prepl), "{$prepl->repl_no} approved – pay it to restore the float.");
    }

    public function rejectRepl(Request $request, PettyReplenishment $prepl)
    {
        $d = $request->validate(['reason' => 'required|string|max:255']);

        return $this->attempt(fn () => Petty::rejectReplenishment($prepl, $d['reason']), "{$prepl->repl_no} rejected.");
    }

    public function payRepl(Request $request, PettyReplenishment $prepl)
    {
        $d = $request->validate(['payment_mode_id' => 'required|integer|owned:payment_modes', 'date' => 'required|date', 'reference' => 'nullable|string|max:80']);

        return $this->attempt(fn () => Petty::payReplenishment($prepl, (int) $d['payment_mode_id'], $d['date'], $d['reference'] ?? null) && false,
            "{$prepl->repl_no} paid – ".money($prepl->amount).' back in the float.');
    }

    public function approveCount(PettyCount $pcount)
    {
        return $this->attempt(fn () => Petty::approveCount($pcount), "Difference on {$pcount->count_no} written off.");
    }

    public function rejectCount(Request $request, PettyCount $pcount)
    {
        $d = $request->validate(['reason' => 'required|string|max:255']);

        return $this->attempt(fn () => Petty::rejectCount($pcount, $d['reason']), "{$pcount->count_no} rejected – count again.");
    }

    public function settings(Request $request)
    {
        $d = $request->validate(['petty_receipt_threshold' => 'required|numeric|min:0|max:100000', 'petty_iou_days' => 'required|integer|min:1|max:60',
            'petty_count_days' => 'required|integer|min:1|max:90', 'petty_low_pct' => 'required|integer|min:0|max:100', 'petty_variance_tolerance' => 'required|numeric|min:0|max:1000']);
        foreach ($d as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => (string) $v]);
        }
        Setting::$version++;
        ActivityLogger::log('petty', 'settings', 'Petty cash settings updated');

        return back()->with('success', 'Petty cash settings saved.');
    }

    public function printTxn(PettyTxn $ptxn)
    {
        return view('petty.voucher', ['t' => $ptxn->load('float.custodian', 'head', 'employee', 'creator', 'approver', 'advance', 'paymentMode', 'claim')]);
    }

    public function printRepl(PettyReplenishment $prepl)
    {
        return view('petty.replenishment', ['r' => $prepl->load('float.custodian', 'float.account', 'txns.head', 'txns.employee', 'requester', 'approver', 'paymentMode')]);
    }

    public function receipt(PettyTxn $ptxn)
    {
        abort_unless($ptxn->receipt_path && Storage::disk('local')->exists($ptxn->receipt_path), 404);

        return Storage::disk('local')->response($ptxn->receipt_path, $ptxn->txn_no.'-receipt.'.pathinfo($ptxn->receipt_path, PATHINFO_EXTENSION));
    }
}
