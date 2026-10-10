@extends('layouts.app')
@section('title', $segment->name)
@section('crumbs') <a href="{{ route('crm.index') }}">Marketing</a> / <a href="{{ route('crm.segments.index') }}">Segments</a> / {{ $segment->name }} @endsection
@section('actions')
@if(can_do('crm.manage'))
  <a class="btn" href="{{ route('crm.segments.export', $segment) }}" id="exportSeg"><x-icon name="download"/> Export CSV</a>
  <a class="btn" href="{{ route('crm.segments.edit', $segment) }}"><x-icon name="edit"/> Edit rules</a>
  @if($segment->is_active)<a class="btn btn-primary" href="{{ route('crm.campaigns.create', ['segment' => $segment->id]) }}"><x-icon name="mail"/> New campaign</a>@endif
@endif
@endsection
@section('content')
@unless($segment->is_active)<div class="alert alert-warn"><x-icon name="alert"/><div>This segment is inactive – it cannot be used for new campaigns.</div></div>@endunless
<div class="stats wide">
  <x-stat label="Guests in segment" :value="$size" icon="users" tone="blue" :sub="$segment->describe()"/>
  @foreach(\App\Services\CrmService::CHANNELS as $ch => $label)
    <x-stat :label="$label.' reachable'" :value="$reach[$ch]" :icon="$ch === 'email' ? 'mail' : 'message'" :tone="$ch === 'email' ? 'purple' : 'green'" :sub="$size ? round($reach[$ch] / $size * 100).'% opted in' : '—'"/>
  @endforeach
</div>
<form class="filters" method="get"><input name="q" class="input" placeholder="Search name, phone, e-mail" value="{{ request('q') }}"><button class="btn"><x-icon name="search"/> Search</button></form>
<div class="card flush"><div class="card-body table-wrap"><table class="table" id="segGuests">
<thead><tr><th>Guest</th><th>Country</th><th class="num">Stays</th><th class="num">Nights</th><th class="num">Revenue</th><th>Last stay</th><th>Value</th><th>Consent</th><th>Tags</th></tr></thead><tbody>
@forelse($guests as $g) @php $rfm = \App\Services\CrmService::rfm($g); @endphp
<tr class="{{ $g->is_blacklisted ? 'muted-row' : '' }}">
  <td><a href="{{ route('guests.show', $g) }}">{{ $g->full_name }}</a>@if($g->is_vip) <span class="badge badge-purple">VIP</span>@endif @if($g->is_blacklisted) <span class="badge badge-red">Blacklisted</span>@endif<div class="tiny muted">{{ $g->company?->name }}</div></td>
  <td class="small">{{ $g->country ?? '—' }}</td><td class="num">{{ $g->stays_count }}</td><td class="num">{{ $g->nights_count }}</td><td class="num">{{ money($g->total_revenue, false) }}</td>
  <td class="small">{{ $g->last_stay_on?->format('d M Y') ?? '—' }}</td><td><span class="badge badge-{{ $rfm['tone'] }}">{{ $rfm['label'] }}</span></td>
  <td class="small nowrap">@foreach(['email' => 'mail', 'sms' => 'message', 'whatsapp' => 'phone'] as $ch => $ic)<span title="{{ \App\Services\CrmService::CHANNELS[$ch] }}" class="{{ $g->{$ch.'_opt_in'} ? 'text-green' : 'muted' }}" style="opacity:{{ $g->{$ch.'_opt_in'} ? 1 : .3 }}"><x-icon :name="$ic"/></span>@endforeach</td>
  <td class="small">@foreach($g->tagList() as $t)<span class="badge badge-slate">{{ $t }}</span> @endforeach</td>
</tr>
@empty<tr><td colspan="9"><x-empty text="No guests match these rules right now." icon="users"/></td></tr>@endforelse
</tbody></table></div></div>
{{ $guests->links() }}
@endsection
