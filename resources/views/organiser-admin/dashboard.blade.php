@extends('layouts.app')
@section('title', 'Organiser Dashboard')
@section('crumbs') Organiser Portal / Dashboard @endsection
@section('actions')
  <a class="btn" href="{{ route('organiser.login', ['h' => $currentHotel->code]) }}" target="_blank" rel="noopener" id="ogaOpenPortal"><x-icon name="globe"/> Open organiser portal</a>
  <a class="btn btn-primary" href="{{ route('organiser-admin.requests') }}"><x-icon name="check"/> Requests &amp; approvals</a>
@endsection
@section('content')
@php $k = $kpi; @endphp
@if(setting('organiser_enabled', '1') !== '1')<div class="alert alert-warn"><x-icon name="alert"/><div>The organiser portal is switched off – organisers cannot sign in. @if(can_do('organiser.manage'))<a href="{{ route('organiser-admin.settings') }}">Portal settings</a>@endif</div></div>@endif
<div class="stats" id="ogaStats">
  <x-stat label="Events on the portal" :value="$k['events']" icon="calendar" tone="blue" :sub="$k['users'].' active login(s) · '.$k['invited'].' invited'" :href="route('organiser-admin.events')"/>
  <x-stat label="BEOs awaiting signature" :value="$k['beoAwaiting']" icon="edit" :tone="$k['beoAwaiting'] ? 'amber' : 'slate'" :sub="$k['beoSigned'].' signed in 30 days'" :href="route('organiser-admin.events')"/>
  <x-stat label="Paid online · 30 days" :value="money($k['online'])" icon="wallet" tone="green" sub="event deposits onto event folios"/>
  <x-stat label="Open requests" :value="$k['requests']" icon="message" :tone="$k['requests'] ? 'amber' : 'slate'" :href="route('organiser-admin.requests')"/>
  <x-stat label="Guests on guest lists" :value="$k['guests']" icon="users" tone="teal"/>
  <x-stat label="Organiser sign-ins · 30 days" :value="$k['logins']" icon="login" tone="purple" :sub="$k['failed'].' failed / locked in 7 days'" :href="route('organiser-admin.activity')"/>
</div>
<div class="g-2-1">
  <div>
    <div class="card" id="ogaUpcoming"><div class="card-head"><h3><x-icon name="calendar"/> Upcoming portal events</h3><a class="small" href="{{ route('organiser-admin.events') }}">All events</a></div><div class="card-body table-wrap">
      @if($upcoming->isEmpty())<p class="muted m-0">No upcoming events on the portal.</p>@else
      <table class="table table-compact"><thead><tr><th>Event</th><th>Date</th><th>Client</th><th class="right">Value</th><th>BEO</th></tr></thead><tbody>
      @foreach($upcoming as $e)<tr><td><a href="{{ route('organiser-admin.event', $e->id) }}"><b>{{ $e->event_no }}</b></a><div class="tiny muted">{{ $e->title }}</div></td>
        <td class="nowrap">{{ $e->event_date?->format('d M') }}</td><td class="small">{{ $e->company?->name ?? $e->contact_name }}</td>
        <td class="right">{{ money($e->total()) }}</td>
        <td>@if($e->beo_approved_at)<span class="badge badge-green">Signed</span>@elseif($e->beo_published_at)<span class="badge badge-amber">Awaiting</span>@else<span class="tiny muted">—</span>@endif</td></tr>@endforeach
      </tbody></table>@endif
    </div></div>
    <div class="card" id="ogaPending"><div class="card-head"><h3><x-icon name="clock"/> Oldest open requests</h3><a class="small" href="{{ route('organiser-admin.requests') }}">Queue</a></div><div class="card-body table-wrap">
      @if($pending->isEmpty())<p class="muted m-0">Nothing waiting – every organiser request is answered.</p>@else
      <table class="table table-compact"><thead><tr><th>No.</th><th>Type</th><th>Event</th><th>What</th><th>Waiting</th></tr></thead><tbody>
      @foreach($pending as $q)<tr><td><b>{{ $q->number }}</b></td><td>{{ \App\Models\OrganiserRequest::TYPES[$q->type] }}</td>
        <td>@if($q->event)<a href="{{ route('organiser-admin.event', $q->event->id) }}">{{ $q->event->event_no }}</a>@else<span class="tiny muted">{{ $q->payload['title'] ?? '—' }}</span>@endif<div class="tiny muted">{{ $q->organiserUser?->name }}</div></td>
        <td class="small">{{ \Illuminate\Support\Str::limit($q->details, 90) }}</td><td class="nowrap {{ $q->responseHours() > 24 ? 'row-amber' : '' }}">{{ $q->responseHours() }} h</td></tr>@endforeach
      </tbody></table>@endif
    </div></div>
  </div>
  <div class="card" id="ogaRecent"><div class="card-head"><h3><x-icon name="eye"/> Latest portal activity</h3><a class="small" href="{{ route('organiser-admin.activity') }}">Full log</a></div><div class="card-body table-wrap">
    <table class="table table-compact"><tbody>
    @forelse($recent as $a)<tr class="{{ in_array($a->action, ['locked', 'login_blocked']) ? 'row-red' : '' }}"><td class="small nowrap">{{ $a->created_at->format('d M H:i') }}</td><td>{{ $a->event?->event_no ?? $a->company?->name ?? '—' }}<div class="tiny muted">{{ $a->organiserUser?->name }}</div></td><td><span class="badge badge-slate">{{ str_replace('_', ' ', $a->action) }}</span></td><td class="small">{{ $a->detail }}</td></tr>@empty<tr><td class="muted">No activity yet.</td></tr>@endforelse
    </tbody></table>
  </div></div>
</div>
@endsection
