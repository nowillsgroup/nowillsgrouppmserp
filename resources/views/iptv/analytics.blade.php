@extends('layouts.app')
@section('title', 'TV Usage Analytics')
@section('crumbs') IPTV &amp; Guest Welcome / Usage Analytics @endsection
@php $E = \App\Models\TvEvent::EVENTS; $L = \App\Support\TvLang::LANGS; $RC = \App\Models\GuestRequest::CATEGORIES; @endphp
@section('actions')
  <a class="btn" href="{{ route('reports.show', ['report' => 'iptv_engagement', 'from' => $from, 'to' => $to]) }}"><x-icon name="download"/> Engagement report</a>
@endsection
@section('content')
<div class="card"><div class="card-body"><form method="get" class="filters" style="align-items:flex-end">
  <div class="field"><label>From</label><input type="date" name="from" value="{{ $from }}"></div><div class="field"><label>To</label><input type="date" name="to" value="{{ $to }}"></div><button class="btn btn-sm">Show</button></form></div></div>
<div class="stats" id="tvAnaStats">
  <x-stat label="Stays that used the TV portal" :value="$a['adoption'] !== null ? $a['adoption'].'%' : '—'" icon="users" tone="purple" :sub="$a['stays_used'].' of '.$a['stays'].' stays'"/>
  <x-stat label="Screen actions" :value="number_format($a['events'])" icon="chart" tone="blue" :sub="$a['welcomes'].' welcome screens seen'"/>
  <x-stat label="Movie rentals" :value="$a['rentals']" icon="eye" tone="teal" :sub="money($a['rental_rev']).' before tax'"/>
  <x-stat label="Dining orders from the TV" :value="$a['orders']" icon="coffee" tone="amber" :sub="money($a['order_value']).' ordered'"/>
  <x-stat label="Service requests from the TV" :value="array_sum($a['requests'])" icon="bell" tone="green" :sub="collect($a['requests'])->map(fn ($n, $k) => ($RC[$k][0] ?? $k).' '.$n)->implode(' · ')"/>
  <x-stat label="Messages read" :value="$a['messages'] ? round($a['reads'] / max(1, $a['messages']), 1).' / msg' : '—'" icon="message" tone="slate" :sub="$a['messages'].' messages sent · '.$a['reads'].' reads'"/>
</div>
<div class="card"><div class="card-head"><h3>Daily activity</h3><span class="tiny muted">screen actions per day · hover for TVs used</span></div><div class="card-body">
  @php $mx = max(1, collect($a['trend'])->max('n')); @endphp
  <div class="bar-chart" id="tvAnaTrend" style="height:160px">@foreach($a['trend'] as $t)<div class="col"><div class="b" style="height:{{ round($t['n'] / $mx * 100) }}%" data-v="{{ $t['label'] }}: {{ $t['n'] }} actions · {{ $t['tvs'] }} TVs"></div><small>{{ count($a['trend']) > 16 ? substr($t['label'], 0, 2) : $t['label'] }}</small></div>@endforeach</div>
</div></div>
<div class="g-3">
  @foreach([['What guests open', collect($a['by_event'])->mapWithKeys(fn ($n, $k) => [$E[$k] ?? $k => $n])->all(), 'tvAnaEvents'], ['Top channels', $a['channels'], 'tvAnaChannels'], ['Top information pages', $a['pages'], 'tvAnaPages']] as [$title, $rows, $id])
  <div class="card"><div class="card-head"><h3>{{ $title }}</h3></div><div class="card-body" id="{{ $id }}">
    @php $m = max(1, max($rows ?: [0])); @endphp
    @forelse($rows as $k => $n)<div class="tv-hbar"><span>{{ \Illuminate\Support\Str::limit($k, 34) }}</span><div class="progress"><span style="width:{{ round($n / $m * 100) }}%"></span></div><b>{{ $n }}</b></div>
    @empty<p class="muted small m-0">No data.</p>@endforelse
  </div></div>
  @endforeach
</div>
<div class="g-2">
  <div class="card flush"><div class="card-head"><h3>Top movies</h3></div><div class="card-body table-wrap"><table class="table table-compact" id="tvAnaMovies"><thead><tr><th>Title</th><th class="num">Rentals</th><th class="num">Revenue</th></tr></thead><tbody>
    @forelse($a['movies'] as $m)<tr><td class="small">{{ $m['title'] }}</td><td class="num">{{ $m['n'] }}</td><td class="num small">{{ money($m['rev']) }}</td></tr>@empty<tr><td colspan="3" class="muted">No rentals.</td></tr>@endforelse
  </tbody></table></div></div>
  <div class="card"><div class="card-head"><h3>Guest languages on the TV</h3></div><div class="card-body" id="tvAnaLangs">
    @php $m = max(1, max($a['languages'] ?: [0])); @endphp
    @forelse($a['languages'] as $k => $n)<div class="tv-hbar"><span>{{ $L[$k] ?? 'Default' }}</span><div class="progress"><span style="width:{{ round($n / $m * 100) }}%"></span></div><b>{{ $n }}</b></div>
    @empty<p class="muted small m-0">No sessions.</p>@endforelse
  </div></div>
</div>
@endsection
