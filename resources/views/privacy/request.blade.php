@extends('layouts.app')
@section('title', $req->request_no.' – '.\App\Models\PrivacyRequest::SHORT[$req->type])
@section('crumbs') <a href="{{ route('privacy.index') }}">Privacy</a> / {{ $req->request_no }} @endsection
@section('actions')
@if(can_do('privacy.handle') && $req->isOpen() && $req->type === 'access' && $req->guest && $req->verified_at)
  <a class="btn" href="{{ route('privacy.requests.report', $req) }}" id="reportBtn" target="_blank"><x-icon name="print"/> Printable report</a>
  <a class="btn btn-primary" href="{{ route('privacy.requests.export', $req) }}" id="exportBtn"><x-icon name="download"/> Download data (JSON)</a>
@endif
@endsection
@section('content')
@include('privacy._nav')
@php $open = $req->isOpen(); $h = can_do('privacy.handle'); @endphp
@if($req->status === 'unverified')<div class="alert alert-warn" id="unverifiedNote"><x-icon name="mail"/><div>Waiting for the requester to click the confirmation link sent to <b>{{ $req->requester_email }}</b> (valid until {{ $req->verify_expires_at?->format('d M H:i') }}). Unconfirmed web requests close automatically after {{ \App\Services\PrivacyService::settings()['retention_unverified_days'] }} days.</div></div>@endif
@if($req->isOverdue())<div class="alert alert-error" id="overdueNote"><x-icon name="alert"/><div>This request is <b>{{ abs($req->daysLeft()) }} day(s) past the statutory deadline</b> ({{ $req->due_on->format('d M Y') }}). Reply now, or record why it is delayed.</div></div>@endif
@if($req->status === 'completed')<div class="alert alert-success" id="outcomeNote"><x-icon name="check"/><div><b>Completed {{ $req->completed_at->format('d M Y') }}</b>{{ $req->completer ? ' by '.$req->completer->name : '' }} – {{ $req->outcome }}</div></div>@endif
@if($req->status === 'rejected')<div class="alert alert-error" id="outcomeNote"><x-icon name="x"/><div><b>Rejected {{ $req->completed_at?->format('d M Y') }}</b> – {{ $req->rejection_reason }}</div></div>@endif

