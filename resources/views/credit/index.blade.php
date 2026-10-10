@extends('layouts.app')
@section('title', 'Credit Control')
@section('crumbs') Accounts / Credit Control @endsection
@section('actions')
@if(can_do('reports.view'))<a class="btn" href="{{ route('reports.show', 'credit_exposure') }}"><x-icon name="chart"/> Exposure report</a>@endif
@if(can_do('credit.manage'))<button class="btn btn-primary" data-modal="reviewModal" id="reviewBtn"><x-icon name="refresh"/> Credit review</button>@endif
@endsection
@section('content')
<div class="stats req5">
  <x-stat label="Total exposure" :value="money($stats['exposure'])" icon="wallet" tone="blue" sub="city ledger + in-house"/>
  <x-stat label="City ledger" :value="money($stats['outstanding'])" icon="building" tone="purple" sub="billed, unpaid"/>
  <x-stat label="Overdue" :value="money($stats['overdue'])" icon="clock" :tone="$stats['overdue'] > 0 ? 'red' : 'green'" sub="past credit terms" href="?show=overdue"/>
  <x-stat label="In-house to bill" :value="money($stats['pending'])" icon="bed" tone="slate" sub="open folios billed to companies"/>
  <x-stat label="On hold / over limit" :value="$stats['hold'].' / '.$stats['over']" icon="lock" :tone="$stats['hold'] + $stats['over'] ? 'amber' : 'slate'" sub="accounts" href="?show=attention"/>
</div>
<div class="tabs" id="creditTabs">@foreach(\App\Http\Controllers\CreditController::FILTERS as $k => $v)<a href="?show={{ $k }}" class="{{ $filter === $k ? 'active' : '' }}">{{ $v }}</a>@endforeach</div>
<div class="card flush"><div class="card-body table-wrap"><table class="table" id="creditTable">
<thead><tr><th>Account</th><th>Terms</th><th class="num">City ledger</th><th class="num">Overdue</th><th class="num">In-house</th><th class="num">Exposure</th><th style="min-width:150px">Limit used</th><th class="num">Available</th><th>Status</th></tr></thead><tbody>
@forelse($rows as $p) @php $co = $p['company']; @endphp
<tr>
  <td><a class="bold" href="{{ route('credit.show', $co) }}">{{ $co->name }}</a><div class="tiny muted">{{ ucwords(str_replace('_', ' ', $co->type)) }}{{ $co->last_statement_at ? ' · statement '.$co->last_statement_at->format('d M') : '' }}</div></td>
  <td class="small nowrap">{{ $co->direct_billing ? $co->credit_days.' days' : 'Pay direct' }}<div class="tiny muted">{{ $p['limit'] > 0 ? 'limit '.money($p['limit']) : 'no limit' }}</div></td>
  <td class="num">{{ money($p['outstanding']) }}</td>
  <td class="num">@if($p['overdue'] > 0)<b class="text-red">{{ money($p['overdue']) }}</b><div class="tiny muted">{{ $p['overdue_days'] }} day(s)</div>@else<span class="muted">—</span>@endif</td>
  <td class="num">{{ $p['pending'] > 0 ? money($p['pending']) : '—' }}</td>
  <td class="num bold">{{ money($p['exposure']) }}</td>
  <td>@if($p['used_pct'] !== null)<div class="flex between tiny"><span></span><b class="{{ $p['over_limit'] ? 'text-red' : '' }}">{{ $p['used_pct'] }}%</b></div><div class="progress"><span style="width:{{ min(100, $p['used_pct']) }}%;{{ $p['over_limit'] ? 'background:#dc2626' : ($p['used_pct'] >= 80 ? 'background:#f59e0b' : '') }}"></span></div>@else<span class="tiny muted">no limit</span>@endif</td>
  <td class="num {{ ($p['available'] ?? 0) < 0 ? 'text-red' : '' }}">{{ $p['available'] !== null ? money($p['available']) : '—' }}</td>
  <td>@if($co->onHold())<span class="badge badge-red" title="{{ $co->credit_hold_reason }}">Hold{{ $co->credit_hold_auto ? ' (auto)' : '' }}</span>@elseif($p['over_limit'])<span class="badge badge-amber">Over limit</span>@elseif(! $co->direct_billing)<span class="badge badge-slate">No credit</span>@else<span class="badge badge-green">Open</span>@endif</td>
</tr>
@empty<tr><td colspan="9"><x-empty text="No accounts in this view." icon="wallet"/></td></tr>@endforelse
</tbody></table></div></div>
<p class="small muted">Exposure = unpaid city-ledger bills + balances of in-house folios billed to the company. Receipts are applied to the oldest bills first; a bill is overdue once its credit days have passed.
  Automatic hold: {{ $settings['auto'] ? 'on – accounts with a bill more than '.$settings['grace'].' days overdue are held at the night audit and released when cleared' : 'off' }}.</p>
@if(can_do('credit.manage'))
<div class="modal" id="reviewModal"><div class="modal-box"><form method="post" action="{{ route('credit.review') }}">@csrf
  <div class="modal-head"><h3>Credit review</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">
    <label class="check mb-1"><input type="hidden" name="credit_auto_hold" value="0"><input type="checkbox" name="credit_auto_hold" value="1" @checked($settings['auto'])> Automatically hold accounts with overdue bills</label>
    <x-field name="credit_hold_grace_days" label="Grace days after the due date" type="number" min="0" max="180" :value="$settings['grace']"/>
    <p class="small muted mt-1">Runs now and every night at the audit. Manual holds are never released automatically.</p>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Save &amp; run review</button></div>
</form></div></div>
@endif
@endsection
