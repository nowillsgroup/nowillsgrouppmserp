@extends('layouts.app')
@section('title', 'Club Visit Log')
@section('crumbs') <a href="{{ route('club.index') }}">Health Club</a> / Visit log @endsection
@section('actions')@if(can_do('reports.view'))<a class="btn" href="{{ route('reports.show', ['club_attendance']) }}"><x-icon name="chart"/> Attendance report</a>@endif @endsection
@section('content')
<form method="get" class="filters">
  <div class="field"><label for="f_from">From</label><input type="date" name="from" id="f_from" value="{{ $from }}"></div><div class="field"><label for="f_to">To</label><input type="date" name="to" id="f_to" value="{{ $to }}"></div>
  <div class="field"><label for="f_fac">Facility</label><select name="facility" id="f_fac"><option value="">All</option>@foreach(\App\Models\ClubPlan::FACILITIES as $k => $l)<option value="{{ $k }}" @selected(request('facility') === $k)>{{ $l }}</option>@endforeach</select></div>
  <div class="field"><label for="f_type">Visitor</label><select name="type" id="f_type"><option value="">All</option>@foreach(\App\Models\ClubVisit::TYPES as $k => $l)<option value="{{ $k }}" @selected(request('type') === $k)>{{ $l }}</option>@endforeach</select></div>
  <label class="check" style="align-self:flex-end"><input type="checkbox" name="denied" value="1" @checked(request('denied') === '1')> <span>Refused only</span></label>
  <div class="field" style="align-self:flex-end"><button class="btn">Filter</button></div></form>
<div class="card flush"><div class="card-body table-wrap"><table class="table" id="visitTable">
  <thead><tr><th>In</th><th>Out</th><th>Who</th><th>Visitor</th><th>Facility</th><th class="num">Pax</th><th>Notes</th><th>Desk</th></tr></thead>
  <tbody>@forelse($rows as $v)
    <tr class="{{ $v->denied ? 'row-alert' : '' }}"><td class="small">{{ $v->checked_in_at->format('d/m H:i') }}</td><td class="small">{{ $v->denied ? '' : ($v->checked_out_at?->format('H:i') ?? 'in') }}</td>
      <td>@if($v->member)<a href="{{ route('club.members.show', $v->member->club_membership_id) }}">{{ $v->name }}</a>@else{{ $v->name }}@endif @if($v->host)<div class="tiny muted">guest of {{ $v->host->name }}</div>@endif</td>
      <td class="small">{{ \App\Models\ClubVisit::TYPES[$v->visitor_type] }}</td><td class="small">{{ \App\Models\ClubPlan::FACILITIES[$v->facility] ?? $v->facility }}</td><td class="num">{{ $v->pax }}</td>
      <td class="small">@if($v->denied)<span class="text-red">Refused – {{ $v->deny_reason }}</span>@elseif($v->override_reason)<span class="text-amber">Override ({{ $v->overrider?->name }}): {{ $v->override_reason }}</span>@elseif($v->club_charge_id)guest pass charged @elseif($v->visitor_type === 'guest' && ! $v->club_day_pass_id)free guest pass @endif</td>
      <td class="small">{{ $v->user?->name }}</td></tr>
  @empty <tr><td colspan="8"><x-empty text="No visits in this period." icon="book"/></td></tr> @endforelse</tbody></table></div></div>
{{ $rows->links() }}
@endsection
