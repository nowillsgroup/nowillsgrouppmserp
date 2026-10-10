@extends('layouts.app')
@section('title', 'Portal Events')
@section('crumbs') Organiser Portal / Events @endsection
@section('content')
<div class="flex justify-between items-center mb-2" style="flex-wrap:wrap;gap:10px">
  <div class="tabs" id="ogaTabs">@foreach(['on' => 'On the portal', 'off' => 'Not on the portal', 'all' => 'All events'] as $k => $l)<a class="{{ $portal === $k ? 'active' : '' }}" href="{{ route('organiser-admin.events', ['portal' => $k] + request()->only('q')) }}">{{ $l }}</a> @endforeach</div>
  <form method="get" action="{{ route('organiser-admin.events') }}" class="flex gap-1"><input type="hidden" name="portal" value="{{ $portal }}"><input class="input" name="q" value="{{ request('q') }}" placeholder="Event no. or title" style="width:14em"><button class="btn" type="submit">Search</button></form>
</div>
<div class="card"><div class="card-body table-wrap">
  @if($rows->isEmpty())<x-empty text="No events here." icon="calendar"/>@else
  <table class="table" id="ogaEvents"><thead><tr><th>Event</th><th>Dates</th><th>Client</th><th class="right">Value</th><th>Status</th><th>BEO</th><th class="right">Guests</th><th>Portal</th></tr></thead><tbody>
  @foreach($rows as $e)
    <tr><td><a href="{{ route('organiser-admin.event', $e->id) }}"><b>{{ $e->event_no }}</b></a><div class="tiny muted">{{ $e->title }}</div></td>
      <td class="nowrap small">{{ $e->event_date?->format('d M Y') }}</td>
      <td class="small">{{ $e->company?->name ?? $e->contact_name }}</td><td class="right">{{ money($e->total()) }}</td>
      <td>{!! $e->statusBadge() !!}</td>
      <td>@if($e->beo_approved_at)<span class="badge badge-green">Signed</span>@elseif($e->beo_published_at)<span class="badge badge-amber">Awaiting</span>@else<span class="tiny muted">—</span>@endif</td>
      <td class="right">{{ $e->attendees_count }}</td>
      <td>@if($e->portal_enabled)<span class="badge badge-green">On</span>@elseif(can_do('organiser.manage'))
        <form method="post" action="{{ route('organiser-admin.portal', $e->id) }}">@csrf<input type="hidden" name="portal_enabled" value="1"><button class="btn btn-sm" type="submit">Switch on</button></form>
        @else<span class="tiny muted">Off</span>@endif</td></tr>
  @endforeach
  </tbody></table>
  {{ $rows->links('partials.pagination') }}
  @endif
</div></div>
@endsection
