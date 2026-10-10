@extends('layouts.app')
@section('title', 'Bank Accounts')
@section('crumbs') Treasury / Bank accounts @endsection
@php
  $TA = \App\Models\TreasuryAccount::class;
  $TS = \App\Services\TreasuryService::class;
  $canManage = can_do('treasury.manage');
  $bal = $pos['rows']->mapWithKeys(fn ($r) => [$r['account']->id => $r['balance']]);
@endphp
@section('actions')
  @if($canManage)<button class="btn btn-primary" data-modal="accountModal" data-action="{{ route('treasury.accounts.store') }}" data-title="Add bank / MoMo / cash account" data-f_method="POST" data-fname="" data-fin_position="1" data-fis_active="1" id="newAccountBtn"><x-icon name="plus"/> Add account</button>@endif
@endsection
@section('content')
<div class="card flush"><div class="card-body table-wrap"><table class="table" id="accountTable">
  <thead><tr><th>Code</th><th>Account</th><th>Type</th><th>Bank / branch</th><th>Account no.</th><th class="num">Balance</th><th class="num">Minimum</th><th class="num">Overdraft</th><th>Settles</th><th>Status</th><th></th></tr></thead>
  <tbody>@foreach($accounts as $a)
    <tr data-account="{{ $a->id }}" class="{{ $a->is_active ? '' : 'muted' }}">
      <td>{{ $a->head?->code }}</td>
      <td><b>{{ $a->name }}</b>@if(! $a->in_position)<div class="tiny muted">not in cash position</div>@endif</td>
      <td><span class="badge badge-{{ $TA::TONES[$a->kind] }}">{{ $TA::KINDS[$a->kind] }}</span></td>
      <td class="small">{{ $a->bank_name }}@if($a->branch)<div class="tiny muted">{{ $a->branch }}</div>@endif</td>
      <td class="small">{{ $a->maskedNo() }} {{ $a->currency !== 'GHS' ? $a->currency : '' }}</td>
      <td class="num">{{ isset($bal[$a->id]) ? money($bal[$a->id], false) : '' }}</td>
      <td class="num small">{{ (float) $a->min_balance ? money($a->min_balance, false) : '' }}</td>
      <td class="num small">{{ (float) $a->overdraft_limit ? money($a->overdraft_limit, false) : '' }}</td>
      <td class="small">@if($a->kind === 'clearing' || (float) $a->fee_rate)T+{{ $a->settle_days }} · {{ rtrim(rtrim(number_format((float) $a->fee_rate, 2), '0'), '.') }}%@endif</td>
      <td><span class="badge badge-{{ $a->is_active ? 'green' : 'slate' }}">{{ $a->is_active ? 'Active' : 'Inactive' }}</span></td>
      <td class="right">@if($canManage)<button class="btn btn-sm btn-ghost" data-modal="accountModal" data-action="{{ route('treasury.accounts.update', $a) }}" data-title="Edit {{ $a->name }}" data-f_method="PUT" data-fname="{{ $a->name }}" data-fbank_name="{{ $a->bank_name }}" data-fbranch="{{ $a->branch }}" data-faccount_no="{{ $a->account_no }}" data-fswift="{{ $a->swift }}" data-fcurrency="{{ $a->currency }}" data-fmin_balance="{{ (float) $a->min_balance }}" data-foverdraft_limit="{{ (float) $a->overdraft_limit }}" data-ffee_rate="{{ (float) $a->fee_rate }}" data-fsettle_days="{{ $a->settle_days }}" data-fin_position="{{ $a->in_position ? 1 : 0 }}" data-fis_active="{{ $a->is_active ? 1 : 0 }}" data-fnotes="{{ $a->notes }}">Edit</button>@endif</td>
    </tr>@endforeach</tbody>
</table></div></div>
<p class="tiny muted mt-1">Every GL account in the Cash &amp; Bank and Petty Cash groups appears here. New bank accounts get their own ledger account (1021–1034) and join Bank Reconciliation automatically. Card / gateway clearing accounts settle after the days shown, net of the fee – the forecast uses both.</p>

