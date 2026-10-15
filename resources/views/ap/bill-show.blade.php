@extends('layouts.app')
@section('title', ($bill->isDebitNote() ? 'Debit Note ' : 'Supplier Bill ').$bill->doc_no)
@section('crumbs') <a href="{{ route('ap.bills') }}">Supplier Bills</a> / {{ $bill->doc_no }} @endsection
@section('actions')
{!! status_badge($bill->status) !!}
<a class="btn" href="{{ route('ap.statement', $bill->supplier_id) }}"><x-icon name="book"/> Statement</a>
@if(! $bill->isDebitNote() && in_array($bill->status, ['open', 'partial']) && can_do('ap.pay'))<a class="btn btn-success" href="{{ route('ap.payments.create', ['supplier' => $bill->supplier_id, 'bills' => [$bill->id]]) }}"><x-icon name="money"/> Pay</a>@endif
@if(! $bill->isDebitNote() && $bill->status !== 'cancelled' && can_do('ap.manage'))<a class="btn" href="{{ route('ap.bills.create', ['mode' => 'debit_note', 'supplier' => $bill->supplier_id, 'against' => $bill->id]) }}"><x-icon name="minus"/> Debit note</a>@endif
@if($bill->status === 'on_hold' && can_do('ap.approve'))<button class="btn btn-primary" data-modal="approveModal"><x-icon name="check"/> Approve variance</button>@endif
@if($bill->status !== 'cancelled' && can_do('ap.manage'))<button class="btn btn-danger" data-modal="cancelModal"><x-icon name="x"/> Cancel</button>@endif
@endsection
@section('content')
@php $od = ! $bill->isDebitNote() && in_array($bill->status, ['open', 'partial']) ? $bill->daysOverdue() : 0; @endphp
@if($bill->status === 'on_hold')
<div class="alert alert-warn"><x-icon name="lock"/><div><b>On hold – price variance {{ money($bill->variance()) }}.</b> The supplier invoice ({{ money($bill->total) }}) differs from the goods received ({{ money($bill->grn_total) }}) by more than the tolerance of {{ money(\App\Services\ApService::tolerance((float) $bill->grn_total)) }}. It is not posted and cannot be paid until someone with approval rights releases it – or raise a debit note / ask the supplier for a corrected invoice.</div></div>
@endif
@if($bill->status === 'cancelled')<div class="alert alert-error"><x-icon name="x"/><div><b>Cancelled</b> {{ $bill->cancelled_at?->format('d M Y H:i') }} – {{ $bill->cancel_reason }}</div></div>@endif

<div class="card"><div class="card-body grid g-3">
  <dl class="kv"><dt>Supplier</dt><dd><b>{{ $bill->supplier->name }}</b></dd><dt>{{ $bill->isDebitNote() ? 'Credit note no.' : 'Invoice no.' }}</dt><dd>{{ $bill->supplier_ref ?? '—' }}</dd>
    <dt>Type</dt><dd>{{ $bill->isDebitNote() ? 'Debit note' : ($bill->kind === 'goods' ? 'Goods (matched to receipt)' : 'Expense / service') }}</dd>
    @if($bill->purchaseOrder)<dt>Purchase order</dt><dd><a href="{{ route('purchases.show', $bill->purchaseOrder) }}">{{ $bill->purchaseOrder->po_no }}</a></dd>@endif
    @if($bill->againstInvoice)<dt>Against bill</dt><dd><a href="{{ route('ap.bills.show', $bill->againstInvoice) }}">{{ $bill->againstInvoice->doc_no }}</a></dd>@endif</dl>
  <dl class="kv"><dt>Date</dt><dd>{{ $bill->doc_date->format('d M Y') }}</dd>@if(! $bill->isDebitNote())<dt>Due</dt><dd class="{{ $od ? 'text-red bold' : '' }}">{{ $bill->due_date?->format('d M Y') }}{{ $od ? " · {$od} days overdue" : '' }}</dd>@endif
    <dt>Entered by</dt><dd>{{ $bill->creator?->name ?? '—' }} · {{ $bill->created_at->format('d M H:i') }}</dd>
    @if($bill->approved_at)<dt>Approved by</dt><dd>{{ $bill->approver?->name }} · {{ $bill->approved_at->format('d M H:i') }}</dd>@endif</dl>
  <div class="ap-summary">
    <div><div class="l">Total</div><div class="v">{{ money($bill->total) }}</div></div>
    <div><div class="l">{{ $bill->isDebitNote() ? 'Applied' : 'Paid / credited' }}</div><div class="v green">{{ money($bill->allocated) }}</div></div>
    <div><div class="l">{{ $bill->isDebitNote() ? 'Unapplied credit' : 'Outstanding' }}</div><div class="v {{ $bill->outstanding() > 0 && ! $bill->isDebitNote() ? 'red' : '' }}" id="billOutstanding">{{ money($bill->outstanding()) }}</div></div>
  </div>
  @if($bill->isForeign())
  <div class="ap-summary" id="fxSummary">
    <div><div class="l">Billed in {{ $bill->currency }}</div><div class="v">{{ $bill->fxLabel() }}</div></div>
    <div><div class="l">Net + tax as billed</div><div class="v">{{ number_format((float) $bill->fx_subtotal, 2) }} + {{ number_format((float) $bill->fx_tax, 2) }}</div></div>
    <div><div class="l">Board rate · {{ $bill->fx_basis }}</div><div class="v">{{ number_format((float) $bill->fx_rate, 4) }}</div></div>
    <div><div class="l">Board of</div><div class="v">{{ $bill->fx_date?->format('d M Y') }} @if($bill->fxStale())<span class="badge badge-amber">carried forward</span>@endif</div></div>
    <div><div class="l">Still owed in {{ $bill->currency }}</div><div class="v {{ $bill->fxOutstanding() > 0 ? 'red' : '' }}">{{ $bill->fxLabel($bill->fxOutstanding()) }}</div></div>
  </div>
  <p class="tiny muted">The ledger, the budget, ageing and every payment carry {{ money($bill->total) }}: each line was converted at the board above and rounded on its own, so the lines sum to what was posted. The supplier's own document is kept here beside it, and Accounts payable › Ageing re-values what is still open against the board as it stands.</p>
  @endif
