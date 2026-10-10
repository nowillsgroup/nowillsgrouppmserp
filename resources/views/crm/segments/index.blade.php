@extends('layouts.app')
@section('title', 'Guest Segments')
@section('crumbs') <a href="{{ route('crm.index') }}">Marketing</a> / Segments @endsection
@section('actions')
@if(can_do('crm.manage'))<a class="btn btn-primary" href="{{ route('crm.segments.create') }}" id="newSegment"><x-icon name="plus"/> New segment</a>@endif
@endsection
@section('content')
<div class="card flush"><div class="card-body table-wrap"><table class="table" id="segTable">
<thead><tr><th>Segment</th><th>Rules</th><th class="num">Guests</th><th class="num">Reachable</th><th class="num">Used by</th><th>Status</th><th></th></tr></thead><tbody>
@forelse($segments as $s)
<tr class="{{ $s->is_active ? '' : 'muted-row' }}">
  <td><a class="bold" href="{{ route('crm.segments.show', $s) }}">{{ $s->name }}</a>@if($s->description)<div class="tiny muted">{{ $s->description }}</div>@endif</td>
  <td class="small" style="max-width:420px">{{ $s->describe() }}</td>
  <td class="num">{{ $s->size }}</td>
  <td class="num" title="Not blacklisted and opted in to at least one channel">{{ $s->reachable }}</td>
  <td class="num small">{{ $s->campaigns_count }} campaign(s)@if($s->journeys_count)<div class="tiny muted">{{ $s->journeys_count }} automation(s)</div>@endif</td>
  <td>{!! status_badge($s->is_active ? 'active' : 'inactive') !!}</td>
  <td class="nowrap right">@if(can_do('crm.manage') && $s->is_active)<a class="btn btn-sm" href="{{ route('crm.campaigns.create', ['segment' => $s->id]) }}"><x-icon name="mail"/> Campaign</a>@endif</td>
</tr>
@empty<tr><td colspan="7"><x-empty text="No segments yet – build one from guest history, value, tags, country or birthdays." icon="users"/></td></tr>@endforelse
</tbody></table></div></div>
<p class="tiny muted mt-1">Segments are dynamic: membership is recalculated every time you open, export or send to them. Stay counts, nights, revenue and last stay are refreshed at the night audit.</p>
@endsection
