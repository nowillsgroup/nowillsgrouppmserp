@extends('layouts.app')
@section('title', 'Banking & Transfers')
@section('crumbs') Treasury / Banking &amp; transfers @endsection
@php
  $TA = \App\Models\TreasuryAccount::class;
  $TT = \App\Models\TreasuryTransfer::class;
  $canOp = can_do('treasury.operate');
@endphp
@section('actions')
  <a class="btn" href="{{ route('treasury.index') }}"><x-icon name="wallet"/> Cash position</a>
  @if($canOp)<button class="btn btn-primary" data-modal="transferModal" id="newTransferBtn"><x-icon name="plus"/> New banking / transfer</button>@endif
@endsection
@section('content')
<form method="get" class="card"><div class="card-body flex gap-1 wrap items-center">
  <select name="kind" aria-label="Type"><option value="">All types</option>@foreach($TT::KINDS as $k => $l)<option value="{{ $k }}" @selected(request('kind') === $k)>{{ $l }}</option>@endforeach</select>
  <select name="status" aria-label="Status"><option value="">All statuses</option>@foreach($TT::STATUSES as $k => $l)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $l }}</option>@endforeach</select>
  <button class="btn btn-sm">Filter</button>
  <span class="tiny muted" style="margin-left:auto">Transfers of {{ money($threshold) }} or more need a second person's approval before they are sent.</span>
</div></form>

<div class="card flush mt-1"><div class="card-body table-wrap"><table class="table" id="transferTable">
  <thead><tr><th>No.</th><th>Date</th><th>Type</th><th>From → to</th><th class="num">Amount</th><th class="num">Fee</th><th class="num">Short / over</th><th>Status</th><th>Requested</th></tr></thead>
  <tbody>@forelse($transfers as $t)
    <tr data-transfer="{{ $t->id }}" data-status="{{ $t->status }}" class="{{ abs((float) $t->variance) >= 0.005 ? 'row-amber' : '' }}">
      <td><a class="bold" href="{{ route('treasury.transfers.show', $t) }}">{{ $t->number }}</a>@if($t->slip_no)<div class="tiny muted">slip {{ $t->slip_no }}</div>@endif</td>
      <td class="small">{{ $t->date->format('d M Y') }}</td>
      <td class="small">{{ $TT::KINDS[$t->kind] }}@if((float) $t->cheque_amount > 0)<div class="tiny muted">incl. cheques {{ money($t->cheque_amount, false) }}</div>@endif</td>
      <td class="small">{{ $t->from?->name }} → {{ $t->to?->name }}</td>
      <td class="num bold">{{ money($t->amount, false) }}</td>
      <td class="num small">{{ (float) $t->fee ? money($t->fee, false) : '' }}</td>
      <td class="num small">{{ abs((float) $t->variance) >= 0.005 ? money($t->variance, false) : '' }}</td>
      <td><span class="badge badge-{{ $TT::TONES[$t->status] }}">{{ $TT::STATUSES[$t->status] }}</span></td>
      <td class="small">{{ $t->requester?->name }}</td>
    </tr>
  @empty<tr><td colspan="9"><x-empty text="No bankings or transfers yet." icon="swap"/></td></tr>@endforelse</tbody>
</table></div></div>
<div class="mt-1">{{ $transfers->links() }}</div>

