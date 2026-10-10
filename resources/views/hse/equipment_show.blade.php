@extends('layouts.app')
@section('title', $e->code.' · '.$e->typeLabel())
@section('crumbs') Health &amp; Safety / <a href="{{ route('hse.equipment') }}">Fire equipment</a> / {{ $e->code }} @endsection
@php $u = auth()->user(); $canMg = \App\Services\HseService::canManage($u); $canCheck = \App\Services\HseService::canCheck($u); $st = $e->state(); @endphp
@section('actions')
  @if($canMg)<button class="btn" data-modal="hseEquipModal" data-action="{{ route('hse.equipment.update', $e) }}" data-title="Edit {{ $e->code }}" data-ftype="{{ $e->type }}" data-fcode="{{ $e->code }}" data-flocation="{{ $e->location }}" data-ffloor="{{ $e->floor }}" data-froom_id="{{ $e->room_id }}" data-fcapacity="{{ $e->capacity }}" data-fgnfs_tag="{{ $e->gnfs_tag }}" data-fserial="{{ $e->serial }}" data-finstalled_on="{{ $e->installed_on?->toDateString() }}" data-fexpires_on="{{ $e->expires_on?->toDateString() }}" data-fcheck_days="{{ $e->check_days }}" data-fservice_days="{{ $e->service_days }}" data-fstatus="{{ $e->status }}" data-fnotes="{{ $e->notes }}"><x-icon name="edit"/> Edit</button>@endif
  @if($canMg && $e->service_days > 0 && $e->status !== 'retired')<button class="btn" data-modal="hseServiceModal" id="hseServiceBtn"><x-icon name="tool"/> Record service</button>@endif
@endsection
@section('content')
<div class="g-2-1">
  <div>
    <div class="card"><div class="card-head"><h3>{{ $e->typeLabel() }}</h3><span id="hseEquipState">@include('hse._state', ['s' => $st])</span></div><div class="card-body"><div class="kv-grid">
      <div><span>Location</span>{{ $e->location }}{{ $e->floor ? ' · floor '.$e->floor : '' }}{{ $e->room ? ' · room '.$e->room->room_no : '' }}</div>
      <div><span>Size</span>{{ $e->capacity ?? '—' }}</div><div><span>GNFS tag</span><span class="mono">{{ $e->gnfs_tag ?? '—' }}</span></div><div><span>Serial</span>{{ $e->serial ?? '—' }}</div>
      <div><span>Installed</span>{{ $e->installed_on?->format('d M Y') ?? '—' }}</div><div><span>Expires</span>{{ $e->expires_on?->format('d M Y') ?? '—' }}</div>
      <div><span>Visual check</span>every {{ $e->check_days }} days · next {{ $e->next_check_on?->format('d M Y') ?? 'now' }}</div>
      <div><span>Contractor service</span>{{ $e->service_days ? 'every '.$e->service_days.' days · next '.($e->next_service_on?->format('d M Y') ?? '—') : 'none' }}</div>
      @if($e->ticket)<div><span>Engineering</span>{{ $e->ticket->ticket_no }} · {{ $e->ticket->status }}</div>@endif
      @if($e->notes)<div><span>Notes</span>{{ $e->notes }}</div>@endif
    </div></div></div>
    <div class="card flush"><div class="card-head"><h3>Inspection history</h3><a class="small" href="{{ route('reports.show', ['report' => 'hse_checks']) }}">inspection log</a></div><div class="card-body table-wrap"><table class="table table-compact" id="hseChecks"><thead><tr><th>Date</th><th>Kind</th><th>Result</th><th>Faults / notes</th><th>By</th></tr></thead><tbody>
      @forelse($e->checks as $c)<tr class="{{ $c->result === 'fail' ? 'row-red' : '' }}"><td class="nowrap small">{{ $c->checked_on->format('d M Y') }}</td><td class="small">{{ $c->kind === 'service' ? 'Service' : 'Visual' }}</td>
        <td><span class="badge badge-{{ $c->result === 'pass' ? 'green' : 'red' }}">{{ $c->result === 'pass' ? 'Pass' : 'Fail' }}</span></td>
        <td class="small">{{ $c->faults ? implode('; ', $c->faults) : '' }}{{ $c->notes && $c->faults ? ' – ' : '' }}{{ $c->notes }}@if($c->certificate_no)<div class="tiny muted">cert. {{ $c->certificate_no }}</div>@endif @if($c->ticket)<div class="tiny muted">ticket {{ $c->ticket->ticket_no }}</div>@endif</td>
        <td class="small">{{ $c->contractor ?? $c->user?->name }}</td></tr>
      @empty<tr><td colspan="5" class="muted">Not checked yet.</td></tr>@endforelse
    </tbody></table></div></div>
  </div>
  <div>
    @if($canCheck && $e->status !== 'retired')<div class="card"><div class="card-head"><h3>Visual check</h3></div><div class="card-body">
      <form method="post" action="{{ route('hse.equipment.check', $e) }}" id="hseCheckForm">@csrf<input type="hidden" name="kind" value="visual">
        @foreach($e->checklist() as $i => $item)<label class="hse-item"><input type="checkbox" name="ok[]" value="{{ $i }}" checked> {{ $item }}</label>@endforeach
        <div class="field mt-1"><label>Date</label><input type="date" name="checked_on" value="{{ bdate()->toDateString() }}" max="{{ bdate()->toDateString() }}"></div>
        <div class="field"><label>Other fault / note</label><input name="notes" maxlength="255" placeholder="untick any item that is not right"></div>
        <button class="btn btn-primary mt-1">Save check</button>
      </form></div></div>@endif
  </div>
</div>
@if($canMg)
@include('hse._equip_modal')
<div class="modal" id="hseServiceModal"><div class="modal-box"><form method="post" action="{{ route('hse.equipment.check', $e) }}">@csrf<input type="hidden" name="kind" value="service">
  <div class="modal-head"><h3>Contractor service – {{ $e->code }}</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field"><label>Date</label><input type="date" name="checked_on" value="{{ bdate()->toDateString() }}" max="{{ bdate()->toDateString() }}" required></div>
    <div class="field"><label>Result</label><select name="result" required><option value="pass">Passed – serviced / recharged</option><option value="fail">Failed – condemned / needs replacing</option></select></div>
    <div class="field"><label>Contractor</label><input name="contractor" maxlength="120" required></div>
    <div class="field"><label>Certificate / job no.</label><input name="certificate_no" maxlength="60"></div>
    <div class="field span-all"><label>Notes</label><input name="notes" maxlength="255"></div>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Record service</button></div>
</form></div></div>
@endif
@endsection
