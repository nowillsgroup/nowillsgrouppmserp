@extends('layouts.app')
@section('title', 'Guest Lookup')
@section('crumbs') Central Reservations / Guest Lookup @endsection
@section('content')
<div class="card" id="lookupCard"><div class="card-body">
  <form method="get" class="flex gap-1 wrap" style="align-items:center" id="lookupForm"><input name="q" value="{{ $term }}" placeholder="Name, phone, e-mail or reservation no." style="min-width:22rem" autofocus aria-label="Search"><button class="btn btn-primary"><x-icon name="search"/> Look up</button>
    <span class="tiny muted">Searching {{ $hotels->count() > 1 ? 'all '.$hotels->count().' hotels of the group: '.$hotels->pluck('name')->implode(', ') : $hotels->first()?->name }}. Erased (privacy) profiles are never shown.</span></form>
</div></div>
@if(mb_strlen($term) >= 3)
<div class="card flush"><div class="card-body table-wrap"><table class="table table-compact" id="lookupTable"><thead><tr><th>Guest</th><th>Hotel</th><th>Contact</th><th class="num">Stays</th><th class="num">Nights</th><th class="num">Revenue</th><th>Last stay</th><th>Upcoming</th><th></th></tr></thead><tbody>
  @forelse($rows as $x)@php $g = $x['guest']; @endphp<tr data-guest="{{ $g->id }}" data-hotel="{{ $x['hotel']->code }}">
    <td><b>{{ $g->full_name }}</b>@if($g->is_vip) <span class="badge badge-purple">VIP</span>@endif @if($g->is_blacklisted)<span class="badge badge-red">Blacklisted</span>@endif<div class="tiny muted">{{ $g->nationality }}{{ $g->preferences ? ' · '.\Illuminate\Support\Str::limit($g->preferences, 60) : '' }}</div></td>
    <td class="small">{{ $x['hotel']->name }}</td>
    <td class="small">{{ $g->phone }}<div class="tiny muted">{{ $g->email }}</div></td>
    <td class="num">{{ $g->stays_count }}</td><td class="num">{{ $g->nights_count }}</td><td class="num">{{ number_format($g->total_revenue, 2) }}</td>
    <td class="small nowrap">{{ $g->last_stay_on?->format('d M Y') ?? '—' }}</td>
    <td class="small">@forelse($x['upcoming'] as $u)<div class="nowrap">{{ $u->reservation_no }} · {{ $u->arrival_date->format('d M') }}{{ $u->status === 'checked_in' ? ' (in house)' : '' }}</div>@empty<span class="muted">—</span>@endforelse</td>
    <td class="nowrap"><a class="btn btn-sm" href="{{ route('cro.index', ['guest' => $g->id, 'ghotel' => $x['hotel']->id]) }}">New booking</a></td></tr>
  @empty<tr><td colspan="9" class="muted">No guest matches “{{ $term }}”.</td></tr>@endforelse
</tbody></table></div></div>
@elseif($term !== '')<div class="alert alert-info">Type at least 3 characters.</div>
@endif
@endsection
