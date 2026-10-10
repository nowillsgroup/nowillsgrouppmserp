@extends('layouts.app')
@section('title', 'Establishment')
@section('crumbs') Talent Management / Establishment @endsection
@php $canMg = auth()->user()->hasPermission('talent.manage'); @endphp
@section('content')
<div class="g-2">
  <form method="post" action="{{ route('talent.establishment.save') }}" class="card flush" id="talEstForm">@csrf<div class="card-head"><h3>Approved headcount by department</h3></div>
    <div class="card-body table-wrap"><table class="table table-compact" id="talEstTable"><thead><tr><th>Department</th><th class="num">Establishment</th><th class="num">In post</th><th class="num">Recruiting</th><th class="num">Gap</th></tr></thead><tbody>
    @foreach($rows as $r)<tr class="{{ $r['gap'] !== null && $r['gap'] < 0 ? 'row-red' : '' }}"><td>{{ $r['department']->name }}</td>
      <td class="num">@if($canMg)<input name="establishment[{{ $r['department']->id }}]" value="{{ $r['establishment'] }}" type="number" min="0" class="tal-num">@else{{ $r['establishment'] ?? '—' }}@endif</td>
      <td class="num">{{ $r['staff'] }}</td><td class="num">{{ $r['recruiting'] ?: '—' }}</td><td class="num">{{ $r['gap'] === null ? '—' : ($r['gap'] > 0 ? '+'.$r['gap'] : $r['gap']) }}</td></tr>@endforeach
    <tr><th>Total</th><th class="num">{{ $rows->sum('establishment') }}</th><th class="num">{{ $rows->sum('staff') }}</th><th class="num">{{ $rows->sum('recruiting') }}</th><th></th></tr>
    </tbody></table></div>
    @if($canMg)<div class="card-foot"><button class="btn btn-primary" id="talEstSave">Save establishment</button></div>@endif
    <div class="card-body tiny muted">Gap = establishment − staff in post − new posts being recruited (replacements are not counted twice). Negative means over establishment.</div>
  </form>
  <form method="post" action="{{ route('talent.managers.save') }}" class="card flush" id="talMgrForm">@csrf<div class="card-head"><h3>Line managers (appraisal reviewers)</h3></div>
    <div class="card-body table-wrap"><table class="table table-compact" id="talMgrTable"><thead><tr><th>Employee</th><th>Line manager</th></tr></thead><tbody>
    @foreach($employees as $e)<tr><td class="small">{{ $e->name }}<div class="tiny muted">{{ $e->designation }} · {{ $e->department?->name }}</div></td>
      <td>@if($canMg)<select name="manager[{{ $e->id }}]" class="tal-sel"><option value="">—</option>@foreach($users as $x)<option value="{{ $x->id }}" @selected($e->manager_user_id === $x->id)>{{ $x->name }}</option>@endforeach</select>@else{{ $users->firstWhere('id', $e->manager_user_id)?->name ?? '—' }}@endif</td></tr>@endforeach
    </tbody></table></div>
    @if($canMg)<div class="card-foot"><button class="btn btn-primary" id="talMgrSave">Save line managers</button></div>@endif
  </form>
</div>
@endsection
