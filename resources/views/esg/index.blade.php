@extends('layouts.app')
@section('title', 'Sustainability & ESG')
@section('crumbs') Sustainability &amp; ESG / Dashboard @endsection
@php $u = auth()->user(); $canRec = \App\Services\EsgService::canRecord($u); $canMg = \App\Services\EsgService::canManage($u); $fmt = fn ($v, $d = 1) => $v === null ? '—' : number_format($v, $d); @endphp
@section('actions')
  <a class="btn" href="{{ route('esg.report') }}"><x-icon name="book"/> Annual ESG report</a>
  @if($canRec)<a class="btn btn-primary" href="{{ route('esg.waste') }}#new" id="esgLogWaste"><x-icon name="plus"/> Log waste</a>@endif
@endsection
@section('content')
<div class="stats" id="esgStats">
  <x-stat label="Carbon per occupied room (HCMI)" :value="$fmt($b['per_room']).' kg'" icon="tree" tone="green" :sub="'CO₂e · '.\Carbon\Carbon::parse($wf)->format('d M Y').' – '.\Carbon\Carbon::parse($wt)->format('d M Y')" :href="route('esg.carbon')"/>
  <x-stat :label="'Carbon footprint · '.(\Carbon\Carbon::parse($wf)->diffInDays(\Carbon\Carbon::parse($wt)) + 1).' days'" :value="$fmt($cb['total_kg'] / 1000).' t'" icon="chart" tone="slate" :sub="'S1 '.$fmt($cb['scopes'][1] / 1000).' · S2 '.$fmt($cb['scopes'][2] / 1000).' · S3 '.$fmt($cb['scopes'][3] / 1000).' t'" :href="route('esg.carbon')"/>
  <x-stat label="Water per occupied room (HWMI)" :value="$fmt($b['hwmi_room'], 0).' L'" icon="thermo" tone="blue" :sub="'Energy '.$fmt($b['kwh_room']).' kWh per room · renewable '.$fmt($renewable).'%'"/>
  <x-stat label="Waste diverted · 90 days" :value="$waste['diversion'] !== null ? $waste['diversion'].'%' : '—'" icon="refresh" :tone="($waste['diversion'] ?? 0) >= 50 ? 'green' : 'amber'" :sub="$fmt($waste['total'], 0).' kg · '.$fmt($waste['per_room'], 2).' kg per room'" :href="route('esg.waste')"/>
  <x-stat label="Green-stay nights · month" :value="$green['nights']" icon="bed" tone="teal" :sub="$green['active'].' rooms on green stay · '.$fmt($green['co2']).' kg CO₂e saved'" :href="route('esg.green')"/>
  <x-stat label="Certification readiness" :value="$cert['score'] !== null ? $cert['score'].'%' : '—'" icon="check" :tone="$cert['ready'] ? 'green' : 'purple'" :sub="$cert['mandatory_met'].' of '.$cert['mandatory'].' mandatory met'" :href="route('esg.certification')"/>
</div>
@php $al = $alerts; @endphp
@if($al['policies_overdue'] || $al['initiatives_pending'] || $al['initiatives_late'] || $al['leaks'] || $al['targets_behind'] || $al['waste_stale'])
<div class="alert alert-warn" id="esgAlerts"><x-icon name="alert"/><div>
  @if($al['leaks'])<a href="{{ route('esg.refrigerants') }}"><b>{{ $al['leaks'] }}</b> cooling system(s) leaking above the alert rate – find and fix the leak.</a> @endif
  @if($al['policies_overdue'])<a href="{{ route('esg.policies') }}"><b>{{ $al['policies_overdue'] }}</b> policy(ies) past their review date.</a> @endif
  @if($al['initiatives_pending'])<a href="{{ route('esg.initiatives', ['status' => 'idea']) }}"><b>{{ $al['initiatives_pending'] }}</b> initiative(s) awaiting a decision.</a> @endif
  @if($al['initiatives_late'])<a href="{{ route('esg.initiatives') }}"><b>{{ $al['initiatives_late'] }}</b> initiative(s) past their due date.</a> @endif
  @if($al['targets_behind'])<a href="{{ route('esg.targets') }}"><b>{{ $al['targets_behind'] }}</b> target(s) behind plan.</a> @endif
  @if($al['waste_stale'])<a href="{{ route('esg.waste') }}">No waste logged in the last 14 days.</a> @endif
