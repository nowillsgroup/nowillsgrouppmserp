@extends('layouts.app')
@section('title', 'Function Diary')
@section('crumbs') <a href="{{ route('banquet.index') }}">Banquet</a> / Diary @endsection
@section('actions')
<a class="btn" href="{{ route('banquet.sheet') }}"><x-icon name="print"/> Today's function sheet</a>
@if(can_do('banquet.manage'))<a class="btn btn-primary" href="{{ route('banquet.create', array_filter(['hall' => $selectedHall, 'date' => $from->gte(bdate()) ? $from->toDateString() : null])) }}"><x-icon name="plus"/> New enquiry</a>@endif
@endsection
@section('content')
@php
  $baseDiaryQuery = ['start' => $from->toDateString(), 'days' => $days, 'enquiries' => $withEnquiries ? 1 : 0];
  if ($selectedHall) $baseDiaryQuery['hall'] = $selectedHall;
  $q = fn ($over) => '?'.http_build_query(array_merge($baseDiaryQuery, $over));
@endphp
<section class="diary-controls card" aria-label="Diary date and view controls">
  <div class="diary-controls-top">
    <nav class="diary-pager" aria-label="Move through diary dates">
      <a class="btn btn-sm" href="{{ $q(['start' => $from->copy()->subDays($days)->toDateString()]) }}" aria-label="Previous {{ $days }} days" title="Previous {{ $days }} days"><x-icon name="chev" style="transform:rotate(180deg)"/><span>Earlier</span></a>
      <a class="btn btn-sm {{ $from->toDateString() === bdate()->toDateString() ? 'btn-primary' : '' }}" href="{{ $q(['start' => bdate()->toDateString()]) }}" @if($from->toDateString() === bdate()->toDateString()) aria-current="date" @endif>Today</a>
      <a class="btn btn-sm" href="{{ $q(['start' => $from->copy()->addDays($days)->toDateString()]) }}" aria-label="Next {{ $days }} days" title="Next {{ $days }} days"><span>Later</span><x-icon name="chev"/></a>
    </nav>
    <div class="diary-date-range" aria-live="polite">
      <span class="diary-eyebrow">Showing functions</span>
      <strong><time datetime="{{ $from->toDateString() }}">{{ $from->format('D d M Y') }}</time><span aria-hidden="true">–</span><time datetime="{{ $to->toDateString() }}">{{ $to->format('D d M Y') }}</time></strong>
    </div>
    <nav class="seg diary-ranges" aria-label="Diary time range">@foreach([7 => 'Week', 14 => '2 weeks', 31 => '31 days'] as $d => $l)<a class="btn btn-sm {{ $days === $d ? 'btn-primary' : '' }}" href="{{ $q(['days' => $d]) }}" @if($days === $d) aria-current="page" @endif>{{ $l }}</a>@endforeach</nav>
  </div>
  <div class="diary-controls-bottom">
    <form class="diary-filter-form diary-date-jump" method="get" action="{{ route('banquet.diary') }}">
      <label for="diaryStart">Jump to date</label><div class="diary-filter-input"><input type="date" class="input input-sm" id="diaryStart" name="start" value="{{ $from->toDateString() }}">
      <input type="hidden" name="days" value="{{ $days }}"><input type="hidden" name="enquiries" value="{{ $withEnquiries ? 1 : 0 }}">@if($selectedHall)<input type="hidden" name="hall" value="{{ $selectedHall }}">@endif
      <button class="btn btn-sm" type="submit">Go</button></div>
    </form>
    <form class="diary-filter-form diary-venue-filter" method="get" action="{{ route('banquet.diary') }}">
      <label for="diaryHall">Venue</label><div class="diary-filter-input"><select class="input input-sm" id="diaryHall" name="hall"><option value="">All active venues</option>@foreach($allHalls as $hall)<option value="{{ $hall->id }}" @selected((string) $selectedHall === (string) $hall->id)>{{ $hall->name }}</option>@endforeach</select>
      <input type="hidden" name="start" value="{{ $from->toDateString() }}"><input type="hidden" name="days" value="{{ $days }}"><input type="hidden" name="enquiries" value="{{ $withEnquiries ? 1 : 0 }}">
      <button class="btn btn-sm" type="submit">Apply</button></div>
    </form>
    <a class="btn btn-sm diary-enquiry-toggle {{ $withEnquiries ? 'btn-soft' : '' }}" href="{{ $q(['enquiries' => $withEnquiries ? 0 : 1]) }}" aria-label="{{ $withEnquiries ? 'Hide enquiry functions' : 'Include enquiry functions' }}"><span class="diary-toggle-dot" aria-hidden="true"></span>{{ $withEnquiries ? 'Enquiries shown' : 'Include enquiries' }}</a>
  </div>
