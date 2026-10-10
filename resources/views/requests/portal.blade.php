@extends('layouts.app')
@section('title', 'Guest Portal – Room '.($stay->room?->room_no ?? $stay->roomType?->name))
@section('actions')<a class="btn" href="{{ route('reservations.show', $stay->reservation_id) }}"><x-icon name="book"/> Reservation</a> <a class="btn" href="{{ route('requests.portal', [$stay, 'card' => 1]) }}" target="_blank"><x-icon name="print"/> Print welcome card</a>@endsection
@section('content')
@php $state = $stay->portalState(); @endphp
<div class="portal-grid">
  <div class="card"><div class="card-head"><h3>Portal link</h3>{!! ['inhouse' => '<span class="badge badge-green">Active – in house</span>', 'upcoming' => '<span class="badge badge-blue">Pre-arrival (info only)</span>', 'departed' => '<span class="badge badge-amber">Read-only after check-out</span>', 'closed' => '<span class="badge badge-slate">Expired</span>'][$state] !!}</div>
    <div class="card-body">
      <div class="portal-qr" style="max-width:260px;margin:0 auto">{!! $svg !!}</div>
      <div class="portal-link mt-1" id="portalUrl">{{ $url }}</div>
      <div class="flex gap-1 wrap mt-1">
        <button type="button" class="btn btn-sm" onclick="navigator.clipboard?.writeText(document.getElementById('portalUrl').textContent.trim()).then(() => this.textContent = 'Copied ✓')"><x-icon name="link" size="sm"/> Copy link</button>
        <a class="btn btn-sm" href="{{ $url }}" target="_blank" rel="noreferrer"><x-icon name="eye" size="sm"/> Open as guest</a>
      </div>
      @if($stay->portal_seen_at)<p class="tiny muted mt-1 m-0">Guest last opened the portal {{ $stay->portal_seen_at->diffForHumans() }}.</p>@else<p class="tiny muted mt-1 m-0">The guest has not opened the portal yet.</p>@endif
    </div></div>

  <div>
    <div class="card"><div class="card-head"><h3>{{ $guest?->full_name }}</h3>{!! status_badge($stay->status) !!}</div><div class="card-body">
      <div class="small">{{ $stay->reservation->reservation_no }} · Room {{ $stay->room?->room_no ?? '—' }} ({{ $stay->roomType?->name }}) · {{ $stay->arrival_date->format('d M') }} → {{ $stay->departure_date->format('d M Y') }}</div>
      <div class="small muted">{{ $guest?->phone ?: 'no mobile number' }} · {{ $guest?->email ?: 'no e-mail' }}</div>
      @if($state !== 'closed')
      <div class="flex gap-1 wrap mt-1">
        @foreach(['sms' => 'Send by SMS', 'whatsapp' => 'Send by WhatsApp', 'email' => 'Send by e-mail'] as $ch => $label)
          <form method="post" action="{{ route('requests.portal.send', $stay) }}" class="inline">@csrf<input type="hidden" name="channel" value="{{ $ch }}"><button class="btn btn-sm" @disabled($ch === 'email' ? ! $guest?->email : ! $guest?->phone)><x-icon name="{{ $ch === 'email' ? 'mail' : 'message' }}" size="sm"/> {{ $label }}</button></form>
        @endforeach
      </div>
      @endif
      <form method="post" action="{{ route('requests.portal.regenerate', $stay) }}" class="mt-1" onsubmit="return confirm('Create a new link? The current link and QR code will stop working immediately.')">@csrf
        <button class="btn btn-sm btn-ghost" style="color:#b91c1c"><x-icon name="refresh" size="sm"/> Regenerate link (lost card / wrong guest)</button></form>
    </div></div>

    <div class="card flush mt-1"><div class="card-head"><h3>Requests this stay</h3><a class="small" href="{{ route('requests.index', ['status' => 'all', 'room' => $stay->room?->room_no]) }}">Queue ›</a></div>
      <div class="card-body table-wrap"><table class="table"><thead><tr><th>No.</th><th>Request</th><th>Raised</th><th>Status</th></tr></thead><tbody>
      @forelse($requests as $r)<tr><td class="bold small">{{ $r->request_no }}</td><td>{{ $r->quantity > 1 ? $r->quantity.' × ' : '' }}{{ $r->item }}<div class="tiny muted">{{ $r->categoryLabel() }}{{ $r->staff_note ? ' · '.$r->staff_note : '' }}</div></td><td class="small">{{ $r->created_at->format('d M H:i') }}</td><td>{!! status_badge($r->status) !!}@if($r->rating)<span class="tiny" style="color:#f59e0b"> {{ str_repeat('★', $r->rating) }}</span>@endif</td></tr>
      @empty<tr><td colspan="4"><x-empty text="No requests yet." icon="bell"/></td></tr>@endforelse
      </tbody></table></div></div>
  </div>
</div>
@endsection
