@extends('layouts.app')
@section('title', 'People & Community')
@section('crumbs') Sustainability &amp; ESG / People &amp; community @endsection
@php $canRec = \App\Services\EsgService::canRecord(auth()->user()); $f = fn ($v, $d = 1, $sfx = '') => $v === null ? '—' : number_format($v, $d).$sfx; $K = \App\Models\EsgCommunity::KINDS; @endphp
@section('actions')
  <a class="btn" href="{{ route('reports.show', ['report' => 'esg_local_spend', 'from' => $from, 'to' => $to]) }}"><x-icon name="download"/> Local sourcing report</a>
  @if($canRec)<button class="btn btn-primary" data-modal="esgCommModal" id="esgCommNew"><x-icon name="plus"/> Record contribution</button>@endif
@endsection
@section('content')
<div class="card"><div class="card-body">@include('esg._range')</div></div>
<div class="stats" id="esgSocialStats">
  <x-stat label="Headcount" :value="$s['headcount']" icon="users" tone="blue" :sub="$s['female'].' women · '.$s['male'].' men'.($s['gender_unknown'] ? ' · '.$s['gender_unknown'].' not recorded' : '')"/>
  <x-stat label="Women in management" :value="$f($s['female_mgmt_pct'], 1, '%')" icon="user" tone="purple" :sub="$s['female_mgmt'].' of '.$s['management'].' managers · workforce '.$f($s['female_pct'], 1, '%')"/>
  <x-stat label="Ghanaian staff" :value="$f($s['local_pct'], 1, '%')" icon="globe" tone="green" :sub="$s['hires'].' hires, '.$s['local_hires'].' local'"/>
  <x-stat label="Turnover" :value="$f($s['turnover_pct'], 1, '%')" icon="refresh" tone="amber" :sub="$s['leavers'].' leavers in the period'"/>
  <x-stat label="Training" :value="$f($s['training_per_emp'], 1, ' h')" icon="book" tone="teal" :sub="'per employee · '.number_format($s['training_hours']).' h in total'"/>
  <x-stat label="LTIFR" :value="$f($s['ltifr'], 2)" icon="shield" :tone="$s['lti'] ? 'red' : 'green'" :sub="$s['lti'].' lost-time injuries per 1,000,000 h'"/>
</div>
@if($s['below_min']->count())<div class="alert alert-error" id="esgMinWage"><x-icon name="alert"/><div><b>{{ $s['below_min']->count() }}</b> employee(s) paid below the national minimum wage ({{ money($s['min_wage']) }} a month): {{ $s['below_min']->pluck('name')->implode(', ') }}.</div></div>@endif
<div class="g-2">
  <div class="card"><div class="card-head"><h3>Diversity &amp; fair pay</h3></div><div class="card-body"><dl class="kv" id="esgDiversity">
    <dt>Gender pay gap (mean basic)</dt><dd>{{ $f($s['pay_gap_pct'], 1, '%') }} <span class="tiny muted">men's average − women's, as % of men's</span></dd>
    <dt>Minimum-wage compliance</dt><dd>{{ $s['below_min']->count() ? $s['below_min']->count().' below' : 'All staff at or above '.money($s['min_wage']).' / month' }}</dd>
    <dt>Staff with a disability</dt><dd>{{ $s['disability'] }}</dd>
    <dt>Nationality recorded</dt><dd>{{ $s['nationality_known'] }} of {{ $s['headcount'] }}</dd>
    <dt>Hours worked (est.)</dt><dd>{{ number_format($s['hours_worked']) }}</dd>
  </dl>
  <table class="table table-compact mt-1" id="esgByDept"><thead><tr><th>Department</th><th class="num">Staff</th><th class="num">Women</th></tr></thead><tbody>
    @foreach($s['by_dept'] as $d => $x)<tr><td class="small">{{ $d }}</td><td class="num">{{ $x['n'] }}</td><td class="num">{{ $x['f'] }}</td></tr>@endforeach
  </tbody></table><p class="tiny muted m-0">Gender and nationality come from the employee master (HR → Employees).</p></div></div>
  <div class="card"><div class="card-head"><h3>Local sourcing</h3><span class="tiny muted">supplier bills in the period</span></div><div class="card-body">
    <div class="esg-big" id="esgLocalPct">{{ $f($src['pct'], 1, '%') }} <small>spent with local suppliers</small></div>
    <div class="small muted">{{ money($src['local']) }} of {{ money($src['total']) }} (net of debit notes, before tax)</div>
    <table class="table table-compact mt-1"><thead><tr><th>Supplier</th><th class="num">Spend</th><th></th></tr></thead><tbody>
      @foreach(array_slice($src['suppliers'], 0, 8) as $x)<tr><td class="small">{{ $x['name'] }}</td><td class="num small">{{ money($x['spend']) }}</td><td>@if($x['local'])<span class="badge badge-green">local</span>@endif</td></tr>@endforeach
    </tbody></table><p class="tiny muted m-0">Flag suppliers as local in Masters → Suppliers.</p></div></div>
