@extends('layouts.app')
@section('title', 'Check-in · '.$stay->reservation->reservation_no)
@section('crumbs')
  @if(can_do('frontdesk.view'))<a href="{{ route('frontdesk.arrivals') }}">Arrivals</a>
  @elseif(can_do('reservations.view'))<a href="{{ route('reservations.workspace') }}">Reservation Workspace</a>
  @else<span>Front Office</span>@endif / Check-in
@endsection
@section('actions')
  @if(can_do('frontdesk.view'))
    <a class="btn" href="{{ route('frontdesk.arrivals') }}"><x-icon name="login" size="sm"/> Arrivals</a>
    <a class="btn btn-soft" href="{{ route('frontdesk.rooms') }}"><x-icon name="bed" size="sm"/> Room view</a>
  @endif
  @if(can_do('reservations.view'))<a class="btn btn-soft" href="{{ route('reservations.show', $stay->reservation_id) }}"><x-icon name="eye" size="sm"/> Reservation</a>@endif
  @if(can_do('frontdesk.checkin'))<a class="btn btn-soft" href="{{ route('frontdesk.grc.sign', $stay) }}"><x-icon name="edit" size="sm"/> Registration &amp; ID</a>@endif
@endsection
@section('content')
@php
  $guestName = $guest?->full_name ?: trim(($guest?->first_name ?? '').' '.($guest?->last_name ?? ''));
  $arrival = $stay->arrival_date;
  $departure = $stay->departure_date;
  $nights = max(1, (int) $arrival->diffInDays($departure));
