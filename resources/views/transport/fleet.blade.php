@extends('layouts.app')
@section('title', 'Fleet & Papers')
@section('crumbs') <a href="{{ route('tr.index') }}">Transport</a> / Fleet &amp; papers @endsection
@section('actions')
  @if(can_do('transport.manage'))<a class="btn" href="{{ route('masters.index', 'drivers') }}"><x-icon name="users"/> Drivers</a><a class="btn btn-primary" href="{{ route('masters.create', 'vehicles') }}"><x-icon name="plus"/> Add vehicle</a>@endif
@endsection
@section('content')
<div class="card flush"><div class="card-head"><h3>Vehicles</h3><span class="small muted">This month: trips, km &amp; running cost since {{ $month->format('d M') }}</span></div>
  <div class="card-body table-wrap"><table class="table" id="fleetTable">
    <thead><tr><th>Vehicle</th><th>Type</th><th class="num">Seats</th><th>Status</th><th>Papers</th><th class="num">Odometer</th><th class="num">Trips</th><th class="num">km</th><th class="num">Revenue</th><th class="num">Running cost</th><th>Next trip</th></tr></thead><tbody>
    @forelse($vehicles as $v)
      @php $ts = $tripStats[$v->id] ?? null; $cs = $cost[$v->id] ?? null; $nx = $nextV[$v->id] ?? null; @endphp
      <tr class="{{ $v->is_active ? '' : 'muted-row' }}" data-vehicle="{{ $v->code }}">
        <td><b>{{ $v->code }}</b> @if(can_do('transport.manage'))<a class="tiny" href="{{ route('masters.edit', ['vehicles', $v->id]) }}">edit</a>@endif<div class="tiny muted">{{ $v->name }} · {{ $v->reg_no }}{{ $v->color ? ' · '.$v->color : '' }}</div>@if($v->asset)<div class="tiny muted">Asset {{ $v->asset->asset_no ?? $v->asset->name }}</div>@endif</td>
        <td class="small">{{ \App\Models\Vehicle::TYPES[$v->type] ?? $v->type }}<div class="tiny muted">{{ \App\Models\Vehicle::FUELS[$v->fuel] ?? $v->fuel }}</div></td>
        <td class="num">{{ $v->seats }}</td>
        <td>@if(!$v->is_active)<span class="badge badge-slate">Retired</span>@elseif($v->status === 'maintenance')<span class="badge badge-amber">Workshop</span>@elseif(count($v->expiredPapers()))<span class="badge badge-red">Off road – papers</span>@else<span class="badge badge-green">Available</span>@endif</td>
        <td style="max-width:260px">@foreach(\App\Models\Vehicle::PAPERS as $col => $lbl){!! paper_chip($lbl, $v->{$col}, $c['paper_days']) !!}@endforeach</td>
        <td class="num">{{ number_format($v->odometer) }}</td>
        <td class="num">{{ (int) ($ts->n ?? 0) }}</td>
        <td class="num">{{ number_format((int) ($ts->km ?? 0)) }}</td>
        <td class="num">{{ money($ts->rev ?? 0) }}</td>
        <td class="num">{{ money($cs->amt ?? 0) }}@if(($ts->km ?? 0) > 0 && ($cs->amt ?? 0) > 0)<div class="tiny muted">{{ money(($cs->amt) / $ts->km) }}/km</div>@endif</td>
        <td class="small">@if($nx)<a href="{{ route('tr.show', $nx) }}">{{ $nx->trip_no }}</a> {{ $nx->busy_from->format('d M H:i') }}@else<span class="muted">—</span>@endif</td>
      </tr>
    @empty<tr><td colspan="11"><x-empty text="No vehicles yet." icon="car"/></td></tr>@endforelse
  </tbody></table></div></div>

<div class="card flush" id="drivers"><div class="card-head"><h3>Drivers</h3></div>
  <div class="card-body table-wrap"><table class="table" id="driverTable">
    <thead><tr><th>Driver</th><th>Mobile</th><th>Licence</th><th>Login</th><th>Languages</th><th class="num">Trips today</th><th>Next trip</th></tr></thead><tbody>
    @forelse($drivers as $d)
      @php $nx = $next[$d->id] ?? null; @endphp
      <tr class="{{ $d->is_active ? '' : 'muted-row' }}"><td><b>{{ $d->name }}</b> @if(can_do('transport.manage'))<a class="tiny" href="{{ route('masters.edit', ['drivers', $d->id]) }}">edit</a>@endif @if($d->employee)<div class="tiny muted">{{ $d->employee->emp_code }}</div>@endif</td>
        <td class="small">{{ $d->phone }}</td>
        <td>{{ $d->licence_no ?: '—' }} {!! paper_chip('valid', $d->licence_until, $c['paper_days']) !!}</td>
        <td class="small">{{ $d->user?->username ?? '—' }}</td>
        <td class="small">{{ $d->languages }}</td>
        <td class="num">{{ (int) ($today[$d->id] ?? 0) }}</td>
        <td class="small">@if($nx)<a href="{{ route('tr.show', $nx) }}">{{ $nx->trip_no }}</a> {{ $nx->busy_from->format('d M H:i') }}@else<span class="muted">—</span>@endif</td></tr>
    @empty<tr><td colspan="7"><x-empty text="No drivers yet." icon="users"/></td></tr>@endforelse
  </tbody></table></div></div>
@endsection
