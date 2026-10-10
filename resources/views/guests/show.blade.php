@extends('layouts.app')
@section('title', $guest->full_name)
@section('crumbs') <a href="{{ route('guests.index') }}">Guests</a> / Profile @endsection
@section('actions')
  @if(can_do('privacy.handle') && ! $guest->isErased())<a class="btn" href="{{ route('privacy.guests.export', $guest) }}" id="exportData"><x-icon name="download"/> Export data</a>@endif
  @if(can_do('guests.manage') && ! $guest->isPrivacyLocked())<a class="btn" href="{{ route('guests.edit', $guest) }}"><x-icon name="edit"/> Edit</a>@endif
  @if(can_do('reservations.create') && !$guest->is_blacklisted && ! $guest->isPrivacyLocked())<a class="btn btn-primary" href="{{ route('reservations.create', ['guest_id' => $guest->id]) }}"><x-icon name="plus"/> New Booking</a>@endif
@endsection
@section('content')
@if($guest->isErased())<div class="alert alert-error" id="privacyBanner"><x-icon name="shield"/><div><b>Personal data erased</b> on {{ $guest->erased_at?->format('d M Y') }}. {{ $guest->privacy_note }}. Stay and billing figures are kept without personal identifiers; a returning guest gets a new profile.</div></div>
@elseif($guest->isRestricted())<div class="alert alert-warn" id="privacyBanner"><x-icon name="shield"/><div><b>Restricted profile</b> – personal data erased on {{ $guest->erased_at?->format('d M Y') }} except what the law requires ({{ $guest->privacy_note }}). No further use: it cannot be booked, edited or marketed to, and will be fully erased on {{ $guest->legal_hold_until?->format('d M Y') }}.</div></div>@endif
@if($guest->is_blacklisted)<div class="alert alert-error"><x-icon name="alert"/><div>This guest is <b>blacklisted</b>. {{ $guest->notes }}</div></div>@endif
<div class="stats">
  <x-stat label="Total stays" :value="$guest->reservations->where('status','checked_out')->count()" icon="bed" tone="blue"/>
  <x-stat label="Room nights" :value="$nights" icon="moon" tone="purple"/>
  <x-stat label="Lifetime spend" :value="money($spend)" icon="money" tone="green"/>
  <x-stat label="Cancellations / no-shows" :value="$guest->reservations->whereIn('status',['cancelled','no_show'])->count()" icon="x" tone="red"/>
