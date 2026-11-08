@extends('layouts.app')
@section('title', 'Find a venue')
@section('crumbs') <a href="{{ route('banquet.index') }}">Banquet</a> / Find a venue @endsection
@section('actions')
<a class="btn btn-primary" href="{{ route('banquet.create', array_filter(['hall' => $firstFreeHall, 'date' => $date, 'start' => $start, 'end' => $end, 'pax' => $pax ?: null, 'style' => $style])) }}"><x-icon name="plus"/> New enquiry</a>
@endsection
@push('head')
<style>
.bq-find{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px;align-items:end}
.bq-find .field{margin:0}
.bq-venue{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:4px 16px;align-items:center;padding:12px 14px;border:1px solid var(--c-border);border-radius:12px;background:var(--c-surface)}
.bq-venue + .bq-venue{margin-top:8px}
.bq-venue.is-free{border-left:4px solid #15803d}
.bq-venue.is-held{border-left:4px solid #b45309;background:linear-gradient(100deg,#fffdf7,var(--c-surface) 60%)}
.bq-venue.is-small{border-left:4px solid #9ca3af}
.bq-venue h3{margin:0;font-size:.95rem}
.bq-venue p{margin:2px 0 0;color:var(--c-muted);font-size:.76rem}
.bq-venue .bq-venue-actions{display:flex;align-items:center;gap:8px;grid-row:1/3;grid-column:2}
.bq-venue-facts{display:flex;flex-wrap:wrap;gap:4px 14px;grid-column:1;color:var(--c-text-2);font-size:.75rem}
.bq-venue-facts b{color:var(--c-text)}
.bq-why{display:block;margin-top:5px;padding:5px 8px;border-radius:7px;background:#fff6e7;color:#7c4708;font-size:.73rem}
.bq-why.is-small{background:#f4f5f6;color:#4b5563}
.bq-free-note{margin:0 0 10px;color:var(--c-muted);font-size:.78rem}
@media(max-width:1000px){.bq-find{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:640px){.bq-find{grid-template-columns:repeat(2,minmax(0,1fr))}.bq-venue{grid-template-columns:1fr}.bq-venue .bq-venue-actions{grid-row:auto;grid-column:1}}
</style>
@endpush
@section('content')
<div class="card"><div class="card-body">
  <form class="bq-find" method="get" action="{{ route('banquet.availability') }}">
    <div class="field"><label for="faDate">Date</label><input class="input" id="faDate" type="date" name="date" value="{{ $date }}"></div>
    <div class="field"><label for="faStart">From</label><input class="input" id="faStart" type="time" name="start" value="{{ $start }}"></div>
    <div class="field"><label for="faEnd">To</label><input class="input" id="faEnd" type="time" name="end" value="{{ $end }}"></div>
    <div class="field"><label for="faPax">Guests</label><input class="input" id="faPax" type="number" min="0" max="10000" name="pax" value="{{ $pax ?: '' }}" placeholder="180"></div>
    <div class="field"><label for="faStyle">Set-up</label><select class="input" id="faStyle" name="style">@foreach($setups as $k => $v)<option value="{{ $k }}" @selected($style === $k)>{{ $v }}</option>@endforeach</select></div>
    <div class="field"><button class="btn btn-primary" id="faGo"><x-icon name="search"/> Check venues</button></div>
  </form>
</div></div>

@if(! $searched)
  <div class="card mt-2"><div class="card-body">
    <p class="bq-free-note">Give the desk the date, the clock and the number of guests and every venue answers: free, too small for that set-up, or already held — and by whom.</p>
    <p class="tiny muted">A venue needs {{ $turnaround }} minutes to turn round between functions, so a room free at 18:30 is not free for a function starting at 19:00.</p>
  </div></div>
@else
  <div class="card mt-2"><div class="card-head"><h3>{{ $freeCount }} {{ $freeCount === 1 ? 'venue is' : 'venues are' }} free</h3>
    <span class="small muted">{{ \Carbon\Carbon::parse($date)->format('D d M Y') }} · {{ $start }}–{{ $end }} @if($pax) · {{ number_format($pax) }} guests @endif · {{ $setups[$style] }}</span></div>
    <div class="card-body">
      <p class="bq-free-note" id="faSummary">{{ $freeCount }} free · {{ $heldCount }} held for another event · {{ $smallCount }} too small for {{ $setups[$style] }} at {{ number_format($pax) }} guests. Turn-around: {{ $turnaround }} minutes.</p>

      @foreach($rows as $row)
        <article class="bq-venue is-{{ $row['status'] }}" aria-labelledby="faHall{{ $row['hall']->id }}">
          <div>
            <h3 id="faHall{{ $row['hall']->id }}">{{ $row['hall']->name }}
              @if($row['status'] === 'free')<span class="badge badge-green">Free</span>
              @elseif($row['status'] === 'held')<span class="badge badge-amber">Held</span>
              @else<span class="badge badge-slate">Too small</span>@endif
            </h3>
            <div class="bq-venue-facts">
              <span>Capacity <b>{{ number_format($row['capacity']) }}</b> · {{ $setups[$style] }}</span>
              <span>Rent <b>{{ money($row['rent']) }}</b> for {{ $start }}–{{ $end }}</span>
            </div>
            @if($row['status'] === 'held')
              <span class="bq-why">{{ $row['held']->event->event_no }} · {{ $row['held']->event->title ?: $row['held']->event->contact_name }} holds this room {{ $row['held']->timeRange() }} ({{ $row['held']->event->status }}). Allow {{ $turnaround }} minutes to turn it round.</span>
            @elseif($row['status'] === 'small')
              <span class="bq-why is-small">Too small: {{ number_format($row['capacity']) }} at {{ $setups[$style] }} — {{ number_format(max(0, $pax - $row['capacity'])) }} guests over.</span>
            @endif
          </div>
          <div class="bq-venue-actions">
            @if($row['status'] === 'free')
              <a class="btn btn-sm btn-primary" href="{{ route('banquet.create', ['hall' => $row['hall']->id, 'date' => $date, 'start' => $start, 'end' => $end, 'pax' => $pax ?: null, 'style' => $style]) }}"><x-icon name="plus"/> Take the enquiry</a>
            @else
              <a class="btn btn-sm" href="{{ route('banquet.diary', ['start' => $date]) }}"><x-icon name="calendar"/> What is on that day</a>
            @endif
          </div>
        </article>
      @endforeach
    </div>
  </div>
@endif
@endsection
