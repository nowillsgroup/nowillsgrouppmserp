@extends('layouts.app')
@section('title', 'My Appraisal')
@section('crumbs') My Appraisal @endsection
@section('content')
<div class="g-2">
  <div class="card flush"><div class="card-head"><h3>My appraisals</h3></div><div class="card-body table-wrap"><table class="table table-compact" id="talMine"><thead><tr><th>Cycle</th><th>Reviewer</th><th class="num">Overall</th><th>Status</th></tr></thead><tbody>
    @forelse($mine as $a)<tr><td><a href="{{ route('talent.appraisal', $a) }}"><b>{{ $a->cycle->name }}</b></a>@if($a->status === 'self')<div class="tiny text-amber">self-assessment due {{ $a->cycle->self_due->format('d M Y') }}</div>@endif</td>
      <td class="small">{{ $a->reviewer?->name ?? '—' }}</td><td class="num">{{ $a->status === 'signed' ? number_format($a->overall, 2) : '—' }}</td>
      <td>@include('talent._badge', ['s' => $a->status, 'l' => $a->statusLabel()])@if($a->status === 'signed' && ! $a->acknowledged_at)<div class="tiny text-amber">please acknowledge</div>@endif</td></tr>
    @empty<tr><td colspan="4" class="muted">{{ $emp ? 'No appraisals yet.' : 'Your login is not linked to an employee record.' }}</td></tr>@endforelse
  </tbody></table></div></div>
  <div class="card flush"><div class="card-head"><h3>Team appraisals I review</h3></div><div class="card-body table-wrap"><table class="table table-compact" id="talTeam"><thead><tr><th>Employee</th><th>Cycle</th><th>Status</th></tr></thead><tbody>
    @forelse($team as $a)<tr><td><a href="{{ route('talent.appraisal', $a) }}"><b>{{ $a->employee->name }}</b></a><div class="tiny muted">{{ $a->employee->designation }}</div></td><td class="small">{{ $a->cycle->name }}</td>
      <td>@include('talent._badge', ['s' => $a->status, 'l' => $a->statusLabel()])@if($a->status === 'review')<div class="tiny text-amber">review due {{ $a->cycle->review_due->format('d M') }}</div>@endif</td></tr>
    @empty<tr><td colspan="3" class="muted">Nobody to review.</td></tr>@endforelse
  </tbody></table></div></div>
</div>
@endsection
