@extends('layouts.app')
@section('title', 'Housekeeping – Room Status')
@section('actions')<a href="{{ route('offline.housekeeping') }}" class="btn"><x-icon name="wifi"/> Offline workspace</a> <a href="{{ route('housekeeping.tasks') }}" class="btn"><x-icon name="broom"/> Tasks</a>@endsection
@section('content')
<div class="stats">
  <x-stat label="Clean" :value="$counts['clean'] ?? 0" icon="check" tone="green" :href="route('housekeeping.index', ['hk' => 'clean'])"/>
  <x-stat label="Dirty" :value="$counts['dirty'] ?? 0" icon="broom" tone="red" :href="route('housekeeping.index', ['hk' => 'dirty'])"/>
  <x-stat label="Inspected" :value="$counts['inspected'] ?? 0" icon="eye" tone="blue" :href="route('housekeeping.index', ['hk' => 'inspected'])"/>
  <x-stat label="Touch-up" :value="$counts['touch_up'] ?? 0" icon="star" tone="amber" :href="route('housekeeping.index', ['hk' => 'touch_up'])"/>
</div>
<form method="post" action="{{ route('housekeeping.status') }}">@csrf
<div class="card flush">
  <div class="card-head">
    <div class="filters">
      <select class="input input-sm" onchange="location='?hk='+this.value+'&floor={{ request('floor') }}'"><option value="">All HK status</option>@foreach(['clean','dirty','inspected','touch_up'] as $s)<option value="{{ $s }}" @selected(request('hk')==$s)>{{ ucwords(str_replace('_',' ',$s)) }}</option>@endforeach</select>
      <select class="input input-sm" onchange="location='?floor='+this.value+'&hk={{ request('hk') }}'"><option value="">All floors</option>@foreach($floors as $id=>$n)<option value="{{ $id }}" @selected(request('floor')==$id)>{{ $n }}</option>@endforeach</select>
    </div>
    @if(can_do('housekeeping.manage'))
    <div class="actions"><span class="small muted">Selected →</span>
      <button name="hk_status" value="clean" class="btn btn-sm btn-success">Clean</button>
      <button name="hk_status" value="dirty" class="btn btn-sm btn-danger">Dirty</button>
      <button name="hk_status" value="inspected" class="btn btn-sm btn-primary">Inspected</button>
      <button name="hk_status" value="touch_up" class="btn btn-sm btn-warning">Touch-up</button>
      <button name="status" value="out_of_order" class="btn btn-sm">Out of order</button>
      <button name="status" value="vacant" class="btn btn-sm">Return to service</button>
    </div>@endif
  </div>
  <div class="card-body table-wrap"><table class="table">
    <thead><tr><th style="width:36px"><input type="checkbox" onclick="document.querySelectorAll('.rc').forEach(c=>c.checked=this.checked)"></th><th>Room</th><th>Type</th><th>Floor</th><th>Occupancy</th><th>HK status</th><th>Guest</th><th>Departure</th><th>Notes</th></tr></thead><tbody>
    @foreach($rooms as $r)
      <tr><td><input type="checkbox" class="rc" name="rooms[]" value="{{ $r->id }}"></td><td class="bold">{{ $r->room_no }}</td><td>{{ $r->roomType->name }}</td><td>{{ $r->floor?->name }}</td>
      <td>{!! status_badge($r->status) !!}</td><td>{!! status_badge($r->hk_status) !!}</td>
      <td>{{ $r->currentStay?->reservation->guest->full_name ?? '—' }}</td><td>{{ $r->currentStay?->departure_date->format('d M') ?? '—' }}</td><td class="small muted">{{ $r->notes }}</td></tr>
    @endforeach
  </tbody></table></div>
</div>
</form>
@endsection