</div></div>
@endif
<div class="g-2-1">
  <div>
    <div class="card"><div class="card-head"><h3>Carbon footprint by scope – 12 months</h3><span class="tiny muted"><i class="esg-key esg-s1"></i>Scope 1 <i class="esg-key esg-s2"></i>Scope 2 <i class="esg-key esg-s3"></i>Scope 3 (kg CO₂e)</span></div><div class="card-body">
      @php $mx = max(1, collect($trend)->max(fn ($t) => $t['s1'] + $t['s2'] + $t['s3'])); @endphp
      <div class="bar-chart esg-trend" id="esgTrend">@foreach($trend as $t)@php $tot = $t['s1'] + $t['s2'] + $t['s3']; @endphp<div class="col">
        <span class="tiny muted">{{ $tot ? number_format($tot / 1000, 1).'t' : '' }}</span>
        <div class="esg-stack" style="height:{{ round($tot / $mx * 100) }}%" title="Scope 1 {{ number_format($t['s1']) }} · Scope 2 {{ number_format($t['s2']) }} · Scope 3 {{ number_format($t['s3']) }} kg">
          @if($t['s3'])<i class="esg-s3" style="flex:{{ $t['s3'] }}"></i>@endif @if($t['s2'])<i class="esg-s2" style="flex:{{ $t['s2'] }}"></i>@endif @if($t['s1'])<i class="esg-s1" style="flex:{{ $t['s1'] }}"></i>@endif
        </div><small>{{ $t['label'] }}</small></div>@endforeach</div>
    </div></div>
    <div class="card flush"><div class="card-head"><h3>Targets</h3><a class="small" href="{{ route('esg.targets') }}">all targets</a></div>
      <div class="card-body table-wrap"><table class="table table-compact" id="esgTargets"><thead><tr><th>Metric</th><th class="num">Baseline</th><th class="num">Now</th><th class="num">Target</th><th>Progress</th></tr></thead><tbody>
      @forelse($targets as $t)<tr><td class="small">{{ $t['label'] }}</td><td class="num small">{{ number_format($t['target']->baseline_value, $t['dp']) }} <span class="tiny muted">{{ $t['target']->baseline_year }}</span></td>
        <td class="num"><b>{{ $t['current'] !== null ? number_format($t['current'], $t['dp']) : '—' }}</b> <span class="tiny muted">{{ $t['unit'] }}</span></td><td class="num small">{{ number_format($t['target']->target_value, $t['dp']) }} <span class="tiny muted">{{ $t['target']->target_year }}</span></td>
        <td>@include('esg._target_state', ['t' => $t])</td></tr>
      @empty<tr><td colspan="5" class="muted">No targets set yet.@if($canMg) <a href="{{ route('esg.targets') }}">Set targets</a>@endif</td></tr>@endforelse
      </tbody></table></div></div>
    <div class="card flush"><div class="card-head"><h3>Initiatives in the pipeline</h3><a class="small" href="{{ route('esg.initiatives') }}">all initiatives</a></div>
      <div class="card-body table-wrap"><table class="table table-compact" id="esgPipeline"><thead><tr><th>Ref</th><th>Initiative</th><th class="num">Saving / yr</th><th class="num">CO₂e / yr</th><th>Due</th><th>Status</th></tr></thead><tbody>
      @forelse($initiatives as $i)<tr class="{{ $i->isLate() ? 'row-red' : '' }}"><td class="nowrap"><a href="{{ route('esg.initiative', $i) }}"><b>{{ $i->number }}</b></a></td><td class="small">{{ $i->title }}<div class="tiny muted">{{ \App\Models\EsgInitiative::CATEGORIES[$i->category] }} · {{ $i->owner?->name ?? 'no owner' }}</div></td>
        <td class="num small">{{ money($i->saving_ghs) }}</td><td class="num small">{{ number_format($i->saving_co2_kg / 1000, 1) }} t</td><td class="small nowrap">{{ $i->due_on?->format('d M Y') ?? '—' }}</td>
        <td><span class="badge badge-{{ $i->statusTone() }}">{{ $i->statusLabel() }}</span></td></tr>
      @empty<tr><td colspan="6" class="muted">No open initiatives.</td></tr>@endforelse
      </tbody></table></div></div>
  </div>
  <div>
    <div class="card"><div class="card-head"><h3>Scope split</h3><span class="tiny muted">{{ number_format($cb['total_kg'] / 1000, 1) }} t CO₂e</span></div><div class="card-body" id="esgScopeSplit">
      @php $t = max(0.1, $cb['total_kg']); @endphp
      <div class="esg-scope"><i class="esg-s1" style="width:{{ $cb['scopes'][1] / $t * 100 }}%"></i><i class="esg-s2" style="width:{{ $cb['scopes'][2] / $t * 100 }}%"></i><i class="esg-s3" style="width:{{ $cb['scopes'][3] / $t * 100 }}%"></i></div>
      <ul class="plain small">@foreach(collect($cb['rows'])->sortByDesc('kg')->take(7) as $r)<li class="esg-sec"><span>{{ $r['label'] }} <span class="tiny muted">S{{ $r['scope'] }}</span></span><b>{{ number_format($r['kg'] / 1000, 2) }} t</b></li>@endforeach</ul>
      @if($cb['avoided_kg'] > 0)<p class="tiny muted m-0">Solar avoided {{ number_format($cb['avoided_kg'] / 1000, 2) }} t CO₂e (not deducted from the footprint).</p>@endif
    </div></div>
    @if($leaks->count())<div class="card"><div class="card-head"><h3>Refrigerant leaks</h3></div><div class="card-body"><ul class="plain small">
      @foreach($leaks as $l)<li class="esg-sec"><span>{{ $l['equipment'] }} <span class="tiny muted">{{ $l['gas'] }}</span></span><span class="badge badge-red">{{ $l['rate'] }}% / yr</span></li>@endforeach
    </ul></div></div>@endif
    <div class="card"><div class="card-head"><h3>Governance</h3><a class="small" href="{{ route('esg.policies') }}">policies</a></div><div class="card-body"><dl class="kv" id="esgGov">
      <dt>Policies in force</dt><dd>{{ $gov['active'] }} of {{ $gov['policies'] }}{{ $gov['overdue'] ? ' · '.$gov['overdue'].' overdue review' : '' }}</dd>
      <dt>Certification</dt><dd>{{ $cert['score'] ?? '—' }}% · {{ $cert['met'] }} met, {{ $cert['partial'] }} partly</dd>
      <dt>Data breaches (12 m)</dt><dd>{{ $gov['breaches'] }}</dd>
      <dt>Licences expired</dt><dd>{{ $gov['compliance_expired'] }} of {{ $gov['compliance_total'] }}</dd>
    </dl></div></div>
    <div class="card"><div class="card-head"><h3>Community · year to date</h3><a class="small" href="{{ route('esg.social') }}">people</a></div><div class="card-body">
      <div class="esg-big">{{ money($community['amount']) }}</div><div class="small muted">{{ $community['count'] }} contributions · {{ number_format($community['hours'], 0) }} volunteering hours · {{ number_format($community['people']) }} people reached</div>
    </div></div>
  </div>
</div>
@endsection
