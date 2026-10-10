@extends('layouts.app')
@section('title', 'Health & Safety')
@section('crumbs') Health &amp; Safety / Dashboard @endsection
@php $u = auth()->user(); $canCheck = \App\Services\HseService::canCheck($u); $canMg = \App\Services\HseService::canManage($u); @endphp
@section('actions')
  @if($canCheck)<a class="btn" href="{{ route('hse.round') }}"><x-icon name="check"/> Inspection round</a>
  <button class="btn" data-modal="hsePermitModal" id="hsePermitNew"><x-icon name="key"/> Request permit</button>
  <button class="btn btn-primary" data-modal="hseAccidentModal" id="hseReportBtn"><x-icon name="alert"/> Report accident / near miss</button>@endif
@endsection
@section('content')
<div class="stats" id="hseStats">
  <x-stat label="Days since a lost-time injury" :value="$k['days_since_lti'] ?? '—'" icon="shield" :tone="$k['days_since_lti'] !== null && $k['days_since_lti'] < 30 ? 'amber' : 'green'" :sub="'LTIFR '.($k['ltifr'] ?? '—').' · '.$k['ltis'].' LTI in 12 months'"/>
  <x-stat label="Fire equipment in order" :value="$eq['pct'] !== null ? $eq['pct'].'%' : '—'" icon="alert" :tone="($eq['pct'] ?? 100) < 95 ? 'red' : 'green'" :sub="$eq['in_order'].' of '.$eq['total'].' · '.$eq['overdue'].' overdue · '.$eq['defective'].' defective'" :href="route('hse.equipment')"/>
  <x-stat label="Permits to work" :value="$k['permits_live'].' live'" icon="key" tone="purple" :sub="$k['permits_requested'].' awaiting issue'" :href="route('hse.permits')"/>
  <x-stat label="Accidents · 12 months" :value="$k['accidents']" icon="users" tone="slate" :sub="$k['near_misses'].' near misses · '.$k['lost_days'].' days lost'" :href="route('hse.accidents')"/>
  <x-stat label="High residual risks" :value="$k['risks_high']" icon="trend" :tone="$k['risks_high'] ? 'amber' : 'green'" :sub="$alerts['reviews'].' reviews overdue'" :href="route('hse.risks')"/>
</div>
@php $al = $alerts; @endphp
@if($al['defective'] || $al['overdue'] || $al['expired'] || $al['permits_overdue'] || $al['unnotified'] || $al['reviews'] || $al['pending'] || $al['drills'] || $al['warden_gaps'] || $al['detectors_isolated'])
<div class="alert alert-warn" id="hseAlerts"><x-icon name="alert"/><div>
  @if($al['unnotified'])<a href="{{ route('hse.accidents', ['reportable' => 1]) }}"><b>{{ $al['unnotified'] }}</b> accident(s) must be notified to the Factories Inspectorate.</a> @endif
  @if($al['defective'])<a href="{{ route('hse.equipment', ['state' => 'defective']) }}"><b>{{ $al['defective'] }}</b> fire-safety item(s) defective.</a> @endif
  @if($al['overdue'])<a href="{{ route('hse.equipment', ['state' => 'overdue']) }}"><b>{{ $al['overdue'] }}</b> item(s) overdue a check or service.</a> @endif
  @if($al['expired'])<a href="{{ route('hse.equipment', ['state' => 'expired']) }}"><b>{{ $al['expired'] }}</b> item(s) past expiry.</a> @endif
  @if($al['permits_overdue'])<a href="{{ route('hse.permits', ['status' => 'issued']) }}"><b>{{ $al['permits_overdue'] }}</b> permit(s) past their end – close out.</a> @endif
  @if($al['detectors_isolated'])<b>{{ $al['detectors_isolated'] }}</b> live permit(s) with smoke detectors isolated. @endif
  @if($al['drills'])<a href="{{ route('hse.drills') }}"><b>{{ $al['drills'] }}</b> shift(s) overdue a fire drill.</a> @endif
  @if($al['warden_gaps'])<a href="{{ route('hse.drills') }}#hseCoverage"><b>{{ $al['warden_gaps'] }}</b> warden / first-aid gap(s).</a> @endif
  @if($al['reviews'])<a href="{{ route('hse.risks') }}"><b>{{ $al['reviews'] }}</b> risk assessment review(s) overdue.</a> @endif
  @if($al['pending'])<b>{{ $al['pending'] }}</b> high residual risk(s) awaiting acceptance. @endif
