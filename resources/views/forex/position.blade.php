@extends('layouts.app')
@section('title', 'Currency Position & Surrender')
@section('crumbs') Foreign Guests / <a href="{{ route('fg.fx') }}">Forex</a> / Position @endsection
@section('actions')
  @if(can_do('reports.view'))<a class="btn" href="{{ route('reports.show', 'forex_position') }}"><x-icon name="chart"/> Report</a>@endif
@endsection
@section('content')
@include('forex._alerts')
@error('surrender')<div class="alert alert-danger" id="surrError"><x-icon name="alert"/><div>{{ $message }}</div></div>@enderror
@php $held = $position->filter(fn ($p) => $p['held'] > 0.004); @endphp
<div class="stats">
  <x-stat label="Currency on hand (cost)" :value="money($alerts['held_value'])" icon="wallet" tone="blue" :sub="$held->count().' currencies'"/>
  <x-stat label="At today's reference" :value="money($held->sum('market'))" icon="trend" tone="purple" :sub="'unrealised '.money($held->sum('market') - $held->sum('cost'))"/>
  <x-stat label="Held too long" :value="$alerts['surrender_overdue']" icon="alert" :tone="$alerts['surrender_overdue'] ? 'red' : 'green'" :sub="'surrender within '.$cfg['surrender_days'].' days'"/>
  <x-stat label="Realised gain" :value="money($position->sum('gain'))" icon="money" tone="green" sub="all surrenders"/>
</div>

<form method="post" action="{{ route('fg.surrender.store') }}" id="surrenderForm">@csrf
<div class="card flush"><div class="card-head"><h3>On hand</h3><span class="small muted">FIFO – the age is the oldest purchase not yet surrendered</span></div>
  <div class="card-body table-wrap"><table class="table" id="positionTable">
    <thead><tr><th>Currency</th><th class="num">Bought</th><th class="num">Surrendered</th><th class="num">On hand</th><th class="num">Avg cost</th><th class="num">Cost {{ hotel()->currency_symbol }}</th><th class="num">At reference {{ hotel()->currency_symbol }}</th><th>Oldest</th>@if(can_do('forex.manage'))<th>Surrender amount</th><th>Bank rate {{ hotel()->currency_symbol }}</th>@endif</tr></thead><tbody>
    @forelse($position as $p)
      <tr data-currency="{{ $p['code'] }}" class="{{ $p['held'] > 0.004 ? '' : 'muted-row' }}">
        <td><b>{{ $p['code'] }}</b> <span class="small muted">{{ $p['name'] }}</span></td>
        <td class="num">{{ number_format($p['bought'], 2) }}</td>
        <td class="num">{{ number_format($p['surrendered'], 2) }}</td>
        <td class="num bold">{{ number_format($p['held'], 2) }}</td>
        <td class="num">{{ $p['avg'] ? rtrim(rtrim(number_format($p['avg'], 4), '0'), '.') : '—' }}</td>
        <td class="num">{{ $p['cost'] ? money($p['cost']) : '—' }}</td>
        <td class="num">{{ $p['market'] !== null ? money($p['market']) : '—' }}</td>
        <td class="small">@if($p['oldest']){{ $p['oldest']->format('d M') }} <span class="{{ $p['overdue'] ? 'badge badge-red' : 'muted' }}">{{ $p['age'] }} d</span>@else — @endif</td>
        @if(can_do('forex.manage'))
        <td>@if($p['held'] > 0.004)<input type="number" step="0.01" min="0" max="{{ $p['held'] }}" name="lines[{{ $p['currency_id'] }}][fc_amount]" value="{{ old('lines.'.$p['currency_id'].'.fc_amount') }}" placeholder="{{ number_format($p['held'], 2, '.', '') }}" style="max-width:7.5rem" aria-label="{{ $p['code'] }} amount">@endif</td>
        <td>@if($p['held'] > 0.004)<input type="number" step="0.0001" min="0" name="lines[{{ $p['currency_id'] }}][rate]" value="{{ old('lines.'.$p['currency_id'].'.rate', $p['reference'] ? rtrim(rtrim(number_format($p['reference'], 4, '.', ''), '0'), '.') : '') }}" style="max-width:7rem" aria-label="{{ $p['code'] }} rate">@if($p['unit'] > 1)<div class="tiny muted">per {{ $p['unit'] }}</div>@endif @endif</td>
        @endif
      </tr>
    @empty<tr><td colspan="10"><x-empty text="No currency bought yet." icon="wallet"/></td></tr>@endforelse
  </tbody></table></div>
  @if(can_do('forex.manage') && $held->isNotEmpty())
  <div class="card-body form-grid" style="border-top:1px solid var(--c-border)">
    <x-field name="date" label="Surrendered on" type="date" :value="bdate()->toDateString()" required/>
    <x-field name="counterparty" label="Bank / licensed bureau" :value="$cfg['franchiser']" required/>
    <x-field name="reference" label="Bank reference / receipt no."/>
    <x-field name="payment_mode_id" label="Cedis received into" type="select" :options="$modes->pluck('name', 'id')" :value="$modes->firstWhere('type', 'bank')?->id" required/>
    <x-field name="notes" label="Notes" class="span-all"/>
  </div>
  <div class="card-foot"><button class="btn btn-primary" id="surrenderBtn"><x-icon name="check"/> Record surrender</button> <span class="small muted">Enter the amounts handed over and the rate the bank paid. The difference to the average cost posts to GL 4986 Exchange Gain / Loss.</span></div>
  @endif
</div>
</form>

<div class="card flush"><div class="card-head"><h3>Recent surrenders</h3></div><div class="card-body table-wrap"><table class="table" id="surrTable">
  <thead><tr><th>Date</th><th>Ref</th><th>To</th><th>Currencies</th><th class="num">Cost</th><th class="num">Realised</th><th class="num">Gain / loss</th></tr></thead><tbody>
  @forelse($surrenders as $s)
    <tr data-surrender="{{ $s->surrender_no }}"><td class="small">{{ $s->date->format('d M Y') }}</td><td><a href="{{ route('fg.surrender.show', $s) }}" class="bold">{{ $s->surrender_no }}</a></td>
      <td>{{ $s->counterparty }}<div class="tiny muted">{{ $s->paymentMode?->name }} {{ $s->reference ? '· '.$s->reference : '' }}</div></td>
      <td class="small">{{ $s->lines->map(fn ($l) => $l->currency?->code.' '.number_format($l->fc_amount, 2))->implode(', ') }}</td>
      <td class="num">{{ money($s->total_cost) }}</td><td class="num">{{ money($s->total_realised) }}</td>
      <td class="num {{ $s->gain < 0 ? 'text-red' : 'text-green' }}">{{ money($s->gain) }}</td></tr>
  @empty<tr><td colspan="7"><x-empty text="Nothing surrendered yet." icon="wallet"/></td></tr>@endforelse
</tbody></table></div></div>
@endsection
