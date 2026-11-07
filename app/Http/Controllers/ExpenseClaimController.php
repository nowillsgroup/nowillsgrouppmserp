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
use Illuminate\Support\Carbon;
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

    /** How long a claim may wait before it is a message somebody has to answer: two weeks is a nudge, a month is a fault. */
    public const WAIT_BUCKETS = ['w0' => '0–7 days', 'w1' => '8–14 days', 'w2' => '15–30 days', 'w3' => 'Over 30 days'];

    public static function waitBucket(?int $days): string
    {
        $days = (int) ($days ?? 0);

        return $days <= 7 ? 'w0' : ($days <= 14 ? 'w1' : ($days <= 30 ? 'w2' : 'w3'));
    }

    /**
     * v9.14 Staff expense claims, read as two queues that age and two rules that either hold or do not.
     *
     * A claim is money an employee has already spent, so the screen's question is never "how much is outstanding" but "how
     * long has this person been waiting, and can the line be proved". The screen counted claims by status and stopped
     * there: a claim submitted in September and one submitted this morning were the same row, and the receipt rule — which
     * the service enforces when a line is added — was invisible once a claim existed, so a line that lost its receipt, or
     * that arrived by import, was never noticed again. Both queues are now aged, and the receipt and the claim window are
     * read back over the live claims instead of trusted to have been right at entry.
     */
    public function index(Request $request)
    {
        $status = $request->query('status', can_do('claims.approve') ? 'submitted' : (can_do('claims.pay') ? 'approved' : ''));
        $status = in_array($status, ['draft', 'submitted', 'approved', 'rejected', 'paid', 'all'], true) ? $status : 'submitted';
        $q = trim((string) $request->query('q'));

        $today = bdate()->copy()->startOfDay();
        $ageOf = fn ($d) => $d ? max(0, (int) round(($today->timestamp - Carbon::parse($d)->startOfDay()->timestamp) / 86400)) : null;

        $claims = ExpenseClaim::with('employee.department', 'approver')->withCount('lines')
            ->when($status !== 'all', fn ($x) => $x->where('status', $status))
            ->when($q !== '', fn ($x) => $x->where(fn ($w) => $w->where('claim_no', 'like', "%{$q}%")->orWhere('title', 'like', "%{$q}%")->orWhereHas('employee', fn ($e) => $e->where('name', 'like', "%{$q}%"))))
            ->orderByDesc('updated_at')->paginate(30)->withQueryString();
        $claims->getCollection()->transform(function ($c) use ($ageOf) {
            $c->waiting = in_array($c->status, ['submitted', 'approved'], true) ? $ageOf($c->status === 'submitted' ? $c->submitted_at : $c->approved_on) : null;
            $c->bucket = $c->waiting === null ? null : self::waitBucket($c->waiting);

            return $c;
        });

        // the two queues, aged: approval waits on submitted_at, payment waits on approved_on
        $queue = [];
        foreach (['submitted' => 'Awaiting approval', 'approved' => 'Awaiting payment'] as $key => $label) {
            $rows = ExpenseClaim::with('employee')->where('status', $key)->get(['id', 'claim_no', 'employee_id', 'total', 'submitted_at', 'approved_on'])
                ->each(fn ($c) => $c->waiting = $ageOf($key === 'submitted' ? $c->submitted_at : $c->approved_on));
            $worst = $rows->sortByDesc('waiting')->first();
            $row = ['key' => $key, 'label' => $label, 'claims' => $rows->count(), 'amount' => round((float) $rows->sum('total'), 2),
                'oldest' => (int) ($rows->max('waiting') ?? 0),
                'worst' => $worst ? ['claim_no' => $worst->claim_no, 'staff' => $worst->employee?->name, 'amount' => round((float) $worst->total, 2), 'days' => (int) $worst->waiting] : null];
            foreach (array_keys(self::WAIT_BUCKETS) as $b) {
                $in = $rows->filter(fn ($c) => self::waitBucket($c->waiting) === $b);
                $row[$b] = round((float) $in->sum('total'), 2);
                $row['n'.$b] = $in->count();
            }
            $queue[$key] = $row;
        }

        // v9.14 the receipt rule, read back over the live claims: over the threshold, a scan or a transaction number or nothing
        $threshold = (float) Claims::cfg('claims_receipt_threshold');
        $claimed = ExpenseClaimLine::with('claim.employee')->where('category', '!=', 'mileage')
            ->whereHas('claim', fn ($w) => $w->whereIn('status', ['submitted', 'approved', 'paid']))
            ->where('amount', '>', $threshold)->orderByDesc('date')->orderByDesc('id')->get()
            ->map(fn ($l) => ['claim_no' => $l->claim?->claim_no, 'staff' => $l->claim?->employee?->name, 'date' => $l->date,
                'age' => $ageOf($l->date), 'category' => $l->category, 'amount' => round((float) $l->amount, 2),
                'description' => $l->description, 'status' => $l->claim?->status,
                'evidence' => $l->receipt_path ? 'receipt' : (trim((string) $l->receipt_ref) !== '' ? 'ref' : 'none')]);
        $gaps = $claimed->where('evidence', 'none')->values();
        $receipt = ['threshold' => $threshold, 'needing' => $claimed->count(), 'with_receipt' => $claimed->where('evidence', 'receipt')->count(),
            'with_ref' => $claimed->where('evidence', 'ref')->count(), 'missing' => $gaps->count(),
            'amount' => round((float) $claimed->sum('amount'), 2), 'missing_amount' => round((float) $gaps->sum('amount'), 2),
            'oldest' => (int) ($claimed->max('age') ?? 0),
            'gap_rows' => $gaps->take(10)->all()];

        // and the claim window: a line older than the window on the day it was submitted should never have been claimable
        $window = (int) Claims::cfg('claims_max_age_days');
        $oldestAtSubmit = 0;
        $late = collect();
        foreach (ExpenseClaim::with('lines', 'employee')->whereIn('status', ['submitted', 'approved', 'paid'])->whereNotNull('submitted_at')->get() as $c) {
            foreach ($c->lines as $l) {
                $age = (int) round((Carbon::parse($c->submitted_at)->startOfDay()->timestamp - $l->date->copy()->startOfDay()->timestamp) / 86400);
                $oldestAtSubmit = max($oldestAtSubmit, $age);
                if ($age > $window) {
                    $late->push(['claim_no' => $c->claim_no, 'staff' => $c->employee?->name, 'date' => $l->date, 'age' => $age,
                        'amount' => round((float) $l->amount, 2), 'description' => $l->description]);
                }
            }
        }
        $windowDoc = ['window' => $window, 'oldest' => $oldestAtSubmit, 'late' => $late->count(),
            'late_amount' => round((float) $late->sum('amount'), 2), 'rows' => $late->take(10)->all()];

        return view('claims.index', [
            'claims' => $claims, 'queue' => $queue, 'waitBuckets' => self::WAIT_BUCKETS, 'receipt' => $receipt, 'windowDoc' => $windowDoc,
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
            'queue' => [], 'waitBuckets' => self::WAIT_BUCKETS,
            'receipt' => ['threshold' => 0, 'needing' => 0, 'with_receipt' => 0, 'with_ref' => 0, 'missing' => 0, 'amount' => 0, 'missing_amount' => 0, 'oldest' => 0, 'gap_rows' => []],
            'windowDoc' => ['window' => 0, 'oldest' => 0, 'late' => 0, 'late_amount' => 0, 'rows' => []],
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