</div></div>
@endif
<div class="g-2-1">
  <div>
    <div class="card flush"><div class="card-head"><h3>Fire equipment needing attention</h3><a class="small" href="{{ route('hse.equipment') }}">register</a></div>
      <div class="card-body table-wrap"><table class="table table-compact" id="hseDueTable"><thead><tr><th>Item</th><th>Location</th><th>Next check</th><th>Service</th><th>State</th></tr></thead><tbody>
      @forelse($due as $e)<tr class="{{ in_array($e->st, ['defective', 'expired', 'overdue'], true) ? 'row-red' : 'row-amber' }}">
        <td><a href="{{ route('hse.equipment.show', $e) }}"><b>{{ $e->code }}</b></a><div class="tiny muted">{{ $e->typeLabel() }}</div></td><td class="small">{{ $e->location }}</td>
        <td class="small nowrap">{{ $e->next_check_on?->format('d M') ?? 'never' }}</td><td class="small nowrap">{{ $e->next_service_on?->format('d M Y') ?? '—' }}</td>
        <td>@include('hse._state', ['s' => $e->st])@if($e->ticket)<div class="tiny muted">{{ $e->ticket->ticket_no }} · {{ $e->ticket->status }}</div>@endif</td></tr>
      @empty<tr><td colspan="5" class="muted">Every item is checked and in order.</td></tr>@endforelse
      </tbody></table></div></div>
    <div class="card flush"><div class="card-head"><h3>Permits to work</h3><a class="small" href="{{ route('hse.permits') }}">all permits</a></div>
      <div class="card-body table-wrap"><table class="table table-compact" id="hseLivePermits"><thead><tr><th>Permit</th><th>Work</th><th>Until</th><th>Status</th></tr></thead><tbody>
      @forelse($live as $p)<tr class="{{ $p->isOverdue() ? 'row-red' : '' }}"><td><a href="{{ route('hse.permit', $p) }}"><b>{{ $p->number }}</b></a><div class="tiny muted">{{ $p->typeLabel() }}</div></td>
        <td class="small">{{ $p->location }} – {{ $p->work }}@if($p->detectors_isolated)<div class="tiny"><span class="badge badge-red">detectors isolated</span> {{ $p->detectors_isolated }}</div>@endif</td>
        <td class="small nowrap">{{ $p->ends_at->format('d M H:i') }}</td><td>@include('hse._permit_badge', ['p' => $p])</td></tr>
      @empty<tr><td colspan="4" class="muted">No live or pending permits.</td></tr>@endforelse
      </tbody></table></div></div>
    <div class="card flush"><div class="card-head"><h3>Recent accidents &amp; near misses</h3><a class="small" href="{{ route('hse.accidents') }}">register</a></div>
      <div class="card-body table-wrap"><table class="table table-compact" id="hseRecent"><thead><tr><th>Ref</th><th>When</th><th>What</th><th>Who</th><th>Outcome</th></tr></thead><tbody>
      @forelse($recent as $a)<tr class="{{ $a->reportable && ! $a->notified_on ? 'row-red' : '' }}"><td><a href="{{ route('hse.accident', $a) }}"><b>{{ $a->number }}</b></a></td><td class="small nowrap">{{ $a->occurred_at->format('d M H:i') }}</td>
        <td class="small">{{ $a->kindLabel() }} · {{ $a->location }}</td><td class="small">{{ $a->personLabel() }}</td>
        <td>@include('hse._severity', ['a' => $a])</td></tr>
      @empty<tr><td colspan="5" class="muted">Nothing reported.</td></tr>@endforelse
      </tbody></table></div></div>
  </div>
  <div>
    <div class="card"><div class="card-head"><h3>Residual risk map</h3><a class="small" href="{{ route('hse.risks') }}">assessments</a></div><div class="card-body">@include('hse._heat', ['heat' => $heat])</div></div>
    <div class="card"><div class="card-head"><h3>Fire drills by shift</h3><a class="small" href="{{ route('hse.drills') }}">drills</a></div><div class="card-body" id="hseDrillStatus">
      @foreach($drills as $s => $d)<div class="hse-shift"><div><b>{{ ucfirst($s) }}</b> <span class="tiny muted">{{ $d['last'] ? 'last '.$d['last']->alarm_at->format('d M Y').' · '.$d['last']->evacLabel() : 'no drill on record' }}</span>
        @if($d['planned'])<div class="tiny">Planned {{ $d['planned']->scheduled_at->format('d M H:i') }}</div>@endif</div>
        <span class="badge badge-{{ ['ok' => 'green', 'due' => 'amber', 'overdue' => 'red', 'never' => 'red'][$d['state']] }}">{{ ['ok' => 'in date', 'due' => 'due '.$d['due']?->format('d M'), 'overdue' => 'overdue', 'never' => 'never drilled'][$d['state']] }}</span></div>@endforeach
    </div></div>
    <div class="card"><div class="card-head"><h3>Wardens &amp; first aiders</h3></div><div class="card-body" id="hseCover">
      @foreach($cover as $s => $c)<div class="hse-shift"><div><b>{{ ucfirst($s) }}</b> <span class="tiny muted">{{ $c['chief'] }} chief · {{ $c['floor'] }} floor · {{ $c['first_aider'] }} first aid{{ $c['expired'] ? ' · '.$c['expired'].' training expired' : '' }}</span></div>
        @if($c['gaps'])<span class="badge badge-red" title="{{ implode('; ', $c['gaps']) }}">{{ count($c['gaps']) }} gap(s)</span>@else<span class="badge badge-green">covered</span>@endif</div>@endforeach
    </div></div>
    @if($reviews->count())<div class="card"><div class="card-head"><h3>Risk assessments to act on</h3></div><div class="card-body"><ul class="plain small">
      @foreach($reviews as $x)<li><a href="{{ route('hse.risk', $x) }}">{{ $x->number }}</a> {{ $x->title }} – @if($x->status === 'pending')<span class="badge badge-amber">awaiting acceptance</span>@else<span class="badge badge-red">review due {{ $x->review_on->format('d M') }}</span>@endif</li>@endforeach
    </ul></div></div>@endif
  </div>
