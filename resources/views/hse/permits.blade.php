@extends('layouts.app')
@section('title', 'Permits to Work')
@section('crumbs') Health &amp; Safety / Permits to work @endsection
@section('actions')
  <a class="btn" href="{{ route('reports.show', ['report' => 'hse_permits']) }}"><x-icon name="download"/> Register</a>
  @if(\App\Services\HseService::canCheck(auth()->user()))<button class="btn btn-primary" data-modal="hsePermitModal" id="hsePermitNew"><x-icon name="plus"/> Request permit</button>@endif
@endsection
@section('content')
<div class="card"><div class="card-body"><form method="get" class="flex gap-1 wrap" style="align-items:center">
  <select name="status" aria-label="Status"><option value="">All</option>@foreach(\App\Models\HsePermit::STATUSES as $k => $l)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $l }}</option>@endforeach</select>
  <select name="type" aria-label="Type"><option value="">All types</option>@foreach(\App\Models\HsePermit::TYPES as $k => $t)<option value="{{ $k }}" @selected(request('type') === $k)>{{ $t[0] }}</option>@endforeach</select>
  <button class="btn btn-sm">Filter</button><span class="tiny muted">Hot work, work at height, confined spaces, electrical isolation and roof work need a permit issued on site before starting.</span>
</form></div></div>
<div class="card flush"><div class="card-body table-wrap"><table class="table table-compact" id="hsePermitsTable"><thead><tr><th>Permit</th><th>Type</th><th>Location &amp; work</th><th>Contractor</th><th>Period</th><th>Requested</th><th>Issued</th><th>Status</th></tr></thead><tbody>
  @forelse($permits as $p)<tr class="{{ $p->isOverdue() ? 'row-red' : ($p->status === 'requested' ? 'row-amber' : '') }}">
    <td><a href="{{ route('hse.permit', $p) }}"><b>{{ $p->number }}</b></a></td><td class="small">{{ $p->typeLabel() }}</td>
    <td class="small">{{ $p->location }}<div class="tiny muted">{{ $p->work }}</div></td><td class="small">{{ $p->contractor ?? 'in-house' }} · {{ $p->workers }}</td>
    <td class="small nowrap">{{ $p->starts_at->format('d M H:i') }} – {{ $p->ends_at->format($p->ends_at->isSameDay($p->starts_at) ? 'H:i' : 'd M H:i') }}</td>
    <td class="small">{{ $p->requester?->name }}</td><td class="small">{{ $p->issuer?->name ?? '—' }}</td><td>@include('hse._permit_badge', ['p' => $p])</td></tr>
  @empty<tr><td colspan="8" class="muted">No permits.</td></tr>@endforelse
</tbody></table></div></div>
{{ $permits->links() }}
@if(\App\Services\HseService::canCheck(auth()->user()))@include('hse._permit_modal')@endif
@endsection
