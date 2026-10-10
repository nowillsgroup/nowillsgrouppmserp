@extends('layouts.app')
@section('title', 'Eco-certification')
@section('crumbs') Sustainability &amp; ESG / Certification self-assessment @endsection
@php $canMg = \App\Services\EsgService::canManage(auth()->user()); $C = \App\Models\EsgChecklistItem::class; @endphp
@section('content')
<div class="stats" id="esgCertStats">
  <x-stat label="Readiness score" :value="$score['score'] !== null ? $score['score'].'%' : '—'" icon="check" :tone="$score['ready'] ? 'green' : 'purple'" sub="(met + ½ partly met) ÷ applicable"/>
  <x-stat label="Mandatory criteria met" :value="$score['mandatory_met'].' / '.$score['mandatory']" icon="shield" :tone="$score['ready'] ? 'green' : 'amber'" :sub="$score['ready'] ? 'ready to apply for certification' : 'all mandatory criteria must be met'"/>
  <x-stat label="Criteria" :value="$score['total']" icon="layers" tone="slate" :sub="$score['met'].' met · '.$score['partial'].' partly · '.$score['not'].' not · '.$score['na'].' n/a'"/>
</div>
<div class="card"><div class="card-body"><form method="get" class="filters" style="align-items:flex-end"><div class="field"><label>Status</label><select name="status"><option value="">All</option>@foreach($C::STATUSES as $k => [$l])<option value="{{ $k }}" @selected(request('status') === $k)>{{ $l }}</option>@endforeach</select></div><button class="btn btn-sm">Filter</button>
  <span class="tiny muted">A generic Green Key / EarthCheck-style self-assessment to prepare for an audit – it is not itself a certification.</span></form></div></div>
@foreach($items as $sec => $rows)
<div class="card flush"><div class="card-head"><h3>{{ $C::SECTIONS[$sec] ?? $sec }}</h3>@if(($score['sections'][$sec] ?? null) !== null)<span class="badge badge-{{ $score['sections'][$sec] >= 80 ? 'green' : ($score['sections'][$sec] >= 50 ? 'amber' : 'red') }}">{{ $score['sections'][$sec] }}%</span>@endif</div>
  <div class="card-body table-wrap"><table class="table table-compact esg-cert" style="table-layout:fixed"><thead><tr><th style="width:90px">Code</th><th>Requirement</th><th style="width:150px">Status</th><th style="width:300px">Evidence</th>@if($canMg)<th style="width:74px"></th>@endif</tr></thead><tbody>
  @foreach($rows as $it)<tr class="{{ $it->is_mandatory && $it->status !== 'met' ? 'row-amber' : '' }}" id="esgItem{{ $it->code }}">
    <td class="small"><b>{{ $it->code }}</b>@if($it->is_mandatory)<div class="tiny"><span class="badge badge-red">mandatory</span></div>@endif</td>
    <td class="small">{{ $it->requirement }}@if($it->guidance)<div class="tiny muted">{{ $it->guidance }}</div>@endif @if($it->reviewed_on)<div class="tiny muted">reviewed {{ $it->reviewed_on->format('d M Y') }} by {{ $it->reviewer?->name }}</div>@endif</td>
    @if($canMg)
      <td><select name="status" class="esg-sel" form="esgF{{ $it->id }}">@foreach($C::STATUSES as $k => [$l])<option value="{{ $k }}" @selected($it->status === $k)>{{ $l }}</option>@endforeach</select></td>
      <td><input name="evidence" class="esg-ev" style="width:100%" maxlength="500" value="{{ $it->evidence }}" placeholder="what proves it" form="esgF{{ $it->id }}"></td>
      <td><form method="post" action="{{ route('esg.certification.assess', $it) }}" id="esgF{{ $it->id }}">@csrf<button class="btn btn-sm">Save</button></form></td>
    @else<td><span class="badge badge-{{ $C::STATUSES[$it->status][1] }}">{{ $C::STATUSES[$it->status][0] }}</span></td><td class="small">{{ $it->evidence ?? '—' }}</td>@endif
  </tr>@endforeach
  </tbody></table></div></div>
@endforeach
@endsection
