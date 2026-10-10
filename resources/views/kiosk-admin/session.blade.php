@extends('layouts.app')
@section('title', 'Kiosk visit '.$s->number)
@section('crumbs') Self-Service Kiosk / <a href="{{ route('kiosks.sessions') }}">Guest visits</a> / {{ $s->number }} @endsection
@php $S = \App\Models\KioskSession::class; @endphp
@section('content')
<div class="g-2-1">
  <div>
    <div class="card"><div class="card-head"><h3>{{ $s->kindLabel() }} · {{ $s->number }}</h3><span class="badge badge-{{ $S::STATUS_TONES[$s->status] ?? 'slate' }}" id="kskStatus">{{ $s->statusLabel() }}</span></div><div class="card-body">
      <dl class="kv">
        <dt>Kiosk</dt><dd>{{ $s->kiosk?->name }} <span class="muted small">{{ $s->kiosk?->location }}</span></dd>
        <dt>Started</dt><dd>{{ $s->started_at->format('d M Y H:i:s') }} · {{ $s->durationLabel() }}</dd>
        <dt>Guest</dt><dd>@if($s->guest)<a href="{{ route('guests.show', $s->guest) }}">{{ $s->guest->full_name }}</a>@else — @endif</dd>
        <dt>Booking</dt><dd>@if($s->reservation)<a href="{{ route('reservations.show', $s->reservation) }}">{{ $s->reservation->reservation_no }}</a> · {{ $s->stay?->roomType?->name }} · {{ $s->stay?->arrival_date?->format('d M') }} – {{ $s->stay?->departure_date?->format('d M') }}@else — @endif</dd>
        <dt>Found by</dt><dd>{{ ['reference' => 'Booking number + surname', 'qr' => 'Pre-check-in QR code', 'room' => 'Room number + surname', 'walkin' => 'Booked at the kiosk'][$s->lookup] ?? '—' }}@if($s->failed_lookups) <span class="muted small">({{ $s->failed_lookups }} failed attempt(s))</span>@endif</dd>
        <dt>Room</dt><dd>{{ $s->room?->room_no ?? '—' }}</dd>
        <dt>Checks</dt><dd class="small">{{ $s->details_ok ? '✔' : '✖' }} details · {{ $s->id_captured ? '✔' : '✖' }} ID photo · {{ $s->signed ? '✔' : '✖' }} registration card · {{ $s->keys_issued }} key card(s)</dd>
        <dt>Paid at the kiosk</dt><dd>{{ money($s->amount_paid) }}@if($s->payment) <span class="muted small">· link {{ $s->payment->pay_no }} ({{ $s->payment->status }})</span>@endif</dd>
        @if($s->rating)<dt>Rating</dt><dd>{{ str_repeat('★', $s->rating) }}{{ str_repeat('☆', 5 - $s->rating) }}</dd>@endif
      </dl>
    </div></div>
    <div class="card"><div class="card-head"><h3>Step by step</h3></div><div class="card-body table-wrap"><table class="table table-compact" id="kskTrail"><tbody>
      @php $tr = array_values($s->trail ?? []); $tr = array_values(array_filter($tr, fn ($t, $i) => filled($t[2] ?? null) || ($tr[$i + 1][1] ?? null) !== $t[1], ARRAY_FILTER_USE_BOTH)); @endphp
      @foreach($tr as $t)<tr><td class="small nowrap muted">{{ $t[0] }}</td><td class="small"><b>{{ $S::STEPS[$t[1]] ?? ucfirst(str_replace('_', ' ', $t[1])) }}</b></td><td class="small">{{ $t[2] }}</td></tr>@endforeach
    </tbody></table></div></div>
  </div>
  <div>
    @if($s->status === 'assist')
    <div class="card {{ $s->resolved_at ? '' : 'ksk-queue-live' }}"><div class="card-head"><h3><x-icon name="bell"/> Handed to the desk</h3></div><div class="card-body">
      <p class="m-0"><span class="badge badge-red">{{ $s->assistLabel() }}</span> {{ $s->assist_at?->format('d M H:i') }}</p>
      @if($s->assist_note)<p class="small">{{ $s->assist_note }}</p>@endif
      @if($s->resolved_at)<p class="small m-0">Dealt with by <b>{{ $s->resolvedBy?->name }}</b> at {{ $s->resolved_at->format('H:i') }} – {{ $s->resolution }}</p>
      @elseif($canAssist)
      <form method="post" action="{{ route('kiosks.resolve', $s) }}" class="form-grid" id="kskResolveForm">@csrf
        <div class="field span-all"><label>What was done</label><input name="resolution" class="input" maxlength="255" value="Guest helped at the desk"></div>
        <div class="span-all"><button class="btn btn-primary">Mark as dealt with</button></div></form>
      @endif
    </div></div>
    @endif
    <div class="card"><div class="card-head"><h3>Documents</h3></div><div class="card-body small">
      @if($s->stay?->grc_signature_path)<p class="m-0"><a href="{{ route('frontdesk.grc', $s->stay) }}" target="_blank">Registration card (signed {{ $s->stay->grc_signed_at?->format('d M H:i') }})</a></p>@endif
      @forelse($docs as $doc)<p class="m-0">{{ \App\Models\GuestDocument::TYPES[$doc->type] ?? ucfirst(str_replace('_', ' ', $doc->type)) }} – {{ $doc->original_name }} · {{ number_format($doc->size / 1024) }} KB</p>@empty @if(! $s->stay?->grc_signature_path)<p class="muted m-0">No documents from this visit.</p>@endif @endforelse
      @if($s->guest)<p class="tiny muted m-0 mt-1">ID photos are kept on the <a href="{{ route('guests.show', $s->guest) }}">guest profile</a> (documents).</p>@endif
    </div></div>
  </div>
</div>
@endsection
