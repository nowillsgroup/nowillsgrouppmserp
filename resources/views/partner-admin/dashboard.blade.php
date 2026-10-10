@extends('layouts.app')
@section('title', 'Partner Dashboard')
@section('crumbs') Partner Portal / Dashboard @endsection
@section('actions')
  <a class="btn" href="{{ route('partner.login', ['h' => $currentHotel->code]) }}" target="_blank" rel="noopener" id="ptaOpenPortal"><x-icon name="globe"/> Open partner portal</a>
  <a class="btn btn-primary" href="{{ route('partner-admin.requests') }}"><x-icon name="check"/> Requests &amp; approvals</a>
@endsection
@section('content')
@php $k = $kpi; @endphp
@if(setting('partner_enabled', '1') !== '1')<div class="alert alert-warn"><x-icon name="alert"/><div>The partner portal is switched off – partners cannot sign in. @if(can_do('partner.manage'))<a href="{{ route('partner-admin.settings') }}">Portal settings</a>@endif</div></div>@endif
<div class="stats" id="ptaStats">
  <x-stat label="Partners on the portal" :value="$k['companies']" icon="building" tone="blue" :sub="$k['users'].' active login(s) · '.$k['invited'].' invited'" :href="route('partner-admin.accounts')"/>
  <x-stat label="Portal bookings · 30 days" :value="$k['bookings']" icon="calendar" tone="teal" :sub="$k['nights'].' room nights · '.$k['cancelled'].' cancelled'"/>
  <x-stat label="Portal booking value · 30 days" :value="money($k['value'])" icon="trend" tone="green" sub="room revenue, taxes excluded"/>
  <x-stat label="Waiting for approval" :value="$k['approvals']" icon="clock" :tone="$k['approvals'] ? 'amber' : 'slate'" :href="route('partner-admin.requests', ['type' => 'approval'])"/>
  <x-stat label="Change / group requests" :value="$k['changes']" icon="message" :tone="$k['changes'] ? 'amber' : 'slate'" :href="route('partner-admin.requests')"/>
  <x-stat label="Partner sign-ins · 30 days" :value="$k['logins']" icon="login" tone="purple" :sub="$k['failed'].' failed / locked in 7 days'" :href="route('partner-admin.activity')"/>
</div>
<div class="g-2-1">
  <div>
    <div class="card" id="ptaPending"><div class="card-head"><h3><x-icon name="clock"/> Oldest open requests</h3><a class="small" href="{{ route('partner-admin.requests') }}">Queue</a></div><div class="card-body table-wrap">
      @if($pending->isEmpty())<p class="muted m-0">Nothing waiting – every partner request is answered.</p>@else
      <table class="table table-compact"><thead><tr><th>No.</th><th>Type</th><th>Partner</th><th>Booking</th><th>Why / what</th><th>Waiting</th></tr></thead><tbody>
      @foreach($pending as $q)<tr><td><b>{{ $q->number }}</b></td><td>{{ \App\Models\PartnerRequest::TYPES[$q->type] }}</td><td>{{ $q->company?->name }}<div class="tiny muted">{{ $q->partnerUser?->name }}</div></td>
        <td>@if($q->reservation)<a href="{{ route('reservations.show', $q->reservation_id) }}">{{ $q->reservation->reservation_no }}</a>@else — @endif</td><td class="small">{{ \Illuminate\Support\Str::limit($q->reason ?: $q->details, 90) }}</td><td class="nowrap {{ $q->responseHours() > 24 ? 'ptn-warn' : '' }}">{{ $q->responseHours() }} h</td></tr>@endforeach
      </tbody></table>@endif
    </div></div>
    <div class="card" id="ptaRecent"><div class="card-head"><h3><x-icon name="eye"/> Latest portal activity</h3><a class="small" href="{{ route('partner-admin.activity') }}">Full log</a></div><div class="card-body table-wrap">
      <table class="table table-compact"><tbody>
      @forelse($recent as $a)<tr class="{{ in_array($a->action, ['locked', 'login_blocked']) ? 'row-red' : '' }}"><td class="small nowrap">{{ $a->created_at->format('d M H:i') }}</td><td>{{ $a->company?->name ?? '—' }}<div class="tiny muted">{{ $a->partnerUser?->name }}</div></td><td><span class="badge badge-slate">{{ str_replace('_', ' ', $a->action) }}</span></td><td class="small">{{ $a->detail }}</td></tr>@empty<tr><td class="muted">No activity yet.</td></tr>@endforelse
      </tbody></table>
    </div></div>
  </div>
  <div class="card" id="ptaTop"><div class="card-head"><h3><x-icon name="chart"/> Top partners · 30 days</h3></div><div class="card-body">
    @forelse($top as $t)
      <div class="flex justify-between items-center" style="padding:.45rem 0;border-bottom:1px dashed var(--c-border)"><div><b>{{ $t['company']?->name }}</b><div class="tiny muted">{{ $t['bookings'] }} booking(s)</div></div><b>{{ money($t['value']) }}</b></div>
    @empty<p class="muted m-0">No portal bookings in the last 30 days.</p>@endforelse
  </div></div>
</div>
@endsection
