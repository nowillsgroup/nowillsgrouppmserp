@extends('layouts.app')
@section('title', 'ESG Policies')
@section('crumbs') Sustainability &amp; ESG / Policy register @endsection
@php $canMg = \App\Services\EsgService::canManage(auth()->user()); $P = \App\Models\EsgPolicy::PILLARS; @endphp
@section('actions')
  @if($canMg)<button class="btn btn-primary" data-modal="esgPolicyModal" id="esgPolicyNew"><x-icon name="plus"/> Add policy</button>@endif
@endsection
@section('content')
<div class="stats">
  <x-stat label="Policies in force" :value="$gov['active'].' / '.$gov['policies']" icon="book" tone="blue" :sub="$gov['draft'].' in draft'"/>
  <x-stat label="Reviewed on time" :value="$gov['current_pct'] !== null ? $gov['current_pct'].'%' : '—'" icon="clock" :tone="$gov['overdue'] ? 'amber' : 'green'" :sub="$gov['overdue'].' past review date'"/>
  <x-stat label="Data breaches · 12 months" :value="$gov['breaches']" icon="lock" :tone="$gov['breaches'] ? 'red' : 'green'" sub="from the privacy register"/>
  <x-stat label="Licences & permits expired" :value="$gov['compliance_expired']" icon="shield" :tone="$gov['compliance_expired'] ? 'red' : 'green'" :sub="'of '.$gov['compliance_total'].' in the compliance register'"/>
</div>
<div class="card flush"><div class="card-body table-wrap"><table class="table table-compact" id="esgPolicyTable"><thead><tr><th>Code</th><th>Policy</th><th>Pillar</th><th>Owner</th><th>Version</th><th>Approved</th><th>Next review</th><th>Status</th><th></th></tr></thead><tbody>
  @foreach($list as $p)<tr class="{{ $p->isOverdue() ? 'row-red' : '' }}"><td><b>{{ $p->code }}</b></td><td class="small">{{ $p->title }}@if($p->summary)<div class="tiny muted">{{ $p->summary }}</div>@endif</td><td class="small">{{ $P[$p->pillar] }}</td>
    <td class="small">{{ $p->owner?->name ?? '—' }}</td><td class="small">{{ $p->version }}</td><td class="small nowrap">{{ $p->approved_on?->format('d M Y') ?? '—' }}@if($p->approved_by_name)<div class="tiny muted">{{ $p->approved_by_name }}</div>@endif</td>
    <td class="small nowrap">{{ $p->next_review_on?->format('d M Y') ?? '—' }}@if($p->isOverdue())<div class="tiny"><span class="badge badge-red">overdue</span></div>@endif</td>
    <td><span class="badge badge-{{ ['active' => 'green', 'draft' => 'slate', 'retired' => 'slate'][$p->status] }}">{{ ['active' => 'in force', 'draft' => 'draft', 'retired' => 'retired'][$p->status] }}</span></td>
    <td class="nowrap">@if($p->file_path)<a class="btn btn-sm" href="{{ route('esg.policy.file', $p) }}" title="{{ $p->file_name }}"><x-icon name="download" size="sm"/></a>@endif @if($canMg)<button class="btn btn-sm" data-modal="esgP{{ $p->id }}">Edit</button>@endif</td></tr>
  @endforeach
</tbody></table></div></div>
@if($canMg)
<div class="modal" id="esgPolicyModal"><div class="modal-box lg"><form method="post" action="{{ route('esg.policies.store') }}" enctype="multipart/form-data" data-reset>@csrf
  <div class="modal-head"><h3>Add policy</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body">@include('esg._policy_fields', ['x' => null])</div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Save</button></div></form></div></div>
@foreach($list as $p)
<div class="modal" id="esgP{{ $p->id }}"><div class="modal-box lg"><form method="post" action="{{ route('esg.policies.update', $p) }}" enctype="multipart/form-data">@csrf
  <div class="modal-head"><h3>{{ $p->code }} – {{ $p->title }}</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body">@include('esg._policy_fields', ['x' => $p])</div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Save</button></div></form></div></div>
@endforeach
@endif
@endsection
