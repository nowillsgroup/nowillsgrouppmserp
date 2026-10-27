@extends('organiser.layout')
@section('title', 'Welcome, '.\Illuminate\Support\Str::before($organiser->name, ' '))
@section('content')
<div class="ptn-hero" id="orgHero" style="background:linear-gradient(120deg,#10271c,#166534)">
  <div><h2>{{ $company?->name ?? 'Your events' }}</h2><p>{{ $welcome }}</p>
    @if($coordinator)<p class="small mt-1" id="orgCoordinator"><x-icon name="user" size="sm"/> Your event coordinator: <b>{{ $coordinator->name }}</b>{{ $coordinator->phone ? ' · '.$coordinator->phone : '' }}{{ $coordinator->email ? ' · '.$coordinator->email : '' }}</p>@endif
  </div>
  @if($organiser->can('events'))<a class="btn btn-lg" href="{{ route('organiser.requests') }}#quote"><x-icon name="plus"/> Request a quote</a>@endif
</div>

<div class="stats" id="orgStats">
  <x-stat label="Upcoming events" :value="$upcoming->count()" icon="calendar" tone="blue" :href="route('organiser.events')"/>
  <x-stat label="BEOs to sign" :value="$beoAwaiting->count()" icon="edit" :tone="$beoAwaiting->isNotEmpty() ? 'amber' : 'green'" :sub="$beoAwaiting->isNotEmpty() ? 'oldest due '.$beoAwaiting->sortBy('event_date')->first()->event_date->format('d M') : 'all signed'"/>
  @if($organiser->can('finance'))<x-stat label="Balance on open events" :value="money($balance)" icon="receipt" :tone="$balance > 0 ? 'amber' : 'green'" :href="route('organiser.events')"/>@endif
  @if($pending)<x-stat label="Open requests" :value="$pending" icon="message" tone="amber" :href="route('organiser.requests')"/>@endif
</div>

@if($beoAwaiting->isNotEmpty() && $organiser->can('events'))
<div class="card" id="orgBeoDue"><div class="card-head"><h3><x-icon name="edit"/> Waiting for your signature</h3></div><div class="card-body table-wrap">
  <table class="table table-compact"><thead><tr><th>Event</th><th>Date</th><th>Published</th><th class="right">Value</th><th></th></tr></thead><tbody>
  @foreach($beoAwaiting as $e)
    <tr><td><a href="{{ route('organiser.event', $e->id) }}"><b>{{ $e->event_no }}</b></a><div class="tiny muted">{{ $e->title }}</div></td>
      <td class="nowrap">{{ $e->event_date?->format('d M Y') }}</td><td class="nowrap small">{{ $e->beo_published_at?->format('d M H:i') }}</td>
      <td class="right">{{ money($e->total()) }}</td><td><a class="btn btn-sm btn-primary" href="{{ route('organiser.beo', $e->id) }}">Check &amp; sign</a></td></tr>
  @endforeach
  </tbody></table>
</div></div>
@endif

<div class="g-2">
  <div class="card" id="orgUpcoming"><div class="card-head"><h3><x-icon name="calendar"/> Upcoming events</h3><a class="small" href="{{ route('organiser.events') }}">All events</a></div><div class="card-body table-wrap">
    @if($upcoming->isEmpty())<p class="muted m-0">No upcoming events on the portal.</p>@else
    <table class="table table-compact"><thead><tr><th>Event</th><th>Date</th><th>Venue</th><th>Status</th><th>BEO</th></tr></thead><tbody>
    @foreach($upcoming as $e)
      <tr><td><a href="{{ route('organiser.event', $e->id) }}"><b>{{ $e->event_no }}</b></a><div class="tiny muted">{{ $e->title }}</div></td>
        <td class="nowrap">{{ $e->event_date?->format('d M') }}{{ $e->end_date && $e->end_date->ne($e->event_date) ? ' – '.$e->end_date->format('d M') : '' }}</td>
        <td class="small">{{ $e->functions->first()?->hall?->name ?? '—' }}</td><td>{!! $e->statusBadge() !!}</td>
        <td>@if($e->beo_approved_at)<span class="badge badge-green">Signed</span>@elseif($e->beo_published_at)<a href="{{ route('organiser.beo', $e->id) }}"><span class="badge badge-amber">Sign</span></a>@else<span class="tiny muted">—</span>@endif</td></tr>
    @endforeach
    </tbody></table>@endif
  </div></div>
  <div class="card" id="orgRequests"><div class="card-head"><h3><x-icon name="message"/> Latest requests</h3><a class="small" href="{{ route('organiser.requests') }}">All requests</a></div><div class="card-body table-wrap">
    @if($requests->isEmpty())<p class="muted m-0">No requests yet.</p>@else
    <table class="table table-compact"><thead><tr><th>No.</th><th>Type</th><th>Event</th><th>Status</th></tr></thead><tbody>
    @foreach($requests as $q)
      <tr><td><b>{{ $q->number }}</b></td><td class="small">{{ \App\Models\OrganiserRequest::TYPES[$q->type] }}</td>
        <td class="small">@if($q->event)<a href="{{ route('organiser.event', $q->event->id) }}">{{ $q->event->event_no }}</a>@else<span class="tiny muted">{{ $q->payload['title'] ?? '—' }}</span>@endif</td>
        <td>{!! $q->statusBadge() !!}</td></tr>
    @endforeach
    </tbody></table>@endif
  </div></div>
</div>
@endsection
