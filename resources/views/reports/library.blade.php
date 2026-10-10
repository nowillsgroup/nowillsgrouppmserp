@extends('layouts.app')
@section('title', 'My Reports & Saved Views')
@section('crumbs') <a href="{{ route('reports.index') }}">Reports</a> / My reports @endsection
@section('actions')
<a class="btn" href="{{ route('reports.index') }}"><x-icon name="chart"/> All reports</a>
@if(can_do('reports.schedule'))<a class="btn" href="{{ route('reports.schedules') }}"><x-icon name="mail"/> Scheduled reports</a>@endif
@if(can_do('reports.alerts'))<a class="btn" href="{{ route('reports.alerts') }}"><x-icon name="alert"/> KPI alerts</a>@endif
@endsection
@php $RS = \App\Services\ReportScheduleService::class; $me = auth()->user(); @endphp
@section('content')
<div class="stats">
  <x-stat label="Saved views I can open" :value="$views->count()" icon="star" tone="blue" :sub="$views->where('user_id', $me->id)->count().' mine · '.$views->where('user_id', '!=', $me->id)->count().' shared with me'"/>
  <x-stat label="Pinned to my dashboard" :value="$views->where('user_id', $me->id)->where('is_pinned', true)->count()" icon="grid" tone="purple" sub="open from the dashboard"/>
  <x-stat label="Schedules I own" :value="$mySchedules->count()" icon="mail" tone="green" :sub="$mySchedules->where('is_active', false)->count().' paused'"/>
  @if(can_do('reports.alerts'))<x-stat label="KPI alerts to acknowledge" :value="$stats['open_events']" icon="alert" :tone="$stats['open_events'] ? 'red' : 'slate'" :href="route('reports.alerts')" sub="last 14 days"/>@endif
</div>

<div class="card flush" id="viewsCard"><div class="card-head"><h3><x-icon name="star"/> Saved views</h3>
  <form class="flex gap-1" method="get" action="{{ route('reports.index') }}"><span class="small muted">Open any report and press <b>Save view</b> to add one.</span></form></div>
<div class="card-body table-wrap"><table class="table">
  <thead><tr><th>View</th><th>Report</th><th>Period</th><th>Columns / filter</th><th>Owner</th><th class="num">Opened</th><th></th></tr></thead>
  <tbody>@forelse($views as $v) @php $mine = $v->user_id === $me->id; [$vf, $vt] = $RS::resolve($v->range, $v->date_from, $v->date_to); @endphp
    <tr data-view="{{ $v->id }}">
      <td class="bold"><a href="{{ $v->url() }}">{{ $v->name }}</a>@if($v->is_pinned && $mine) <span class="badge badge-purple">pinned</span>@endif @if($v->is_shared) <span class="badge badge-blue">shared</span>@endif</td>
      <td class="small">{{ $v->title() }}<div class="tiny muted">{{ \App\Http\Controllers\ReportController::REPORTS[$v->report_key][0] ?? '' }}</div></td>
      <td class="small">{{ $RS::RANGES[$v->range] ?? $v->range }}<div class="tiny muted">{{ $RS::rangeLabel($v->report_key, 'custom', $vf, $vt) }}</div></td>
      <td class="small">{{ $v->columns ? count($v->columns).' column(s)' : 'All columns' }}@if($v->filter)<div class="tiny muted">rows containing “{{ $v->filter }}”</div>@endif</td>
      <td class="small">{{ $mine ? 'Me' : $v->user?->name }}</td>
      <td class="num small">{{ $v->opens }}<div class="tiny muted">{{ $v->last_opened_at?->diffForHumans() }}</div></td>
      <td class="nowrap right">
        <a class="btn btn-sm btn-primary" href="{{ $v->url() }}"><x-icon name="eye"/> Open</a>
        @if($mine)
          <form method="post" action="{{ route('reports.views.pin', $v) }}" style="display:inline">@csrf<button class="btn btn-sm" title="{{ $v->is_pinned ? 'Unpin from' : 'Pin to' }} dashboard">{{ $v->is_pinned ? 'Unpin' : 'Pin' }}</button></form>
          <form method="post" action="{{ route('reports.views.destroy', $v) }}" style="display:inline" data-confirm="Delete the view “{{ $v->name }}”?">@csrf @method('DELETE')<button class="btn btn-sm btn-ghost" title="Delete"><x-icon name="trash"/></button></form>
        @endif
      </td>
    </tr>
  @empty
    <tr><td colspan="7"><x-empty text="No saved views yet – open a report, set the period and columns, then press Save view." icon="star"/></td></tr>
  @endforelse</tbody>
</table></div></div>

<div class="card flush"><div class="card-head"><h3><x-icon name="mail"/> Reports e-mailed on my behalf</h3>@if(can_do('reports.schedule'))<a class="btn btn-sm btn-primary" href="{{ route('reports.schedules', ['new' => 'flash']) }}"><x-icon name="plus"/> New schedule</a>@endif</div>
<div class="card-body table-wrap"><table class="table">
  <thead><tr><th>Schedule</th><th>Report</th><th>When</th><th>Last run</th><th>Next run</th><th>Status</th></tr></thead>
  <tbody>@forelse($mySchedules as $s)
    <tr><td class="bold">{{ $s->name }}</td><td class="small">{{ $s->title() }}</td><td class="small">{{ $s->when() }}</td>
      <td class="small">{{ $s->last_run_at?->format('d M H:i') ?? '—' }} @if($s->last_status)<span class="badge badge-{{ ['sent' => 'green', 'partial' => 'amber', 'failed' => 'red', 'skipped' => 'slate'][$s->last_status] }}">{{ $s->last_status }}</span>@endif</td>
      <td class="small">{{ $s->next_run_at?->format('D d M H:i') ?? ($s->frequency === 'after_audit' && $s->is_active ? 'next night audit' : '—') }}</td>
      <td>{!! $s->is_active ? '<span class="badge badge-green">active</span>' : '<span class="badge badge-slate">paused</span>' !!}</td></tr>
  @empty
    <tr><td colspan="6"><x-empty text="You have no scheduled reports." icon="mail"/></td></tr>
  @endforelse</tbody>
</table></div></div>
@endsection
