@extends('layouts.app')
@section('title', 'Cash Position')
@section('crumbs') Treasury / Cash position @endsection
@php
  $TA = \App\Models\TreasuryAccount::class;
  $TT = \App\Models\TreasuryTransfer::class;
  $canOp = can_do('treasury.operate');
  $canApprove = can_do('treasury.approve');
  $maxAbs = max(1, collect($fc['weeks'])->map(fn ($w) => abs($w['closing']))->max());
  $allPos = collect($fc['weeks'])->every(fn ($w) => $w['closing'] >= 0);
  $zero = $allPos ? 128 : 66; $span = $allPos ? 118 : 60;
@endphp
@section('actions')
  <a class="btn" href="{{ route('treasury.cheques') }}"><x-icon name="receipt"/> Cheques</a>
  <a class="btn" href="{{ route('treasury.forecast') }}"><x-icon name="trend"/> 13-week forecast</a>
  @if($canOp)<a class="btn btn-primary" id="newBankingBtn" href="{{ route('treasury.transfers', ['new' => 1]) }}"><x-icon name="swap"/> New banking / transfer</a>@endif
@endsection
@section('content')
@foreach($alerts as $a)
  <div class="alert alert-{{ ['red' => 'error', 'amber' => 'warn', 'blue' => 'info'][$a['tone']] ?? 'info' }}" data-alert><x-icon name="alert"/> {{ $a['text'] }}</div>
@endforeach

<div class="stats tr-stats">
  <x-stat label="Cash position" :value="money($pos['cash'])" icon="wallet" tone="blue" :sub="'banks, MoMo, cash, floats & cheques · '.dmy($pos['as_of'])"/>
  <x-stat label="Banks & Mobile Money" :value="money($pos['banks'])" icon="building" tone="green" :sub="$pos['unpresented'] > 0 ? money($pos['unpresented']).' in cheques not yet presented' : 'no cheques outstanding'"/>
  <x-stat label="On hand & in transit" :value="money($pos['on_hand'] + $pos['transit'])" icon="money" tone="amber" :sub="'in transit '.money($pos['transit'])"/>
  <x-stat label="Due from acquirers" :value="money($pos['clearing'])" icon="refresh" tone="purple" sub="card / gateway receipts not yet settled"/>
  <x-stat label="13-week low point" :value="$fc['low'] ? money($fc['low']['closing']) : '—'" icon="trend" :tone="$fc['first_short'] ? 'red' : 'green'" :sub="$fc['low'] ? 'week of '.$fc['low']['start']->format('d M') : ''" :href="route('treasury.forecast')"/>
</div>

<div class="grid g-2-1 mt-1">
<div>
  <div class="card flush" id="positionCard"><div class="card-head"><h3><x-icon name="wallet"/> Accounts</h3><a class="small" href="{{ route('treasury.accounts') }}">Manage accounts ›</a></div>
  <div class="card-body table-wrap"><table class="table tr-pos" id="positionTable">
    <thead><tr><th>Account</th><th class="num">Book balance</th><th class="num">Minimum</th><th>Reconciled to</th><th></th></tr></thead>
    <tbody>@foreach($pos['rows'] as $r) @php $a = $r['account']; @endphp
      <tr data-account="{{ $a->id }}" data-kind="{{ $a->kind }}" class="{{ $r['overdrawn'] || $r['below_min'] ? 'row-red' : '' }}">
        <td><b>{{ $a->name }}</b><div class="tiny muted"><span class="badge badge-{{ $TA::TONES[$a->kind] }}">{{ $TA::KINDS[$a->kind] }}</span> {{ $a->head?->code }}@if($a->maskedNo()) · {{ $a->maskedNo() }}@endif @if(! $a->in_position) · not in cash position @endif</div></td>
        <td class="num bold">{{ money($r['balance'], false) }}@if($r['unpresented'] > 0)<div class="tiny muted">{{ money($r['unpresented'], false) }} unpresented</div>@endif</td>
        <td class="num small">{{ (float) $a->min_balance ? money($a->min_balance, false) : '' }}</td>
        <td class="small">{{ $r['reconciled_to'] ? $r['reconciled_to']->format('d M Y') : '' }}</td>
        <td class="right">@if($canOp && $a->kind === 'cash' && $r['balance'] > 0)<a class="btn btn-sm" href="{{ route('treasury.transfers', ['new' => 1, 'kind' => 'deposit', 'from' => $a->id]) }}">Bank cash</a>@elseif($canOp && in_array($a->kind, ['clearing', 'momo'], true) && $r['balance'] > 0 && $a->head?->system_key !== 'gateway_clearing')<a class="btn btn-sm" href="{{ route('treasury.transfers', ['new' => 1, 'kind' => 'sweep', 'from' => $a->id]) }}">Sweep</a>@elseif($canOp && $a->kind === 'cheques' && $inHand->whereNull('transfer_id')->count())<a class="btn btn-sm" href="{{ route('treasury.transfers', ['new' => 1, 'kind' => 'deposit', 'from' => $a->id]) }}">Bank cheques</a>@endif</td>
      </tr>@endforeach</tbody>
    <tfoot><tr class="bold-row"><td>Cash position</td><td class="num">{{ money($pos['cash'], false) }}</td><td colspan="3"></td></tr></tfoot>
  </table></div></div>

  <div class="card mt-1" id="forecastMini"><div class="card-head"><h3><x-icon name="trend"/> Projected closing cash – next 13 weeks</h3><a class="small" href="{{ route('treasury.forecast') }}">Open forecast ›</a></div><div class="card-body">
    <svg viewBox="0 0 650 150" class="tr-chart" role="img" aria-label="Projected weekly closing cash">
      <line x1="0" y1="{{ $zero }}" x2="650" y2="{{ $zero }}" stroke="#cbd5e1" stroke-width="1"/>
      @foreach($fc['weeks'] as $i => $w) @php $h = round(abs($w['closing']) / $maxAbs * $span, 1); $x = $i * 50 + 6; @endphp
        <rect x="{{ $x }}" y="{{ $w['closing'] >= 0 ? $zero - $h : $zero }}" width="38" height="{{ max(1, $h) }}" rx="3" fill="{{ $w['short'] ? '#dc2626' : ($w['net'] < 0 ? '#f59e0b' : '#16a34a') }}"><title>W{{ $w['n'] }} {{ $w['start']->format('d M') }}: {{ money($w['closing']) }} (net {{ money($w['net']) }})</title></rect>
        <text x="{{ $x + 19 }}" y="148" font-size="10" text-anchor="middle" fill="#64748b">{{ $w['start']->format('d/m') }}</text>
      @endforeach
    </svg>
    <p class="tiny muted" style="margin:.3rem 0 0">Opening {{ money($fc['opening']) }} · expected in {{ money($fc['total_in']) }} · out {{ money($fc['total_out']) }}@if($fc['min'] > 0) · combined minimum balances {{ money($fc['min']) }}@endif. Red = below the minimum; amber = a week that burns cash.</p>
  </div></div>
