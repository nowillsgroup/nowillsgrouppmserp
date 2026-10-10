@extends('layouts.app')
@section('title', $c->claim_no)
@section('crumbs') {!! can_do('claims.approve') || can_do('claims.pay') ? 'Accounts / <a href="'.route('claims.index').'">Expense claims</a>' : 'HR / <a href="'.route('claims.mine').'">My expense claims</a>' !!} / {{ $c->claim_no }} @endsection
@section('actions')
@if($canEdit)
  <form method="post" action="{{ route('claims.submit', $c) }}" style="display:inline">@csrf<button class="btn btn-primary" id="submitClaim" @disabled(! $c->lines->count())><x-icon name="check"/> Submit for approval</button></form>
@endif
@if($c->status === 'submitted' && can_do('claims.approve') && ! $c->isOwn() && (int) $c->created_by !== (int) auth()->id())
  <form method="post" action="{{ route('claims.approve', $c) }}" style="display:inline">@csrf<button class="btn btn-primary" id="approveClaim"><x-icon name="check"/> Approve</button></form>
  <button class="btn" data-modal="rejectModal" id="rejectClaim"><x-icon name="x"/> Send back</button>
@endif
@if($c->status === 'approved' && can_do('claims.pay'))<button class="btn btn-primary" data-modal="payModal" id="payClaim"><x-icon name="money"/> Pay {{ money($c->total) }}</button>@endif
<a class="btn" href="{{ route('claims.print', $c) }}"><x-icon name="print"/> Print</a>
@endsection
@section('content')
@if($c->status === 'rejected')<div class="alert alert-error" id="rejectNote"><x-icon name="alert"/><div><b>Sent back</b> by {{ $c->approver?->name }}: {{ $c->reject_reason }}. Correct it and submit again.</div></div>@endif
@if($c->status === 'paid')<div class="alert alert-success" id="paidNote"><x-icon name="check"/><div>Paid {{ dmy($c->paid_on) }} {{ $c->pay_method === 'petty' ? 'in cash from '.$c->pettyTxn?->float?->name.' ('.$c->pettyTxn?->txn_no.')' : 'by '.$c->paymentMode?->name }}{{ $c->pay_method === 'mode' && $c->pay_reference ? ' · ref '.$c->pay_reference : '' }} by {{ $c->payer?->name }}.</div></div>@endif

<div class="grid g-2-1">
<div class="card"><div class="card-body">
  <dl class="kv" id="claimInfo"><dt>Claimant</dt><dd>{{ $c->employee->name }} <span class="tiny muted">{{ $c->employee->emp_code }} · {{ $c->employee->department?->name }}</span></dd>
  <dt>Title</dt><dd>{{ $c->title }}</dd>
  @if($c->notes)<dt>Notes</dt><dd>{{ $c->notes }}</dd>@endif
  <dt>Status</dt><dd>{!! status_badge($c->status) !!}</dd></dl>
  <div class="tiny muted mt-1">Raised by {{ $c->creator?->name }} · {{ $c->created_at->format('d M Y') }}@if($c->submitted_at) · submitted {{ $c->submitted_at->format('d M Y') }}@endif @if($c->approved_on) · approved {{ dmy($c->approved_on) }} by {{ $c->approver?->name }}@endif</div>
</div></div>
<div class="stats" style="margin:0">
  <x-stat label="Total claimed" :value="money($c->total)" icon="receipt" tone="blue" :sub="$c->lines->count().' item(s)'"/>
  <x-stat label="Input VAT, NHIL & GETFund" :value="money($c->vat_total)" icon="book" tone="slate" sub="recoverable on GRA VAT invoices"/>
</div>
</div>

<div class="card flush mt-1">
  <div class="card-head"><h3>Expenses</h3></div>
  <div class="card-body table-wrap"><table class="table" id="lineTable">
    <thead><tr><th>Date</th><th>Type</th><th>Description</th><th>Account</th><th class="num">VAT</th><th class="num">Amount</th><th>Receipt</th><th></th></tr></thead>
    <tbody>
    @forelse($c->lines as $l)
      <tr><td class="small">{{ dmy($l->date) }}</td><td class="small">{{ $cats[$l->category] ?? $l->category }}</td>
        <td class="small">{{ $l->description }}@if($l->km)<div class="tiny muted">{{ rtrim(rtrim(number_format((float) $l->km, 1), '0'), '.') }} km × {{ money($cfg['claims_mileage_rate'], false) }}</div>@endif</td>
        <td class="tiny">{{ $l->head?->label }}</td><td class="num">{{ (float) $l->vat_amount ? money($l->vat_amount, false) : '' }}</td><td class="num">{{ money($l->amount, false) }}</td>
        <td class="small">@if($l->receipt_path)<a href="{{ route('claims.lines.receipt', $l) }}" target="_blank">view</a>@endif {{ $l->receipt_ref }}</td>
        <td>@if($canEdit)<form method="post" action="{{ route('claims.lines.delete', $l) }}" data-confirm="Remove this expense?">@csrf @method('DELETE')<button class="btn btn-sm btn-ghost" title="Remove"><x-icon name="trash" size="sm"/></button></form>@endif</td></tr>
    @empty<tr><td colspan="8"><x-empty text="No expenses added yet." icon="receipt"/></td></tr>@endforelse
    @if($c->lines->count())<tr class="bold"><td colspan="4">Total</td><td class="num">{{ money($c->vat_total, false) }}</td><td class="num" id="claimTotal">{{ money($c->total, false) }}</td><td colspan="2"></td></tr>@endif
    </tbody></table></div>