</div></div>

@if($bill->kind === 'goods' && ! $bill->isDebitNote())
<div class="card"><div class="card-head"><h3>Three-way match</h3></div><div class="card-body table-wrap"><table class="table table-compact" id="matchTable">
<thead><tr><th></th><th class="num">Purchase order / goods received</th><th class="num">Supplier invoice</th><th class="num">Difference</th></tr></thead><tbody>
@php $po = $bill->purchaseOrder; @endphp
<tr><td>Subtotal</td><td class="num">{{ money($po->subtotal, false) }}</td><td class="num">{{ money($bill->subtotal, false) }}</td><td class="num">{{ money($bill->subtotal - $po->subtotal, false) }}</td></tr>
<tr><td>Tax</td><td class="num">{{ money($po->tax, false) }}</td><td class="num">{{ money($bill->tax, false) }}</td><td class="num">{{ money($bill->tax - $po->tax, false) }}</td></tr>
<tr class="bold"><td>Total</td><td class="num">{{ money($bill->grn_total, false) }}</td><td class="num">{{ money($bill->total, false) }}</td><td class="num {{ $bill->variance() ? ($bill->status === 'on_hold' ? 'text-red' : 'text-amber') : 'text-green' }}">{{ $bill->variance() ? money($bill->variance(), false) : 'Matched' }}</td></tr>
</tbody></table>
<details class="mt-1"><summary class="small">Items received ({{ $po->items->count() }})</summary><table class="table table-compact">@foreach($po->items as $l)<tr><td>{{ $l->item->name }}</td><td class="num">{{ (float) $l->qty }} {{ $l->item->unit }}</td><td class="num">{{ money($l->rate, false) }}</td><td class="num">{{ money($l->amount, false) }}</td></tr>@endforeach</table></details>
</div></div>
@else
<div class="card flush"><div class="card-body table-wrap"><table class="table"><thead><tr><th>Account</th><th>Description</th><th class="num">Amount</th><th class="num">Tax %</th><th class="num">Tax</th><th class="num">Total</th></tr></thead><tbody>
@foreach($bill->lines as $l)<tr><td>{{ $l->head?->label }}</td><td>{{ $l->description }}</td><td class="num">{{ money($l->amount, false) }}</td><td class="num">{{ (float) $l->tax_rate }}</td><td class="num">{{ money($l->tax_amount, false) }}</td><td class="num">{{ money($l->amount + $l->tax_amount, false) }}</td></tr>@endforeach
</tbody><tfoot><tr><td colspan="2" class="right">Totals</td><td class="num">{{ money($bill->subtotal, false) }}</td><td></td><td class="num">{{ money($bill->tax, false) }}</td><td class="num bold">{{ money($bill->total, false) }}</td></tr></tfoot></table></div></div>
@endif

