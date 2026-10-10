@extends('layouts.app')
@section('title', 'My Roster & Time Clock')
@section('crumbs') Self-service / Roster &amp; time clock @endsection
@section('actions')<a class="btn" href="{{ route('me.hr') }}"><x-icon name="id"/> Payslips &amp; leave</a>@endsection
@section('content')
@php $todayShift = $shifts[$today] ?? null; @endphp
<div class="grid g-2">
  <div class="card"><div class="card-head"><h3><x-icon name="clock"/> Time clock</h3></div><div class="card-body center" id="myClock">
    <div class="clock-big">{{ now()->format('H:i') }}</div>
    <div class="small muted mb-1">Today: @if($todayShift?->isShift())<b>{{ $todayShift->code() }}</b> {{ $todayShift->label() }}@elseif($todayShift) day off @else not rostered @endif</div>
    @if($open)
      <div class="alert alert-success" id="onDuty"><x-icon name="check"/><div>On duty since <b>{{ $open->clock_in->format('H:i') }}</b>{{ $open->late_minutes ? ' · '.$open->late_minutes.' min late' : '' }}</div></div>
    @endif
    @if(! $selfOn)
      <p class="small muted">Self-service clocking is switched off – use the kiosk.</p>
    @elseif(! $ipOk)
      <p class="small text-red">Clocking in is only allowed from the hotel network – use the kiosk.</p>
    @else
      <form method="post" action="{{ route('me.clock') }}">@csrf<button class="btn {{ $open ? '' : 'btn-primary' }}" style="font-size:1.1rem;padding:.7rem 2rem" id="clockToggle"><x-icon name="{{ $open ? 'logout' : 'login' }}"/> {{ $open ? 'Clock out' : 'Clock in' }}</button></form>
    @endif
  </div></div>
  <div class="card"><div class="card-head"><h3><x-icon name="key"/> Kiosk PIN</h3></div><div class="card-body">
    <p class="small">{{ $hasPin ? 'You have a kiosk PIN.' : 'You have no kiosk PIN yet.' }} Clock in at the shared kiosk with your code <b>{{ $e->emp_code }}</b> and PIN.</p>
    <form method="post" action="{{ route('me.pin') }}" class="form-grid" id="myPin">@csrf
      <div class="field"><label for="mp_pin">New PIN (4–6 digits)</label><input type="password" name="pin" id="mp_pin" inputmode="numeric" pattern="[0-9]{4,6}" required autocomplete="new-password"></div>
      <div class="field"><label for="mp_pin2">Repeat PIN</label><input type="password" name="pin_confirmation" id="mp_pin2" inputmode="numeric" pattern="[0-9]{4,6}" required autocomplete="new-password"></div>
      <div class="field"><label for="mp_pw">Your login password</label><input type="password" name="password" id="mp_pw" required autocomplete="current-password"></div>
      <div><button class="btn">Change PIN</button></div>
    </form>
  </div></div>
</div>

<div class="card flush mt-2"><div class="card-head"><h3><x-icon name="calendar"/> My shifts</h3><span class="tiny muted">published rosters only</span></div>
  <div class="card-body table-wrap"><table class="table" id="myShifts">
    <thead><tr><th>Date</th><th>Shift</th><th>Time</th><th class="num">Paid h</th><th></th></tr></thead><tbody>
    @foreach($days as $d)
      @php $s = $shifts[$d] ?? null; $c = \Carbon\Carbon::parse($d); @endphp
      @if($s || $d >= $today)
      <tr class="{{ $d === $today ? 'bold' : ($d < $today ? 'muted' : '') }}" data-day="{{ $d }}"><td>{{ $c->format('D d M') }}{{ $d === $today ? ' · today' : '' }}</td>
        <td>@if($s?->isShift())<span style="display:inline-block;width:.7rem;height:.7rem;border-radius:2px;background:{{ $s->template?->color ?? '#64748b' }}"></span> <b>{{ $s->code() }}</b> {{ $s->template?->name }}@elseif($s) OFF @else <span class="muted">—</span> @endif</td>
        <td>{{ $s?->isShift() ? $s->label() : '' }}</td><td class="num">{{ $s?->isShift() ? number_format($s->paidMinutes() / 60, 1) : '' }}</td>
        <td>@if($swappable->has($d))<button class="btn btn-sm" data-modal="swapModal" data-f-roster_shift_id="{{ $s->id }}" data-title="Swap {{ $c->format('D d M') }} · {{ $s->code() }}" data-swap-day="{{ $d }}">Swap…</button>@endif</td></tr>
      @endif
    @endforeach
    </tbody></table></div></div>

