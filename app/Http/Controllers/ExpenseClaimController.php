<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\ExpenseClaim;
use App\Models\ExpenseClaimLine;
use App\Models\PaymentMode;
use App\Models\PettyFloat;
use App\Models\Setting;
use App\Services\ActivityLogger;
use App\Services\ExpenseClaimService as Claims;
use App\Services\PettyCashService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** v4.7 Staff expense claims: my claims (self-service), the approval / payment queue, claim form and print. */
class ExpenseClaimController extends Controller
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

    protected function guard(ExpenseClaim $c): void
    {
        abort_unless(Claims::canSee($c), 403, 'This claim belongs to someone else.');
    }

    public function index(Request $request)
    {
        $status = $request->query('status', can_do('claims.approve') ? 'submitted' : (can_do('claims.pay') ? 'approved' : ''));
        $status = in_array($status, ['draft', 'submitted', 'approved', 'rejected', 'paid', 'all'], true) ? $status : 'submitted';
        $q = trim((string) $request->query('q'));

        return view('claims.index', [
            'claims' => ExpenseClaim::with('employee.department', 'approver')->withCount('lines')
                ->when($status !== 'all', fn ($x) => $x->where('status', $status))
                ->when($q !== '', fn ($x) => $x->where(fn ($w) => $w->where('claim_no', 'like', "%{$q}%")->orWhere('title', 'like', "%{$q}%")->orWhereHas('employee', fn ($e) => $e->where('name', 'like', "%{$q}%"))))
                ->orderByDesc('updated_at')->paginate(30)->withQueryString(),
            'counts' => ExpenseClaim::selectRaw('status, count(*) n, sum(total) t')->groupBy('status')->get()->keyBy('status'),
            'status' => $status, 'q' => $q, 'mine' => false,
            'employees' => Employee::where('is_active', true)->orderBy('name')->get()->mapWithKeys(fn ($e) => [$e->id => $e->name.' ('.$e->emp_code.')'])->all(),
            'cfg' => collect(Claims::DEFAULTS)->map(fn ($v, $k) => Claims::cfg($k))->all(), 'me' => Claims::employeeFor(),
        ]);
    }

    public function mine()
    {
        $me = Claims::employeeFor();

        return view('claims.index', [
            'claims' => ExpenseClaim::with('employee', 'approver')->withCount('lines')->where(fn ($w) => $w->where('employee_id', $me?->id ?? 0)->orWhere('created_by', auth()->id()))
                ->orderByDesc('updated_at')->paginate(30),
            'counts' => collect(), 'status' => 'all', 'q' => '', 'mine' => true, 'employees' => [], 'me' => $me,
            'cfg' => collect(Claims::DEFAULTS)->map(fn ($v, $k) => Claims::cfg($k))->all(),
        ]);
    }

    public function store(Request $request)
    {
        $d = $request->validate(['title' => 'required|string|max:150', 'employee_id' => 'nullable|integer|owned:employees', 'notes' => 'nullable|string|max:500']);

        return $this->attempt(function () use ($d) {
            $c = Claims::create(isset($d['employee_id']) ? (int) $d['employee_id'] : null, $d['title'], $d['notes'] ?? null);

            return redirect()->route('claims.show', $c)->with('success', "Claim {$c->claim_no} started – add your expenses and receipts, then submit.");
        });
    }

    public function show(ExpenseClaim $eclaim)
    {
        $this->guard($eclaim);

        return view('claims.show', [
            'c' => $eclaim->load('employee.department', 'lines.head', 'approver', 'payer', 'paymentMode', 'pettyTxn.float', 'creator'),
            'canEdit' => Claims::canEdit($eclaim), 'cats' => collect(Claims::CATEGORIES)->map(fn ($c) => $c[0])->all(),
            'modes' => PaymentMode::where('is_active', true)->whereIn('type', ['cash', 'bank', 'wallet'])->orderBy('name')->pluck('name', 'id')->all(),
            'floats' => PettyFloat::where('is_active', true)->orderBy('code')->get()->filter(fn ($f) => PettyCashService::canOperate($f))
                ->mapWithKeys(fn ($f) => [$f->id => $f->name.' – '.money(PettyCashService::available($f)).' available'])->all(),
            'cfg' => collect(Claims::DEFAULTS)->map(fn ($v, $k) => Claims::cfg($k))->all(),
        ]);
    }

    public function update(Request $request, ExpenseClaim $eclaim)
    {
        $this->guard($eclaim);
        $d = $request->validate(['title' => 'required|string|max:150', 'notes' => 'nullable|string|max:500']);

        return $this->attempt(function () use ($eclaim, $d) {
            if (! Claims::canEdit($eclaim)) {
                throw new RuntimeException('This claim can no longer be changed.');
            }
            $eclaim->update($d);
        }, 'Claim updated.');
    }

    public function destroy(ExpenseClaim $eclaim)
    {
        $this->guard($eclaim);

        return $this->attempt(function () use ($eclaim) {
            Claims::destroy($eclaim);

            return redirect()->route(can_do('claims.approve') || can_do('claims.pay') ? 'claims.index' : 'claims.mine')->with('success', "Claim {$eclaim->claim_no} deleted.");
        });
    }

    public function addLine(Request $request, ExpenseClaim $eclaim)
    {
        $this->guard($eclaim);
        $d = $request->validate(['date' => 'required|date', 'category' => 'required|string|in:'.implode(',', array_keys(Claims::CATEGORIES)), 'description' => 'required|string|max:255',
            'km' => 'nullable|numeric|min:0|max:2000', 'amount' => 'nullable|numeric|min:0|max:1000000', 'vat_amount' => 'nullable|numeric|min:0',
            'receipt' => 'nullable|file|max:5120|mimes:jpg,jpeg,png,webp,pdf', 'receipt_ref' => 'nullable|string|max:60']);

        return $this->attempt(fn () => Claims::addLine($eclaim, $d, $request->file('receipt')) && false, 'Expense added.');
    }

    public function deleteLine(ExpenseClaimLine $ecline)
    {
        $this->guard($ecline->claim);

        return $this->attempt(fn () => Claims::deleteLine($ecline), 'Expense removed.');
    }

    public function receipt(ExpenseClaimLine $ecline)
    {
        $this->guard($ecline->claim);
        abort_unless($ecline->receipt_path && Storage::disk('local')->exists($ecline->receipt_path), 404);

        return Storage::disk('local')->response($ecline->receipt_path, $ecline->claim->claim_no.'-'.$ecline->id.'.'.pathinfo($ecline->receipt_path, PATHINFO_EXTENSION));
    }

    public function submit(ExpenseClaim $eclaim)
    {
        $this->guard($eclaim);

        return $this->attempt(fn () => Claims::submit($eclaim), "{$eclaim->claim_no} submitted for approval.");
    }

    public function approve(ExpenseClaim $eclaim)
    {
        return $this->attempt(fn () => Claims::approve($eclaim), "{$eclaim->claim_no} approved – ready to pay.");
    }

    public function reject(Request $request, ExpenseClaim $eclaim)
    {
        $d = $request->validate(['reason' => 'required|string|max:255']);

        return $this->attempt(fn () => Claims::reject($eclaim, $d['reason']), "{$eclaim->claim_no} sent back to the claimant.");
    }

    public function pay(Request $request, ExpenseClaim $eclaim)
    {
        $d = $request->validate(['method' => 'required|in:mode,petty', 'payment_mode_id' => 'nullable|required_if:method,mode|integer|owned:payment_modes',
            'petty_float_id' => 'nullable|required_if:method,petty|integer|owned:petty_floats', 'date' => 'required|date', 'reference' => 'nullable|string|max:80']);

        return $this->attempt(fn () => Claims::pay($eclaim, $d['method'], isset($d['payment_mode_id']) ? (int) $d['payment_mode_id'] : null,
            isset($d['petty_float_id']) ? (int) $d['petty_float_id'] : null, $d['date'], $d['reference'] ?? null), "{$eclaim->claim_no} paid.");
    }

    public function settings(Request $request)
    {
        $d = $request->validate(['claims_mileage_rate' => 'required|numeric|min:0|max:100', 'claims_receipt_threshold' => 'required|numeric|min:0|max:100000', 'claims_max_age_days' => 'required|integer|min:7|max:365']);
        foreach ($d as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => (string) $v]);
        }
        Setting::$version++;
        ActivityLogger::log('claims', 'settings', 'Expense claim settings updated');

        return back()->with('success', 'Expense claim settings saved.');
    }

    public function print(ExpenseClaim $eclaim)
    {
        $this->guard($eclaim);

        return view('claims.print', ['c' => $eclaim->load('employee.department', 'lines.head', 'approver', 'payer', 'paymentMode', 'pettyTxn.float', 'creator')]);
    }
}
