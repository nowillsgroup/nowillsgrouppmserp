@extends('portal.layout')
@section('title', 'Currency exchange')
@section('heading', 'Currency exchange')
@section('sub', 'We buy foreign currency at the front desk – Ghana cedis in cash or a credit to your room bill.')
@section('content')
<div class="gp-card" id="gpRates"><h3>Today's buying rates · {{ bdate()->format('d M Y') }}</h3>
  @forelse($currencies as $c)
    @php $r = $rates[$c->id]; @endphp
    <div class="gp-row" data-currency="{{ $c->code }}"><div style="flex:1"><b>{{ $c->code }}</b> <span class="tiny muted">{{ $c->name }}</span></div>
      <b>{{ money((float) $r->buy_rate) }}</b><span class="tiny muted" style="margin-left:.3rem">per {{ $c->unit > 1 ? $c->unit.' ' : '' }}{{ $c->symbol ?: $c->code }}</span></div>
  @empty
    <p class="muted small">Rates for today are not published yet – please ask at the front desk.</p>
  @endforelse
</div>
<div class="gp-card"><h3>Good to know</h3>
  <ul class="small" style="padding-left:1.1rem;margin:0">
    <li>Bring your passport – we record it for every exchange (Bank of Ghana rules).</li>
    <li>Cash is limited to the equivalent of USD {{ number_format($cfg['cash_cap_usd']) }} per guest within {{ $cfg['aggregate_days'] }} days; larger amounts can be credited to your room bill.</li>
    <li>No VAT or levies apply to currency exchange.</li>
    <li>Keep the encashment certificate – keep it with your travel documents as proof of the exchange when you leave Ghana.</li>
  </ul>
</div>
@endsection
