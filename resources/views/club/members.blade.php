@extends('layouts.app')
@section('title', 'Club Members')
@section('crumbs') <a href="{{ route('club.index') }}">Health Club</a> / Members @endsection
@section('actions')
@if(can_do('club.members'))<a class="btn btn-primary" href="{{ route('club.members.create') }}" id="enrolBtn"><x-icon name="plus"/> Enrol member</a>@endif
@if(can_do('reports.view'))<a class="btn" href="{{ route('reports.show', ['club_members']) }}"><x-icon name="chart"/> Report</a>@endif
@endsection
@section('content')
<div class="tabs">@foreach(['live' => 'Current', 'active' => 'Active', 'frozen' => 'Frozen', 'expired' => 'Expired', 'cancelled' => 'Cancelled', 'all' => 'All'] as $k => $l)
  <a href="{{ route('club.members', array_merge(request()->except('page', 'status'), ['status' => $k])) }}" class="{{ $status === $k ? 'active' : '' }}">{{ $l }} <span class="muted small">{{ $k === 'live' ? ($counts['active'] ?? 0) + ($counts['frozen'] ?? 0) : ($k === 'all' ? $counts->sum() : ($counts[$k] ?? 0)) }}</span></a>@endforeach</div>
<form method="get" class="filters"><input type="hidden" name="status" value="{{ $status }}">
  <div class="field"><label for="f_q">Search</label><input name="q" id="f_q" value="{{ request('q') }}" placeholder="Name, MB-, card, phone"></div>
  <div class="field"><label for="f_plan">Plan</label><select name="plan" id="f_plan"><option value="">All plans</option>@foreach($plans as $p)<option value="{{ $p->id }}" @selected(request('plan') == $p->id)>{{ $p->name }}</option>@endforeach</select></div>
  <div class="field" style="align-self:flex-end"><button class="btn">Filter</button></div></form>
<div class="card flush"><div class="card-body table-wrap"><table class="table" id="membersTable">
  <thead><tr><th>Membership</th><th>Holder</th><th>Plan</th><th class="num">Cards</th><th>Term</th><th>Status</th><th class="num">Outstanding</th></tr></thead>
  <tbody>@forelse($rows as $m)
    <tr data-membership="{{ $m->membership_no }}"><td><a class="bold" href="{{ route('club.members.show', $m) }}">{{ $m->membership_no }}</a></td>
      <td>{{ $m->holder_name }}<div class="tiny muted">{{ $m->phone }}{{ $m->company ? ' · '.$m->company->name : '' }}</div></td>
      <td class="small">{{ $m->plan->name }}</td><td class="num">{{ $m->members_count }}</td>
      <td class="small">{{ dmy($m->start_date) }} – {{ dmy($m->end_date) }}@if($m->frozen_to && $m->frozen_to->gte(bdate()))<div class="tiny text-purple">frozen {{ dmy($m->frozen_from, 'd/m') }}–{{ dmy($m->frozen_to, 'd/m') }}</div>@endif</td>
      <td>@include('club._status', ['m' => $m])</td>
      <td class="num {{ ($bal[$m->id] ?? 0) > 0.004 ? 'text-red bold' : '' }}">{{ ($bal[$m->id] ?? 0) > 0.004 ? money($bal[$m->id], false) : '—' }}</td></tr>
  @empty <tr><td colspan="7"><x-empty text="No memberships found." icon="users"/></td></tr> @endforelse</tbody></table></div></div>
{{ $rows->links() }}
@endsection
