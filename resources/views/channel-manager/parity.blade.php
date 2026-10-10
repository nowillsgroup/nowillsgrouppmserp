@extends('layouts.app')
@section('title', 'Rate Parity')
@section('crumbs') <a href="{{ route('cm.index') }}">Channel Manager</a> / Rate parity @endsection
@section('content')
@include('channel-manager._nav')
@if(! $c)<x-empty text="No channel connections yet." icon="globe"/>@else
<form class="filters" method="get" id="parityFilter">
  <select name="connection">@foreach($connections as $x)<option value="{{ $x->id }}" @selected($x->id === $c->id)>{{ $x->name }}</option>@endforeach</select>
  <input type="date" name="from" value="{{ $from->toDateString() }}"><button class="btn">Show</button>
</form>
<div class="alert {{ $outOfSync ? 'alert-warn' : 'alert-success' }}" id="paritySummary"><x-icon name="{{ $outOfSync ? 'alert' : 'check' }}"/><div>
  @if($outOfSync)<b>{{ $outOfSync }}</b> room-night(s) on {{ $c->name }} differ from what the PMS would publish now (highlighted).@if($c->is_active) They go out with the next push.@endif
  @else {{ $c->name }} shows exactly what the PMS publishes for these {{ $days }} nights.@endif
  <span class="muted">Channel price = PMS rate (+ meal-plan supplement) {{ (float) $c->rate_markup_pct ? (($c->rate_markup_pct > 0 ? '+ ' : '− ').rtrim(rtrim((string) abs($c->rate_markup_pct), '0'), '.').'% markup') : 'with no markup' }}.</span>
  @if(can_do('channels.manage') && $c->is_active)<form method="post" action="{{ route('cm.connections.push', $c) }}" class="inline" style="margin-left:.5rem">@csrf<button class="btn btn-sm" id="parityPush"><x-icon name="refresh"/> Push now</button></form>@endif
</div></div>
<div class="card flush"><div class="card-body table-wrap"><table class="table" id="parityGrid" style="font-size:.74rem">
<thead><tr><th>Room / rate</th>@for($i = 0; $i < $days; $i++) @php $d = $from->copy()->addDays($i); @endphp<th class="center" style="{{ $d->isWeekend() ? 'background:#f8fafc' : '' }}">{{ $d->format('d') }}<div class="muted">{{ $d->format('D') }}</div></th>@endfor</tr></thead><tbody>
@foreach($types as $t) @php $row = $grid[$t->id] ?? []; $first = reset($row); $codes = $first ? array_keys($first['expected']['rates']) : []; @endphp
  <tr><td class="nowrap"><b>{{ $t->name }}</b><div class="tiny muted">{{ $first['expected']['code'] ?? '' }} · availability</div></td>
  @foreach($row as $date => $cell) @php $e = $cell['expected']; $h = $cell['has']; $bad = $h === null || $h['available'] !== $e['available'] || $h['stop_sell'] !== $e['stop_sell']; @endphp
    <td class="center" style="{{ $bad ? 'background:#fef3c7' : '' }}{{ $e['stop_sell'] ? ';color:#b91c1c' : '' }}" title="PMS: {{ $e['available'] }}{{ $e['stop_sell'] ? ' (stop-sell)' : '' }} · channel: {{ $h ? $h['available'].($h['stop_sell'] ? ' (stop-sell)' : '') : 'never pushed' }}{{ $e['min_stay'] > 1 ? ' · min '.$e['min_stay'] : '' }}">
      <b>{{ $e['stop_sell'] ? '✕' : $e['available'] }}</b>@if($bad)<div class="tiny muted">{{ $h ? ($h['stop_sell'] ? '✕' : $h['available']) : '–' }}</div>@endif @if($e['min_stay'] > 1)<div class="tiny muted">m{{ $e['min_stay'] }}</div>@endif</td>
  @endforeach</tr>
  <tr><td class="nowrap small muted" style="padding-left:1rem">Direct (BAR)</td>@foreach($row as $cell)<td class="center muted">{{ number_format($cell['expected']['bar']) }}</td>@endforeach</tr>
  @foreach($codes as $code)
  <tr><td class="nowrap small" style="padding-left:1rem"><code>{{ $code }}</code></td>
    @foreach($row as $cell) @php $p = $cell['expected']['rates'][$code] ?? null; $hp = $cell['has']['rates'][$code] ?? null; $bad = $hp === null || abs((float) $hp - (float) $p) > 0.004; @endphp
      <td class="center" style="{{ $bad ? 'background:#fef3c7' : '' }}" title="PMS {{ $p }} · channel {{ $hp ?? 'never pushed' }}">{{ number_format($p) }}@if($bad)<div class="tiny muted">{{ $hp !== null ? number_format($hp) : '–' }}</div>@endif</td>
    @endforeach</tr>
  @endforeach
@endforeach
</tbody></table></div></div>
<p class="tiny muted">Top figure = what the PMS publishes now; a second figure on a highlighted cell = what the channel last acknowledged. ✕ = closed (stop-sell), m = minimum stay.</p>
@endif
@endsection
