@extends('layouts.app')
@section('title', 'Room Move · '.$stay->reservation->guest?->full_name)
@section('crumbs')
  @if($canViewReservation)<a href="{{ route('reservations.show', $stay->reservation_id) }}">{{ $stay->reservation->reservation_no }}</a>
  @elseif(can_do('frontdesk.view'))<a href="{{ route('frontdesk.inhouse') }}">In-house</a>
  @else<span>Front Office</span>@endif / Room move
@endsection
@section('actions')
  @if(can_do('frontdesk.view'))<a class="btn btn-soft" href="{{ route('frontdesk.inhouse') }}"><x-icon name="users"/> In-house</a>@endif
  @if($canViewReservation)<a class="btn" href="{{ route('reservations.show', $stay->reservation_id) }}"><x-icon name="eye"/> Reservation</a>@endif
  @if(can_do('frontdesk.checkout'))<a class="btn btn-warning" href="{{ route('frontdesk.checkout', $stay) }}"><x-icon name="logout"/> Check out</a>@endif
@endsection
@section('content')
@php
  $guestName = $stay->reservation->guest?->full_name ?? 'Guest';
  $roomNo = $stay->room?->room_no ?? 'Unassigned';
  $roomTypeName = $stay->room?->roomType?->name ?? $stay->roomType?->name ?? 'Room type not set';
  $nights = $stayNights ?? max(1, (int) $stay->arrival_date->diffInDays($stay->departure_date));
  $currentRate = (float) $stay->rate;
