@extends('layouts.app')
@section('title', 'Gateway Payouts')
@section('crumbs')<a href="{{ route('payments.index') }}">Online payments</a> / Payouts @endsection
@section('actions')<button class="btn btn-primary" data-modal="payoutModal"><x-icon name="plus"/> Record payout</button>@endsection
@section('content')
<div class="stats wide">
  <x-stat label="Awaiting payout (1050 clearing)" :value="money($clearing)" icon="wallet" tone="purple" sub="collected online − refunds − payouts"/>
  <x-stat label="Paid out this month" :value="money($month->g)" icon="download" tone="green"/>
  <x-stat label="Gateway fees this month" :value="money($month->f)" icon="receipt" tone="amber" sub="6120 Payment Gateway & Bank Charges"/>
</div>
<div class="alert alert-info"><x-icon name="book"/><div>When {{ ucfirst($provider === 'demo' ? 'your gateway' : $provider) }} settles to your bank, record the payout from its settlement report: the <b>gross</b> relieves 1050 Payment Gateway Clearing, the <b>fees</b> go to 6120 and the <b>net</b> to the bank account. The GL control <i>Payment gateway clearing</i> then shows what is still due from the gateway.</div></div>
<div class="card flush"><div class="card-body table-wrap"><table class="table" id="payoutTable">
<thead><tr><th>Payout</th><th>Date</th><th>Gateway</th><th>Reference</th><th>Bank</th><th class="num">Gross</th><th class="num">Fees</th><th class="num">Net to bank</th><th></th></tr></thead><tbody>
@forelse($list as $po)
<tr><td class="bold">{{ $po->payout_no }}<div class="tiny muted">{{ $po->creator?->name }}</div></td><td>{{ dmy($po->date) }}</td><td>{{ ucfirst($po->provider) }}</td><td class="small">{{ $po->reference }}@if($po->notes)<div class="tiny muted">{{ $po->notes }}</div>@endif</td><td>{{ $po->paymentMode?->name }}</td>
<td class="num">{{ money($po->gross) }}</td><td class="num">{{ money($po->fees) }}</td><td class="num bold">{{ money($po->net()) }}</td>
<td class="right">@if(! $lock || $po->date->toDateString() > $lock)<form method="post" action="{{ route('payments.payouts.destroy', $po) }}" onsubmit="return confirm('Delete payout {{ $po->payout_no }}? Its journal will be reversed.')">@csrf<button class="btn btn-sm btn-ghost btn-icon" title="Delete"><x-icon name="trash"/></button></form>@else<x-icon name="lock" class="muted"/>@endif</td></tr>
@empty<tr><td colspan="9"><x-empty text="No payouts recorded yet." icon="download"/></td></tr>@endforelse
</tbody></table></div></div>
{{ $list->links() }}

<div class="modal" id="payoutModal"><div class="modal-box"><form method="post" action="{{ route('payments.payouts.store') }}">@csrf
<div class="modal-head"><h3>Record gateway payout</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
<div class="modal-body form-grid">
  <div class="field"><label>Payout date <span class="req">*</span></label><input type="date" name="date" class="input" required value="{{ old('date', now()->toDateString()) }}" max="{{ now()->toDateString() }}"></div>
  <div class="field"><label>Gateway <span class="req">*</span></label><select name="provider" class="input">@foreach(\App\Services\OnlinePaymentService::PROVIDERS as $k => $v)<option value="{{ $k }}" @selected(old('provider', $provider) === $k)>{{ ucfirst($k) }}</option>@endforeach</select></div>
  <div class="field"><label>Gross settled <span class="req">*</span></label><input type="number" name="gross" step="0.01" min="0.01" class="input" required value="{{ old('gross') }}" id="poGross" oninput="poNet()"><span class="hint">Awaiting: {{ money($clearing) }}</span></div>
  <div class="field"><label>Fees deducted</label><input type="number" name="fees" step="0.01" min="0" class="input" value="{{ old('fees', 0) }}" id="poFees" oninput="poNet()"></div>
  <div class="field"><label>Landed in <span class="req">*</span></label><select name="payment_mode_id" class="input" required>@foreach($modes as $id => $n)<option value="{{ $id }}" @selected(old('payment_mode_id') == $id)>{{ $n }}</option>@endforeach</select></div>
  <div class="field"><label>Settlement reference</label><input name="reference" class="input" maxlength="120" value="{{ old('reference') }}"></div>
  <div class="field span-2"><label>Notes</label><input name="notes" class="input" maxlength="255" value="{{ old('notes') }}"></div>
  <p class="span-2 m-0">Net to bank: <b id="poNet">—</b></p>
</div>
<div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Record payout</button></div></form></div></div>
<script>function poNet(){const g=parseFloat(document.getElementById('poGross').value)||0,f=parseFloat(document.getElementById('poFees').value)||0;document.getElementById('poNet').textContent=(g-f).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});}</script>
@endsection
