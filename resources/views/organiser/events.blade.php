@extends('organiser.layout')
@section('title', 'My events')
@section('content')
<div class="tabs" id="orgTabs">
  @foreach(['upcoming' => 'Upcoming', 'past' => 'Past', 'cancelled' => 'Cancelled', 'all' => 'All'] as $k => $label)
    <a href="{{ route('organiser.events', ['tab' => $k]) }}" class="{{ $tab === $k ? 'active' : '' }}">{{ $label }}</a>
  @endforeach
</div>
<div class="card"><div class="card-body table-wrap">
  @if($rows->isEmpty())<x-empty text="No events on this tab." icon="calendar"/>@else
  <table class="table" id="orgEvents"><thead><tr><th>Event</th><th>Type</th><th>Dates</th><th class="right">Guests</th><th class="right">Value</th><th>Status</th><th>BEO</th></tr></thead><tbody>
  @foreach($rows as $e)
    <tr><td><a href="{{ route('organiser.event', $e->id) }}"><b>{{ $e->event_no }}</b></a><div class="tiny muted">{{ $e->title }}</div></td>
      <td class="small">{{ \App\Models\BanquetEvent::TYPES[$e->event_type] ?? $e->event_type }}</td>
      <td class="nowrap small">{{ $e->event_date?->format('d M Y') }}{{ $e->end_date && $e->end_date->ne($e->event_date) ? ' – '.$e->end_date->format('d M Y') : '' }}</td>
      <td class="right">{{ $e->pax() }}</td><td class="right">{{ money($e->total()) }}</td><td>{!! $e->statusBadge() !!}</td>
      <td>@if($e->beo_approved_at)<span class="badge badge-green">Signed</span>@elseif($e->beo_published_at)<a href="{{ route('organiser.beo', $e->id) }}"><span class="badge badge-amber">Sign</span></a>@else<span class="tiny muted">—</span>@endif</td></tr>
  @endforeach
  </tbody></table>@endif
</div></div>
@endsection
