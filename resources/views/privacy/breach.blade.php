@extends('layouts.app')
@section('title', $b->ref.' – '.$b->title)
@section('crumbs') <a href="{{ route('privacy.breaches.index') }}">Breaches</a> / {{ $b->ref }} @endsection
@section('actions')@if(can_do('privacy.breach') && $b->status !== 'closed')<a class="btn" href="{{ route('privacy.breaches.edit', $b) }}"><x-icon name="edit"/> Edit</a>@endif @endsection
@section('content')
@include('privacy._nav')
@php $m = can_do('privacy.breach') && $b->status !== 'closed'; $now = now()->format('Y-m-d\TH:i'); @endphp
@if($b->notificationPending())@php $hl = $b->hoursLeft(); @endphp
<div class="alert {{ $hl < 0 ? 'alert-error' : 'alert-warn' }}" id="deadlineNote"><x-icon name="clock"/><div>Notify the Data Protection Commission by <b>{{ $b->deadline()->format('d M Y H:i') }}</b> – {{ $hl < 0 ? abs($hl).' hours overdue' : $hl.' hours left' }}.</div></div>@endif
<div class="grid g-2">
  <div class="card"><div class="card-head"><h3>{{ $b->title }}</h3><span>{!! status_badge($b->status) !!}</span></div><div class="card-body">
    <dl class="kv">
      <dt>Detected</dt><dd>{{ $b->detected_at->format('d M Y H:i') }}{{ $b->reporter ? ' · recorded by '.$b->reporter->name : '' }}</dd>
      <dt>Occurred</dt><dd>{{ $b->occurred_at?->format('d M Y H:i') ?? 'unknown' }}</dd>
      <dt>Severity</dt><dd>{{ ucfirst($b->severity) }}</dd>
      <dt>People affected</dt><dd>{{ number_format($b->affected_count) }}</dd>
      <dt>Data involved</dt><dd>{{ collect($b->categoryList())->map(fn ($c) => \App\Models\DataBreach::CATEGORIES[$c] ?? $c)->implode(', ') ?: '—' }}</dd>
      <dt>Notifiable</dt><dd>{{ $b->notifiable ? 'Yes' : 'No – '.$b->not_notifiable_reason }}</dd>
    </dl>
    <div class="small mt-1" style="white-space:pre-line">{{ $b->description }}</div>
  </div></div>
  <div class="card"><div class="card-head"><h3>Response</h3></div><div class="card-body">
    <ul class="timeline" id="breachSteps">
      <li class="tl-ok small"><b>Detected</b> {{ $b->detected_at->format('d M Y H:i') }}</li>
      <li class="{{ $b->contained_at ? 'tl-ok' : '' }} small"><b>Contained</b> {{ $b->contained_at?->format('d M Y H:i') ?? 'pending' }}@if($b->containment)<div class="tiny">{{ $b->containment }}</div>@endif</li>
      @if($b->notifiable)<li class="{{ $b->authority_notified_at ? ($b->authority_notified_at->gt($b->deadline()) ? 'tl-bad' : 'tl-ok') : '' }} small"><b>Data Protection Commission notified</b> {{ $b->authority_notified_at?->format('d M Y H:i') ?? 'pending (due '.$b->deadline()->format('d M H:i').')' }}{{ $b->authority_ref ? ' · ref '.$b->authority_ref : '' }}</li>
      <li class="{{ $b->subjects_notified_at ? 'tl-ok' : '' }} small"><b>People affected informed</b> {{ $b->subjects_notified_at?->format('d M Y H:i') ?? 'pending' }}@if($b->subjects_notice)<div class="tiny">{{ $b->subjects_notice }}</div>@endif</li>@endif
      <li class="{{ $b->closed_at ? 'tl-ok' : '' }} small"><b>Closed</b> {{ $b->closed_at?->format('d M Y H:i') ?? '—' }}@if($b->root_cause)<div class="tiny">Root cause: {{ $b->root_cause }}</div><div class="tiny">Lessons: {{ $b->lessons }}</div>@endif</li>
    </ul>
    @if($m)
      @if($b->status === 'open')
      <form method="post" action="{{ route('privacy.breaches.contain', $b) }}" class="form-grid mt-1" id="containForm">@csrf
        <x-field name="contained_at" label="Contained at" type="datetime-local" :value="$now" required/>
        <x-field name="containment" label="Containment actions" type="textarea" required class="span-all" placeholder="e.g. Recipient asked to delete and confirmed; mailbox password reset; access revoked"/>
        <div><button class="btn btn-primary" id="containBtn">Record containment</button></div></form>
      @endif
      @if($b->notifiable && ! $b->authority_notified_at)
      <form method="post" action="{{ route('privacy.breaches.notify', $b) }}" class="filters mt-1" id="notifyForm">@csrf
        <div class="field"><label>Board notified at</label><input type="datetime-local" name="authority_notified_at" value="{{ $now }}" required></div>
        <div class="field"><label>Reference</label><input name="authority_ref" placeholder="Acknowledgement no."></div><button class="btn" id="notifyBtn">Record notification</button></form>
      @endif
      @if($b->notifiable && ! $b->subjects_notified_at)
      <form method="post" action="{{ route('privacy.breaches.subjects', $b) }}" class="filters mt-1" id="subjectsForm">@csrf
        <div class="field"><label>People informed at</label><input type="datetime-local" name="subjects_notified_at" value="{{ $now }}" required></div>
        <div class="field" style="flex:1"><label>How</label><input name="subjects_notice" placeholder="e.g. E-mail to the 14 guests on the list" required></div><button class="btn" id="subjectsBtn">Record</button></form>
      @endif
      @if($b->status !== 'open' && (! $b->notifiable || $b->authority_notified_at))
      <form method="post" action="{{ route('privacy.breaches.close', $b) }}" class="form-grid mt-1" id="closeForm">@csrf
        <x-field name="root_cause" label="Root cause" type="textarea" required class="span-all"/>
        <x-field name="lessons" label="Lessons learned / preventive actions" type="textarea" required class="span-all"/>
        <div><button class="btn btn-primary" id="closeBtn">Close breach</button></div></form>
      @elseif($b->status !== 'open')<p class="tiny muted mt-1" id="closeHint">The breach can be closed once the Data Protection Commission has been notified.</p>
      @endif
    @endif
  </div></div>
</div>
@endsection
