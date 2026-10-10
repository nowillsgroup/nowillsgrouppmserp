@extends('layouts.app')
@section('title', $c->name.' – '.$date->format('D d M'))
@section('crumbs') <a href="{{ route('club.index') }}">Health Club</a> / <a href="{{ route('club.classes', ['week' => $date->toDateString()]) }}">Timetable</a> / {{ $c->name }} {{ $date->format('d/m') }} @endsection
@section('content')
@php $past = $start->lt(now()); @endphp
<div class="stats">
  <x-stat :label="$c->name" :value="$start->format('D d M H:i')" icon="clock" :sub="$c->duration_min.' min · '.(\App\Models\ClubPlan::FACILITIES[$c->facility] ?? '').' · '.$c->instructorName()"/>
  <x-stat label="Booked" :value="$taken.' / '.$c->capacity" icon="users" :tone="$taken >= $c->capacity ? 'amber' : 'green'" :sub="$bookings->where('status', 'waitlist')->count().' on the waitlist'"/>
  <x-stat label="Attended" :value="$bookings->where('status', 'attended')->count()" icon="check" tone="purple" :sub="$bookings->where('status', 'no_show')->count().' no-show(s)'"/>
</div>
<div class="fs-split">
  <div class="card flush"><div class="card-head"><h3>Register</h3></div><div class="card-body table-wrap">
    <form method="post" action="{{ route('club.classes.attendance', [$c, $date->toDateString()]) }}" id="registerForm">@csrf
    <table class="table" id="bookingTable"><thead><tr><th>#</th><th>Name</th><th>Status</th><th>Attendance</th><th></th></tr></thead>
      <tbody>@forelse($bookings as $i => $b)
        <tr data-booking="{{ $b->id }}" class="{{ in_array($b->status, ['cancelled']) ? 'muted' : '' }}"><td class="small">{{ $b->status === 'waitlist' ? 'W'.$b->position : $i + 1 }}</td>
          <td>{{ $b->name }}<div class="tiny muted">{{ $b->member ? $b->member->membership->membership_no : ($b->stay ? 'Hotel guest' : '') }}</div></td>
          <td><span class="badge badge-{{ ['booked' => 'blue', 'attended' => 'green', 'no_show' => 'red', 'waitlist' => 'amber', 'cancelled' => 'slate'][$b->status] }}">{{ \App\Models\ClubBooking::STATUSES[$b->status] }}</span></td>
          <td>@if(in_array($b->status, ['booked', 'attended', 'no_show']) && can_do('club.desk'))<select name="mark[{{ $b->id }}]" aria-label="Attendance {{ $b->name }}"><option value="">—</option><option value="attended" @selected($b->status === 'attended')>Attended</option><option value="no_show" @selected($b->status === 'no_show')>No-show</option></select>@endif</td>
          <td class="num">@if(in_array($b->status, ['booked', 'waitlist']) && ! $past && can_do('club.desk'))<button type="submit" form="cancelB{{ $b->id }}" class="btn btn-sm btn-ghost">Cancel</button>@endif</td></tr>
      @empty <tr><td colspan="5"><x-empty text="No bookings yet." icon="users"/></td></tr> @endforelse</tbody></table>
    @if($bookings->whereIn('status', ['booked', 'attended', 'no_show'])->isNotEmpty() && can_do('club.desk'))<div class="card-foot"><button class="btn btn-primary" id="registerSave">Save register</button> <span class="tiny muted">from 15 minutes before the start</span></div>@endif
    </form>
    @foreach($bookings->whereIn('status', ['booked', 'waitlist']) as $b)<form method="post" action="{{ route('club.bookings.cancel', $b) }}" id="cancelB{{ $b->id }}">@csrf</form>@endforeach
  </div></div>
  <div>
    @if(! $past && can_do('club.desk'))
    <div class="card" id="bookCard"><div class="card-head"><h3>Book a place</h3></div><div class="card-body">
      <form method="post" action="{{ route('club.classes.book', [$c, $date->toDateString()]) }}">@csrf
        <x-field name="card" label="Member card / membership no." placeholder="8xxxxxxxxx"/>
        @if($c->residents)<x-field name="room_no" label="…or hotel guest room no." placeholder="101"/>@endif
        <button class="btn btn-primary" id="bookSave">Book</button>
      </form>
      <p class="tiny muted">Full sessions go to the waitlist and move up automatically. Cancelling less than {{ \App\Services\ClubService::cfg('club_class_cancel_hours') }} h before the start counts as a no-show; {{ \App\Services\ClubService::cfg('club_noshow_limit') }} no-shows in 30 days put bookings on hold.</p>
    </div></div>
    @else<div class="card"><div class="card-body small muted">This session has started – bookings are closed.</div></div>@endif
  </div>
</div>
@endsection