<div class="grid g-1-2">
  <div>
    <div class="card"><div class="card-head"><h3>{{ \App\Models\PrivacyRequest::TYPES[$req->type] }}</h3><span>{!! status_badge($req->status) !!} {!! $req->slaBadge() !!}</span></div><div class="card-body">
      <dl class="kv">
        <dt>Requester</dt><dd>{{ $req->requester_name }} <span class="tiny muted">({{ ['self' => 'the guest', 'nominee' => 'nominee', 'authorised' => 'authorised agent'][$req->relationship] ?? $req->relationship }})</span></dd>
        <dt>Contact</dt><dd>{{ $req->requester_email ?? '—' }}{{ $req->requester_phone ? ' · '.$req->requester_phone : '' }}</dd>
        <dt>Received</dt><dd>{{ $req->received_at->format('d M Y H:i') }} · {{ \App\Models\PrivacyRequest::CHANNELS[$req->channel] ?? $req->channel }}</dd>
        <dt>Reply due</dt><dd>{{ $req->due_on->format('d M Y') }}@if($req->extended) <span class="badge badge-slate">extended</span><div class="tiny muted">{{ $req->extension_reason }}</div>@endif</dd>
        @if($req->booking_ref)<dt>Booking ref.</dt><dd>{{ $req->booking_ref }}</dd>@endif
        <dt>Identity</dt><dd id="identityState">@if($req->verified_at)<span class="badge badge-green">verified</span> {{ \App\Models\PrivacyRequest::METHODS[$req->verification_method] ?? '' }}<div class="tiny muted">{{ $req->verified_at->format('d M Y H:i') }} · {{ $req->verifier?->name }}</div>
          @elseif($req->email_verified_at)<span class="badge badge-blue">e-mail confirmed</span> <span class="tiny muted">identity not yet checked</span>@else<span class="badge badge-amber">not verified</span>@endif</dd>
        <dt>Assigned to</dt><dd>{{ $req->assignee?->name ?? '—' }}</dd>
      </dl>
      @if($req->details)<div class="mt-1 small" style="white-space:pre-line;background:var(--c-bg,#f8fafc);padding:.6rem .75rem;border-radius:8px">{{ $req->details }}</div>@endif
    </div></div>

    <div class="card"><div class="card-head"><h3><x-icon name="user"/> Guest profile</h3></div><div class="card-body" id="guestLink">
      @if($req->guest)
        <p><a href="{{ route('guests.show', $req->guest) }}" class="bold">{{ $req->guest->full_name }}</a> <span class="tiny muted">G-{{ $req->guest_id }}</span> @if($req->guest->privacy_status){!! status_badge($req->guest->privacy_status) !!}@endif
          <br><span class="small muted">{{ $req->guest->email ?? 'no e-mail' }} · {{ $req->guest->phone ?? 'no mobile' }}</span></p>
        @if($h && $open)<form method="post" action="{{ route('privacy.requests.link', $req) }}">@csrf<input type="hidden" name="guest_id" value=""><button class="btn btn-sm btn-ghost">Unlink</button></form>@endif
      @else
        <p class="small muted">Not linked to a profile yet.</p>
        @if($candidates->isNotEmpty())<p class="small bold mb-0">Possible matches</p><ul class="bq-list" id="candidates">
          @foreach($candidates as $c)<li><span><a href="{{ route('guests.show', $c) }}">{{ $c->full_name }}</a> <span class="tiny muted">G-{{ $c->id }} · {{ $c->email ?? $c->phone }}</span></span>
            @if($h && $open)<form method="post" action="{{ route('privacy.requests.link', $req) }}">@csrf<input type="hidden" name="guest_id" value="{{ $c->id }}"><button class="btn btn-sm">Link</button></form>@endif</li>@endforeach</ul>@endif
        @if($h && $open)<form method="post" action="{{ route('privacy.requests.link', $req) }}" class="filters mt-1" id="linkForm">@csrf<div class="field"><label>Guest profile ID</label><input type="number" name="guest_id" required min="1"></div><button class="btn btn-sm">Link</button></form>@endif
      @endif
    </div></div>

    <div class="card"><div class="card-head"><h3><x-icon name="clock"/> Timeline</h3></div><div class="card-body">
      <ul class="timeline" id="timeline">@foreach($req->events as $e)<li class="{{ in_array($e->action, ['completed', 'erased', 'verified', 'email_verified']) ? 'tl-ok' : (in_array($e->action, ['rejected']) ? 'tl-bad' : 'tl-info') }} small">
        <b>{{ ucwords(str_replace('_', ' ', $e->action)) }}</b> – {{ $e->note }}<div class="tiny muted">{{ $e->created_at->format('d M Y H:i') }}{{ $e->user ? ' · '.$e->user->name : '' }}</div></li>@endforeach</ul>
      @if($h)<form method="post" action="{{ route('privacy.requests.note', $req) }}" class="filters">@csrf<div class="field" style="flex:1"><input name="note" placeholder="Add a note (call made, documents received…)" required></div><button class="btn btn-sm" id="addNote">Add note</button></form>@endif
    </div></div>
  </div>

  <div>
  @if($h && $open)
    @if($req->needsIdentity() && ! $req->verified_at)
    <div class="card" id="verifyCard"><div class="card-head"><h3><x-icon name="id"/> 1 · Verify identity</h3></div><div class="card-body">
      <p class="small">Before disclosing, changing or erasing personal data make sure the requester is the guest (or entitled to act for them). Ask for no more information than you already hold.</p>
      <form method="post" action="{{ route('privacy.requests.verify', $req) }}" class="form-grid" id="verifyForm">@csrf
        <x-field name="verification_method" label="How was identity checked?" type="select" :options="\App\Models\PrivacyRequest::METHODS" required/>
        <x-field name="note" label="Note" placeholder="e.g. Passport matched the profile at the desk"/>
        <div><button class="btn btn-primary" id="verifyBtn"><x-icon name="check"/> Mark verified</button></div>
      </form></div></div>
    @endif

    @if($req->type === 'access')
    <div class="card" id="actionCard"><div class="card-head"><h3><x-icon name="download"/> Provide a copy of the data</h3></div><div class="card-body">
      <p class="small">The export covers the profile, stays, bills, loyalty, feedback, requests, spa, messages and e-mails sent, marketing, consent history, payments, documents held and who accessed the guest's identity data. Send it securely (in person or password-protected).</p>
      @if(! $req->guest || ! $req->verified_at)<p class="small muted">Link a profile and verify identity to enable the export.</p>@else<p class="small">Exports generated: <b>{{ $req->exports }}</b></p>@endif
      <form method="post" action="{{ route('privacy.requests.complete', $req) }}" class="mt-1" id="completeForm">@csrf
        <x-field name="outcome" label="Outcome (how and when the data was provided)" type="textarea" placeholder="e.g. Password-protected JSON and printed report e-mailed on …; password sent by SMS"/>
        <button class="btn btn-primary" id="completeBtn" @disabled(! $req->exports)><x-icon name="check"/> Complete request</button></form>
    </div></div>
    @elseif($req->type === 'correction')
    <div class="card" id="actionCard"><div class="card-head"><h3><x-icon name="edit"/> Correct the data</h3></div><div class="card-body">
      @if($req->guest && $req->verified_at)<p class="small">Update the profile, then record what was changed.</p><a class="btn btn-sm mb-1" href="{{ route('guests.edit', $req->guest) }}" target="_blank"><x-icon name="edit" size="sm"/> Edit guest profile</a>@else<p class="small muted">Link a profile and verify identity first.</p>@endif
      <form method="post" action="{{ route('privacy.requests.complete', $req) }}" id="completeForm">@csrf
        <x-field name="outcome" label="What was corrected" type="textarea" placeholder="e.g. Surname spelling corrected from … to …; old e-mail removed"/>
        <button class="btn btn-primary" id="completeBtn"><x-icon name="check"/> Complete request</button></form>
    </div></div>
    @elseif($req->type === 'consent')
    <div class="card" id="actionCard"><div class="card-head"><h3><x-icon name="mail"/> Withdraw consent</h3></div><div class="card-body">
      <p class="small">Switches off e-mail, SMS and WhatsApp marketing for the linked profile (logged as proof in the consent history) and completes the request. Withdrawing consent must be as easy as giving it – no identity check needed.</p>
      @if($req->guest)<p class="small">Currently: @foreach(\App\Services\CrmService::CHANNELS as $ch => $l)<span class="badge {{ $req->guest->{$ch.'_opt_in'} ? 'badge-green' : 'badge-slate' }}">{{ $l }} {{ $req->guest->{$ch.'_opt_in'} ? 'on' : 'off' }}</span> @endforeach</p>@endif
      <form method="post" action="{{ route('privacy.requests.consent', $req) }}">@csrf<button class="btn btn-primary" id="withdrawBtn" @disabled(! $req->guest)><x-icon name="x"/> Withdraw all marketing consent</button></form>
    </div></div>
    @elseif($req->type === 'grievance')
    <div class="card" id="actionCard"><div class="card-head"><h3><x-icon name="message"/> Respond to the grievance</h3></div><div class="card-body">
      <form method="post" action="{{ route('privacy.requests.complete', $req) }}" id="completeForm">@csrf
        <x-field name="outcome" label="Response given" type="textarea" placeholder="Summary of the investigation and the reply sent. Mention the right to escalate to the Data Protection Commission."/>
        <button class="btn btn-primary" id="completeBtn"><x-icon name="check"/> Complete request</button></form>
    </div></div>
    @elseif($req->type === 'erasure')
    <div class="card" id="actionCard"><div class="card-head"><h3><x-icon name="trash"/> Erase personal data</h3></div><div class="card-body">
      @if(! $req->guest)<p class="small muted">Link the guest profile to see what would be erased.</p>
      @elseif($plan)
        @if($plan['blockers'])<div class="alert alert-error small" id="eraseBlockers"><x-icon name="alert"/><div><b>Cannot erase yet:</b><ul class="mb-0">@foreach($plan['blockers'] as $b)<li>{{ $b }}</li>@endforeach</ul></div></div>@endif
        <p class="small bold mb-0">What happens</p>
        <ul class="plan-list" id="erasePlan">@foreach($plan['items'] as $k => [$label, $n])<li class="{{ $n ? '' : 'zero' }}"><span>{{ $label }}</span><b>{{ $n }}</b></li>@endforeach</ul>
        @if($plan['holds'])
        <div class="alert alert-warn small mt-1" id="holdNote"><x-icon name="shield"/><div><b>Legal hold – the profile will be restricted, not fully erased.</b>
          @foreach($plan['holds'] as $hd)<div>{{ $hd['label'] }}: keep until <b>{{ $hd['until']->format('d M Y') }}</b></div>@endforeach
          <div class="mt-1">Kept: {{ collect($plan['retain'])->map(fn ($f) => \App\Services\PrivacyService::FIELD_LABELS[$f])->implode(', ') }}. Nothing else is kept; the profile cannot be booked, edited or marketed to, and is fully erased automatically when the hold ends.</div></div></div>
        @else<p class="small mt-1" id="holdNote">No legal hold applies – the profile will be <b>fully anonymised</b>. Folios, invoices and payments stay (without personal identifiers).</p>@endif
        @if(can_do('privacy.erase'))
          @if(! $req->verified_at)<p class="small muted">Verify identity before erasing.</p>@endif
          <form method="post" action="{{ route('privacy.requests.erase', $req) }}" class="filters mt-1" id="eraseForm">@csrf
            <div class="field"><label>Type ERASE to confirm</label><input name="confirm" autocomplete="off" required pattern="ERASE" id="eraseConfirm"></div>
            <button class="btn btn-danger" id="eraseBtn" @disabled($plan['blockers'] || ! $req->verified_at)><x-icon name="trash"/> Erase now</button></form>
          <p class="tiny muted">This cannot be undone.</p>
        @else<p class="small muted" id="eraseNoPerm">Only the Data Protection Officer / administrator can execute erasure.</p>@endif
      @endif
    </div></div>
    @endif

    <div class="card"><div class="card-head"><h3>Other actions</h3></div><div class="card-body">
      <form method="post" action="{{ route('privacy.requests.assign', $req) }}" class="filters">@csrf<div class="field" style="flex:1"><label>Assign to</label><select name="assigned_to"><option value="">— nobody —</option>@foreach($users as $id => $n)<option value="{{ $id }}" @selected($req->assigned_to == $id)>{{ $n }}</option>@endforeach</select></div><button class="btn btn-sm">Save</button></form>
      @if(! $req->extended)
      <form method="post" action="{{ route('privacy.requests.extend', $req) }}" class="filters" id="extendForm">@csrf<div class="field" style="flex:1"><label>Extend the deadline once (+{{ \App\Services\PrivacyService::settings()['privacy_extension_days'] }} days)</label><input name="extension_reason" placeholder="Reason – e.g. complex request, many records" required minlength="10"></div><button class="btn btn-sm" id="extendBtn">Extend</button></form>
      @endif
      <form method="post" action="{{ route('privacy.requests.reject', $req) }}" class="filters" id="rejectForm">@csrf<div class="field" style="flex:1"><label>Reject</label><input name="rejection_reason" list="rejections" placeholder="Reason given to the requester" required minlength="5"><datalist id="rejections">@foreach(\App\Models\PrivacyRequest::REJECTIONS as $r)<option value="{{ $r }}">@endforeach</datalist></div><button class="btn btn-sm btn-danger" id="rejectBtn">Reject</button></form>
    </div></div>
  @elseif($h && $req->status === 'unverified')
    <div class="card"><div class="card-body"><form method="post" action="{{ route('privacy.requests.reject', $req) }}" class="filters" id="rejectForm">@csrf<div class="field" style="flex:1"><label>Close as spam / not genuine</label><input name="rejection_reason" value="E-mail address never confirmed" required></div><button class="btn btn-sm btn-danger">Reject</button></form></div></div>
  @else
    <div class="card"><div class="card-body small muted">{{ $open ? 'You can view this request but not process it.' : 'This request is closed.' }}</div></div>
  @endif
  </div>
</div>
@endsection
