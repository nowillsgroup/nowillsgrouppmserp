<?php

namespace App\Services;

use App\Models\AccountHead;
use App\Models\Department;
use App\Models\Employee;
use App\Models\InventoryItem;
use App\Models\PurchaseOrderItem;
use App\Models\Requisition;
use App\Models\RequisitionLine;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\StoreIssue;
use App\Models\StorePar;
use App\Models\User;
use App\Support\Numbering;
use App\Support\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * v2.4 store requisitions (indents).
 *
 * draft → submitted → approved → partial → issued, or closed short / rejected / cancelled.
 *  - The head of department approves (quantities may be cut). Nobody approves their own requisition; a user with
 *    requisitions.manage can approve for any department. Approvals are checked against the department's monthly
 *    stores budget (issued this month + still committed on open requisitions + this one).
 *  - The store issues against the approved quantities, in part or in full, never more than it holds. Each issue is
 *    an issue note (SIN-…) and stock movements: a transfer when the department has a sub-store (no ledger effect –
 *    the value stays in inventory), otherwise an issue expensed to the department's cost account.
 *  - The department acknowledges receipt of each issue note.
 */
class RequisitionService
{
    // ------------------------------------------------------------------ who may do what

    public static function userDepartmentId(?User $u = null): ?int
    {
        $u ??= auth()->user();

        return $u ? Employee::where('user_id', $u->id)->value('department_id') : null;
    }

    /** Why this user can't approve (null = may approve). */
    public static function approvalBlocker(User $u, Requisition $r): ?string
    {
        if ($r->status !== 'submitted') {
            return 'Only requisitions awaiting approval can be approved.';
        }
        if (! $u->hasPermission('requisitions.approve') && ! $u->hasPermission('requisitions.manage')) {
            return 'You do not have approval rights.';
        }
        if ((int) $r->raised_by === (int) $u->id) {
            return 'You raised this requisition – someone else has to approve it.';
        }
        $hod = $r->department?->hod_user_id;
        if ($hod && (int) $hod !== (int) $u->id && ! $u->hasPermission('requisitions.manage')) {
            return 'Only the head of '.$r->department->name.' ('.($r->department->hod?->name ?? 'HOD').') can approve this.';
        }

        return null;
    }

    public static function canApprove(User $u, Requisition $r): bool
    {
        return self::approvalBlocker($u, $r) === null;
    }

    public static function canEdit(User $u, Requisition $r): bool
    {
        return $r->isEditable() && ((int) $r->raised_by === (int) $u->id || $u->hasPermission('requisitions.manage'));
    }

    public static function canCancel(User $u, Requisition $r): bool
    {
        return in_array($r->status, ['draft', 'submitted', 'approved'], true) && ! $r->issues()->exists()
            && ((int) $r->raised_by === (int) $u->id || $u->hasPermission('requisitions.manage'));
    }

    public static function canIssue(User $u, Requisition $r): bool
    {
        return $r->isOpenForIssue() && $u->hasPermission('requisitions.issue');
    }

    public static function canAcknowledge(User $u, StoreIssue $i): bool
    {
        if ($i->isAcknowledged() || ! $u->hasPermission('requisitions.raise')) {
            return false;
        }

        return (int) $i->requisition?->raised_by === (int) $u->id || (int) self::userDepartmentId($u) === (int) $i->department_id
            || $u->hasPermission('requisitions.manage');
    }

    /** Requisitions / issue notes waiting for this user (sidebar badge). */
    public static function actionCount(?User $u = null): int
    {
        $u ??= auth()->user();
        if (! $u) {
            return 0;
        }
        $n = 0;
        if ($u->hasPermission('requisitions.approve') || $u->hasPermission('requisitions.manage')) {
            $n += Requisition::with('department')->where('status', 'submitted')->where('raised_by', '!=', $u->id)->get()
                ->filter(fn ($r) => self::canApprove($u, $r))->count();
        }
        if ($u->hasPermission('requisitions.issue')) {
            $n += Requisition::whereIn('status', Requisition::OPEN_FOR_ISSUE)->count();
        }
        if ($u->hasPermission('requisitions.raise')) {
            $n += StoreIssue::whereNull('received_at')->whereHas('requisition', fn ($q) => $q->where('raised_by', $u->id))->count();
        }

        return $n;
    }