</div>

<div>
  <div class="card" id="pendingCard"><div class="card-head"><h3><x-icon name="swap"/> Open transfers</h3><a class="small" href="{{ route('treasury.transfers') }}">All ›</a></div><div class="card-body">
    @forelse($pending as $t)
      <div class="flex justify-between items-center mb-1" data-transfer="{{ $t->id }}" data-status="{{ $t->status }}">
        <div><a class="bold" href="{{ route('treasury.transfers.show', $t) }}">{{ $t->number }}</a> <span class="badge badge-{{ $TT::TONES[$t->status] }}">{{ $TT::STATUSES[$t->status] }}</span>
          <div class="tiny muted">{{ $t->from?->name }} → {{ $t->to?->name }} · {{ $t->requester?->name }}</div></div>
        <b>{{ money($t->amount, false) }}</b>
      </div>
    @empty<x-empty text="Nothing waiting – every banking and transfer is complete." icon="check"/>@endforelse
  </div></div>

  <div class="card mt-1" id="inHandCard"><div class="card-head"><h3><x-icon name="receipt"/> Cheques in hand</h3><span class="tiny muted">{{ money($inHand->sum('amount')) }}</span></div><div class="card-body">
    @forelse($inHand->take(8) as $c)
      <div class="flex justify-between small mb-1" data-cheque="{{ $c->id }}"><span>{{ $c->party() }} <span class="muted">#{{ $c->cheque_no }} · {{ $c->received_on->format('d M') }}</span>@if($c->transfer_id) <span class="badge badge-blue">on deposit</span>@endif</span><b>{{ money($c->amount, false) }}</b></div>
    @empty<p class="small muted" style="margin:0">No cheques waiting to be banked.</p>@endforelse
    @if($pdcs->count())<div class="tiny muted mt-1 mb-1" style="text-transform:uppercase;letter-spacing:.04em">Post-dated</div>
      @foreach($pdcs as $c)<div class="flex justify-between small mb-1" data-pdc="{{ $c->id }}"><span>{{ $c->party() }} <span class="muted">due {{ $c->cheque_date->format('d M') }}</span>@if($c->cheque_date->lte(bdate())) <span class="badge badge-amber">due</span>@endif</span><b>{{ money($c->amount, false) }}</b></div>@endforeach
    @endif
  </div></div>

  <div class="card mt-1"><div class="card-head"><h3><x-icon name="check"/> Recently credited</h3></div><div class="card-body">
    @forelse($recent as $t)
      <div class="flex justify-between small mb-1"><span><a href="{{ route('treasury.transfers.show', $t) }}">{{ $t->number }}</a> <span class="muted">{{ $t->to?->name }} · {{ $t->completed_on?->format('d M') }}</span>@if(abs((float) $t->variance) >= 0.005) <span class="badge badge-red">{{ money($t->variance, false) }}</span>@endif</span><b>{{ money($t->net(), false) }}</b></div>
    @empty<p class="small muted" style="margin:0">No completed transfers yet.</p>@endforelse
  </div></div>
</div>
</div>
@endsection