@endphp
<style>
  .checkin-page { --checkin-green:#17623b; --checkin-green-soft:#edf7ef; color:var(--c-text); }
  .checkin-hero { display:flex; align-items:flex-start; justify-content:space-between; gap:1.2rem; margin:0 0 1rem; padding:1.1rem 1.2rem; border:1px solid #d7e8db; border-radius:15px; background:linear-gradient(115deg,#eef8f0 0%,var(--c-surface) 72%); }
  .checkin-eyebrow { display:flex; align-items:center; gap:.42rem; color:var(--checkin-green); font-size:.72rem; font-weight:800; letter-spacing:.09em; text-transform:uppercase; }
  .checkin-eyebrow .i { width:15px; height:15px; }
  .checkin-hero h2 { margin:.35rem 0 .25rem; color:var(--c-text); font-size:1.45rem; line-height:1.2; letter-spacing:-.02em; }
  .checkin-hero-copy > p { margin:.28rem 0 0; max-width:680px; color:var(--c-text-2); font-size:.86rem; }
  .checkin-reservation-line { display:flex; align-items:center; gap:.45rem; flex-wrap:wrap; margin-top:.55rem; color:var(--c-text-2); font-size:.77rem; }
  .checkin-reservation-line .i { width:13px; height:13px; }
  .checkin-date-panel { display:grid; grid-template-columns:repeat(3,minmax(88px,1fr)); gap:.45rem; flex:0 0 auto; }
  .checkin-date-item { min-width:0; padding:.58rem .68rem; border:1px solid #dcebe0; border-radius:10px; background:rgba(255,255,255,.86); }
  .checkin-date-item span { display:block; color:var(--c-text-2); font-size:.66rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; }
  .checkin-date-item time,.checkin-date-item b { display:block; margin-top:.2rem; color:#24583a; font-size:.83rem; font-weight:760; font-variant-numeric:tabular-nums; white-space:nowrap; }
  .checkin-alert { margin:0 0 .9rem; }
  .checkin-errors { margin:0 0 .9rem; padding:.8rem 1rem; border:1px solid #f0c4bd; border-radius:11px; background:#fff4f2; color:#8a261d; }
  .checkin-errors h2 { margin:0 0 .3rem; font-size:.9rem; }.checkin-errors ul { margin:.25rem 0 0; padding-left:1.25rem; font-size:.82rem; }
  .checkin-layout { display:grid; grid-template-columns:minmax(0,1.7fr) minmax(285px,.82fr); gap:1rem; align-items:start; }
  .checkin-main,.checkin-sidebar { min-width:0; }
  .checkin-card { overflow:visible; }
  .checkin-card .card-head { min-height:52px; }
  .checkin-card-title { display:flex; align-items:center; gap:.55rem; }
  .checkin-card-title .i { width:18px; height:18px; color:var(--checkin-green); }
  .checkin-card-subtitle { margin:.15rem 0 0; color:var(--c-text-2); font-size:.75rem; }
  .checkin-readonly { min-height:40px; display:flex; align-items:center; padding:.5rem .7rem; border:1px solid var(--c-border); border-radius:8px; background:var(--c-bg); color:var(--c-text); font-size:.84rem; font-weight:650; }
  .checkin-field-room { grid-column:1/-1; }
  .checkin-field-room select { min-height:44px; font-weight:650; }
  .checkin-room-status { display:block; margin-top:.35rem; }
  .checkin-room-summary { margin:.6rem 0 0; padding:.62rem .72rem; border:1px solid #dcebe0; border-radius:9px; background:#f5faf6; color:#315c3d; font-size:.78rem; }
  .checkin-room-summary b { color:#174d2e; }
  .checkin-card-note { margin:-.15rem 0 .8rem; color:var(--c-text-2); font-size:.78rem; line-height:1.45; }
  .checkin-special { margin:0 0 1rem; }
  .checkin-special strong { display:block; margin-bottom:.18rem; }
  .checkin-deposit-note { margin:0 0 .85rem; padding:.6rem .68rem; border:1px solid var(--c-border); border-radius:9px; background:var(--c-bg); color:var(--c-text-2); font-size:.76rem; line-height:1.45; }
  .checkin-complete { padding:.95rem; border:1px solid #d7e8db; border-radius:13px; background:linear-gradient(135deg,#f0f8f2,#fff); box-shadow:var(--shadow-sm); }
  .checkin-complete h2 { margin:0; color:#174d2e; font-size:.95rem; }
  .checkin-complete p { margin:.3rem 0 .75rem; color:var(--c-text-2); font-size:.78rem; line-height:1.4; }
  .checkin-complete .btn { min-height:46px; width:100%; font-weight:750; }
  .checkin-no-room { margin:0 0 .6rem; color:#9a3412; font-size:.78rem; }
  .checkin-step { display:inline-flex; align-items:center; gap:.3rem; padding:.2rem .45rem; border:1px solid var(--c-border); border-radius:999px; color:var(--c-text-2); background:var(--c-bg); font-size:.66rem; font-weight:700; }
  .checkin-step .i { width:12px; height:12px; }
  .checkin-room-readiness { display:inline-flex; align-items:center; gap:.3rem; margin-left:auto; color:var(--c-text-2); font-size:.72rem; }
  .checkin-room-readiness .i { width:14px; height:14px; }
  .checkin-page .dropdown[role="listbox"] { z-index:40; }
  .checkin-page :focus-visible { outline:3px solid rgba(37,99,235,.75); outline-offset:2px; }
  body.dark .checkin-hero { border-color:var(--c-border); background:linear-gradient(115deg,#172c1f 0%,var(--c-surface) 75%); }
  body.dark .checkin-eyebrow { color:#bce8c9; }
  body.dark .checkin-date-item { border-color:var(--c-border); background:#1b2d21; }
  body.dark .checkin-date-item time,body.dark .checkin-date-item b { color:#bce8c9; }
  body.dark .checkin-errors { border-color:#74362e; background:#351c1a; color:#ffc4ba; }
  body.dark .checkin-room-summary { border-color:#315a3e; background:#193525; color:#c5ddcb; }
  body.dark .checkin-room-summary b { color:#bce8c9; }
  body.dark .checkin-complete { border-color:#315a3e; background:linear-gradient(135deg,#172c1f,#1b2d21); }
  body.dark .checkin-complete h2 { color:#bce8c9; }
  body.dark .checkin-step { border-color:var(--c-border); background:#18271e; }
  @media(max-width:1080px) { .checkin-layout { grid-template-columns:minmax(0,1.35fr) minmax(275px,.85fr); } }
  @media(max-width:850px) { .checkin-layout { grid-template-columns:1fr; }.checkin-sidebar { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); align-items:start; gap:0 1rem; }.checkin-special,.checkin-complete { grid-column:1/-1; } }
  @media(max-width:620px) { .checkin-hero { display:block; padding:.9rem; }.checkin-hero h2 { font-size:1.25rem; }.checkin-date-panel { grid-template-columns:repeat(3,minmax(0,1fr)); margin-top:.8rem; }.checkin-date-item { padding:.5rem .45rem; }.checkin-date-item span { font-size:.59rem; }.checkin-date-item time,.checkin-date-item b { font-size:.72rem; }.checkin-sidebar { display:block; }.checkin-card .card-body { padding:.85rem; }.checkin-main .form-grid { grid-template-columns:1fr; }.checkin-field-room { grid-column:auto; } }
  @media(prefers-reduced-motion:reduce) { .checkin-page * { scroll-behavior:auto!important; transition:none!important; } }
  @media print { .checkin-page .checkin-hero { background:#fff; border-color:#999; }.checkin-page .checkin-date-item { background:#fff; border-color:#999; }.checkin-page .checkin-errors { background:#fff; }.checkin-page .checkin-complete,.checkin-page .checkin-special,.checkin-page .no-print { display:none!important; }.checkin-layout { display:block; }.checkin-card { box-shadow:none; break-inside:avoid; }.checkin-sidebar { display:block; } }
</style>

<div class="checkin-page">
  <section class="checkin-hero" aria-label="Guest check-in overview">
    <div class="checkin-hero-copy">
      <div class="checkin-eyebrow"><x-icon name="login"/> Front office · Guest arrival</div>
      <h2>Check in {{ $guestName ?: 'guest' }}</h2>
      <p>Confirm the room, complete the guest registration and record an optional deposit before finalizing the arrival.</p>
      <div class="checkin-reservation-line">
        <x-icon name="calendar"/> Reservation <strong>{{ $stay->reservation->reservation_no }}</strong>
        {!! status_badge($stay->reservation->status) !!}
        @if($stay->reservation->prechecked_in_at)<span class="badge badge-green">Pre-checked online</span>@endif
        @if($stay->grc_signed_at)<span class="badge badge-blue">Registration signed</span>@endif
      </div>
    </div>
    <div class="checkin-date-panel" aria-label="Stay dates and length">
      <div class="checkin-date-item"><span>Arrival</span><time datetime="{{ $arrival->toDateString() }}">{{ $arrival->format('d M Y') }}</time></div>
      <div class="checkin-date-item"><span>Departure</span><time datetime="{{ $departure->toDateString() }}">{{ $departure->format('d M Y') }}</time></div>
      <div class="checkin-date-item"><span>Length</span><b>{{ $nights }} {{ \Illuminate\Support\Str::plural('night', $nights) }}</b></div>
    </div>
  </section>

  @if($stay->reservation->prechecked_in_at)
    <div class="alert alert-success checkin-alert" role="status"><x-icon name="check"/><div><b>Guest checked in online</b> on <time datetime="{{ $stay->reservation->prechecked_in_at->toIso8601String() }}">{{ $stay->reservation->prechecked_in_at->format('d M Y H:i') }}</time>{{ $stay->reservation->eta ? ' (ETA '.substr($stay->reservation->eta,0,5).')' : '' }}. The details below were confirmed online.@if($stay->grc_signed_at) The registration card is already signed.@else Review the registration/signature record before proceeding.@endif</div></div>
  @endif

  @if($errors->any())
    <section class="checkin-errors" role="alert" aria-labelledby="checkin-error-title">
      <h2 id="checkin-error-title">Check-in needs a correction</h2><p class="small" style="margin:.1rem 0">Review the highlighted fields and try again.</p>
      <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </section>
  @endif

  <form id="checkInForm" method="post" action="{{ route('frontdesk.checkin.store', $stay) }}">
    @csrf
    <div class="checkin-layout">
      <div class="checkin-main">
        <section class="card checkin-card" aria-labelledby="checkin-room-title">
          <div class="card-head">
            <div><h3 class="checkin-card-title" id="checkin-room-title"><x-icon name="bed"/> Room assignment</h3><p class="checkin-card-subtitle">Match the booking to an available room; room moves or type changes are rechecked when saved.</p></div>
            <span class="checkin-step">Step 1 of 3</span>
          </div>
          <div class="card-body">
            <div class="form-grid">
              <div class="field"><label>Booked room type</label><div class="checkin-readonly">{{ $stay->roomType?->name ?? 'Room type unavailable' }} · {{ $stay->ratePlan?->name ?? 'Rack rate' }}</div></div>
              <div class="field"><label>Guests</label><div class="checkin-readonly">{{ $stay->adults }} adults · {{ $stay->children }} children</div></div>
              <div class="field checkin-field-room">
                <label for="checkinRoom">Assign room <span class="req">*</span></label>
                @php
                  $selectedRoomId = old('room_id', $stay->room_id);
                  if (! is_scalar($selectedRoomId)) $selectedRoomId = null;
                  $hasSelectedRoom = $rooms->contains(fn ($room) => (string) $room->id === (string) $selectedRoomId);
                  $hasAssignedRoom = $stay->room_id && $rooms->contains(fn ($room) => (string) $room->id === (string) $stay->room_id);
                  $roomWasSubmitted = old('room_id') !== null;
                  $roomSelectionIssue = $rooms->isEmpty() || ($roomWasSubmitted && ! $hasSelectedRoom) || ($stay->room_id && ! $hasAssignedRoom);
                @endphp
                <select id="checkinRoom" name="room_id" required @if($roomSelectionIssue) aria-describedby="checkinRoomStatus checkin-room-summary" @else aria-describedby="checkin-room-summary" @endif>
                  @if(! $hasSelectedRoom)<option value="" disabled selected>Choose an available room…</option>@endif
                  @foreach($rooms as $room)
                    @php $roomReadinessLabel = match ($room->hk_status) { 'inspected' => ' · Inspected', 'clean' => ' · Clean', 'dirty' => ' · ⚠ Dirty', 'touch_up' => ' · ⚠ Touch-up', default => '' }; @endphp
                    <option value="{{ $room->id }}" data-room-no="{{ $room->room_no }}" data-hk-status="{{ $room->hk_status }}" @selected((string) $selectedRoomId === (string) $room->id)>{{ $room->room_no }} – {{ $room->roomType?->name }}{{ $room->room_type_id !== $stay->room_type_id ? ' (upgrade/change)' : '' }}{{ $roomReadinessLabel }}</option>
                  @endforeach
                </select>
                @if($rooms->isEmpty())<span id="checkinRoomStatus" class="err checkin-room-status" role="status">No vacant rooms are available for this stay. Review availability before checking in.</span>
                @elseif($stay->room_id && ! $hasAssignedRoom)<span id="checkinRoomStatus" class="err checkin-room-status" role="status">The previously assigned room is no longer available. Choose another room.</span>
                @elseif($roomWasSubmitted && ! $hasSelectedRoom)<span id="checkinRoomStatus" class="err checkin-room-status" role="status">{{ $selectedRoomId ? 'The selected room is no longer available. Choose another room.' : 'Choose a room to continue.' }}</span>@endif
                <p id="checkin-room-summary" class="checkin-room-summary" role="status" aria-live="polite">Choose a room to see its readiness.</p>
              </div>
              <x-field name="rate" label="Rate per night" type="number" step="0.01" min="0" :value="$stay->rate" required hint="This rate is saved with the checked-in stay."/>
              <div class="field"><label>Stay length</label><div class="checkin-readonly">{{ $nights }} {{ \Illuminate\Support\Str::plural('night', $nights) }}</div></div>
            </div>
          </div>
        </section>

        <section class="card checkin-card" aria-labelledby="checkin-guest-title">
          <div class="card-head">
            <div><h3 class="checkin-card-title" id="checkin-guest-title"><x-icon name="users"/> Guest registration</h3><p class="checkin-card-subtitle">Confirm the lead guest’s contact and identity details for the registration record.</p></div>
            <span class="checkin-step">Step 2 of 3</span>
          </div>
          <div class="card-body">
            <p class="checkin-card-note">First name, mobile number, ID type and ID number are required to complete check-in. Confirm details with the guest before saving.</p>
            @include('reservations._guest')
          </div>
        </section>
      </div>

      <aside class="checkin-sidebar" aria-label="Deposit and check-in completion">
        @if($stay->reservation->special_request)
          <div class="alert alert-info checkin-special"><x-icon name="bell"/><div><strong>Guest request / note</strong>{{ $stay->reservation->special_request }}</div></div>
        @endif
        <section class="card checkin-card" aria-labelledby="checkin-deposit-title">
          <div class="card-head">
            <div><h3 class="checkin-card-title" id="checkin-deposit-title"><x-icon name="receipt"/> Deposit</h3><p class="checkin-card-subtitle">Optional advance payment posted to the guest folio.</p></div>
            <span class="checkin-step">Step 3 of 3</span>
          </div>
          <div class="card-body">
            <p class="checkin-deposit-note">Leave the amount at zero if no deposit is collected. Split tender and cheque details are available when needed.</p>
            <div id="checkinAdvanceSingle">
              <x-field name="advance" label="Amount" type="number" step="0.01" min="0" value="0" hint="Optional; enter zero to skip."/><div class="mt-1"></div>
              <x-field name="advance_mode" label="Payment mode" type="select" :options="$paymentModes" data-payment-mode/><div class="mt-1"></div>
              <x-payment-momo-fields mode-name="advance_mode"/>
              <div class="mt-1"></div><x-field name="advance_ref" label="Reference / transaction ID" hint="Receipt, mobile-money or bank reference, if applicable."/>
              <x-cheque-fields/>
            </div>
            <x-payment-split-tender widget-id="checkinAdvanceSplit" single-id="checkinAdvanceSingle" :modes="$paymentModes" label="deposit"/>
          </div>
        </section>
        <div class="checkin-complete">
          <h2>Ready to check in?</h2>
          <p>Confirm the selected room and guest identity details. The room’s housekeeping state will not be changed by check-in.</p>
          @if($rooms->isEmpty())<p class="checkin-no-room">No room can currently be selected, so check-in is disabled.</p>@endif
          <button type="submit" class="btn btn-success btn-lg" @disabled($rooms->isEmpty())><x-icon name="login"/> Complete check-in</button>
        </div>
      </aside>
    </div>
  </form>

  <script>
    (() => {
      const form = document.getElementById('checkInForm');
      const roomSelect = document.getElementById('checkinRoom');
      const roomSummary = document.getElementById('checkin-room-summary');
      const readiness = { inspected:'Inspected and ready', clean:'Clean', dirty:'Dirty — confirm with the guest', touch_up:'Touch-up needed — confirm with the guest' };
      const updateRoomSummary = () => {
        const option = roomSelect?.selectedOptions?.[0];
        if (!roomSummary) return;
        roomSummary.textContent = option?.value
          ? `Selected room ${option.dataset.roomNo}. ${readiness[option.dataset.hkStatus] || 'Housekeeping status unavailable'}.`
          : 'Choose a room to see its readiness.';
      };
      roomSelect?.addEventListener('change', updateRoomSummary);
      updateRoomSummary();
      form?.addEventListener('submit', event => {
        const option = roomSelect?.selectedOptions?.[0];
        const status = option?.dataset.hkStatus;
        if (!['dirty', 'touch_up'].includes(status)) {
          delete form.dataset.confirm;
          return;
        }
        const label = status === 'touch_up' ? 'Touch-up' : 'Dirty';
        form.dataset.confirm = `Room ${option.dataset.roomNo} is ${label}. Check-in will not clean the room. Continue?`;
      });
    })();
  </script>
</div>
@if(can_do('upsell.sell'))@include('upsell._checkin')@endif
@endsection