<div class="card flush mt-2"><div class="card-head"><h3><x-icon name="swap"/> Swap requests</h3></div>
  <div class="card-body table-wrap"><table class="table" id="mySwaps">
    <thead><tr><th>Shift</th><th>Between</th><th>Status</th><th></th></tr></thead><tbody>
    @forelse($swaps as $s)
      <tr data-swap="{{ $s->id }}"><td class="small">{{ $s->shift ? dmy($s->shift->date).' '.$s->shift->code() : '—' }}{{ $s->colleagueShift ? ' ⇄ '.dmy($s->colleagueShift->date).' '.$s->colleagueShift->code() : ' (hand-over)' }}</td>
        <td class="small">{{ $s->requester?->name }} → {{ $s->colleague?->name }}@if($s->reason)<div class="tiny muted">{{ $s->reason }}</div>@endif</td>
        <td><span class="badge {{ ['pending_peer' => 'badge-slate', 'pending_manager' => 'badge-amber', 'approved' => 'badge-green'][$s->status] ?? 'badge-red' }}">{{ str_replace('_', ' ', $s->status) }}</span></td>
        <td class="nowrap">
          @if($s->status === 'pending_peer' && $s->colleague_id === $e->id)
            <form method="post" action="{{ route('me.swap.respond', $s) }}" style="display:inline">@csrf<input type="hidden" name="accept" value="1"><button class="btn btn-sm btn-primary" data-accept-swap="{{ $s->id }}">Accept</button></form>
            <form method="post" action="{{ route('me.swap.respond', $s) }}" style="display:inline">@csrf<input type="hidden" name="accept" value="0"><button class="btn btn-sm">Decline</button></form>
          @elseif(in_array($s->status, ['pending_peer', 'pending_manager']) && $s->requester_id === $e->id)
            <form method="post" action="{{ route('me.swap.cancel', $s) }}" style="display:inline">@csrf<button class="btn btn-sm">Withdraw</button></form>
          @endif</td></tr>
    @empty
      <tr><td colspan="4"><x-empty text="No swap requests." icon="swap"/></td></tr>
    @endforelse
    </tbody></table></div></div>

<div class="card flush mt-2"><div class="card-head"><h3><x-icon name="clock"/> My time punches (14 days)</h3></div>
  <div class="card-body table-wrap"><table class="table" id="myPunches">
    <thead><tr><th>Day</th><th>In</th><th>Out</th><th class="num">Hours</th><th>Notes</th></tr></thead><tbody>
    @forelse($punches as $p)
      <tr><td>{{ $p->work_date->format('D d M') }}</td><td>{{ $p->clock_in->format('H:i') }}</td><td>{{ $p->clock_out?->format('H:i') ?? 'on duty' }}</td><td class="num">{{ $p->clock_out ? number_format($p->hours(), 2) : '' }}</td>
        <td class="tiny">{{ $p->late_minutes ? "late {$p->late_minutes} min · " : '' }}{{ $p->edit_reason ? 'corrected: '.$p->edit_reason.' · ' : '' }}{{ $p->status === 'approved' ? 'approved' : '' }}</td></tr>
    @empty
      <tr><td colspan="5"><x-empty text="No punches yet." icon="clock"/></td></tr>
    @endforelse
    </tbody></table></div></div>

<div class="modal" id="swapModal"><div class="modal-box"><form method="post" action="{{ route('me.swap') }}" data-reset>@csrf
  <input type="hidden" name="roster_shift_id" value="">
  <div class="modal-head"><h3 data-title>Swap shift</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">
    <x-field name="colleague_id" label="Swap with" type="select" :options="$colleagues" required/>
    <x-field name="reason" label="Reason" maxlength="255" placeholder="e.g. family function"/>
    <p class="tiny muted">If your colleague works that day you exchange shifts; if they are off, they take your shift. They accept first, then a manager approves.</p>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="swapSend">Send request</button></div>
</form></div></div>
@endsection
