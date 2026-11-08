@extends('layouts.app')
@section('title', 'Vouchers')
@section('crumbs') Accounts / Voucher Register @endsection
@section('actions') @if(can_do('accounts.manage'))<button class="btn btn-danger" data-modal="vModal" data-ftype="payment"><x-icon name="minus"/> Payment (Expense)</button><button class="btn btn-success" data-modal="vModal" data-ftype="receipt"><x-icon name="plus"/> Receipt (Income)</button>@endif @endsection
@section('content')

<div class="stats"><x-stat label="Receipts" :value="money($rec)" icon="money" tone="green" sub="money in, by the head that took it"/><x-stat label="Payments" :value="money($pay)" icon="wallet" tone="red" sub="money out, by the head it was charged to"/><x-stat label="Net" :value="money($rec - $pay)" icon="chart" tone="blue" :sub="'for the period '.dmy($from).' – '.dmy($to)"/><x-stat label="Vouchers" :value="count($vouchers)" icon="receipt" tone="slate" sub="every receipt and payment written in the period"/></div>

{{-- v9.11 a voucher becomes a ledger entry at its head: say whether the period's vouchers have got there, and name the
     heads that have not, instead of leaving the accountant to compare the register with the trial balance by hand --}}
<div class="card" id="voucherTie"><div class="card-head"><h3>Are these vouchers in the ledger?</h3>
  <span class="muted small">{{ $tie['posted'] }} posted · {{ $tie['unposted'] }} not yet · posted through {{ dmy($tie['posted_through']) }}</span></div>
  <div class="card-body"><div class="stats" style="margin:0">
    <x-stat label="The register says" :value="money($tie['expected_abs'])" icon="receipt" tone="slate" :sub="$tie['posted'].' vouchers dated on or before the posted-through day'"/>
    <x-stat label="The ledger says" :value="money($tie['actual_abs'])" icon="book" tone="slate" sub="the movement posted to the same account heads"/>
    <x-stat label="Difference" :value="money($tie['diff_abs'])" icon="alert" :tone="$tie['ties'] ? 'green' : 'red'" :sub="$tie['ties'] ? 'the two agree' : 'the two disagree'"/>
  </div>
  @if($tie['ties'])
    <p class="small" id="voucherTieState" style="margin:.6rem 0 0"><x-icon name="check"/>Every voucher dated on or before {{ dmy($tie['posted_through']) }} is in the general ledger,
      at its own head, for {{ money($tie['expected_abs']) }} of movement. @if($tie['unposted'] > 0) {{ $tie['unposted'] }} {{ $tie['unposted'] === 1 ? 'voucher is' : 'vouchers are' }} dated after that day and
      {{ $tie['unposted'] === 1 ? 'is' : 'are' }} excluded here: {{ $tie['unposted'] === 1 ? 'it has' : 'they have' }} not been posted yet. @endif</p>
  @else
    <p class="small text-red" id="voucherTieState" style="margin:.6rem 0 0"><x-icon name="alert"/>The register expects {{ money($tie['expected']) }} and the ledger has {{ money($tie['actual']) }} —
      a difference of {{ money($tie['diff_abs']) }}. The heads that disagree are
      @foreach($tie['drift'] as $d){{ $d['head'] }} (register {{ money($d['expected'], false) }}, ledger {{ money($d['actual'], false) }}) @if(! $loop->last), @endif @endforeach
      — a voucher the sync has not posted, or one posted twice. Check the sync failures on the general ledger page before the period is closed.</p>
  @endif
  </div>
</div>

<div class="card flush" id="voucherHeadsCard"><div class="card-head"><h3>By account head</h3>
  <span class="muted small">the register read as an analysis — each head's vouchers, receipts, payments and net</span></div>
<div class="card-body table-wrap"><table class="table table-compact" id="voucherHeads">
<thead><tr><th>Account head</th><th>Type</th><th class="num">Vouchers</th><th class="num">Receipts</th><th class="num">Payments</th><th class="num">Net</th></tr></thead><tbody>
@forelse($byHead as $h)
<tr data-head="{{ $h['head'] }}"><td class="bold">{{ $h['head'] }}</td><td><span class="badge {{ $h['type'] === 'receipt' ? 'badge-green' : 'badge-red' }}">{{ $h['type'] }}</span></td>
<td class="num">{{ $h['vouchers'] }}</td><td class="num">{{ $h['in'] ? money($h['in'], false) : '—' }}</td><td class="num">{{ $h['out'] ? money($h['out'], false) : '—' }}</td>
<td class="num bold">{{ money($h['net'], false) }}</td></tr>
@empty<tr><td colspan="6"><x-empty text="No vouchers in this period." icon="receipt"/></td></tr>@endforelse
</tbody><tfoot><tr class="bold"><td colspan="2">Total</td><td class="num">{{ count($vouchers) }}</td><td class="num" id="vhIn">{{ money($rec, false) }}</td><td class="num" id="vhOut">{{ money($pay, false) }}</td><td class="num" id="vhNet">{{ money($rec - $pay, false) }}</td></tr></tfoot></table></div></div>