</div>
<div class="card"><div class="card-head"><h3>Accidents &amp; near misses – 12 months</h3><span class="tiny muted">A healthy reporting culture logs many near misses for every injury.</span></div><div class="card-body">
  @php $mx = max(1, collect($trend)->max(fn ($t) => $t['injury'] + $t['near'] + $t['dangerous'])); @endphp
  <div class="bar-chart hse-trend" id="hseTrend">@foreach($trend as $t)@php $tot = $t['injury'] + $t['near'] + $t['dangerous']; @endphp<div class="col">
    <span class="tiny muted">{{ $tot ?: '' }}</span>
    <div class="hse-stack" style="height:{{ round($tot / $mx * 100) }}%" title="{{ $t['injury'] }} injuries · {{ $t['near'] }} near misses · {{ $t['dangerous'] }} dangerous occurrences">
      @if($t['dangerous'])<i class="d" style="flex:{{ $t['dangerous'] }}"></i>@endif @if($t['injury'])<i class="i" style="flex:{{ $t['injury'] }}"></i>@endif @if($t['near'])<i class="n" style="flex:{{ $t['near'] }}"></i>@endif
    </div><small>{{ $t['label'] }}</small></div>@endforeach</div>
  <div class="tiny muted mt-1"><i class="hse-key n"></i> near miss <i class="hse-key i"></i> injury <i class="hse-key d"></i> dangerous occurrence</div>
</div></div>
@if($canCheck)@include('hse._accident_modal')@include('hse._permit_modal')@endif
@endsection
