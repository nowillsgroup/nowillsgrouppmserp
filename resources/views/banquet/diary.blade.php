@extends('layouts.app')
@section('title', 'Function Diary')
@section('crumbs') <a href="{{ route('banquet.index') }}">Banquet</a> / Diary @endsection
@section('actions')
<a class="btn" href="{{ route('banquet.sheet') }}"><x-icon name="print"/> Today's function sheet</a>
@if(can_do('banquet.manage'))<a class="btn btn-primary" href="{{ route('banquet.create') }}"><x-icon name="plus"/> New enquiry</a>@endif
@endsection
@section('content')
@php $q = fn ($over) => '?'.http_build_query(array_merge(['start' => $from->toDateString(), 'days' => $days, 'enquiries' => $withEnquiries ? 1 : 0], $over)); @endphp
<div class="flex items-center gap-1 mb-1 diary-nav">
  <a class="btn btn-sm" href="{{ $q(['start' => $from->copy()->subDays($days)->toDateString()]) }}"><x-icon name="chev" style="transform:rotate(180deg)"/> Earlier</a>
  <a class="btn btn-sm" href="{{ $q(['start' => bdate()->toDateString()]) }}">Today</a>
  <a class="btn btn-sm" href="{{ $q(['start' => $from->copy()->addDays($days)->toDateString()]) }}">Later <x-icon name="chev"/></a>
  <b class="grow" style="margin-left:.5rem">{{ $from->format('d M') }} – {{ $to->format('d M Y') }}</b>
  <div class="seg">@foreach([7 => 'Week', 14 => '2 weeks', 31 => 'Month'] as $d => $l)<a class="btn btn-sm {{ $days === $d ? 'btn-primary' : '' }}" href="{{ $q(['days' => $d]) }}">{{ $l }}</a>@endforeach</div>
  <a class="btn btn-sm" href="{{ $q(['enquiries' => $withEnquiries ? 0 : 1]) }}">{{ $withEnquiries ? 'Hide' : 'Show' }} enquiries</a>
</div>
<div class="card flush"><div class="card-body table-wrap"><table class="diary" id="diary">
  <thead><tr><th class="diary-hall">Venue</th>@for($d = $from->copy(); $d->lte($to); $d->addDay())<th class="{{ $d->isWeekend() ? 'we' : '' }} {{ $d->eq(bdate()) ? 'today' : '' }}">{{ $d->format('D') }}<div>{{ $d->format('d M') }}</div></th>@endfor</tr></thead>
  <tbody>
  @foreach($halls as $h)
    <tr><th class="diary-hall">{{ $h->name }}<div class="tiny muted">{{ $h->capacity }} max{{ $h->is_outdoor ? ' · outdoor' : '' }}</div></th>
    @for($d = $from->copy(); $d->lte($to); $d->addDay())
      @php $list = $cells->get($h->id.'|'.$d->toDateString(), collect()); @endphp
      <td class="{{ $d->isWeekend() ? 'we' : '' }} {{ $d->eq(bdate()) ? 'today' : '' }}">
        @foreach($list as $f)
          <a class="dfn dfn-{{ $f->event->status }}" href="{{ route('banquet.show', $f->event) }}" title="{{ $f->event->event_no }} {{ $f->event->title }} – {{ $f->name }} {{ $f->timeRange() }}, {{ $f->billablePax() }} pax ({{ $f->event->status }})">
            <b>{{ substr($f->start_time, 0, 5) }}</b> {{ \Illuminate\Support\Str::limit($f->event->title ?: $f->event->contact_name, 18) }}<span>{{ $f->name }} · {{ $f->billablePax() }}</span>
          </a>
        @endforeach
        @if(can_do('banquet.manage') && $d->gte(bdate()))<a class="dadd" href="{{ route('banquet.create', ['hall' => $h->id, 'date' => $d->toDateString()]) }}" title="New enquiry for {{ $h->name }} on {{ $d->format('d M') }}">+</a>@endif
      </td>
    @endfor
    </tr>
  @endforeach
  </tbody>
</table></div></div>
<div class="flex gap-1 tiny mt-1 diary-legend"><span class="dfn dfn-definite">Definite</span><span class="dfn dfn-tentative">Tentative</span><span class="dfn dfn-enquiry">Enquiry (no hold)</span><span class="dfn dfn-completed">Completed</span><span class="muted">Tentative and definite functions hold the venue, with {{ setting('banquet_turnaround_minutes', '60') }} minutes to turn the room round between functions.</span></div>
@endsection