<div class="grid g-2 mt-1">
@if($canManage)
<div class="card" id="settingsCard"><div class="card-head"><h3><x-icon name="tool"/> Treasury settings</h3></div><div class="card-body">
  <form method="post" action="{{ route('treasury.settings') }}" id="treasurySettings">@csrf
    <div class="form-grid">
      <div class="field"><label for="tsThr">Approval needed from (GH₵)</label><input type="number" step="0.01" min="0" name="approval_threshold" id="tsThr" value="{{ $cfg['treasury_approval_threshold'] }}" required></div>
      <div class="field"><label for="tsFloat">Cash float kept when banking</label><input type="number" step="0.01" min="0" name="cash_float" id="tsFloat" value="{{ $cfg['treasury_cash_float'] }}" required></div>
      <div class="field"><label for="tsPay">Payday (day of month)</label><input type="number" min="1" max="28" name="payday" id="tsPay" value="{{ $cfg['treasury_payday'] }}" required></div>
      <div class="field"><label for="tsClear">Cheques clear after (days)</label><input type="number" min="0" max="10" name="clear_days" id="tsClear" value="{{ $cfg['treasury_clear_days'] }}" required></div>
      <div class="field"><label for="tsCol">Overdue receivables collected in week 1 (%)</label><input type="number" min="0" max="100" name="collect_pct" id="tsCol" value="{{ $cfg['treasury_collect_pct'] }}" required></div>
      <div class="field"><label for="tsArr">Guest-paid arrivals that pay (%)</label><input type="number" min="0" max="100" name="arrival_pct" id="tsArr" value="{{ $cfg['treasury_arrival_pct'] }}" required></div>
    </div>
    <div class="right mt-1"><button class="btn btn-primary btn-sm">Save settings</button></div>
  </form>
</div></div>
@endif

<details class="card flush" id="cfCard" @if(request('cf')) open @endif><summary class="card-head"><h3><x-icon name="layers"/> Cash-flow classification (IAS 7)</h3></summary>
  <form method="post" action="{{ route('treasury.cfclasses') }}">@csrf
  <div class="card-body table-wrap" style="max-height:28rem;overflow:auto"><table class="table">
    <thead><tr><th>Account</th><th>Group</th><th>Class</th></tr></thead>
    <tbody>@foreach($heads as $h) @php $auto = $TS::autoClass($h); @endphp
      <tr><td>{{ $h->code }} {{ $h->name }}</td><td class="small muted">{{ $h->group_name }}</td>
        <td>@if($canManage)<select name="cf[{{ $h->id }}]" aria-label="Class for {{ $h->name }}">@foreach($TS::CF_CLASSES as $k => $l)<option value="{{ $k }}" @selected(($h->cf_class ?: $auto) === $k)>{{ $l }}{{ $k === $auto ? ' (default)' : '' }}</option>@endforeach</select>@else{{ $TS::CF_CLASSES[$h->cf_class ?: $auto] }}@endif</td></tr>
    @endforeach</tbody>
  </table></div>
  @if($canManage)<div class="card-body right" style="border-top:1px solid var(--c-border)"><button class="btn btn-primary btn-sm">Save classification</button></div>@endif
  </form>
</details>
</div>

@if($canManage)
<div class="modal" id="accountModal"><div class="modal-box" style="max-width:44rem"><form method="post" action="{{ route('treasury.accounts.store') }}" id="accountForm">@csrf <input type="hidden" name="_method" value="POST">
  <div class="modal-head"><h3 data-title>Account</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field"><label for="acKind">Type <span class="tiny muted">(new accounts only)</span></label><select name="kind" id="acKind"><option value="bank">Bank account</option><option value="momo">Mobile Money wallet</option><option value="cash">Cash box</option></select></div>
    <div class="field"><label for="acName">Name</label><input name="name" id="acName" required maxlength="100" placeholder="e.g. Ecobank – Collections"></div>
    <div class="field"><label for="acBank">Bank / provider</label><input name="bank_name" id="acBank" maxlength="80"></div>
    <div class="field"><label for="acBranch">Branch</label><input name="branch" id="acBranch" maxlength="80"></div>
    <div class="field"><label for="acNo">Account / wallet no.</label><input name="account_no" id="acNo" maxlength="40"></div>
    <div class="field"><label for="acSwift">SWIFT / sort code</label><input name="swift" id="acSwift" maxlength="15"></div>
    <div class="field"><label for="acCur">Currency</label><input name="currency" id="acCur" maxlength="3" value="GHS"></div>
    <div class="field"><label for="acMin">Minimum balance</label><input type="number" step="0.01" min="0" name="min_balance" id="acMin" value="0"></div>
    <div class="field"><label for="acOd">Overdraft limit</label><input type="number" step="0.01" min="0" name="overdraft_limit" id="acOd" value="0"></div>
    <div class="field"><label for="acFee">Fee on settlements (%)</label><input type="number" step="0.01" min="0" max="20" name="fee_rate" id="acFee" value="0"></div>
    <div class="field"><label for="acSettle">Settlement days</label><input type="number" min="0" max="30" name="settle_days" id="acSettle" value="0"></div>
    <div class="field span-all"><label class="check"><input type="checkbox" name="in_position" value="1" checked> <span>Counts in the cash position (cash &amp; cash equivalents)</span></label>
      <label class="check"><input type="checkbox" name="is_active" value="1" checked> <span>Active</span></label></div>
    <div class="field span-all"><label for="acNotes">Notes</label><input name="notes" id="acNotes" maxlength="255"></div>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="accountSave">Save</button></div>
</form></div></div>
@endif
@endsection