</section>
@php
  $agendaFunctions = $cells->flatMap(fn ($items) => $items)->sortBy(fn ($f) => $f->date->toDateString().' '.$f->start_time);
  $agendaByDay = $agendaFunctions->groupBy(fn ($f) => $f->date->toDateString());
  $dayCounts = $agendaByDay->map(fn ($items) => $items->count());
  $statusCounts = $agendaFunctions->countBy(fn ($f) => $f->event->status);
  $diaryHallsById = $halls->keyBy('id');
  $functionCount = $agendaFunctions->count();
@endphp
<div class="diary-summary" role="status" aria-live="polite">
  <div class="diary-summary-total"><b>{{ $functionCount }}</b><span>{{ $functionCount === 1 ? 'function' : 'functions' }}</span></div>
  <span class="diary-summary-context">@if($selectedHall) 1 of {{ $allHalls->count() }} active {{ \Illuminate\Support\Str::plural('venue', $allHalls->count()) }}@else {{ $halls->count() }} active {{ \Illuminate\Support\Str::plural('venue', $halls->count()) }}@endif</span>
  <span class="diary-summary-context">{{ $withEnquiries ? 'Enquiries included' : 'Enquiries hidden' }}</span>
  <div class="diary-status-counts" role="group" aria-label="Functions by status">
    @foreach(['definite' => 'Definite', 'tentative' => 'Tentative', 'enquiry' => 'Enquiry', 'completed' => 'Completed'] as $status => $label)
      @if(($statusCounts->get($status) ?? 0) > 0)<span class="diary-status-count diary-status-{{ $status }}"><i aria-hidden="true"></i>{{ $label }} <b>{{ $statusCounts->get($status) }}</b></span>@endif
    @endforeach
  </div>
</div>
@if($halls->isEmpty())
  <div class="alert alert-warn" role="status">No active banquet venues are configured for this property.</div>
@elseif($functionCount === 0)
  <div class="alert alert-info diary-empty-alert" role="status">
    <div><b>No functions in this date range</b><span>Try another date range, or start an enquiry for a future date.</span></div>
    @if(! $withEnquiries)<a class="btn btn-sm" href="{{ $q(['enquiries' => 1]) }}">Include enquiries</a>@elseif(can_do('banquet.manage'))<a class="btn btn-sm btn-primary" href="{{ route('banquet.create', array_filter(['hall' => $selectedHall, 'date' => $from->gte(bdate()) ? $from->toDateString() : null])) }}">New enquiry</a>@endif
  </div>
@endif
@if($halls->isNotEmpty())
<div class="table-wrap diary-scroll" role="region" aria-label="Function diary by venue and day" tabindex="0"><table class="diary" id="diary" style="--diary-days:{{ $days }}">
  <caption class="sr-only">Banquet function diary from {{ $from->format('d M Y') }} through {{ $to->format('d M Y') }} by venue</caption>
  <thead><tr><th scope="col" class="diary-hall">Venue</th>@for($d = $from->copy(); $d->lte($to); $d->addDay())<th scope="col" class="{{ $d->isWeekend() ? 'we' : '' }} {{ $d->eq(bdate()) ? 'today' : '' }}"><span class="diary-day-weekday">{{ $d->format('D') }}</span><time datetime="{{ $d->toDateString() }}">{{ $d->format('d M') }}</time>@if($d->eq(bdate()))<small class="diary-today-label">Today</small>@endif @if(($dayCounts->get($d->toDateString()) ?? 0) > 0)<small class="diary-day-count" aria-label="{{ $dayCounts->get($d->toDateString()) }} {{ $dayCounts->get($d->toDateString()) === 1 ? 'function' : 'functions' }}">{{ $dayCounts->get($d->toDateString()) }}</small>@endif</th>@endfor</tr></thead>
  <tbody>
  @foreach($halls as $h)
    <tr><th scope="row" class="diary-hall"><b>{{ $h->name }}</b><div class="tiny muted">Capacity {{ $h->capacity }}{{ $h->is_outdoor ? ' · outdoor' : '' }}</div></th>
    @for($d = $from->copy(); $d->lte($to); $d->addDay())
      @php $list = $cells->get($h->id.'|'.$d->toDateString(), collect()); @endphp
      <td class="{{ $d->isWeekend() ? 'we' : '' }} {{ $d->eq(bdate()) ? 'today' : '' }} {{ can_do('banquet.manage') && $d->gte(bdate()) ? 'diary-can-add' : '' }}">
        @foreach($list as $f)
          @php $eventTitle = $f->event->title ?: $f->event->contact_name; @endphp
          <a class="dfn dfn-{{ $f->event->status }}" href="{{ route('banquet.show', $f->event) }}" title="{{ $f->event->event_no }} {{ $eventTitle }} – {{ $f->name }} {{ $f->timeRange() }}, {{ $f->billablePax() }} guests ({{ $f->event->status }})" aria-label="{{ $f->event->event_no }}: {{ $eventTitle }}, {{ $f->name }}, {{ $f->date->format('l d M Y') }}, {{ $f->timeRange() }}, {{ $f->billablePax() }} guests, {{ $f->event->status }}">
            <b><time datetime="{{ $f->date->toDateString() }}T{{ substr($f->start_time, 0, 5) }}">{{ substr($f->start_time, 0, 5) }}</time></b> {{ \Illuminate\Support\Str::limit($eventTitle, 18) }}<span>{{ $f->name }} · {{ $f->billablePax() }}</span>
          </a>
        @endforeach
        @if(can_do('banquet.manage') && $d->gte(bdate()))<a class="dadd" href="{{ route('banquet.create', ['hall' => $h->id, 'date' => $d->toDateString()]) }}" title="New enquiry for {{ $h->name }} on {{ $d->format('d M') }}" aria-label="Create a banquet enquiry for {{ $h->name }} on {{ $d->format('l d M Y') }}">+</a>@endif
      </td>
    @endfor
    </tr>
  @endforeach
  </tbody>
