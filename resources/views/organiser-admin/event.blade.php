@extends('layouts.app')
@section('title', $e->event_no.' · '.$e->title)
@section('crumbs') Organiser Portal / <a href="{{ route('organiser-admin.events') }}">Events</a> / {{ $e->event_no }} @endsection
@section('actions')<a class="btn" href="{{ route('banquet.show', $e->id) }}"><x-icon name="calendar"/> Open in Banquet</a>@endsection
@section('content')
@if($errors->has('portal'))<div class="alert alert-error"><x-icon name="alert"/><div>{{ $errors->first('portal') }}</div></div>@endif
@if($errors->has('beo'))<div class="alert alert-error"><x-icon name="alert"/><div>{{ $errors->first('beo') }}</div></div>@endif
<div class="stats" id="ogaEventStats">
  <x-stat label="Portal" :value="$e->portal_enabled ? 'On' : 'Off'" icon="globe" :tone="$e->portal_enabled ? 'green' : 'slate'"/>
  <x-stat label="Event value" :value="money($money['total'])" icon="trend" tone="blue" :sub="'paid '.money($money['deposits']).' · balance '.money($money['balance'])"/>
  <x-stat label="BEO" :value="$e->beo_approved_at ? 'Signed' : ($e->beo_published_at ? 'Awaiting signature' : 'Not published')" icon="edit" :tone="$e->beo_approved_at ? 'green' : ($e->beo_published_at ? 'amber' : 'slate')" :sub="$e->beo_approved_at ? 'by '.$e->beo_sign_name : null"/>
  <x-stat label="Guest list" :value="$summary['total']" icon="users" tone="teal" :sub="$summary['dietary'] ? array_sum($summary['dietary']).' special meals' : null"/>
</div>
<div class="g-2">
  <div>
    <div class="card" id="ogaPortal"><div class="card-head"><h3><x-icon name="globe"/> Portal &amp; BEO</h3></div><div class="card-body">
      @if(can_do('organiser.manage'))
      <form method="post" action="{{ route('organiser-admin.portal', $e->id) }}" class="flex gap-1 items-center mb-2">@csrf
        <input type="hidden" name="portal_enabled" value="{{ $e->portal_enabled ? 0 : 1 }}">
        <button class="btn" type="submit">{{ $e->portal_enabled ? 'Switch the portal off' : 'Switch the portal on' }}</button>
        <span class="tiny muted">portal address: {{ $loginUrl }}</span>
      </form>
      <form method="post" action="{{ route('organiser-admin.beo.publish', $e->id) }}" onsubmit="return confirm('{{ $e->beo_published_at ? 'Re-publish the BEO? Any signature falls away and the organiser signs again.' : 'Publish the BEO and ask the organiser to sign it?' }}')">@csrf
        <button class="btn btn-primary" type="submit"><x-icon name="edit"/> {{ $e->beo_published_at ? 'Re-publish the BEO' : 'Publish the BEO' }}</button>
      </form>
      @endif
      @if($e->beo_published_at)
        <p class="small mt-2 m-0">Published {{ $e->beo_published_at->format('d M Y H:i') }}{{ $e->beoPublisher ? ' by '.$e->beoPublisher->name : '' }}.
        @if($e->beo_approved_at)Signed by <b>{{ $e->beo_sign_name }}</b> on {{ $e->beo_approved_at->format('d M Y H:i') }}.@else<span class="badge badge-amber">Awaiting signature</span>@endif</p>
        @if($changed)<p class="small mt-1"><span class="badge badge-amber">Changed since publishing</span> – re-publish so the organiser signs the current version.</p>@endif
      @else<p class="muted small m-0">No BEO published yet – the organiser sees the event but cannot sign anything.</p>@endif
    </div></div>
    <div class="card" id="ogaLogins"><div class="card-head"><h3><x-icon name="users"/> Organiser logins on this event</h3><a class="small" href="{{ route('organiser-admin.organisers') }}">All logins</a></div><div class="card-body table-wrap">
      @if($logins->isEmpty())<p class="muted m-0">Nobody follows this event yet – invite the organiser below.</p>@else
      <table class="table table-compact"><thead><tr><th>Name</th><th>E-mail</th><th>Role</th><th>Status</th></tr></thead><tbody>
      @foreach($logins as $x)<tr><td>{{ $x->name }}</td><td class="small">{{ $x->email }}</td><td class="small">{{ \App\Models\OrganiserUser::ROLES[$x->role] }}</td><td><span class="badge {{ $x->statusBadge() }}">{{ $x->statusLabel() }}</span></td></tr>@endforeach
      </tbody></table>@endif
    </div></div>
    <div class="card" id="ogaRequests"><div class="card-head"><h3><x-icon name="message"/> Requests on this event</h3><a class="small" href="{{ route('organiser-admin.requests') }}">Queue</a></div><div class="card-body table-wrap">
      @if($requests->isEmpty())<p class="muted m-0">No requests on this event.</p>@else
      <table class="table table-compact"><thead><tr><th>No.</th><th>Type</th><th>Details</th><th>Status</th></tr></thead><tbody>
      @foreach($requests as $q)<tr><td><b>{{ $q->number }}</b></td><td class="small">{{ \App\Models\OrganiserRequest::TYPES[$q->type] }}</td><td class="small">{{ \Illuminate\Support\Str::limit($q->details, 100) }}</td><td>{!! $q->statusBadge() !!}</td></tr>@endforeach
      </tbody></table>@endif
    </div></div>
  </div>
  <div class="card" id="ogaTrail"><div class="card-head"><h3><x-icon name="eye"/> Portal trail</h3></div><div class="card-body table-wrap">
    @if($audit->isEmpty())<p class="muted m-0">No portal activity on this event.</p>@else
    <table class="table table-compact"><tbody>
    @foreach($audit as $a)<tr><td class="small nowrap">{{ $a->created_at->format('d M H:i') }}</td><td class="small">{{ $a->organiserUser?->name ?? 'Hotel' }}</td><td><span class="badge badge-slate">{{ str_replace('_', ' ', $a->action) }}</span></td><td class="small">{{ $a->detail }}</td></tr>@endforeach
    </tbody></table>@endif
  </div></div>
</div>
@endsection