@if($canOp)
<div class="modal" id="transferModal"><div class="modal-box" style="max-width:46rem"><form method="post" action="{{ route('treasury.transfers.store') }}" id="transferForm">@csrf
  <div class="modal-head"><h3>New banking / transfer</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field"><label for="tfKind">Type</label><select name="kind" id="tfKind" required>@foreach($TT::KINDS as $k => $l)<option value="{{ $k }}" @selected(old('kind', request('kind', 'deposit')) === $k)>{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label for="tfDate">Date</label><input type="date" name="date" id="tfDate" value="{{ old('date', bdate()->toDateString()) }}" max="{{ bdate()->toDateString() }}"></div>
    <div class="field"><label for="tfFrom">From</label><select name="from_account_id" id="tfFrom" required>
      @foreach($accounts as $a) @continue(in_array($a->kind, ['transit', 'petty'], true) || $a->head?->system_key === 'gateway_clearing') @php $s = $suggest[$a->id] ?? null; @endphp
        <option value="{{ $a->id }}" data-kind="{{ $a->kind }}" data-amount="{{ $s['amount'] ?? '' }}" data-fee="{{ $s['fee'] ?? '' }}" data-note="{{ $s['note'] ?? '' }}" @selected((int) old('from_account_id', request('from')) === $a->id)>{{ $a->name }} ({{ $TA::KINDS[$a->kind] }})</option>
      @endforeach</select><div class="tiny muted" id="tfNote"></div></div>
    <div class="field"><label for="tfTo">To</label><select name="to_account_id" id="tfTo" required>
      @foreach($accounts->whereIn('kind', ['bank', 'momo', 'cash']) as $a)<option value="{{ $a->id }}" data-kind="{{ $a->kind }}" @selected((int) old('to_account_id', request('to')) === $a->id)>{{ $a->name }} ({{ $TA::KINDS[$a->kind] }})</option>@endforeach</select></div>
    <div class="field"><label for="tfAmount">Cash / amount (GH₵)</label><input type="number" step="0.01" min="0" name="cash_amount" id="tfAmount" value="{{ old('cash_amount', request('amount')) }}" @if(old('cash_amount', request('amount'))) data-touched="1" @endif></div>
    <div class="field"><label for="tfFee">Fee / MDR withheld</label><input type="number" step="0.01" min="0" name="fee" id="tfFee" value="{{ old('fee') }}"></div>
    <div class="field"><label for="tfFeeAcct">Fee account</label><select name="fee_account_id" id="tfFeeAcct"><option value="">Bank charges / MoMo fees (default)</option>@foreach($feeHeads as $h)<option value="{{ $h->id }}">{{ $h->code }} {{ $h->name }}</option>@endforeach</select></div>
    <div class="field"><label for="tfSlip">Deposit slip / reference</label><input name="slip_no" id="tfSlip" maxlength="40" value="{{ old('slip_no') }}"></div>
    <div class="field span-all"><label class="check"><input type="checkbox" name="via_transit" value="1" id="tfTransit" checked> <span>Goes through <b>Cash in Transit</b> – sent today, confirmed when the bank credits it (sweeps are credited directly)</span></label></div>

    <div class="field span-all" id="tfChequeBox"><label>Cheques to bank with this deposit</label>
      @forelse($cheques as $c)<label class="check small"><input type="checkbox" name="cheque_ids[]" value="{{ $c->id }}" data-amt="{{ $c->amount }}"> <span>{{ $c->party() }} · #{{ $c->cheque_no }} {{ $c->bank_name }} · <b>{{ money($c->amount) }}</b></span></label>
      @empty<div class="tiny muted">No cheques in hand.</div>@endforelse
    </div>
    <details class="span-all" id="tfCount"><summary class="small bold">Cash count by denomination (optional)</summary>
      <div class="den-grid mt-1">@foreach($TT::DENOMINATIONS as $d)<label class="small"><span>GH₵{{ rtrim(rtrim($d, '0'), '.') }}</span><input type="number" min="0" name="count[{{ $d }}]" data-den="{{ $d }}" placeholder="0"></label>@endforeach</div>
      <div class="small mt-1">Counted: <b id="tfCounted">0.00</b> <button type="button" class="btn btn-sm" id="tfUseCount">Use as amount</button></div>
    </details>
    <div class="field span-all"><label for="tfNotes">Notes</label><input name="notes" id="tfNotes" maxlength="500" value="{{ old('notes', request('notes')) }}"></div>
    <div class="span-all small">Total: <b id="tfTotal">0.00</b> <span class="muted" id="tfApproval"></span></div>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="transferSave">Save</button></div>
</form></div></div>
@endif
@endsection
@push('scripts')
<script>
(function () {
  const f = document.getElementById('transferForm'); if (!f) return;
  const routes = @json(\App\Services\TreasuryService::ROUTES), limit = {{ $threshold }};
  const kind = f.querySelector('#tfKind'), from = f.querySelector('#tfFrom'), to = f.querySelector('#tfTo'), amt = f.querySelector('#tfAmount'), fee = f.querySelector('#tfFee');
  const fit = () => {
    const r = routes[kind.value];
    [...from.options].forEach(o => { o.hidden = !r[0].includes(o.dataset.kind); });
    [...to.options].forEach(o => { o.hidden = !r[1].includes(o.dataset.kind); });
    if (from.selectedOptions[0]?.hidden) { const o = [...from.options].find(x => !x.hidden); if (o) from.value = o.value; }
    if (to.selectedOptions[0]?.hidden) { const o = [...to.options].find(x => !x.hidden); if (o) to.value = o.value; }
    document.getElementById('tfChequeBox').style.display = kind.value === 'deposit' ? '' : 'none';
    document.getElementById('tfCount').style.display = kind.value === 'deposit' ? '' : 'none';
    document.getElementById('tfTransit').closest('.field').style.display = kind.value === 'sweep' ? 'none' : '';
  };
  const pick = () => {
    const o = from.selectedOptions[0]; if (!o) return;
    document.getElementById('tfNote').textContent = o.dataset.note || '';
    if (o.dataset.kind === 'cheques') { amt.value = ''; amt.disabled = true; } else { amt.disabled = false; if (!amt.dataset.touched && o.dataset.amount) amt.value = o.dataset.amount; }
    if (!fee.dataset.touched && o.dataset.fee) fee.value = o.dataset.fee;
    total();
  };
  const total = () => {
    let t = parseFloat(amt.value || 0) || 0;
    f.querySelectorAll('input[name="cheque_ids[]"]:checked').forEach(c => t += parseFloat(c.dataset.amt));
    document.getElementById('tfTotal').textContent = t.toFixed(2);
    document.getElementById('tfApproval').textContent = t >= limit ? '– needs a second person\'s approval' : '';
  };
  const counted = () => { let t = 0; f.querySelectorAll('[data-den]').forEach(i => t += (parseInt(i.value || 0) || 0) * parseFloat(i.dataset.den)); document.getElementById('tfCounted').textContent = t.toFixed(2); return t; };
  f.addEventListener('input', e => { if (e.target === amt) amt.dataset.touched = 1; if (e.target === fee) fee.dataset.touched = 1; if (e.target.dataset.den !== undefined) counted(); total(); });
  f.addEventListener('change', total);
  document.getElementById('tfUseCount').addEventListener('click', () => { amt.value = counted().toFixed(2); amt.dataset.touched = 1; total(); });
  kind.addEventListener('change', () => { fit(); pick(); });
  from.addEventListener('change', () => { delete amt.dataset.touched; delete fee.dataset.touched; pick(); });
  fit(); pick();
  @if(request('new') || $errors->any() || session('error'))
  document.getElementById('newTransferBtn')?.click();
  @endif
})();
</script>
@endpush
