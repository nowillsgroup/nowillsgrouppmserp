@extends('layouts.app')
@section('title', 'CRO Performance')
@section('crumbs') Central Reservations / Performance @endsection
@php $hms = fn ($s) => $s === null ? '—' : gmdate($s >= 3600 ? 'H:i:s' : 'i:s', $s); @endphp
@section('actions')<a class="btn" href="{{ route('reports.show', ['report' => 'cro_agents']) }}"><x-icon name="download"/> Agent report</a>@endsection
@section('content')
<div class="card"><div class="card-body"><form method="get" class="flex gap-1 wrap" style="align-items:center" id="perfFilter"><input type="date" name="from" value="{{ $from->toDateString() }}" aria-label="From"><input type="date" name="to" value="{{ $to->toDateString() }}" aria-label="To"><button class="btn btn-sm">Show</button></form></div></div>
<div class="stats" id="perfStats">
  <x-stat label="Enquiries" :value="$kpi['total']" icon="phone" tone="blue" :sub="'avg call '.$hms($kpi['avg_handle'])"/>
  <x-stat label="Conversion" :value="$kpi['conversion'] !== null ? $kpi['conversion'].'%' : '—'" icon="trend" :tone="$kpi['conversion'] !== null && $kpi['conversion'] >= $kpi['target'] ? 'green' : 'amber'" :sub="'booked ÷ closed · target '.rtrim(rtrim(number_format($kpi['target'], 1), '0'), '.').'%'"/>
  <x-stat label="Group retention" :value="$kpi['retention'] !== null ? $kpi['retention'].'%' : '—'" icon="swap" tone="purple" sub="(booked + referred) ÷ closed"/>
  <x-stat label="CRO room revenue" :value="money_short($kpi['booked_revenue'])" icon="money" tone="green" :sub="number_format($kpi['booked_nights']).' room nights · '.$kpi['referrals_in'].' referrals received'"/>
</div>
<div class="card flush mt-1" id="agentsCard"><div class="card-head"><h3><x-icon name="users"/> Agents</h3></div><div class="card-body table-wrap"><table class="table table-compact"><thead><tr><th>Agent</th><th class="num">Enquiries</th><th class="num">Open</th><th class="num">Booked</th><th class="num">Referred</th><th class="num">Denials</th><th class="num">Regrets</th><th class="num">Conversion</th><th class="num">Revenue</th><th class="num">To sisters</th><th class="num">Lost value</th><th class="num">Avg call</th></tr></thead><tbody>
  @forelse($agents as $a)<tr data-agent="{{ $a['label'] }}"><td>{{ $a['label'] }}</td><td class="num">{{ $a['total'] }}</td><td class="num">{{ $a['open'] ?: '' }}</td><td class="num">{{ $a['booked'] }}</td><td class="num">{{ $a['referred'] ?: '' }}</td><td class="num">{{ $a['denials'] ?: '' }}</td><td class="num">{{ $a['regrets'] ?: '' }}</td>
    <td class="num">@if($a['conversion'] !== null)<span class="badge badge-{{ $a['on_target'] ? 'green' : 'amber' }}">{{ $a['conversion'] }}%</span>@else — @endif</td><td class="num">{{ number_format($a['revenue'], 2) }}</td><td class="num">{{ $a['referred_revenue'] ? number_format($a['referred_revenue'], 2) : '' }}</td><td class="num">{{ number_format($a['lost_revenue'], 2) }}</td><td class="num">{{ $hms($a['avg_handle']) }}</td></tr>
  @empty<tr><td colspan="12" class="muted">No enquiries in this period.</td></tr>@endforelse
</tbody></table></div></div>
<div class="g-2 mt-1">
  <div class="card flush" id="channelsCard"><div class="card-head"><h3><x-icon name="message"/> Channels</h3></div><div class="card-body table-wrap"><table class="table table-compact"><thead><tr><th>Channel</th><th class="num">Enquiries</th><th class="num">Booked</th><th class="num">Conversion</th><th class="num">Revenue</th></tr></thead><tbody>
    @forelse($channels as $c)<tr><td>{{ $c['label'] }}</td><td class="num">{{ $c['total'] }}</td><td class="num">{{ $c['booked'] }}</td><td class="num">{{ $c['conversion'] !== null ? $c['conversion'].'%' : '—' }}</td><td class="num">{{ number_format($c['revenue'], 2) }}</td></tr>
    @empty<tr><td colspan="5" class="muted">—</td></tr>@endforelse
  </tbody></table></div></div>
  <div class="card flush" id="referralsCard"><div class="card-head"><h3><x-icon name="swap"/> Referrals between sister hotels</h3></div><div class="card-body table-wrap"><table class="table table-compact"><thead><tr><th>From</th><th>To</th><th class="num">Referrals</th><th class="num">Room nights</th><th class="num">Revenue kept</th></tr></thead><tbody>
    @forelse($referrals as $x)<tr><td>{{ $x['from']->name }}</td><td>{{ $x['to']->name }}</td><td class="num">{{ $x['count'] }}</td><td class="num">{{ $x['nights'] }}</td><td class="num">{{ number_format($x['value'], 2) }}</td></tr>
    @empty<tr><td colspan="5" class="muted">{{ $group ? 'No referrals in this period.' : 'Referrals are visible to group reservations agents.' }}</td></tr>@endforelse
  </tbody></table></div></div>
</div>
@endsection
