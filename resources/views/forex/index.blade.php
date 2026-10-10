@extends('layouts.app')
@section('title', 'Forex Desk')
@section('crumbs') Foreign Guests / Forex @endsection
@section('actions')
  <a class="btn" href="{{ route('fg.rates') }}"><x-icon name="trend"/> Today's rates</a>
  <a class="btn" href="{{ route('fg.position') }}"><x-icon name="wallet"/> Position</a>
  @if(can_do('reports.view'))<a class="btn" href="{{ route('reports.show', 'forex_register') }}"><x-icon name="chart"/> Register</a>@endif
  @if(can_do('forex.exchange') && $enabled)<a class="btn btn-primary" href="{{ route('fg.fx.create') }}" id="newFxBtn"><x-icon name="plus"/> Buy currency</a>@endif
@endsection
@section('content')
@unless($enabled)<div class="alert alert-warn"><x-icon name="alert"/><div>The forex desk is switched off. @if(can_do('forex.manage'))<a href="{{ route('fg.settings') }}">Settings</a>@endif</div></div>@endunless
@include('forex._alerts')
<div class="stats">
  <x-stat label="Exchanges" :value="$totals['count']" icon="swap" tone="blue" :sub="$from->format('d M').' – '.$to->format('d M')"/>
  <x-stat label="Cedi value" :value="money($totals['inr'])" icon="money" tone="purple" :sub="'USD '.number_format($totals['usd'], 2).' equiv.'"/>
  <x-stat label="Paid in cash" :value="money($totals['cash'])" icon="wallet" tone="amber" :sub="'VAT-exempt'"/>
  <x-stat label="Credited to bills" :value="money($totals['folio'])" icon="receipt" tone="green"/>
</div>
<div class="card flush">
  <form class="filters" style="padding:.8rem 1rem 0">
    <input class="input" type="date" name="from" value="{{ $from->toDateString() }}" aria-label="From"> <input class="input" type="date" name="to" value="{{ $to->toDateString() }}" aria-label="To">
    <select class="input" name="currency" data-autosubmit aria-label="Currency"><option value="">All currencies</option>@foreach($currencies as $c)<option value="{{ $c->id }}" @selected(request('currency') == $c->id)>{{ $c->code }}</option>@endforeach</select>
    <select class="input" name="payout" data-autosubmit aria-label="Paid"><option value="">Cash &amp; bill</option><option value="cash" @selected(request('payout') === 'cash')>Cash</option><option value="folio" @selected(request('payout') === 'folio')>Room bill</option></select>
    <input class="input" name="q" value="{{ request('q') }}" placeholder="Ref, guest or passport" id="fxSearch">
    <button class="btn btn-sm">Filter</button>
  </form>
  <div class="card-body table-wrap"><table class="table" id="fxTable">
    <thead><tr><th>Date</th><th>Ref</th><th>Guest</th><th>Currency</th><th class="num">Rate</th><th class="num">Value</th><th class="num">Paid</th><th>How</th><th>By</th></tr></thead><tbody>
    @forelse($rows as $x)
      <tr class="{{ $x->voided_at ? 'muted-row' : '' }}" data-fx="{{ $x->exchange_no }}">
        <td class="nowrap small">{{ $x->business_date->format('d M') }}</td>
        <td class="nowrap"><a href="{{ route('fg.fx.show', $x) }}" class="bold">{{ $x->exchange_no }}</a>@if($x->voided_at) <span class="badge badge-red">void</span>@endif</td>
        <td>{{ $x->guest_name }}@if($x->stay?->room) <span class="badge badge-purple">Rm {{ $x->stay->room->room_no }}</span>@endif<div class="tiny muted">{{ $x->nationality }} · {{ \App\Models\Guest::mask($x->passport_no) }}</div></td>
        <td class="nowrap">{{ $x->fc() }}@if($x->instrument === 'tc')<div class="tiny muted">travellers' cheques</div>@endif</td>
        <td class="num">{{ rtrim(rtrim(number_format($x->rate, 4), '0'), '.') }}@if($x->unit > 1)<div class="tiny muted">per {{ $x->unit }}</div>@endif</td>
        <td class="num">{{ money($x->inr_amount) }}</td>
        <td class="num bold">{{ money($x->net_amount) }}</td>
        <td class="small">{{ $x->payout === 'cash' ? 'Cash' : 'Room bill' }}</td>
        <td class="small muted">{{ $x->creator?->name }}</td>
      </tr>
    @empty<tr><td colspan="10"><x-empty text="No exchanges in this period." icon="swap"/></td></tr>@endforelse
  </tbody></table></div>
  <div class="card-foot">{{ $rows->links() }}</div>
</div>
<p class="small muted">Hotels licensed as money changers may only <b>buy</b> foreign currency, at close to the bank's rate of the day. Cash is limited to USD {{ number_format(\App\Services\ForexService::cfg()['cash_cap_usd']) }} per foreign guest within {{ \App\Services\ForexService::cfg()['aggregate_days'] }} days; an encashment certificate is issued for every purchase.</p>
@endsection
