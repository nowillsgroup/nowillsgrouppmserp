@extends('layouts.app')
@section('title', 'Guest Profiles')
@section('actions')@if(can_do('guests.manage'))<a href="{{ route('guests.create') }}" class="btn btn-primary"><x-icon name="plus"/> New Guest</a>@endif @endsection
@section('content')
<div class="tabs">
  @foreach([''=>'All','vip'=>'VIP','foreign'=>'Foreign nationals','birthday'=>'Birthdays this month','blacklisted'=>'Blacklisted'] as $k=>$v)
    <a href="{{ route('guests.index', array_filter(['filter'=>$k, 'q'=>request('q')])) }}" class="{{ request('filter','') === $k ? 'active' : '' }}">{{ $v }}</a>
  @endforeach
</div>
<div class="card"><div class="card-body"><form class="filters"><input type="hidden" name="filter" value="{{ request('filter') }}"><div class="field" style="flex:1"><input name="q" value="{{ request('q') }}" placeholder="Search name, phone, email, ID number…"></div><button class="btn btn-primary">Search</button></form></div></div>
<div class="card flush"><div class="card-body table-wrap"><table class="table">
<thead><tr><th>Name</th><th>Mobile</th><th>Email</th><th>Nationality</th><th>Company</th><th class="center">Stays</th><th></th></tr></thead><tbody>
@forelse($guests as $g)
<tr><td><a href="{{ route('guests.show', $g) }}" class="bold">{{ $g->full_name }}</a> @if($g->is_vip)<span class="badge badge-purple">VIP</span>@endif @if($g->is_blacklisted)<span class="badge badge-red">Blacklisted</span>@endif @if($g->privacy_status)<span class="badge {{ $g->isErased() ? 'badge-slate' : 'badge-amber' }}" title="{{ $g->privacy_note }}">{{ $g->isErased() ? 'Erased' : 'Restricted' }}</span>@endif</td>
<td>{{ $g->phone }}</td><td>{{ $g->email }}</td><td>{{ $g->nationality }}</td><td>{{ $g->company?->name }}</td><td class="center">{{ $g->stays_count }}</td>
<td class="nowrap right"><a class="btn btn-sm" href="{{ route('guests.show', $g) }}"><x-icon name="eye" size="sm"/></a> @if(can_do('reservations.create'))<a class="btn btn-sm btn-soft" href="{{ route('reservations.create', ['guest_id' => $g->id]) }}">Book</a>@endif</td></tr>
@empty<tr><td colspan="7"><x-empty text="No guests found." icon="users"/></td></tr>@endforelse
</tbody></table>{{ $guests->links() }}</div></div>
@endsection
