@extends('layouts.app')
@section('title', 'Appraisals')
@section('crumbs') Talent Management / Appraisals @endsection
@section('actions')<button class="btn btn-primary" data-modal="talCycleModal" id="talCycleNew"><x-icon name="plus"/> Open a cycle</button>@endsection
@section('content')
<p class="small muted">Overall score = goals × {{ $cfg['goal_weight'] }} % + competencies × {{ 100 - $cfg['goal_weight'] }} %. Bands: below 1.8 Unsatisfactory · 1.8 Needs improvement · 2.6 Meets · 3.4 Exceeds · 4.2 Outstanding. Below 2.6 starts a performance improvement plan.</p>
<div class="card flush"><div class="card-body table-wrap"><table class="table" id="talCycles"><thead><tr><th>Cycle</th><th>Period</th><th>Self-assessment due</th><th>Review due</th><th>Progress</th><th>Status</th></tr></thead><tbody>
@forelse($cycles as $c)<tr><td><a href="{{ route('talent.cycle', $c) }}"><b>{{ $c->name }}</b></a></td><td class="small">{{ $c->period_from->format('d M Y') }} – {{ $c->period_to->format('d M Y') }}</td>
  <td class="small">{{ $c->self_due->format('d M Y') }}</td><td class="small">{{ $c->review_due->format('d M Y') }}</td>
  <td class="small"><div class="tal-prog"><i style="width:{{ $c->appraisals_count ? round($c->signed_count / $c->appraisals_count * 100) : 0 }}%"></i></div>{{ $c->signed_count }}/{{ $c->appraisals_count }} signed</td>
  <td>@include('talent._badge', ['s' => $c->status === 'active' ? 'open' : 'closed', 'l' => ucfirst($c->status)])</td></tr>
@empty<tr><td colspan="6" class="muted">No appraisal cycles yet.</td></tr>@endforelse
</tbody></table></div></div>
<div class="modal" id="talCycleModal"><div class="modal-box"><form method="post" action="{{ route('talent.cycles.store') }}" data-reset>@csrf
  <div class="modal-head"><h3>Open an appraisal cycle</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body"><div class="form-grid">
    <x-field name="name" label="Name" placeholder="2026 Year-end review" required class="span-all"/>
    <x-field name="period_from" label="Period from" type="date" required/><x-field name="period_to" label="Period to" type="date" required/>
    <x-field name="self_due" label="Self-assessment due" type="date" required/><x-field name="review_due" label="Manager review due" type="date" required/>
    <x-field name="goals" label="Common goals (one per line, weights split equally – reviewers can adjust)" type="textarea" class="span-all" value="Guest satisfaction in my area
Attendance & punctuality
Departmental standards (SOP / QA audits)"/>
  </div><p class="tiny muted">Every active employee who joined before the period end is included; the line manager becomes the reviewer. Staff without a login skip the self-assessment.</p></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Open cycle</button></div>
</form></div></div>
@endsection