@endphp
<style>
  .roommove-page { --move-green:#17623b; color:var(--c-text); }
  .roommove-hero { display:flex; justify-content:space-between; align-items:flex-start; gap:1rem; margin-bottom:1rem; padding:1.05rem 1.15rem; border:1px solid #d7e8db; border-radius:15px; background:linear-gradient(115deg,#eef8f0 0%,var(--c-surface) 74%); }
  .roommove-kicker { display:flex; align-items:center; gap:.42rem; color:var(--move-green); font-size:.7rem; font-weight:800; letter-spacing:.09em; text-transform:uppercase; }.roommove-kicker .i{width:15px;height:15px}
  .roommove-hero h2 { margin:.35rem 0 .22rem; color:var(--c-text); font-size:1.38rem; line-height:1.2; letter-spacing:-.02em; }
  .roommove-hero p { max-width:690px; margin:.25rem 0 0; color:var(--c-text-2); font-size:.84rem; line-height:1.48; }
  .roommove-context { display:flex; flex-wrap:wrap; align-items:center; gap:.4rem; margin-top:.52rem; color:var(--c-text-2); font-size:.77rem; }.roommove-context .i{width:13px;height:13px}
  .roommove-dates { display:grid; grid-template-columns:repeat(2,minmax(110px,1fr)); gap:.45rem; flex:0 0 auto; }
  .roommove-date { padding:.58rem .68rem; border:1px solid #dcebe0; border-radius:10px; background:rgba(255,255,255,.86); }
  .roommove-date span { display:block; color:var(--c-text-2); font-size:.65rem; font-weight:750; letter-spacing:.05em; text-transform:uppercase; }
  .roommove-date time { display:block; margin-top:.2rem; color:#24583a; font-size:.82rem; font-weight:750; font-variant-numeric:tabular-nums; white-space:nowrap; }
  .roommove-metrics { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:.65rem; margin-bottom:1rem; }
  .roommove-metric { min-width:0; padding:.72rem .82rem; border:1px solid var(--c-border); border-top:3px solid #17623b; border-radius:12px; background:var(--c-surface); box-shadow:var(--shadow-sm); }
  .roommove-metric:nth-child(2){border-top-color:#2563eb}.roommove-metric:nth-child(3){border-top-color:#d97706}.roommove-metric:nth-child(4){border-top-color:#7c3aed}
  .roommove-metric b { display:block; overflow:hidden; font-size:1.03rem; line-height:1.2; font-weight:780; text-overflow:ellipsis; white-space:nowrap; font-variant-numeric:tabular-nums; }
  .roommove-metric span { display:block; margin-top:.2rem; color:var(--c-text-2); font-size:.72rem; }
  .roommove-forms { display:grid; grid-template-columns:minmax(0,1.08fr) minmax(0,.92fr); align-items:start; gap:.85rem; }
  .roommove-card { overflow:hidden; margin-bottom:.9rem; }
  .roommove-card .card-head { align-items:flex-start; }
  .roommove-card .card-head h3 { display:flex; align-items:center; gap:.45rem; margin:0; }
  .roommove-card .card-head p { margin:.3rem 0 0; color:var(--c-text-2); font-size:.78rem; line-height:1.45; }
  .roommove-step { display:inline-flex; align-items:center; justify-content:center; width:23px; height:23px; border-radius:8px; background:#e9f5ed; color:#17623b; font-size:.7rem; font-weight:800; }
  .roommove-page .field label { display:block; margin-bottom:.28rem; font-weight:650; }
  .roommove-page .field select,.roommove-page .field input { width:100%; }
  .roommove-hint { margin:.32rem 0 0; color:var(--c-text-2); font-size:.73rem; line-height:1.45; }
  .roommove-preview { margin-top:.75rem; padding:.7rem .78rem; border:1px solid #d5e9da; border-radius:10px; background:#f1f8f3; color:#31483a; font-size:.78rem; line-height:1.5; }
  .roommove-preview[hidden] { display:none; }
  .roommove-preview strong { color:#165b36; }
  .roommove-callout { display:flex; gap:.55rem; margin-top:.75rem; padding:.7rem .78rem; border:1px solid #d8e4f3; border-radius:10px; background:#f4f8fd; color:#40526a; font-size:.76rem; line-height:1.45; }
  .roommove-callout .i { flex:0 0 auto; width:16px; height:16px; margin-top:.05rem; }
  .roommove-empty { padding:.9rem; border:1px dashed var(--c-border-strong); border-radius:10px; color:var(--c-text-2); font-size:.82rem; }
  .roommove-history { margin-top:.35rem; }
  .roommove-history-table { width:100%; min-width:690px; border-collapse:collapse; font-size:.78rem; }
  .roommove-history-table th { padding:.62rem .7rem; border-bottom:1px solid var(--c-border); background:#f5f8f6; color:#53645a; text-align:left; font-size:.66rem; letter-spacing:.05em; text-transform:uppercase; white-space:nowrap; }
  .roommove-history-table td { padding:.68rem .7rem; border-bottom:1px solid var(--c-border); vertical-align:top; }
  .roommove-history-table tr:last-child td { border-bottom:0; }
  .roommove-history-table .num { text-align:right; white-space:nowrap; font-variant-numeric:tabular-nums; }
  body.dark .roommove-hero { border-color:var(--c-border); background:linear-gradient(115deg,#172c1f 0%,var(--c-surface) 75%); }
  body.dark .roommove-kicker { color:#bce8c9; }
  body.dark .roommove-date { border-color:var(--c-border); background:#1b2d21; }
  body.dark .roommove-date time { color:#bce8c9; }
  body.dark .roommove-step { background:#20392a; color:#bce8c9; }
  body.dark .roommove-preview { border-color:#34563e; background:#18271e; color:var(--c-text-2); }
  body.dark .roommove-preview strong { color:#bce8c9; }
  body.dark .roommove-callout { border-color:var(--c-border); background:#1b2a3b; color:#c4d4e9; }
  body.dark .roommove-history-table th { background:#18271e; color:var(--c-text-2); }
  @media(max-width:1050px) { .roommove-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.roommove-forms{grid-template-columns:1fr} }
  @media(max-width:650px) { .roommove-hero{display:block;padding:.9rem}.roommove-hero h2{font-size:1.2rem}.roommove-dates{margin-top:.75rem}.roommove-metrics{gap:.5rem}.roommove-metric{padding:.64rem .7rem}.roommove-metric b{font-size:.92rem}.roommove-page .table-wrap{overflow-x:auto}.roommove-history-table{min-width:650px}.roommove-card .card-body{padding:.85rem}.roommove-card .card-foot{padding:.7rem .85rem} }
  @media(prefers-reduced-motion:reduce) { .roommove-page * { scroll-behavior:auto!important; transition:none!important; } }
  @media print { .roommove-page .roommove-hero{background:#fff;border-color:#888}.roommove-page .roommove-date{background:#fff;border-color:#888}.roommove-page .roommove-metrics{grid-template-columns:repeat(4,1fr)}.roommove-page .roommove-forms,.roommove-page .no-print{display:none!important}.roommove-history{box-shadow:none}.roommove-history-table{min-width:0;font-size:8pt}.roommove-history-table th,.roommove-history-table td{padding:4px}.roommove-page .card{break-inside:avoid} }
</style>
<div class="roommove-page">
  <section class="roommove-hero" aria-label="Room move and stay amendment overview">
    <div>
      <div class="roommove-kicker"><x-icon name="swap"/> Front office · In-house stay service</div>
      <h2>Room move &amp; stay amendment</h2>
      <p>Move {{ $guestName }} to a verified vacant room, or update the scheduled departure. Availability is checked again when you save; changing room or dates does not post a folio charge.</p>
      <div class="roommove-context"><x-icon name="user"/> {{ $guestName }} · Reservation <strong>{{ $stay->reservation->reservation_no }}</strong> · In room <strong>{{ $roomNo }}</strong> · {{ $roomTypeName }} {!! status_badge('checked_in') !!}</div>
    </div>
    <div class="roommove-dates" aria-label="Current stay dates">
      <div class="roommove-date"><span>Arrived</span><time datetime="{{ $stay->arrival_date->toDateString() }}">{{ $stay->arrival_date->format('d M Y') }}</time></div>
      <div class="roommove-date"><span>Scheduled departure</span><time datetime="{{ $stay->departure_date->toDateString() }}">{{ $stay->departure_date->format('d M Y') }}</time></div>
    </div>
  </section>

  <section class="roommove-metrics" aria-label="Current stay summary">
    <div class="roommove-metric"><b>{{ $roomNo }}</b><span>Current room</span></div>
    <div class="roommove-metric"><b>{{ $rooms->count() }}</b><span>Eligible vacant rooms through {{ $moveUntil->format('d M') }}</span></div>
    <div class="roommove-metric"><b>{{ number_format($nights) }}</b><span>Scheduled room nights</span></div>
    <div class="roommove-metric"><b>{{ money($currentRate) }}</b><span>Current rate per night</span></div>
  </section>

  <div class="roommove-forms">
    <form method="post" action="{{ route('frontdesk.move.store', $stay) }}" class="card roommove-card" id="roomMoveForm" data-confirm="Move this checked-in guest to the selected room? The move will be recorded in the stay history.">@csrf
      <div class="card-head"><div><h3><span class="roommove-step">1</span> Move to another room</h3><p>The current room is released as vacant and dirty; the destination becomes occupied after a successful move.</p></div></div>
      <div class="card-body">
        @if($rooms->isEmpty())
          <div class="roommove-empty" role="status">No vacant rooms are currently eligible through {{ $moveUntil->format('d M Y') }}. Check the room board or resolve a room block before moving this guest.</div>
        @else
          <div class="field">
            <label for="moveToRoom">New room <span class="req">*</span></label>
            <select name="to_room_id" id="moveToRoom" required aria-describedby="moveRoomHint">
              <option value="">Select a vacant room</option>
              @foreach($rooms as $r)
                <option value="{{ $r->id }}" data-room-no="{{ $r->room_no }}" data-room-type="{{ $r->roomType->name }}" data-base-rate="{{ money($r->roomType->base_rate) }}" data-hk-status="{{ str_replace('_', ' ', $r->hk_status ?? 'unknown') }}" @selected((string) old('to_room_id') === (string) $r->id)>{{ $r->room_no }} · {{ $r->roomType->name }}{{ $r->hk_status === 'dirty' ? ' · Dirty' : '' }}</option>
              @endforeach
            </select>
            <p class="roommove-hint" id="moveRoomHint">The server rechecks vacancy, overlapping stays, leases and room outages when you confirm.</p>
            @error('to_room_id')<p class="field-error" role="alert">{{ $message }}</p>@enderror
          </div>
          <div class="roommove-preview" id="moveRoomPreview" aria-live="polite" hidden></div>
        @endif
        <div class="field mt-1">
          <label for="moveNewRate">New nightly rate <span class="muted">(optional)</span></label>
          <input id="moveNewRate" name="new_rate" type="number" min="0" step="0.01" value="{{ old('new_rate') }}" placeholder="Leave blank to keep {{ money($currentRate) }}" aria-describedby="moveRateHint">
          <p class="roommove-hint" id="moveRateHint">Changing room keeps the current rate unless you enter a new one. The destination’s base rate is only a reference.</p>
          @error('new_rate')<p class="field-error" role="alert">{{ $message }}</p>@enderror
        </div>
        <div class="field mt-1">
          <label for="moveReason">Reason <span class="req">*</span></label>
          <select name="reason" id="moveReason" required>
            <option value="">Select a reason</option>
            @foreach(['Guest request','Upgrade','Maintenance issue','Noise complaint','Overbooking'] as $reason)
              <option value="{{ $reason }}" @selected(old('reason') === $reason)>{{ $reason }}</option>
            @endforeach
          </select>
          @error('reason')<p class="field-error" role="alert">{{ $message }}</p>@enderror
        </div>
      </div>
      <div class="card-foot"><button class="btn btn-primary" @disabled($rooms->isEmpty())><x-icon name="swap"/> Move room</button></div>
    </form>

    <form method="post" action="{{ route('frontdesk.amend', $stay) }}" class="card roommove-card" id="amendStayForm" data-confirm="Save the new departure date or nightly rate? No folio charge will be posted by this change.">@csrf
      <div class="card-head"><div><h3><span class="roommove-step">2</span> Amend the stay</h3><p>Change the planned departure date and/or room rate. The current arrival date and room remain unchanged.</p></div></div>
      <div class="card-body">
        <div class="roommove-callout"><x-icon name="info"/><div>This guest is already checked in. A new departure must be after the business date. To finish the stay today, use Check-out instead.</div></div>
        <div class="field mt-1">
          <label for="amendDeparture">New departure date <span class="req">*</span></label>
          <input id="amendDeparture" name="departure_date" type="date" min="{{ $businessDate->copy()->addDay()->toDateString() }}" value="{{ old('departure_date', $stay->departure_date->toDateString()) }}" required>
          @error('departure_date')<p class="field-error" role="alert">{{ $message }}</p>@enderror
        </div>
        <div class="field mt-1">
          <label for="amendRate">Rate per night</label>
          <input id="amendRate" name="rate" type="number" min="0" step="0.01" value="{{ old('rate', $currentRate) }}" aria-describedby="amendRateHint">
          <p class="roommove-hint" id="amendRateHint">The updated rate applies to future night-audit postings; this form does not add or reverse folio transactions.</p>
          @error('rate')<p class="field-error" role="alert">{{ $message }}</p>@enderror
        </div>
      </div>
      <div class="card-foot"><button class="btn btn-accent"><x-icon name="calendar"/> Save amendment</button></div>
    </form>
  </div>

  <section class="card flush roommove-history" aria-labelledby="moveHistoryTitle">
    <div class="card-head"><div><h3 id="moveHistoryTitle">Room move history</h3><p class="small muted">Audited room transfers recorded for this stay, including any rate set during each move.</p></div><span class="small muted">{{ $stay->moves->count() }} record(s)</span></div>
    <div class="card-body table-wrap">
      <table class="roommove-history-table"><thead><tr><th scope="col">When</th><th scope="col">From</th><th scope="col">To</th><th scope="col" class="num">Old rate</th><th scope="col" class="num">New rate</th><th scope="col">Reason</th><th scope="col">By</th></tr></thead><tbody>
        @forelse($stay->moves as $m)
          <tr><td class="nowrap">{{ $m->created_at->format('d M Y H:i') }}</td><td>{{ $m->fromRoom?->room_no ?? '—' }}</td><td>{{ $m->toRoom?->room_no ?? '—' }}</td><td class="num">{{ money($m->old_rate) }}</td><td class="num">{{ money($m->new_rate) }}</td><td>{{ $m->reason ?: '—' }}</td><td>{{ $m->user?->name ?? '—' }}</td></tr>
        @empty
          <tr><td colspan="7" class="muted center">No room moves have been recorded for this stay.</td></tr>
        @endforelse
      </tbody></table>
    </div>
  </section>
</div>
@endsection
@push('scripts')
<script>
(() => {
  const select = document.getElementById('moveToRoom');
  const preview = document.getElementById('moveRoomPreview');
  const newRate = document.getElementById('moveNewRate');
  const rateHint = document.getElementById('moveRateHint');
  if (!select || !preview) return;
  const currentRate = @json(money($currentRate));
  const update = () => {
    const option = select.selectedOptions[0];
    if (!option || !option.value) {
      preview.hidden = true;
      return;
    }
    const typeChanged = option.dataset.roomType !== @json($roomTypeName);
    const rateMessage = newRate?.value
      ? `A new rate of ${newRate.value} per night will be saved.`
      : `The current rate of ${currentRate} per night will be retained.`;
    const heading = document.createElement('strong');
    heading.textContent = 'Selected: ';
    preview.replaceChildren(heading, document.createTextNode(`Room ${option.dataset.roomNo}, ${option.dataset.roomType}. Base rate: ${option.dataset.baseRate}. Housekeeping: ${option.dataset.hkStatus}. ${typeChanged ? 'This changes the assigned room type; the rate stays unchanged unless you set a new one. ' : ''}${rateMessage}`));
    preview.hidden = false;
    if (rateHint) rateHint.textContent = newRate?.value
      ? 'The entered nightly rate will be recorded with this move.'
      : 'The destination base rate is a reference only; the current nightly rate is retained unless you enter a new amount.';
  };
  select.addEventListener('change', update);
  newRate?.addEventListener('input', update);
  update();
})();
</script>
@endpush