</div>
<div class="card flush"><div class="card-head"><h3>Community investment</h3><span class="tiny muted">{{ money($s['community']['amount']) }} · {{ number_format($s['community']['hours'], 0) }} volunteering hours · {{ number_format($s['community']['people']) }} people reached</span></div>
  <div class="card-body table-wrap"><table class="table table-compact" id="esgCommunity"><thead><tr><th>Ref</th><th>Date</th><th>Type</th><th>Beneficiary</th><th class="num">Value</th><th class="num">Hours</th><th class="num">Reached</th><th></th></tr></thead><tbody>
  @forelse($list as $c)<tr><td class="nowrap"><b>{{ $c->number }}</b></td><td class="small nowrap">{{ $c->date->format('d M Y') }}</td><td class="small">{{ $c->kindLabel() }}</td><td class="small">{{ $c->beneficiary }}@if($c->description)<div class="tiny muted">{{ $c->description }}</div>@endif</td>
    <td class="num">{{ (float) $c->amount ? money($c->amount) : '' }}</td><td class="num small">{{ $c->hours ?: '' }}{{ $c->volunteers ? ' ('.$c->volunteers.' staff)' : '' }}</td><td class="num small">{{ $c->people_reached ?: '' }}</td>
    <td>@if($c->evidence_path)<a class="small" href="{{ route('esg.community.file', $c) }}">evidence</a>@endif</td></tr>
  @empty<tr><td colspan="8" class="muted">Nothing recorded in the period.</td></tr>@endforelse
  </tbody></table></div></div>
@if($canRec)
<div class="modal" id="esgCommModal"><div class="modal-box lg"><form method="post" action="{{ route('esg.community.store') }}" enctype="multipart/form-data" data-reset>@csrf
  <div class="modal-head"><h3>Community contribution</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field"><label>Date</label><input type="date" name="date" value="{{ bdate()->toDateString() }}" max="{{ bdate()->toDateString() }}" required></div>
    <div class="field"><label>Type</label><select name="kind" required>@foreach($K as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
    <div class="field span-all"><label>Beneficiary / project</label><input name="beneficiary" maxlength="150" required></div>
    <div class="field span-all"><label>Description</label><input name="description" maxlength="500"></div>
    <div class="field"><label>Value (GH₵, cash or in kind)</label><input type="number" name="amount" step="0.01" min="0"></div>
    <div class="field"><label>Volunteering hours (total)</label><input type="number" name="hours" step="0.5" min="0"></div>
    <div class="field"><label>Staff volunteers</label><input type="number" name="volunteers" min="0"></div>
    <div class="field"><label>People reached</label><input type="number" name="people_reached" min="0"></div>
    <div class="field span-all"><label>Evidence (PDF / photo, max 5 MB)</label><input type="file" name="evidence" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx"></div>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Save</button></div></form></div></div>
@endif
@endsection
