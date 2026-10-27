@extends('portal.layout')
@section('title', 'Spa & activities')
@section('heading', 'Spa & activities')
@section('sub', 'Book a time that suits you – it is added to your room bill.')
@section('content')
@if($mine->isNotEmpty())
<div class="gp-card"><h3>My bookings</h3>
@foreach($mine as $a)
  <div class="gp-row"><div><b class="small">{{ $a->service?->name }}</b><div class="tiny muted">{{ $a->appt_no }} · {{ $a->date->format('D d M') }} · {{ substr($a->start_time, 0, 5) }}–{{ substr($a->end_time, 0, 5) }} · {{ money($a->price - $a->discount) }}</div></div>
    <div class="right"><span class="st {{ $a->status }}">{{ ucfirst(str_replace('_', ' ', $a->status)) }}</span>
      @if($a->status === 'booked' && \Illuminate\Support\Carbon::parse($a->date->toDateString().' '.$a->start_time)->gt(now()->addHours(2)))
        <form method="post" action="{{ route('portal.spa.cancel', [$token, $a->id]) }}" class="mt-1">@csrf<button class="btn btn-ghost btn-sm" style="color:#b91c1c">Cancel</button></form>@endif
    </div></div>
@endforeach
</div>
@endif

@if(! $service)
  @foreach($services->groupBy('category') as $cat => $list)
    <h3 class="small muted mt-1 mb-1" style="text-transform:uppercase;letter-spacing:.04em">{{ ucfirst($cat) }}</h3>
    @foreach($list as $s)
      <a class="gp-card flex justify-between items-center" style="text-decoration:none;color:inherit;display:flex" href="{{ route('portal.spa', [$token, 'service' => $s->id]) }}">
        <div><b>{{ $s->name }}</b><div class="tiny muted">{{ $s->duration_min }} min{{ $s->description ? ' · '.\Illuminate\Support\Str::limit($s->description, 70) : '' }}</div></div>
        <div class="right"><b>{{ money($s->price) }}</b><div class="tiny" style="color:#166534">Book ›</div></div></a>
    @endforeach
  @endforeach
@else
  <div class="gp-card">
    <div class="flex justify-between items-center"><div><b>{{ $service->name }}</b><div class="tiny muted">{{ $service->duration_min }} min · {{ money($service->price) }} per person</div></div>
      <a class="btn btn-ghost btn-sm" href="{{ route('portal.spa', $token) }}">Change</a></div>
    <div class="small bold mt-1 mb-1">Day</div>
    <div class="gp-chips">@foreach($dates as $d)<a class="gp-chip {{ $d === $date ? 'on' : '' }}" style="text-decoration:none" href="{{ route('portal.spa', [$token, 'service' => $service->id, 'date' => $d]) }}">{{ \Illuminate\Support\Carbon::parse($d)->format('D d') }}</a>@endforeach</div>
    <form method="post" action="{{ route('portal.spa.book', $token) }}" class="mt-1">@csrf
      <input type="hidden" name="spa_service_id" value="{{ $service->id }}"><input type="hidden" name="date" value="{{ $date }}">
      <div class="small bold mb-1">Time</div>
      @if($slots)
        <div class="gp-chips mb-1">@foreach($slots as $t => $s)<label class="gp-chip"><input type="radio" name="time" value="{{ $t }}" required>{{ $t }}</label>@endforeach</div>
        <div class="field"><label>People</label><select name="pax" class="input">@for($p = 1; $p <= 4; $p++)<option value="{{ $p }}">{{ $p }}</option>@endfor</select></div>
        <button class="btn btn-primary btn-block">Book {{ $service->name }}</button>
      @else
        <p class="small muted m-0">No free times on this day – please try another day or call the spa.</p>
      @endif
    </form>
  </div>
@endif
@endsection