</div>
<div class="grid g-1-2">
  <div class="card"><div class="card-head"><h3>Profile</h3>@if($guest->is_vip)<span class="badge badge-purple">VIP</span>@endif</div><div class="card-body"><dl class="kv">
    <dt>Mobile</dt><dd>{{ $guest->phone ?? '—' }}</dd><dt>Email</dt><dd>{{ $guest->email ?? '—' }}</dd>
    <dt>Gender</dt><dd>{{ $guest->gender ?? '—' }}</dd><dt>DOB</dt><dd class="pii" data-k="dob">{{ $guest->dob ? '•• ••• ••••' : '—' }}</dd><dt>Anniversary</dt><dd>{{ dmy($guest->anniversary) }}</dd>
    <dt>Nationality</dt><dd>{{ $guest->nationality ?? '—' }}</dd><dt>ID</dt><dd>@if($guest->id_type || $guest->id_number){{ config('pms.id_types.'.$guest->id_type, '') }} @endif<span class="pii" data-k="id_number">{{ \App\Models\Guest::mask($guest->id_number) }}</span></dd>
    @if($guest->passport_no)<dt>Passport</dt><dd><span class="pii" data-k="passport_no">{{ \App\Models\Guest::mask($guest->passport_no) }}</span> (visa <span class="pii" data-k="visa_expiry">{{ $guest->visa_expiry ? '•• ••• ••••' : '—' }}</span>)</dd>@endif
    <dt>Address</dt><dd>{{ $guest->address }} {{ $guest->city }} {{ $guest->state }} {{ $guest->country }}</dd>
    <dt>Company</dt><dd>{{ $guest->company?->name ?? '—' }}</dd><dt>TIN</dt><dd>{{ $guest->tax_number ?? '—' }}</dd>
    <dt>Preferences</dt><dd>{{ $guest->preferences ?? '—' }}</dd><dt>Loyalty pts</dt><dd>{{ $guest->loyalty_points }}</dd>
  </dl>
  @if(can_do('pii.reveal') && ! $guest->isErased() && ($guest->id_number || $guest->passport_no || $guest->dob))
  <form class="filters mt-1" id="revealForm" data-url="{{ route('guests.reveal', $guest) }}"><div class="field" style="flex:1"><input name="reason" id="revealReason" placeholder="Reason (optional) – e.g. Form-C, police request" maxlength="190"></div>
    <button type="submit" class="btn btn-sm" id="revealPii"><x-icon name="eye" size="sm"/> Reveal ID details</button></form>
  <div class="tiny muted">ID and passport numbers and dates of birth are masked. Every reveal is recorded in the PII access log{{ $piiViews ? ' ('.$piiViews.' in the last 30 days)' : '' }}.</div>
  @endif
  @if($guest->notes)<div class="alert alert-warn small mt-2 mb-0">{{ $guest->notes }}</div>@endif</div></div>
  <div class="card flush"><div class="card-head"><h3>Stay history</h3></div><div class="card-body table-wrap"><table class="table">
    <thead><tr><th>Res. No</th><th>Arrival</th><th>Departure</th><th>Rooms</th><th>Status</th></tr></thead><tbody>
    @forelse($guest->reservations as $r)<tr><td><a href="{{ route('reservations.show', $r) }}">{{ $r->reservation_no }}</a></td><td>{{ $r->arrival_date->format('d M Y') }}</td><td>{{ $r->departure_date->format('d M Y') }}</td><td class="small">{{ $r->rooms->map(fn($s) => $s->room?->room_no ?? $s->roomType->code)->join(', ') }}</td><td>{!! status_badge($r->status) !!}</td></tr>
    @empty<tr><td colspan="5" class="muted center">No stays yet.</td></tr>@endforelse</tbody></table></div></div>
