@extends('layouts.app')
@section('title', 'Rate Parity')
@section('crumbs') <a href="{{ route('cm.index') }}">Channel Manager</a> / Rate parity @endsection
@section('content')
<div class="cm-page">
@include('channel-manager._nav')
<header class="cm-hero"><div class="cm-hero-copy"><div class="cm-kicker"><x-icon name="chart"/> Channel inventory quality</div><h2>Availability &amp; rate parity</h2><p>Compare the latest PMS-published values with what each channel acknowledged over the next 14 nights.</p></div><span class="cm-hero-mark" aria-hidden="true"><x-icon name="chart"/></span></header>
@if(! $c)<x-empty text="No channel connections yet. Add a connection to start checking parity." icon="globe"/>@else
<form class="cm-filter" method="get" id="parityFilter">
  <label class="field"><span>Channel connection</span><select name="connection" aria-label="Select channel connection">@foreach($connections as $x)<option value="{{ $x->id }}" @selected($x->id === $c->id)>{{ $x->name }}</option>@endforeach</select></label>
  <label class="field"><span>First night</span><input type="date" name="from" value="{{ $from->toDateString() }}" aria-label="Select the first night to compare"></label>
  <button class="btn btn-primary"><x-icon name="search"/> Refresh parity</button>
</form>
<div class="alert {{ $outOfSync ? 'alert-warn' : 'alert-success' }}" id="paritySummary" role="status"><x-icon name="{{ $outOfSync ? 'alert' : 'check' }}"/><div>
  @if($outOfSync)<b>{{ $outOfSync }} room-night(s) differ</b> on {{ $c->name }} from what the PMS would publish now.@if($c->is_active) They will be sent with the next push.@endif
  @else {{ $c->name }} matches what the PMS publishes for these {{ $days }} nights.@endif
  <span class="muted">Channel price = PMS rate plus meal-plan supplement{{ (float) $c->rate_markup_pct ? ', then '.(($c->rate_markup_pct > 0 ? '+ ' : '− ').rtrim(rtrim((string) abs($c->rate_markup_pct), '0'), '.').'% markup') : ' with no markup' }}.</span>
  @if(can_do('channels.manage') && $c->is_active)<form method="post" action="{{ route('cm.connections.push', $c) }}" class="inline" style="margin-left:.5rem">@csrf<button type="submit" class="btn btn-sm" id="parityPush"><x-icon name="refresh"/> Push now</button></form>@endif
</div></div>
<div class="card flush"><div class="card-body table-wrap" role="region" aria-label="Scrollable 14-night rate and availability comparison" tabindex="0"><table class="table cm-parity-table" id="parityGrid">
<caption class="cm-sr-only">PMS expected availability and rates compared with the values last acknowledged by {{ $c->name }}. Highlighted cells are out of sync. A second value shows the channel value.</caption>
<thead><tr><th scope="col">Room / rate code</th>@for($i = 0; $i < $days; $i++) @php $d = $from->copy()->addDays($i); @endphp<th scope="col" class="center {{ $d->isWeekend() ? 'cm-weekend' : '' }}" aria-label="{{ $d->format('l, d F Y') }}">{{ $d->format('d') }}<span class="cm-day">{{ $d->format('D') }}</span></th>@endfor</tr></thead><tbody>
@foreach($types as $t) @php $row = $grid[$t->id] ?? []; $first = reset($row); $codes = $first ? array_keys($first['expected']['rates']) : []; @endphp
  <tr><th scope="row" class="cm-sticky-cell"><b>{{ $t->name }}</b><span class="cm-row-sub">{{ $first['expected']['code'] ?? '' }} · availability</span></th>
  @foreach($row as $date => $cell) @php $e = $cell['expected']; $h = $cell['has']; $bad = $h === null || $h['available'] !== $e['available'] || $h['stop_sell'] !== $e['stop_sell']; @endphp
    <td class="center {{ $bad ? 'cm-parity-mismatch' : '' }} {{ $e['stop_sell'] ? 'cm-stop-sell' : '' }}" title="PMS: {{ $e['available'] }}{{ $e['stop_sell'] ? ' (stop-sell)' : '' }} · channel: {{ $h ? $h['available'].($h['stop_sell'] ? ' (stop-sell)' : '') : 'never pushed' }}{{ $e['min_stay'] > 1 ? ' · min '.$e['min_stay'] : '' }}" aria-label="{{ $date }}: PMS {{ $e['stop_sell'] ? 'closed' : $e['available'].' left' }}; channel {{ $h ? ($h['stop_sell'] ? 'closed' : $h['available'].' left') : 'not yet pushed' }}">
      <b>{{ $e['stop_sell'] ? 'Closed' : $e['available'] }}</b>@if($bad)<span class="cm-channel-value">{{ $h ? ($h['stop_sell'] ? 'OTA closed' : 'OTA '.$h['available']) : 'Not pushed' }}</span>@endif @if($e['min_stay'] > 1)<span class="cm-day">min {{ $e['min_stay'] }} nights</span>@endif</td>
  @endforeach</tr>
  <tr><th scope="row" class="cm-sticky-cell cm-subrow">Direct (BAR)</th>@foreach($row as $cell)<td class="center muted">{{ number_format($cell['expected']['bar']) }}</td>@endforeach</tr>
  @foreach($codes as $code)
  <tr><th scope="row" class="cm-sticky-cell cm-subrow"><code>{{ $code }}</code></th>
    @foreach($row as $cell) @php $p = $cell['expected']['rates'][$code] ?? null; $hp = $cell['has']['rates'][$code] ?? null; $bad = $hp === null || abs((float) $hp - (float) $p) > 0.004; @endphp
      <td class="center {{ $bad ? 'cm-parity-mismatch' : '' }}" title="PMS {{ $p }} · channel {{ $hp ?? 'never pushed' }}" aria-label="PMS rate {{ number_format($p, 2) }}; channel {{ $hp !== null ? number_format($hp, 2) : 'not yet pushed' }}">{{ number_format($p) }}@if($bad)<span class="cm-channel-value">{{ $hp !== null ? 'OTA '.number_format($hp) : 'Not pushed' }}</span>@endif</td>
    @endforeach</tr>
  @endforeach
@endforeach
</tbody></table></div></div>
<p class="cm-inline-note"><b>How to read this:</b> top line is PMS availability; mapped rate rows show expected price. Highlighted cells are different from the last channel acknowledgement; the smaller line shows the channel value. “Closed” means stop-sell; “min” means minimum stay.</p>
@endif
</div>
@endsection