    // ------------------------------------------------------------------ drafting

    /**
     * Create or update a draft.
     *
     * @param  array{department_id:int, from_store_id:int, to_store_id?:?int, required_by?:?string, priority?:string, purpose?:?string, lines:array<int, array{item_id:int, qty:float, remarks?:?string}>}  $d
     */
    public static function saveDraft(array $d, ?Requisition $r = null): Requisition
    {
        if ($r && ! $r->isEditable()) {
            throw ValidationException::withMessages(['lines' => 'Only a draft can be changed.']);
        }
        $to = ! empty($d['to_store_id']) ? (int) $d['to_store_id'] : null;
        if ($to && $to === (int) $d['from_store_id']) {
            throw ValidationException::withMessages(['to_store_id' => 'The department store has to be different from the issuing store.']);
        }
        if (! empty($d['required_by']) && $d['required_by'] < bdate()->toDateString()) {
            throw ValidationException::withMessages(['required_by' => 'The required-by date is in the past.']);
        }
        $lines = collect($d['lines'] ?? [])->filter(fn ($l) => ! empty($l['item_id']) && (float) ($l['qty'] ?? 0) > 0)
            ->groupBy(fn ($l) => (int) $l['item_id'])
            ->map(fn ($g, $id) => ['item_id' => (int) $id, 'qty' => round((float) $g->sum(fn ($l) => (float) $l['qty']), 2),
                'remarks' => $g->pluck('remarks')->filter()->implode('; ') ?: null])->values();
        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['lines' => 'Add at least one item with a quantity.']);
        }
        $inactive = InventoryItem::whereIn('id', $lines->pluck('item_id'))->where('is_active', false)->pluck('name');
        if ($inactive->isNotEmpty()) {
            throw ValidationException::withMessages(['lines' => 'Not stocked any more: '.$inactive->implode(', ').'.']);
        }

        return DB::transaction(function () use ($d, $r, $to, $lines) {
            $attrs = ['department_id' => (int) $d['department_id'], 'from_store_id' => (int) $d['from_store_id'], 'to_store_id' => $to,
                'required_by' => $d['required_by'] ?? null, 'priority' => ($d['priority'] ?? 'normal') === 'urgent' ? 'urgent' : 'normal',
                'purpose' => $d['purpose'] ?? null];
            if ($r) {
                $r->update($attrs);
                $r->lines()->delete();
            } else {
                $r = Requisition::create($attrs + ['req_no' => Numbering::next('requisition'), 'req_date' => bdate()->toDateString(),
                    'status' => 'draft', 'raised_by' => auth()->id()]);
            }
            foreach ($lines as $l) {
                $r->lines()->create(['inventory_item_id' => $l['item_id'], 'qty_requested' => $l['qty'], 'remarks' => $l['remarks'] ? mb_substr($l['remarks'], 0, 190) : null]);
            }
            $r->forceFill(['est_value' => self::valueOf($r->lines()->with('item')->get(), 'qty_requested')])->save();

            return $r->fresh(['lines.item']);
        });
    }

    public static function submit(Requisition $r): Requisition
    {
        if ($r->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'This requisition has already been submitted.']);
        }
        if (! $r->lines()->exists()) {
            throw ValidationException::withMessages(['lines' => 'Add at least one item before submitting.']);
        }

        return DB::transaction(function () use ($r) {
            $r->loadMissing('lines.item', 'department');
            $value = self::valueOf($r->lines, 'qty_requested');
            $r->forceFill(['status' => 'submitted', 'submitted_at' => now(), 'est_value' => $value])->save();
            $limit = (float) setting('req_auto_approve_below', '0');
            $budget = self::budgetStatus($r->department, null, $r->id);
            $fits = $budget['budget'] === null || $budget['used'] + $value <= $budget['budget'] + 0.005;
            if ($limit > 0 && $value < $limit && $fits) {
                foreach ($r->lines as $l) {
                    $l->forceFill(['qty_approved' => $l->qty_requested])->save();
                }
                $r->forceFill(['status' => 'approved', 'approved_at' => now(), 'approval_note' => 'Auto-approved – below '.money($limit)])->save();
                ActivityLogger::log('inventory', 'requisition', "Requisition {$r->req_no} submitted and auto-approved (".money($value).')', $r);
            } else {
                ActivityLogger::log('inventory', 'requisition', "Requisition {$r->req_no} submitted for approval (".money($value).')', $r);
            }

            return $r->fresh(['lines.item']);
        });
    }

    // ------------------------------------------------------------------ approval

    /**
     * @param  array<int|string, float|string|null>  $qty  requisition_line_id => approved quantity (missing = as requested)
     */
    public static function approve(Requisition $r, array $qty = [], ?string $note = null, bool $overBudgetOk = false): Requisition
    {
        $u = auth()->user();
        if ($why = self::approvalBlocker($u, $r)) {
            throw ValidationException::withMessages(['approve' => $why]);
        }
        $r->loadMissing('lines.item', 'department');
        $approved = [];
        foreach ($r->lines as $l) {
            $q = array_key_exists($l->id, $qty) && $qty[$l->id] !== null && $qty[$l->id] !== '' ? round((float) $qty[$l->id], 2) : (float) $l->qty_requested;
            if ($q < 0 || $q > (float) $l->qty_requested + 0.0001) {
                throw ValidationException::withMessages(['approve' => "Approve between 0 and {$l->qty_requested} of {$l->item?->name} – you can cut a request, not raise it."]);
            }
            $approved[$l->id] = $q;
        }
        if (array_sum($approved) <= 0) {
            throw ValidationException::withMessages(['approve' => 'Nothing approved – reject the requisition instead.']);
        }
        $value = round($r->lines->sum(fn ($l) => $approved[$l->id] * self::cost($l->item)), 2);
        $b = self::budgetStatus($r->department, null, $r->id);
        $over = $b['budget'] !== null && $b['used'] + $value > $b['budget'] + 0.005;
        if ($over) {
            $msg = "This takes {$r->department->name} over its monthly stores budget: ".money($b['used'] + $value).' against '.money($b['budget']).'.';
            if (setting('req_over_budget', 'warn') === 'block' && ! $u->hasPermission('requisitions.manage')) {
                throw ValidationException::withMessages(['approve' => $msg.' Only a user with requisition setup rights can approve it.']);
            }
            if (! $overBudgetOk || trim((string) $note) === '') {
                throw ValidationException::withMessages(['approve' => $msg.' Tick "approve over budget" and give a reason.']);
            }
        }

        return DB::transaction(function () use ($r, $approved, $value, $note, $over, $u) {
            foreach ($r->lines as $l) {
                $l->forceFill(['qty_approved' => $approved[$l->id]])->save();
            }
            $r->forceFill(['status' => 'approved', 'approved_by' => $u->id, 'approved_at' => now(), 'est_value' => $value,
                'over_budget' => $over, 'approval_note' => $note ? mb_substr(trim($note), 0, 255) : null])->save();
            $cut = $r->lines->filter(fn ($l) => $approved[$l->id] + 0.0001 < (float) $l->qty_requested)->count();
            ActivityLogger::log('inventory', 'requisition', "Requisition {$r->req_no} approved (".money($value).')'.($cut ? " – {$cut} line(s) cut" : '').($over ? ' – over budget' : ''), $r);

            return $r->fresh(['lines.item']);
        });
    }

    public static function reject(Requisition $r, string $reason): Requisition
    {
        if ($why = self::approvalBlocker(auth()->user(), $r)) {
            throw ValidationException::withMessages(['approve' => $why]);
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Give a reason for rejecting.']);
        }
        $r->forceFill(['status' => 'rejected', 'approved_by' => auth()->id(), 'approved_at' => now(), 'rejected_reason' => mb_substr(trim($reason), 0, 255)])->save();
        ActivityLogger::log('inventory', 'requisition', "Requisition {$r->req_no} rejected: {$reason}", $r);

        return $r;
    }

    // ------------------------------------------------------------------ issuing

    /**
     * @param  array<int|string, float|string|null>  $qty  requisition_line_id => quantity to issue now
     */
    public static function issue(Requisition $r, array $qty, ?string $date = null, ?string $notes = null): StoreIssue
    {
        if (! $r->isOpenForIssue()) {
            throw ValidationException::withMessages(['issue' => 'Only approved requisitions can be issued.']);
        }
        $date = Carbon::parse($date ?: bdate())->toDateString();
        if ($date > bdate()->toDateString()) {
            throw ValidationException::withMessages(['date' => 'The issue date is after the business date.']);
        }
        if ($date < $r->req_date->toDateString()) {
            throw ValidationException::withMessages(['date' => 'The issue date is before the requisition date.']);
        }
        if (($lock = LedgerService::lockDate()) && $date <= $lock) {
            throw ValidationException::withMessages(['date' => 'The books are locked up to '.Carbon::parse($lock)->format('d M Y').'.']);
        }

        return DB::transaction(function () use ($r, $qty, $date, $notes) {
            $r = Requisition::whereKey($r->id)->lockForUpdate()->first();
            $r->load('lines.item', 'department');
            $pick = [];
            foreach ($r->lines as $l) {
                $q = round((float) ($qty[$l->id] ?? 0), 2);
                if ($q <= 0) {
                    continue;
                }
                if ($q > $l->outstanding() + 0.0001) {
                    throw ValidationException::withMessages(['issue' => "Only {$l->outstanding()} {$l->item->unit} of {$l->item->name} is still to issue."]);
                }
                $have = $l->item->stock((int) $r->from_store_id);
                if ($q > $have + 0.0001) {
                    throw ValidationException::withMessages(['issue' => "Not enough {$l->item->name} in ".$r->fromStore->name.": {$have} {$l->item->unit} available."]);
                }
                $pick[] = [$l, $q];
            }
            if (! $pick) {
                throw ValidationException::withMessages(['issue' => 'Enter a quantity to issue for at least one item.']);
            }
            $issue = StoreIssue::create(['issue_no' => Numbering::next('store_issue'), 'requisition_id' => $r->id, 'department_id' => $r->department_id,
                'from_store_id' => $r->from_store_id, 'to_store_id' => $r->to_store_id, 'date' => $date, 'notes' => $notes ? mb_substr($notes, 0, 255) : null,
                'issued_by' => auth()->id()]);
            $total = 0;
            foreach ($pick as [$l, $q]) {
                $rate = self::cost($l->item);
                $amt = round($q * $rate, 2);
                $issue->lines()->create(['requisition_line_id' => $l->id, 'inventory_item_id' => $l->inventory_item_id, 'qty' => $q, 'rate' => $rate, 'amount' => $amt]);
                $base = ['inventory_item_id' => $l->inventory_item_id, 'date' => $date, 'rate' => $rate, 'department' => $r->department->name,
                    'department_id' => $r->department_id, 'store_issue_id' => $issue->id, 'reference' => $issue->issue_no, 'remarks' => $r->req_no, 'user_id' => auth()->id()];
                if ($r->to_store_id) {
                    StockMovement::create($base + ['store_id' => $r->from_store_id, 'type' => 'transfer_out', 'qty' => -$q]);
                    StockMovement::create($base + ['store_id' => $r->to_store_id, 'type' => 'transfer_in', 'qty' => $q]);
                } else {
                    StockMovement::create($base + ['store_id' => $r->from_store_id, 'type' => 'issue', 'qty' => -$q]);
                }
                $l->forceFill(['qty_issued' => round((float) $l->qty_issued + $q, 2)])->save();
                $total += $amt;
            }
            $issue->forceFill(['value' => round($total, 2)])->save();
            $r->load('lines');
            $r->forceFill(['status' => $r->lines->every(fn ($l) => $l->outstanding() <= 0.0001) ? 'issued' : 'partial'])->save();
            ActivityLogger::log('inventory', 'store_issue', "Issue note {$issue->issue_no} against {$r->req_no}: ".count($pick).' item(s), '.money($total).($r->to_store_id ? ' → '.$r->toStore?->name : ' to '.$r->department->name), $issue);
            if ($date < bdate()->toDateString() && ! $r->to_store_id) {
                DB::afterCommit(fn () => LedgerService::syncSafely()); // back-dated into a closed day
            }

            return $issue->fresh(['lines.item']);
        });
    }

    public static function acknowledge(StoreIssue $i, ?string $note = null): StoreIssue
    {
        if (! self::canAcknowledge(auth()->user(), $i)) {
            throw ValidationException::withMessages(['ack' => $i->isAcknowledged() ? 'Already acknowledged.' : 'Only the department that asked for it can acknowledge this issue.']);
        }
        $i->forceFill(['received_by' => auth()->id(), 'received_at' => now(), 'receipt_note' => $note ? mb_substr(trim($note), 0, 255) : null])->save();
        ActivityLogger::log('inventory', 'store_issue', "Issue note {$i->issue_no} received by the department".($note ? " – {$note}" : ''), $i);

        return $i;
    }

    public static function closeShort(Requisition $r, string $reason): Requisition
    {
        if (! $r->isOpenForIssue()) {
            throw ValidationException::withMessages(['close' => 'Only approved or part-issued requisitions can be closed.']);
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Give a reason for closing short.']);
        }
        $left = $r->load('lines')->outstandingQty();
        $r->forceFill(['status' => 'closed', 'closed_reason' => mb_substr(trim($reason), 0, 255), 'closed_by' => auth()->id(), 'closed_at' => now()])->save();
        ActivityLogger::log('inventory', 'requisition', "Requisition {$r->req_no} closed short ({$left} unit(s) not issued): {$reason}", $r);

        return $r;
    }

    public static function cancel(Requisition $r): Requisition
    {
        if (! in_array($r->status, ['draft', 'submitted', 'approved'], true) || $r->issues()->exists()) {
            throw ValidationException::withMessages(['cancel' => 'Stock has already been issued – close it short instead.']);
        }
        $r->forceFill(['status' => 'cancelled', 'closed_by' => auth()->id(), 'closed_at' => now()])->save();
        ActivityLogger::log('inventory', 'requisition', "Requisition {$r->req_no} cancelled", $r);

        return $r;
    }

    /** New draft with the same department, stores and items. */
    public static function repeat(Requisition $r): Requisition
    {
        $r->loadMissing('lines.item');

        return self::saveDraft(['department_id' => $r->department_id, 'from_store_id' => $r->from_store_id, 'to_store_id' => $r->to_store_id,
            'priority' => 'normal', 'purpose' => $r->purpose,
            'lines' => $r->lines->filter(fn ($l) => $l->item?->is_active)->map(fn ($l) => ['item_id' => $l->inventory_item_id, 'qty' => $l->qty_requested, 'remarks' => $l->remarks])->all()]);
    }

    /**
     * Draft purchase orders for what the issuing store can't cover (outstanding − in stock − already on order),
     * one per supplier: the cheapest current agreed price (v4.8 price lists), else the last-used supplier.
     * Returns [POs, item names with neither a price nor a purchase history].
     *
     * @return array{0: Collection, 1: Collection}
     */
    public static function raisePurchase(Requisition $r): array
    {
        if (! $r->isOpenForIssue()) {
            throw ValidationException::withMessages(['po' => 'Only approved or part-issued requisitions can be ordered.']);
        }
        $r->loadMissing('lines.item');
        $onOrder = self::onOrder();
        $last = self::lastSupplier();
        $best = []; // item => [supplier_id, rate] – lowest agreed rate valid today
        foreach (ProcurementService::priceMap() as $sid => $items) {
            foreach ($items as $iid => $rate) {
                if (! isset($best[$iid]) || $rate < $best[$iid][1]) {
                    $best[$iid] = [(int) $sid, $rate];
                }
            }
        }
        $lines = [];
        $skipped = collect();
        foreach ($r->lines as $l) {
            $short = round($l->outstanding() - max(0, $l->item->stock((int) $r->from_store_id)) - (float) ($onOrder[$l->inventory_item_id] ?? 0), 2);
            if ($short <= 0) {
                continue;
            }
            if (isset($best[$l->inventory_item_id])) {
                $lines[] = ['item_id' => $l->inventory_item_id, 'supplier_id' => $best[$l->inventory_item_id][0], 'qty' => ceil($short), 'rate' => $best[$l->inventory_item_id][1]];

                continue;
            }
            if (! isset($last[$l->inventory_item_id])) {
                $skipped->push($l->item->name);

                continue;
            }
            $lines[] = ['item_id' => $l->inventory_item_id, 'supplier_id' => (int) $last[$l->inventory_item_id]->supplier_id, 'qty' => ceil($short),
                'rate' => (float) ($last[$l->inventory_item_id]->rate ?: $l->item->last_cost)];
        }
        if (! $lines && $skipped->isEmpty()) {
            throw ValidationException::withMessages(['po' => 'Everything still to issue is in stock or already on order.']);
        }
        $pos = $lines ? StockTakeService::createPurchaseOrders($lines, (int) $r->from_store_id, null, "For requisition {$r->req_no} ({$r->department?->name})") : collect();

        return [$pos, $skipped];
    }

    // ------------------------------------------------------------------ budgets, pars, values

    /**
     * Budget position of a department for a month: issued (value of issue notes dated in the month), committed
     * (still to issue on approved / part-issued requisitions at last cost) and what is left.
     *
     * @return array{budget: ?float, issued: float, committed: float, used: float, left: ?float, pct: ?float}
     */
    public static function budgetStatus(?Department $d, ?Carbon $month = null, ?int $exceptReq = null): array
    {
        $month ??= bdate();
        if (! $d) {
            return ['budget' => null, 'issued' => 0.0, 'committed' => 0.0, 'used' => 0.0, 'left' => null, 'pct' => null];
        }
        $issued = (float) StoreIssue::where('department_id', $d->id)->whereBetween('date', [$month->copy()->startOfMonth()->toDateString(), $month->copy()->endOfMonth()->toDateString()])->sum('value');
        $committed = Requisition::with('lines.item')->where('department_id', $d->id)->whereIn('status', Requisition::OPEN_FOR_ISSUE)
            ->when($exceptReq, fn ($q) => $q->whereKeyNot($exceptReq))->get()
            ->sum(fn ($r) => $r->lines->sum(fn ($l) => $l->outstanding() * self::cost($l->item)));
        $budget = $d->monthly_budget !== null && (float) $d->monthly_budget > 0 ? (float) $d->monthly_budget : null;
        $used = round($issued + $committed, 2);

        return ['budget' => $budget, 'issued' => round($issued, 2), 'committed' => round($committed, 2), 'used' => $used,
            'left' => $budget === null ? null : round($budget - $used, 2), 'pct' => $budget ? round($used / $budget * 100, 1) : null];
    }

    /**
     * Sub-store top-up: par − stock in the store − still coming on open requisitions to that store.
     *
     * @return Collection<object{item: InventoryItem, par: float, stock: float, pending: float, suggest: float}>
     */
    public static function parSuggestions(int $storeId): Collection
    {
        $pars = StorePar::with('item')->where('store_id', $storeId)->get()->filter(fn ($p) => $p->item?->is_active);
        $stock = StockMovement::where('store_id', $storeId)->groupBy('inventory_item_id')->selectRaw('inventory_item_id, SUM(qty) q')->pluck('q', 'inventory_item_id');
        $pending = [];
        Requisition::with('lines')->where('to_store_id', $storeId)->whereIn('status', ['submitted', 'approved', 'partial'])->get()
            ->each(function ($r) use (&$pending) {
                foreach ($r->lines as $l) {
                    $pending[$l->inventory_item_id] = ($pending[$l->inventory_item_id] ?? 0) + ($r->status === 'submitted' ? (float) $l->qty_requested : $l->outstanding());
                }
            });

        return $pars->map(function ($p) use ($stock, $pending) {
            $s = round((float) ($stock[$p->inventory_item_id] ?? 0), 2);
            $pend = round((float) ($pending[$p->inventory_item_id] ?? 0), 2);

            return (object) ['item' => $p->item, 'par' => (float) $p->par_qty, 'stock' => $s, 'pending' => $pend, 'suggest' => max(0, ceil((float) $p->par_qty - $s - $pend))];
        })->sortBy(fn ($r) => $r->item->name)->values();
    }

    public static function cost(?InventoryItem $item): float
    {
        return (float) ($item?->last_cost ?? 0);
    }

    public static function valueOf(Collection $lines, string $field): float
    {
        return round($lines->sum(fn ($l) => (float) $l->{$field} * self::cost($l->item)), 2);
    }

    /** Quantity on open (draft / approved) purchase orders per item. */
    protected static function onOrder(): Collection
    {
        return PurchaseOrderItem::join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->where('purchase_orders.hotel_id', Tenant::id())->whereIn('purchase_orders.status', ['draft', 'approved', 'partial'])
            ->groupBy('purchase_order_items.inventory_item_id')->selectRaw('purchase_order_items.inventory_item_id, SUM(CASE WHEN purchase_order_items.qty > purchase_order_items.received_qty THEN purchase_order_items.qty - purchase_order_items.received_qty ELSE 0 END) q')
            ->pluck('q', 'inventory_item_id');
    }

    protected static function lastSupplier(): Collection
    {
        return PurchaseOrderItem::join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->where('purchase_orders.hotel_id', Tenant::id())->where('purchase_orders.status', '!=', 'cancelled')
            ->orderBy('purchase_orders.id')->get(['purchase_order_items.inventory_item_id', 'purchase_orders.supplier_id', 'purchase_order_items.rate'])
            ->keyBy('inventory_item_id');
    }

    /** Cost account for an expensed issue: the department's own account, else the store-type default. */
    public static function expenseHeadFor(?int $departmentId): ?int
    {
        return $departmentId ? Department::whereKey($departmentId)->value('expense_head_id') : null;
    }

    /** v2.4 upgrade: sensible cost accounts / sub-stores for the standard departments (only where not set). */
    public static function ensureDefaults(): void
    {
        $stores = Store::pluck('id', 'type');
        $map = [
            'Kitchen' => ['cost_fnb', $stores['kitchen'] ?? null], 'F&B Service' => ['cost_fnb', $stores['bar'] ?? null],
            'Housekeeping' => ['cost_hk', null], 'Spa & Wellness' => ['cost_hk', null],
        ];
        $repairs = AccountHead::where('type', 'expense')->where('name', 'Repairs & Maintenance')->value('id');
        foreach (Department::all() as $d) {
            [$key, $store] = $map[$d->name] ?? ['cost_general', null];
            $head = $d->name === 'Maintenance' && $repairs ? $repairs : AccountHead::sys($key);
            $d->forceFill(['expense_head_id' => $d->expense_head_id ?: $head, 'default_store_id' => $d->default_store_id ?: $store])->save();
        }
        foreach (['req_auto_approve_below' => '0', 'req_over_budget' => 'warn'] as $k => $v) {
            Setting::firstOrCreate(['key' => $k], ['value' => $v]);
        }
    }
}