</div>
<div class="grid g-1-2 mb-1" id="marketing">
  <div class="card"><div class="card-head"><h3><x-icon name="mail"/> Marketing</h3><span class="badge badge-{{ $rfm['tone'] }}" title="Recency {{ $rfm['r'] }} · Frequency {{ $rfm['f'] }} · Monetary {{ $rfm['m'] }}">{{ $rfm['label'] }}</span></div><div class="card-body">
    @if($guest->isPrivacyLocked())<p class="small muted" id="consentLocked">Marketing is permanently off for this profile.</p>@else
    <form method="post" action="{{ route('guests.consent', $guest) }}" id="consentForm">@csrf
      <div class="seg">
      @foreach(\App\Services\CrmService::CHANNELS as $ch => $label)
        <label class="check"><input type="checkbox" name="{{ $ch }}_opt_in" value="1" @checked($guest->{$ch.'_opt_in'}) @disabled(! can_do('guests.manage') && ! can_do('crm.manage'))> {{ $label }}</label>
      @endforeach
      </div>
      @if(can_do('guests.manage') || can_do('crm.manage'))<button class="btn btn-sm mt-1" id="saveConsent"><x-icon name="check"/> Save consent</button>@endif
    </form>@endif
    <ul class="bq-list mt-1">
      <li><span>Consent</span><b>{{ $guest->consent_at ? $guest->consent_at->format('d M Y').' · '.str_replace('_', ' ', (string) $guest->consent_source) : 'none recorded' }}</b></li>
      <li><span>Tags</span><b>@forelse($guest->tagList() as $t)<span class="badge badge-slate">{{ $t }}</span> @empty — @endforelse</b></li>
      <li><span>Last stay</span><b>{{ $guest->last_stay_on?->format('d M Y') ?? '—' }}</b></li>
      <li><span>Revenue (net, excl. tax)</span><b>{{ money($guest->total_revenue) }}</b></li>
    </ul>
    @if($consentLog->isNotEmpty())
    <details><summary class="small muted">Consent history ({{ $consentLog->count() }})</summary>
      <ul class="timeline mt-1">@foreach($consentLog as $l)<li class="{{ $l->opted_in ? 'tl-ok' : 'tl-bad' }} small">{{ \App\Services\CrmService::CHANNELS[$l->channel] ?? $l->channel }} {{ $l->opted_in ? 'opt-in' : 'opt-out' }} · {{ str_replace('_', ' ', $l->source) }}<div class="tiny muted">{{ $l->created_at->format('d M Y H:i') }}{{ $l->user ? ' · '.$l->user->name : '' }}</div></li>@endforeach</ul>
    </details>
    @endif
  </div></div>
  <div class="card flush"><div class="card-head"><h3>Campaigns &amp; automations received</h3></div><div class="card-body table-wrap"><table class="table" id="mktMessages">
    <thead><tr><th>Date</th><th>Message</th><th>Channel</th><th>Status</th><th>Engagement</th></tr></thead><tbody>
    @forelse($marketing as $m)<tr><td class="small nowrap">{{ $m->created_at->format('d M Y') }}</td>
      <td class="small">@if($m->campaign)<a href="{{ route('crm.campaigns.show', $m->campaign) }}">{{ $m->campaign->name }}</a>@elseif($m->journey)<a href="{{ route('crm.journeys.show', $m->journey) }}">{{ $m->journey->name }}</a>@endif</td>
      <td class="small">{{ \App\Services\CrmService::CHANNELS[$m->channel] ?? $m->channel }}</td>
      <td>{!! status_badge($m->status) !!}@if($m->skip_reason)<div class="tiny muted">{{ $m->skip_reason }}</div>@endif</td>
      <td class="small">{{ $m->clicked_at ? 'clicked' : '' }}@if($m->reservation) · booked <a href="{{ route('reservations.show', $m->reservation) }}">{{ $m->reservation->reservation_no }}</a>@endif{{ $m->unsubscribed_at ? ' · unsubscribed' : '' }}</td></tr>
    @empty<tr><td colspan="5" class="muted center">{{ can_do('crm.view') ? 'No marketing messages yet.' : 'You do not have access to marketing history.' }}</td></tr>@endforelse
    </tbody></table></div></div>
</div>
@if($messages->isNotEmpty() || $spa->isNotEmpty())
<div class="grid g-2 mb-1">
  <div class="card flush" id="messages"><div class="card-head"><h3><x-icon name="message"/> SMS / WhatsApp</h3></div><div class="card-body table-wrap"><table class="table">
    <thead><tr><th>When</th><th>Channel</th><th>Message</th><th>Status</th></tr></thead><tbody>
    @forelse($messages as $m)<tr><td class="nowrap small">{{ $m->created_at->format('d M y H:i') }}</td><td class="small">{{ $m->channel === 'whatsapp' ? 'WhatsApp' : 'SMS' }}</td><td class="small">{{ \Illuminate\Support\Str::limit($m->body, 110) }}</td>
      <td><span class="badge {{ $m->status === 'sent' ? 'badge-green' : 'badge-red' }}">{{ $m->status }}</span></td></tr>
    @empty<tr><td colspan="4" class="muted center">No messages.</td></tr>@endforelse</tbody></table></div></div>
  <div class="card flush" id="spa"><div class="card-head"><h3><x-icon name="spa"/> Spa &amp; activities</h3></div><div class="card-body table-wrap"><table class="table">
    <thead><tr><th>Date</th><th>Service</th><th class="num">Net</th><th>Status</th></tr></thead><tbody>
    @forelse($spa as $a)<tr><td class="nowrap small"><a href="{{ route('spa.index', ['date' => $a->date->toDateString()]) }}#appt-{{ $a->id }}">{{ $a->date->format('d M y') }}</a> {{ substr($a->start_time,0,5) }}</td><td class="small">{{ $a->service->name }}</td><td class="num">{{ money($a->net()) }}</td><td class="small">{{ ucwords(str_replace('_',' ',$a->status)) }}</td></tr>
    @empty<tr><td colspan="4" class="muted center">None.</td></tr>@endforelse</tbody></table></div></div>