</table></div>
<div class="diary-agenda" role="region" aria-label="Function diary agenda">
  @for($d = $from->copy(); $d->lte($to); $d->addDay())
    @php $dayKey = $d->toDateString(); $dayFunctions = $agendaByDay->get($dayKey, collect()); @endphp
    <section class="diary-agenda-day" aria-labelledby="agendaDay{{ $d->format('Ymd') }}">
      <div class="diary-agenda-day-head">
        <h3 id="agendaDay{{ $d->format('Ymd') }}"><time datetime="{{ $dayKey }}">{{ $d->format('l d M Y') }}</time>@if($d->eq(bdate())) <span class="badge badge-green">Today</span>@endif</h3>
        <span class="diary-agenda-count">{{ $dayFunctions->count() }} {{ $dayFunctions->count() === 1 ? 'function' : 'functions' }}</span>
        @if(can_do('banquet.manage') && $d->gte(bdate()))<a class="btn btn-sm" href="{{ route('banquet.create', array_filter(['date' => $dayKey, 'hall' => $selectedHall])) }}" aria-label="Create a banquet enquiry for {{ $d->format('l d M Y') }}{{ $selectedHall ? ' in '.($diaryHallsById->get($selectedHall)?->name ?? 'this venue') : '' }}"><x-icon name="plus"/> New enquiry</a>@endif
      </div>
      @if($dayFunctions->isEmpty())
        <p class="diary-agenda-empty">No functions scheduled.</p>
      @else
        <ul class="diary-agenda-list">
          @foreach($dayFunctions as $f)
            @php $hall = $diaryHallsById->get($f->banquet_hall_id); $eventTitle = $f->event->title ?: $f->event->contact_name; @endphp
            <li class="diary-agenda-item">
              <a class="diary-agenda-event" href="{{ route('banquet.show', $f->event) }}" aria-label="{{ $f->event->event_no }}: {{ $eventTitle }}, {{ $f->name }}, {{ $f->timeRange() }}, {{ $hall?->name }}, {{ $f->billablePax() }} guests, {{ $f->event->status }}">
                <time class="diary-agenda-time" datetime="{{ $dayKey }}T{{ substr($f->start_time, 0, 5) }}">{{ $f->timeRange() }}</time>
                <span class="diary-agenda-copy"><b>{{ $eventTitle }}</b><small><span class="diary-event-ref">{{ $f->event->event_no }}</span> · {{ $f->name }} · {{ $hall?->name ?? 'Venue' }}</small></span>
                {!! $f->event->statusBadge() !!}
              </a>
              <div class="diary-agenda-meta"><span>{{ $f->billablePax() }} guests</span><span>{{ ucwords(str_replace('_', ' ', $f->setup_style)) }}</span></div>
            </li>
          @endforeach
        </ul>
      @endif
    </section>
  @endfor
</div>
@endif
<div class="diary-legend" role="group" aria-label="Diary status legend">
  <span class="dfn dfn-definite">Definite</span><span class="dfn dfn-tentative">Tentative</span>
  @if($withEnquiries)<span class="dfn dfn-enquiry">Enquiry · no hold</span>@endif
  <span class="dfn dfn-completed">Completed</span>
  @if(can_do('banquet.manage'))<span class="diary-add-legend"><b aria-hidden="true">+</b> New enquiry</span>@endif
  <span class="diary-legend-note">Tentative and definite functions hold the venue, with {{ setting('banquet_turnaround_minutes', '60') }} minutes for room turn-around.</span>
</div>
@endsection