<div class="card"><div class="card-body"><form class="filters">
<div class="field"><label>From</label><input type="date" name="from" value="{{ $from }}" max="{{ bdate()->toDateString() }}"></div><div class="field"><label>To</label><input type="date" name="to" value="{{ $to }}" max="{{ bdate()->toDateString() }}"></div>
<div class="field"><label>Type</label><select name="type"><option value="">All</option>@foreach(['payment','receipt'] as $t)<option value="{{ $t }}" @selected(request('type') === $t)>{{ $t }}</option>@endforeach</select></div>
<div class="field"><label>Head</label><select name="head"><option value="">All</option>@foreach($heads as $h)<option value="{{ $h->id }}" @selected(request('head') == $h->id)>{{ $h->label }}</option>@endforeach</select></div>
<button class="btn btn-primary">Filter</button></form></div></div>
<div class="card flush"><div class="card-body table-wrap"><table class="table" id="vouchersTable">
<thead><tr><th>Voucher</th><th>Date</th><th>Type</th><th>Account head</th><th>Party</th><th>Mode</th><th>Ref</th><th>Narration</th><th class="num">Amount</th><th></th></tr></thead><tbody>
@forelse($vouchers as $v)<tr data-type="{{ $v->type }}"><td class="bold">{{ $v->voucher_no }}</td><td>{{ $v->date->format('d M Y') }}</td><td><span class="badge {{ $v->type==='receipt' ? 'badge-green' : ($v->type==='payment' ? 'badge-red' : 'badge-slate') }}">{{ $v->type }}</span></td>
<td>{{ $v->head->name }}</td><td>{{ $v->supplier?->name ?? $v->party_name }}</td><td>{{ $v->paymentMode?->name }}</td><td>{{ $v->reference }}</td><td class="small">{{ $v->narration }}</td><td class="num bold">{{ money($v->amount) }}</td>
<td>@if(can_do('accounts.manage'))<form method="post" action="{{ route('accounts.vouchers.delete', $v) }}" data-confirm="Delete voucher {{ $v->voucher_no }}?">@csrf @method('DELETE')<button class="btn btn-sm btn-ghost"><x-icon name="trash" size="sm"/></button></form>@endif</td></tr>
@empty<tr><td colspan="10"><x-empty text="No vouchers in this period." icon="receipt"/></td></tr>@endforelse</tbody>
<tfoot><tr class="bold"><td colspan="8">Total — {{ count($vouchers) }} {{ count($vouchers) === 1 ? 'voucher' : 'vouchers' }}</td><td class="num" id="vouchersTotal">{{ money($rec + $pay) }}</td><td></td></tr></tfoot></table></div></div>

<p class="small muted">A voucher is money actually moved: a payment debits the head it is charged to and credits the payment mode's cash or bank account, a receipt
does the reverse. Each one reaches the general ledger at that head once its day is closed, so the tie above compares the register with the posted
movement head by head — a difference is a document the sync has not carried, not a rounding. The heads analysed above are the same lines the
<a href="{{ route('accounts.pnl', ['from' => $from, 'to' => $to]) }}">profit &amp; loss</a> prints for this period: the payment heads are its expenses,
the receipt heads its income, and a company receipt is not income at all — it reduces the city ledger.</p>

<div class="modal" id="vModal"><div class="modal-box"><form method="post" action="{{ route('accounts.vouchers.store') }}">@csrf
<div class="modal-head"><h3>New Voucher</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
<div class="modal-body form-grid">
<x-field name="type" label="Type" type="select" :options="['payment'=>'Payment (money out)','receipt'=>'Receipt (money in)']" :placeholder="false"/>
<x-field name="date" label="Date" type="date" :value="bdate()->toDateString()" required/>
<div class="field"><label>Account head <span class="req">*</span></label><select name="account_head_id" required><option value="">— Select —</option>@foreach($headGroups as $type=>$hs)<optgroup label="{{ ucfirst($type) }}">@foreach($hs as $h)<option value="{{ $h->id }}">{{ $h->label }}</option>@endforeach</optgroup>@endforeach</select></div>
<x-field name="amount" label="Amount" type="number" step="0.01" min="0.01" required/>
<x-field name="payment_mode_id" label="Payment mode" type="select" :options="$paymentModes"/>
<x-field name="supplier_id" label="Supplier" type="select" :options="$suppliers"/>
<x-field name="party_name" label="Party name (if not supplier)"/><x-field name="reference" label="Bill / Ref no."/>
<x-field name="narration" label="Narration" class="span-all"/>
<p class="small muted span-all">Payment: Dr the account head, Cr the payment mode's cash/bank account. Receipt: the reverse. Posted to the general ledger once the day is closed. @if(can_do('gl.journal'))For entries without cash/bank movement use a <a href="{{ route('gl.journals.create') }}">manual journal</a>. @endif</p>
</div>
<div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Save Voucher</button></div></form></div></div>
@endsection