</div>
@endif
@if($dining->isNotEmpty())
<div class="card flush mb-1" id="guestDiningCard"><div class="card-head"><h3><x-icon name="coffee"/> Restaurant bookings</h3>
  <span class="small">{{ $diningStats['visits'] }} visit(s) @if($diningStats['no_shows'])· <b class="text-red">{{ $diningStats['no_shows'] }} no-show(s)</b>@endif @if($diningStats['cancelled'])· {{ $diningStats['cancelled'] }} cancelled @endif</span></div>
  <div class="card-body table-wrap"><table class="table">
  <thead><tr><th>Date</th><th>Ref</th><th>Outlet</th><th class="num">Pax</th><th>Table</th><th>Status</th></tr></thead><tbody>
  @foreach($dining as $d)<tr><td class="small nowrap">{{ $d->start_at->format('d M Y H:i') }}</td><td class="small"><a href="{{ route('tb.show', $d) }}">{{ $d->booking_no }}</a></td><td class="small">{{ $d->outlet?->name }}</td><td class="num">{{ $d->party_size }}</td><td class="small">{{ $d->tableLabel() }}</td><td>{!! tb_badge($d->status) !!}</td></tr>@endforeach
  </tbody></table></div></div>
@endif
@if($feedback->isNotEmpty() || $reviews->isNotEmpty() || $cases->isNotEmpty())
@php $gNps = \App\Services\FeedbackService::npsOf($feedback); $openCases = $cases->filter->isActive(); @endphp
<div class="card flush mb-1" id="guestFeedbackCard"><div class="card-head"><h3><x-icon name="star"/> Feedback &amp; guest cases</h3>
  <span class="small">@if($gNps['n']) last recommend score <b>{{ $feedback->whereNotNull('nps')->first()->nps }}</b>/10 {!! nps_badge($feedback->whereNotNull('nps')->first()->nps) !!}@endif
  @if($openCases->count()) <span class="badge badge-red">{{ $openCases->count() }} open case(s)</span>@endif</span></div>
<div class="card-body table-wrap"><table class="table">
  <thead><tr><th>Date</th><th>What</th><th>Score</th><th>Details</th><th>Status</th></tr></thead><tbody>
  @foreach($feedback as $f)<tr><td class="small nowrap">{{ $f->created_at->format('d M Y') }}</td><td class="small">{{ \App\Models\GuestFeedback::SOURCES[$f->source] ?? $f->source }}</td>
    <td class="nowrap">{!! stars($f->rating_overall) !!}@if($f->nps !== null) <span class="tiny muted">NPS {{ $f->nps }}</span>@endif</td>
    <td class="small">@if(can_do('feedback.view'))<a href="{{ route('rep.responses.show', $f) }}">{{ \Illuminate\Support\Str::limit($f->comments ?: 'View response', 90) }}</a>@else{{ \Illuminate\Support\Str::limit($f->comments, 90) }}@endif</td><td>{!! sentiment_badge($f->sentiment) !!}</td></tr>@endforeach
  @foreach($reviews as $r)<tr><td class="small nowrap">{{ $r->review_date->format('d M Y') }}</td><td class="small">{{ $r->sourceName() }} review</td><td class="nowrap">{!! stars($r->rating5) !!}</td>
    <td class="small"><a href="{{ route('rep.reviews.show', $r) }}">{{ \Illuminate\Support\Str::limit($r->title ?: $r->body, 90) }}</a></td><td>{!! status_badge($r->status) !!}</td></tr>@endforeach
  @foreach($cases as $c)<tr class="{{ $c->isOverdue() ? 'row-alert' : '' }}"><td class="small nowrap">{{ $c->created_at->format('d M Y') }}</td><td class="small">Case {{ $c->case_no }}</td><td>@include('reputation._sev', ['s' => $c->severity])</td>
    <td class="small"><a href="{{ route('rep.cases.show', $c) }}">{{ \Illuminate\Support\Str::limit($c->title, 90) }}</a>@if($c->goodwill_points)<div class="tiny muted">{{ number_format($c->goodwill_points) }} goodwill points</div>@endif</td><td>{!! status_badge($c->status) !!}</td></tr>@endforeach
