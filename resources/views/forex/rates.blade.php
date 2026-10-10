@extends('layouts.app')
@section('title', 'Exchange Rates · '.bdate()->format('d M Y'))
@section('crumbs') Foreign Guests / <a href="{{ route('fg.fx') }}">Forex</a> / Rates @endsection
@section('actions')
  @if($canEdit)<form method="post" action="{{ route('fg.rates.copy') }}" style="display:inline">@csrf<button class="btn" id="copyRates"><x-icon name="refresh"/> Copy yesterday's</button></form>@endif
  <a class="btn" href="{{ route('fg.fx') }}"><x-icon name="swap"/> Exchanges</a>
@endsection
@section('content')
@include('forex._alerts')
@if($errors->any())<div class="alert alert-danger" id="rateErrors"><x-icon name="alert"/><div>@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div></div>@endif
<form method="post" action="{{ route('fg.rates.save') }}" id="ratesForm">@csrf
<div class="card flush"><div class="card-head"><h3>Rates for business date {{ bdate()->format('d M Y') }}</h3><span class="small muted">Cedis per unit · reference = bank / Bank of Ghana reference rate · buy = what the desk pays (at most {{ rtrim(rtrim(number_format($cfg['max_margin'], 2), '0'), '.') }}% below)</span></div>
  <div class="card-body table-wrap"><table class="table" id="ratesTable">
    <thead><tr><th>Currency</th><th class="num">Unit</th><th class="num">Yesterday (ref / buy)</th><th>Reference {{ hotel()->currency_symbol }}</th><th>Buy {{ hotel()->currency_symbol }}</th><th class="num">Margin</th><th>Status</th><th>Last 14 days (buy)</th></tr></thead><tbody>
    @foreach($currencies as $c)
      @php $t = $today[$c->id] ?? null; $p = $prev[$c->id] ?? null; $h = $history[$c->id] ?? collect(); $key = 'rates.'.$c->id.'.buy'; @endphp
      <tr data-currency="{{ $c->code }}" class="{{ $c->is_active ? '' : 'muted-row' }}">
        <td><b>{{ $c->code }}</b> <span class="small muted">{{ $c->name }}</span>@unless($c->is_active)<div class="tiny muted">inactive</div>@endunless</td>
        <td class="num">{{ $c->unit }}</td>
        <td class="num small">{{ $p ? rtrim(rtrim(number_format($p->reference_rate, 4), '0'), '.').' / '.rtrim(rtrim(number_format($p->buy_rate, 4), '0'), '.') : '—' }}@if($p)<div class="tiny muted">{{ $p->date->format('d M') }}</div>@endif</td>
        <td><input type="number" step="0.0001" min="0" name="rates[{{ $c->id }}][reference]" value="{{ old('rates.'.$c->id.'.reference', $t?->reference_rate ? rtrim(rtrim($t->reference_rate, '0'), '.') : '') }}" style="max-width:7.5rem" @disabled(! $canEdit) aria-label="{{ $c->code }} reference"></td>
        <td><input type="number" step="0.0001" min="0" name="rates[{{ $c->id }}][buy]" value="{{ old('rates.'.$c->id.'.buy', $t?->buy_rate ? rtrim(rtrim($t->buy_rate, '0'), '.') : '') }}" style="max-width:7.5rem" class="@error($key) is-invalid @enderror" @disabled(! $canEdit) aria-label="{{ $c->code }} buy">
          @error($key)<div class="err tiny">{{ $message }}</div>@enderror</td>
        <td class="num small">{{ $t && $t->reference_rate > 0 ? number_format((1 - $t->buy_rate / $t->reference_rate) * 100, 2).'%' : '—' }}</td>
        <td>@if(! $t)<span class="badge badge-{{ $c->is_active ? 'red' : 'slate' }}">not set</span>@elseif($t->carried)<span class="badge badge-amber">carried</span>@else<span class="badge badge-green">set</span>@endif</td>
        <td>@if($h->count() > 1)
          @php $vals = $h->pluck('buy_rate')->map(fn ($v) => (float) $v); $min = $vals->min(); $max = $vals->max(); $n = $vals->count(); $pts = $vals->values()->map(fn ($v, $i) => round($i / max(1, $n - 1) * 110, 1).','.round(22 - ($max > $min ? ($v - $min) / ($max - $min) * 20 : 10), 1))->implode(' '); @endphp
          <svg width="112" height="24" viewBox="0 0 112 24" aria-label="{{ $c->code }} trend"><polyline points="{{ $pts }}" fill="none" stroke="#2563eb" stroke-width="1.5"/></svg>
        @else<span class="tiny muted">—</span>@endif</td>
      </tr>
    @endforeach
  </tbody></table></div>
  @if($canEdit)<div class="card-foot"><button class="btn btn-primary" id="saveRates"><x-icon name="check"/> Save rates</button> <span class="small muted">Blank rows are left unchanged. Currencies are managed under <a href="{{ route('masters.index', 'currencies') }}">Masters › Currencies</a>.</span></div>@endif
</div>
</form>
@endsection
