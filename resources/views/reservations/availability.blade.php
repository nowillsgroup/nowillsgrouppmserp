@extends('layouts.app')
@section('title', 'Room Availability')
@section('crumbs') <a href="{{ route('reservations.index') }}">Reservations</a> / Availability @endsection
@section('actions')
<form class="filters" method="get"><div class="field"><input type="date" name="from" value="{{ $from->toDateString() }}" data-autosubmit></div>
<div class="field"><select name="days" data-autosubmit>@foreach([7,14,21,30] as $d)<option value="{{ $d }}" @selected($days==$d)>{{ $d }} days</option>@endforeach</select></div></form>
<a class="btn" href="{{ route('reservations.availability', ['from' => $from->copy()->subDays($days)->toDateString(), 'days' => $days]) }}">‹</a>
<a class="btn" href="{{ route('reservations.availability', ['from' => $from->copy()->addDays($days)->toDateString(), 'days' => $days]) }}">›</a>
@endsection
@section('content')
<div class="card flush"><div class="card-body table-wrap">
<table class="table table-compact avail">
  <thead><tr><th>Room Type</th><th class="center">Total</th>@for($i=0;$i<$days;$i++) @php $d = $from->copy()->addDays($i); @endphp<th class="center {{ $d->isWeekend() ? 'text-amber' : '' }}">{{ $d->format('D') }}<br>{{ $d->format('d/m') }}</th>@endfor</tr></thead>
  <tbody>
  @foreach($types as $t)
    <tr><td class="bold nowrap">{{ $t->name }}<div class="tiny muted">{{ money($t->base_rate) }}</div></td><td class="center">{{ $t->rooms_count }}</td>
    @for($i=0;$i<$days;$i++) @php $d = $from->copy()->addDays($i)->toDateString(); $v = $matrix[$t->id][$d] ?? 0; $cls = $v <= 0 ? 'low' : ($v <= max(1, $t->rooms_count * .25) ? 'mid' : 'ok'); @endphp
      <td class="center {{ $cls }}">@if($v > 0 && can_do('reservations.create'))<a href="{{ route('reservations.create', ['arrival' => $d, 'departure' => \Illuminate\Support\Carbon::parse($d)->addDay()->toDateString(), 'room_type_id' => $t->id]) }}" style="color:inherit">{{ $v }}</a>@else{{ $v }}@endif</td>
    @endfor</tr>
  @endforeach
  <tr><td class="bold">Total available</td><td class="center">{{ $types->sum('rooms_count') }}</td>
  @for($i=0;$i<$days;$i++) @php $d = $from->copy()->addDays($i)->toDateString(); @endphp<td class="center bold">{{ collect($matrix)->sum(fn($m) => $m[$d] ?? 0) }}</td>@endfor</tr>
  </tbody>
</table>
</div></div>
<div class="legend"><span><i style="background:#f0fdf4;border:1px solid #86efac"></i>Good availability</span><span><i style="background:#fffbeb;border:1px solid #fcd34d"></i>Limited</span><span><i style="background:#fef2f2;border:1px solid #fca5a5"></i>Sold out</span><span>Click a number to book.</span></div>
@endsection