<div class="grid g-2">
<div class="card"><div class="card-head"><h3>{{ $bill->isDebitNote() ? 'Applied to' : 'Payments & credits applied' }}</h3></div><div class="card-body table-wrap">
  @php $rows = $bill->isDebitNote() ? $bill->applications : $bill->allocations; @endphp
  <table class="table table-compact" id="allocTable"><thead><tr><th>Date</th><th>Source</th><th class="num">Amount</th><th></th></tr></thead><tbody>
  @forelse($rows as $a)
  <tr><td>{{ $a->date->format('d M Y') }}</td>
    <td>@if($bill->isDebitNote())<a href="{{ route('ap.bills.show', $a->invoice) }}">{{ $a->invoice->doc_no }}</a> <span class="tiny muted">{{ $a->invoice->supplier_ref }}</span>
        @elseif($a->payment)<a href="{{ route('ap.payments.show', $a->payment) }}">{{ $a->payment->payment_no }}</a> <span class="tiny muted">{{ $a->payment->paymentMode?->name }} {{ $a->payment->reference }}{{ $a->payment->isForeign() ? ' · '.$a->payment->fxLabel().' @ '.number_format((float) $a->payment->fx_rate, 4) : '' }}</span>
        @else<a href="{{ route('ap.bills.show', $a->debitNote) }}">{{ $a->debitNote->doc_no }}</a> <span class="tiny muted">debit note</span>@endif</td>
    <td class="num">{{ money($a->amount, false) }}</td>
    <td class="right">@if(can_do('ap.pay'))<form method="post" action="{{ route('ap.allocations.delete', $a) }}" onsubmit="return confirm('Remove this allocation? The amount becomes unapplied supplier credit again.')">@csrf @method('delete')<button class="btn btn-ghost btn-sm" title="Un-apply"><x-icon name="x" size="sm"/></button></form>@endif</td></tr>
  @empty<tr><td colspan="4" class="empty">Nothing applied yet.</td></tr>@endforelse
  </tbody></table>
  @if($credits->count() && can_do('ap.pay'))
  <form method="post" action="{{ route('ap.bills.apply', $bill) }}" class="flex gap-1 wrap items-center mt-1" id="applyCredit">@csrf
    <select name="source" class="input input-sm" style="flex:1;min-width:220px">@foreach($credits as $c)<option value="{{ $c['key'] }}" data-free="{{ $c['free'] }}">{{ $c['label'] }}</option>@endforeach</select>
    <input type="number" name="amount" step="0.01" min="0.01" class="input input-sm num" style="width:120px" value="{{ min($credits->first()['free'], $bill->outstanding()) }}">
    <button class="btn btn-sm btn-primary">Apply credit</button>
  </form>
  @endif
</div></div>

<div class="card"><div class="card-head"><h3>Ledger postings</h3></div><div class="card-body">
  @if($grnJournal)<p class="small" style="margin-top:0">Goods receipt: <a href="{{ route('gl.journals.show', $grnJournal) }}">{{ $grnJournal->journal_no }}</a> – Dr Inventory / Cr Accounts Payable {{ money($grnJournal->total) }}</p>@endif
  @forelse($journals as $j)
    <div class="small"><a href="{{ route('gl.journals.show', $j) }}">{{ $j->journal_no }}</a> · {{ $j->date->format('d M Y') }} · {{ money($j->total) }} {{ $j->reversal_of_id ? '(reversal)' : ($j->reversed_by_id ? '(reversed)' : '') }}</div>
  @empty
    <p class="small muted" style="margin:0">@if($bill->status === 'on_hold')Nothing posts while the bill is on hold.@elseif($bill->kind === 'goods' && ! $bill->isDebitNote() && ! $bill->variance())Matched bill – nothing further to post.@else Posts with the ledger sync once {{ $bill->doc_date->format('d M') }} is closed by the night audit.@endif</p>
  @endforelse
  @if($bill->notes)<hr><div class="small" style="white-space:pre-line">{{ $bill->notes }}</div>@endif
</div></div>
</div>

<div class="modal" id="cancelModal"><div class="modal-box"><form method="post" action="{{ route('ap.bills.cancel', $bill) }}">@csrf
<div class="modal-head"><h3>Cancel {{ $bill->doc_no }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
<div class="modal-body"><p class="small">@if($bill->allocated > 0)<b>{{ money($bill->allocated) }}</b> applied to this document will be released back as unapplied supplier credit. @endif Any ledger posting is reversed.</p>
<div class="field"><label>Reason <span class="req">*</span></label><input name="reason" required maxlength="200" class="input"></div></div>
<div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-danger">Cancel document</button></div></form></div></div>

@if($bill->status === 'on_hold')
<div class="modal" id="approveModal"><div class="modal-box"><form method="post" action="{{ route('ap.bills.approve', $bill) }}">@csrf
<div class="modal-head"><h3>Approve price variance</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
<div class="modal-body"><p>Accept the supplier's invoice of <b>{{ money($bill->total) }}</b> against goods received of <b>{{ money($bill->grn_total) }}</b>. The variance of <b>{{ money($bill->variance()) }}</b> is posted to Purchase Price Variance.</p>
<div class="field"><label>Approval note</label><input name="note" maxlength="200" class="input" placeholder="e.g. Market price increase confirmed with chef"></div></div>
<div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Approve</button></div></form></div></div>
@endif
@endsection
