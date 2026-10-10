@extends('layouts.app')
@section('title', 'Privacy & Data Protection')
@section('crumbs') Guests &amp; CRM / Privacy @endsection
@section('actions')
@if(can_do('reports.view'))<a class="btn" href="{{ route('reports.show', 'privacy_requests') }}"><x-icon name="chart"/> Request register</a>@endif
@if(can_do('privacy.handle'))<a class="btn btn-primary" href="{{ route('privacy.requests.create') }}" id="newRequest"><x-icon name="plus"/> Log request</a>@endif
@endsection
@section('content')
@include('privacy._nav')
<div class="stats req5">
  <x-stat label="Open requests" :value="$counts['open']" icon="shield" tone="blue" :sub="$alerts['due_soon'].' due within 7 days'"/>
  <x-stat label="Overdue" :value="$alerts['overdue']" icon="alert" :tone="$alerts['overdue'] ? 'red' : 'slate'" :sub="'reply within '.$settings['privacy_response_days'].' days of receipt'" :href="route('privacy.index', ['tab' => 'overdue'])"/>
  <x-stat label="Answered on time" :value="$onTime === null ? '—' : $onTime.'%'" icon="clock" tone="green" :sub="$avgDays === null ? 'no completed requests yet' : 'average '.$avgDays.' days · last 12 months'"/>
  <x-stat label="Erased / restricted profiles" :value="($erased['erased'] ?? 0).' / '.($erased['restricted'] ?? 0)" icon="users" tone="slate" sub="anonymised · under legal hold"/>
  <x-stat label="Breaches to notify" :value="$alerts['breach']" icon="alert" :tone="$alerts['breach'] ? 'red' : 'slate'" :sub="$alerts['breach_next'] ? 'next deadline '.$alerts['breach_next']->deadline()->format('d M H:i') : 'none pending'" :href="route('privacy.breaches.index')"/>
</div>

@if($alerts['breach_next'])
@php $bn = $alerts['breach_next']; $hl = $bn->hoursLeft(); @endphp
<div class="alert alert-error" id="breachAlert"><x-icon name="alert"/><div><b>Breach {{ $bn->ref }} – {{ $bn->title }}</b>: the Data Protection Commission must be notified by <b>{{ $bn->deadline()->format('d M Y H:i') }}</b>
  ({{ $hl >= 0 ? $hl.' hours left' : abs($hl).' hours overdue' }}). <a href="{{ route('privacy.breaches.show', $bn) }}">Open the breach record →</a></div></div>
@endif

<div class="card flush">
  <div class="card-head"><div class="tabs" style="margin:0;border:0">
    @foreach(\App\Http\Controllers\PrivacyController::TABS as $k => $l)<a href="{{ route('privacy.index', array_filter(['tab' => $k, 'type' => request('type'), 'q' => request('q')])) }}" class="{{ $tab === $k ? 'active' : '' }}">{{ $l }} <span class="muted">{{ $counts[$k] }}</span></a>@endforeach
  </div>
  <form class="filters" style="margin:0"><input type="hidden" name="tab" value="{{ $tab }}">
    <select name="type" onchange="this.form.submit()"><option value="">All types</option>@foreach(\App\Models\PrivacyRequest::SHORT as $k => $l)<option value="{{ $k }}" @selected(request('type') === $k)>{{ $l }}</option>@endforeach</select>
    <input name="q" value="{{ request('q') }}" placeholder="Ref, name or e-mail"></form></div>
  <div class="card-body table-wrap"><table class="table" id="requestTable">
    <thead><tr><th>Request</th><th>Type</th><th>Requester</th><th>Guest profile</th><th>Received</th><th>Due</th><th>Identity</th><th>Status</th><th>Assigned</th></tr></thead><tbody>
    @forelse($requests as $r)
    <tr class="{{ $r->isOverdue() ? 'row-alert' : '' }}"><td><a href="{{ route('privacy.requests.show', $r) }}" class="bold">{{ $r->request_no }}</a><div class="tiny muted">{{ \App\Models\PrivacyRequest::CHANNELS[$r->channel] ?? $r->channel }}</div></td>
      <td class="small">{{ \App\Models\PrivacyRequest::SHORT[$r->type] }}</td>
      <td class="small">{{ $r->requester_name }}<div class="tiny muted">{{ $r->requester_email ?? $r->requester_phone }}</div></td>
      <td class="small">@if($r->guest)<a href="{{ route('guests.show', $r->guest) }}">{{ $r->guest->full_name }}</a> <span class="tiny muted">G-{{ $r->guest_id }}</span>@else<span class="muted">not linked</span>@endif</td>
      <td class="small nowrap">{{ $r->received_at->format('d M Y') }}</td>
      <td class="small nowrap">{{ $r->due_on->format('d M Y') }}@if($r->extended) <span class="badge badge-slate" title="{{ $r->extension_reason }}">ext.</span>@endif</td>
      <td class="small">{!! $r->verified_at ? '<span class="badge badge-green">verified</span>' : ($r->needsIdentity() ? '<span class="badge badge-amber">to verify</span>' : '<span class="muted">not needed</span>') !!}</td>
      <td class="nowrap">{!! status_badge($r->status) !!} {!! $r->slaBadge() !!}</td>
      <td class="small">{{ $r->assignee?->name ?? '—' }}</td></tr>
    @empty<tr><td colspan="9"><x-empty text="No privacy requests here." icon="shield"/></td></tr>@endforelse
    </tbody></table></div>
  @if($requests->hasPages())<div class="card-foot">{{ $requests->links() }}</div>@endif
</div>

<div class="grid g-2 mt-1">
  <div class="card"><div class="card-head"><h3><x-icon name="clock"/> Retention</h3><a class="small" href="{{ route('privacy.retention') }}">Policies →</a></div><div class="card-body">
    <ul class="bq-list">
      <li><span>ID scans &amp; signatures</span><b>{{ $settings['retention_id_days'] ? $settings['retention_id_days'].' days after check-out' : 'kept (rule off)' }}</b></li>
      <li><span>SMS / WhatsApp / e-mail contents</span><b>{{ $settings['retention_message_days'] ? $settings['retention_message_days'].' days' : 'kept (rule off)' }}</b></li>
      <li><span>Inactive guest profiles</span><b>{{ $settings['retention_inactive_years'] ? 'anonymised after '.$settings['retention_inactive_years'].' years' : 'kept (rule off)' }}</b></li>
      <li><span>Last run</span><b>{{ $lastRun ? $lastRun->created_at->diffForHumans().' · '.$lastRun->total().' item(s)' : 'never' }}</b></li>
      <li><span>Automatic run</span><b>{!! $settings['retention_enabled'] ? '<span class="badge badge-green">daily 03:30</span>' : '<span class="badge badge-red">switched off</span>' !!}</b></li>
    </ul></div></div>
  <div class="card"><div class="card-head"><h3><x-icon name="eye"/> Access to identity data</h3><a class="small" href="{{ route('privacy.access') }}">Log →</a></div><div class="card-body">
    <p class="small">ID and passport numbers and dates of birth are masked on screen. Staff with the <i>reveal</i> permission can unmask them; every reveal, ID-document view, e-signature view and data export is recorded.</p>
    <div class="big bold">{{ $reveals }}</div><div class="tiny muted">accesses in the last 30 days</div></div></div>
</div>
@endsection
