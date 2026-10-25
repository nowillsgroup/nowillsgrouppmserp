<?php

namespace App\Http\Controllers;

use App\Models\BillingReconciliation;
use App\Models\BillingReconciliationItem;
use App\Services\BillingReconciliationService as Reconciliation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** Accounts › Billing Reconciliation: non-posting folio-document working papers. */
class BillingReconciliationController extends Controller
{
    protected function attempt(callable $fn)
    {
        try {
            return $fn();
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function index()
    {
        $reconciliations = BillingReconciliation::with('preparer', 'reviewer')
            ->withCount('items')
            ->withCount(['items as pending_count' => fn ($q) => $q->where('review_status', 'pending')])
            ->orderByDesc('period_to')->orderByDesc('id')->paginate(25)->withQueryString();
        $today = bdate();

        return view('billing-reconciliation.index', [
            'reconciliations' => $reconciliations,
            'defaultFrom' => $today->copy()->startOfMonth()->toDateString(),
            'defaultTo' => $today->toDateString(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'period_from' => 'required|date_format:Y-m-d',
            'period_to' => 'required|date_format:Y-m-d|after_or_equal:period_from',
        ]);

        return $this->attempt(function () use ($data, $request) {
            [$reconciliation, $created, $counts] = Reconciliation::create($data['period_from'], $data['period_to'], $request->user());
            $message = $created
                ? "Created {$reconciliation->reconciliation_no}: {$counts['added']} folio document(s) snapshotted."
                : "A workpaper already exists for {$reconciliation->label()}; it has been opened.";
            if ($counts['skipped_finalised'] ?? 0) {
                $message .= ' '.(int) $counts['skipped_finalised'].' document(s) already belong to another finalised workpaper and were not duplicated.';
            }

            return redirect()->route('billingrec.show', $reconciliation)->with('success', $message);
        });
    }

    public function show(BillingReconciliation $billingReconciliation)
    {
        $items = $billingReconciliation->items()->with('folio.guest', 'folio.company', 'folio.original', 'reviewer')->get();
        $billingReconciliation->setRelation('items', $items);

        return view('billing-reconciliation.show', [
            'reconciliation' => $billingReconciliation,
            'items' => $items,
            'totals' => $billingReconciliation->totals(),
            'reviewStatuses' => Reconciliation::REVIEW_STATUSES,
        ]);
    }

    public function refresh(BillingReconciliation $billingReconciliation)
    {
        return $this->attempt(function () use ($billingReconciliation) {
            $counts = Reconciliation::refresh($billingReconciliation);
            $message = "Snapshot refresh complete: {$counts['added']} new, {$counts['updated']} changed, {$counts['unchanged']} unchanged.";
            if ($counts['skipped_finalised']) {
                $message .= " {$counts['skipped_finalised']} document(s) were already in a finalised workpaper and were skipped.";
            }

            return redirect()->route('billingrec.show', $billingReconciliation)->with('success', $message);
        });
    }

    public function updateItem(Request $request, BillingReconciliation $billingReconciliation, BillingReconciliationItem $billingReconciliationItem)
    {
        $data = $request->validate([
            'review_status' => 'required|in:matched,exception,not_applicable',
            'settled_payments' => 'nullable|numeric|between:0,9999999999.99',
            'settled_refunds' => 'nullable|numeric|between:0,9999999999.99',
            'settlement_reference' => 'nullable|string|max:160',
            'review_note' => 'nullable|string|max:2000',
            'evidence' => 'nullable|file|max:10240|mimes:pdf,jpg,jpeg,png,csv,xlsx',
        ]);
        abort_unless((int) $billingReconciliationItem->billing_reconciliation_id === (int) $billingReconciliation->id, 404);

        return $this->attempt(function () use ($request, $data, $billingReconciliation, $billingReconciliationItem) {
            Reconciliation::reviewItem($billingReconciliation, $billingReconciliationItem, $data, $request->user(), $request->file('evidence'));

            return redirect()->route('billingrec.show', $billingReconciliation)->with('success', "{$billingReconciliationItem->document_no} review saved.");
        });
    }

    public function finalise(Request $request, BillingReconciliation $billingReconciliation)
    {
        return $this->attempt(function () use ($billingReconciliation, $request) {
            Reconciliation::finalise($billingReconciliation, $request->user());

            return redirect()->route('billingrec.show', $billingReconciliation)->with('success', 'Billing reconciliation finalised and locked. No journal entries were created.');
        });
    }

    public function reopen(Request $request, BillingReconciliation $billingReconciliation)
    {
        $data = $request->validate(['reason' => 'required|string|max:2000']);

        return $this->attempt(function () use ($billingReconciliation, $data, $request) {
            Reconciliation::reopen($billingReconciliation, $data['reason'], $request->user());

            return redirect()->route('billingrec.show', $billingReconciliation)->with('success', 'Billing reconciliation reopened for correction.');
        });
    }

    public function evidence(BillingReconciliation $billingReconciliation, BillingReconciliationItem $billingReconciliationItem)
    {
        abort_unless((int) $billingReconciliationItem->billing_reconciliation_id === (int) $billingReconciliation->id, 404);
        abort_unless($billingReconciliationItem->evidence_path && Storage::disk('local')->exists($billingReconciliationItem->evidence_path), 404);

        return Storage::disk('local')->download($billingReconciliationItem->evidence_path, $billingReconciliationItem->evidence_name ?: 'settlement-evidence', [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function print(BillingReconciliation $billingReconciliation)
    {
        $items = $billingReconciliation->items()->with('folio.guest', 'folio.company', 'reviewer')->get();
        $billingReconciliation->setRelation('items', $items);

        return view('billing-reconciliation.print', [
            'reconciliation' => $billingReconciliation,
            'items' => $items,
            'totals' => $billingReconciliation->totals(),
        ]);
    }
}