</div>

@if($canEdit)
<div class="card mt-2" id="addLineCard"><div class="card-head"><h3><x-icon name="plus"/> Add an expense</h3></div><div class="card-body">
  <form method="post" action="{{ route('claims.lines.add', $c) }}" enctype="multipart/form-data" id="lineForm">@csrf
    <div class="form-grid">
      <x-field name="date" label="Date" type="date" :value="bdate()->toDateString()" required/>
      <x-field name="category" label="Type" type="select" :options="$cats" required/>
      <x-field name="amount" label="Amount (GH₵)" type="number" step="0.01" min="0"/>
      <x-field name="km" label="Distance (km) – mileage only" type="number" step="0.1" min="0"/>
      <x-field name="vat_amount" label="VAT + NHIL + GETFund (VAT invoice)" type="number" step="0.01" min="0"/>
      <x-field name="receipt_ref" label="Receipt / MoMo no." maxlength="60"/>
      <x-field name="receipt" label="Receipt (photo / PDF)" type="file" accept=".jpg,.jpeg,.png,.webp,.pdf"/>
    </div>
    <x-field name="description" label="Description (where, why)" required maxlength="255"/>
    <button class="btn btn-primary" id="lineSave">Add expense</button>
    <span class="tiny muted">Mileage is paid at {{ money($cfg['claims_mileage_rate']) }}/km. On a GRA VAT invoice the tax is 20/120 of the total (VAT 15% + NHIL 2.5% + GETFund 2.5%).</span>
  </form>
  <div class="mt-1 right">
    <button class="btn btn-sm btn-ghost" data-modal="editClaimModal"><x-icon name="edit" size="sm"/> Edit title</button>
    <form method="post" action="{{ route('claims.destroy', $c) }}" style="display:inline" data-confirm="Delete this claim?">@csrf @method('DELETE')<button class="btn btn-sm btn-ghost text-red" id="deleteClaim"><x-icon name="trash" size="sm"/> Delete claim</button></form>
  </div>
</div></div>
<div class="modal" id="editClaimModal"><div class="modal-box"><form method="post" action="{{ route('claims.update', $c) }}">@csrf @method('PUT')
  <div class="modal-head"><h3>Edit claim</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="title" label="Title" required maxlength="150" :value="$c->title"/><x-field name="notes" label="Notes" type="textarea" maxlength="500" :value="$c->notes"/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Save</button></div>
</form></div></div>
@endif

<div class="modal" id="rejectModal"><div class="modal-box"><form method="post" action="{{ route('claims.reject', $c) }}">@csrf
  <div class="modal-head"><h3>Send {{ $c->claim_no }} back</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="reason" label="What needs fixing?" required maxlength="255"/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="rejectSave">Send back</button></div>
</form></div></div>

<div class="modal" id="payModal"><div class="modal-box"><form method="post" action="{{ route('claims.pay', $c) }}">@csrf
  <div class="modal-head"><h3>Pay {{ $c->claim_no }} · {{ money($c->total) }} to {{ $c->employee->name }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">
    <div class="field"><label>Pay by</label>
      <label class="check"><input type="radio" name="method" value="mode" checked onchange="payToggle()"> Bank transfer / Mobile Money / cash</label>
      <label class="check"><input type="radio" name="method" value="petty" onchange="payToggle()" @disabled(! count($floats))> Cash from a petty cash float</label></div>
    <div id="payMode"><x-field name="payment_mode_id" label="Payment mode" type="select" :options="$modes"/><x-field name="reference" label="Transfer / MoMo reference" maxlength="80"/></div>
    <div id="payPetty" style="display:none"><x-field name="petty_float_id" label="Float" type="select" :options="$floats"/></div>
    <x-field name="date" label="Date" type="date" :value="bdate()->toDateString()" required/>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="paySave">Pay</button></div>
</form></div></div>
<script>function payToggle(){const p=document.querySelector('#payModal [name=method]:checked').value==='petty';document.getElementById('payMode').style.display=p?'none':'';document.getElementById('payPetty').style.display=p?'':'none';}</script>
@endsection