</tbody></table></div></div>
@endif
@if(can_do('privacy.view'))
<div class="card flush mb-1" id="privacyCard"><div class="card-head"><h3><x-icon name="shield"/> Privacy requests</h3>
  @if(can_do('privacy.handle') && ! $guest->isErased())<a class="btn btn-sm" href="{{ route('privacy.requests.create', ['guest_id' => $guest->id]) }}" id="logPrivacy"><x-icon name="plus" size="sm"/> Log request</a>@endif</div>
  <div class="card-body table-wrap"><table class="table"><thead><tr><th>Request</th><th>Type</th><th>Received</th><th>Status</th></tr></thead><tbody>
  @forelse($privacyRequests as $pr)<tr><td><a href="{{ route('privacy.requests.show', $pr) }}">{{ $pr->request_no }}</a></td><td class="small">{{ \App\Models\PrivacyRequest::SHORT[$pr->type] }}</td>
    <td class="small">{{ $pr->received_at->format('d M Y') }}</td><td>{!! status_badge($pr->status) !!} {!! $pr->slaBadge() !!}</td></tr>
  @empty<tr><td colspan="4" class="muted center">No privacy requests.</td></tr>@endforelse</tbody></table></div></div>
@endif
<div class="card" id="documents"><div class="card-head"><h3><x-icon name="id"/> ID documents ({{ $guest->documents->count() }})</h3></div><div class="card-body">
  @if((can_do('frontdesk.checkin') || can_do('guests.manage')) && ! $guest->isPrivacyLocked())
  <form method="post" action="{{ route('guests.documents.store', $guest) }}" enctype="multipart/form-data" class="filters">@csrf
    <div class="field"><label>Type</label><select name="type">@foreach(\App\Models\GuestDocument::TYPES as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
    <div class="field" style="flex:1;min-width:220px"><label>Scan / photo (JPG, PNG, WebP, PDF · max 5 MB)</label><input type="file" name="file" accept="image/*,application/pdf" required></div>
    <button class="btn btn-primary"><x-icon name="plus" size="sm"/> Upload</button>
  </form>
  @endif
  @include('guests._documents', ['docs' => $guest->documents])
</div></div>
@endsection
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
  const f = document.getElementById('revealForm');
  if (!f) return;
  f.addEventListener('submit', async e => {
    e.preventDefault();
    const r = await fetch(f.dataset.url, {method: 'POST', headers: {'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json', 'Content-Type': 'application/json'},
      body: JSON.stringify({reason: document.getElementById('revealReason').value})});
    if (!r.ok) { alert('Could not reveal (' + r.status + ')'); return; }
    const d = await r.json();
    document.querySelectorAll('.pii').forEach(el => { if (d[el.dataset.k] !== undefined) { el.textContent = d[el.dataset.k]; el.classList.add('revealed'); } });
    f.remove();
  });
});
</script>
@endpush
