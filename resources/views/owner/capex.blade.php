@extends('layouts.app')
@section('title', $p->number.' '.$p->title)
@section('crumbs') Owner Reporting / <a href="{{ route('owner.reserve') }}">FF&E reserve & capex</a> / {{ $p->number }} @endsection
@php
  $CP = \App\Models\CapexProject::class; $FE = \App\Models\FfeEntry::class; $U = \App\Services\UsaliService::class;
  $canOp = can_do('owner.operate'); $canApprove = can_do('owner.approve');
  $spent = $p->spent(); $pctSpent = (float) $p->budget > 0 ? min(100, round($spent * 100 / (float) $p->budget)) : 0;
  $own = (int) $p->requested_by === (int) auth()->id();
@endphp
@section('actions')
  @if($canOp && in_array($p->status, ['draft', 'rejected'], true))
    <button class="btn" data-modal="projectModal" id="editProjectBtn"><x-icon name="edit"/> Edit</button>
    <form method="post" action="{{ route('owner.capex.submit', $p) }}" class="inline">@csrf<button class="btn btn-primary" id="submitProjectBtn"><x-icon name="mail"/> Send to owner</button></form>
  @endif
  @if($canOp && $p->status === 'approved')
    <form method="post" action="{{ route('owner.capex.close', [$p, 'completed']) }}" class="inline" data-confirm="Mark {{ $p->number }} completed? No more spend can be charged to it.">@csrf<button class="btn btn-primary" id="completeProjectBtn"><x-icon name="check"/> Complete</button></form>
  @endif
  @if($canOp && in_array($p->status, ['draft', 'submitted', 'approved', 'rejected'], true) && $spent <= 0)
    <form method="post" action="{{ route('owner.capex.close', [$p, 'cancelled']) }}" class="inline" data-confirm="Cancel {{ $p->number }}?">@csrf<button class="btn btn-ghost">Cancel project</button></form>
  @endif
@endsection
@section('content')
@if($p->status === 'submitted' && $canApprove)
  <div class="card mt-0" id="decisionCard"><div class="card-head"><h3><x-icon name="shield"/> Owner decision</h3></div><div class="card-body">
    @if($own)<div class="small muted">You requested this project – another owner's representative must decide it.</div>
    @else
    <form method="post" id="decisionForm">@csrf
      <div class="field"><label for="dNote">Note to the operator</label><input name="note" id="dNote" maxlength="255" placeholder="conditions, or the reason for rejecting"></div>
      <div class="mt-1"><button class="btn btn-primary" formaction="{{ route('owner.capex.approve', $p) }}" id="approveBtn"><x-icon name="check"/> Approve {{ money($p->budget) }}</button>
      <button class="btn btn-danger" formaction="{{ route('owner.capex.reject', $p) }}" id="rejectBtn"><x-icon name="x"/> Reject</button></div>
    </form>@endif
  </div></div>
@endif

<div class="grid g-2-1 mt-1">
<div>
  <div class="card" id="projectCard"><div class="card-head"><h3>{{ $p->title }}</h3><span class="badge badge-{{ $CP::TONES[$p->status] }}" id="projectStatus">{{ $CP::STATUSES[$p->status] }}</span></div><div class="card-body">
    <dl class="kv">
      <dt>Number</dt><dd>{{ $p->number }}</dd>
      <dt>Category</dt><dd>{{ $CP::CATEGORIES[$p->category] ?? $p->category }}{{ $p->usali_dept ? ' · '.$U::label($p->usali_dept) : '' }}</dd>
      <dt>Funded by</dt><dd>{{ $CP::FUNDING[$p->funding] }}@if($p->funding === 'reserve') <span class="tiny muted">(reserve balance {{ money($reserve) }})</span>@endif</dd>
      <dt>Budget</dt><dd>{{ money($p->budget) }}</dd>
      <dt>Needed by</dt><dd>{{ $p->needed_by?->format('d M Y') ?? '—' }}</dd>
      <dt>Requested</dt><dd>{{ $p->requester?->name }} · {{ $p->created_at->format('d M Y') }}{{ $p->submitted_at ? ' · sent '.$p->submitted_at->format('d M Y') : '' }}</dd>
      @if($p->decided_at)<dt>{{ $p->status === 'rejected' ? 'Rejected' : 'Approved' }}</dt><dd>{{ $p->decider?->name }} · {{ $p->decided_at->format('d M Y') }}@if($p->decision_note)<div class="small">“{{ $p->decision_note }}”</div>@endif</dd>@endif
      @if($p->completed_at)<dt>Completed</dt><dd>{{ $p->completed_at->format('d M Y') }}</dd>@endif
    </dl>
    @if($p->justification)<h4 class="mt-1" style="margin-bottom:.3rem">Justification</h4><div class="small" style="white-space:pre-line">{{ $p->justification }}</div>@endif
  </div></div>

  <div class="card flush mt-1" id="assetsCard"><div class="card-head"><h3><x-icon name="box"/> Fixed assets bought</h3></div>
  <div class="card-body table-wrap"><table class="table table-compact" id="assetsTable">
    <thead><tr><th>Asset</th><th>Purchased</th><th class="num">Cost</th><th></th></tr></thead>
    <tbody>@forelse($p->assets as $a)
      <tr data-asset="{{ $a->id }}"><td><a href="{{ route('assets.show', $a) }}"><b>{{ $a->asset_no }}</b></a> {{ $a->name }}</td><td class="small">{{ $a->purchase_date?->format('d M Y') }}</td><td class="num">{{ money($a->cost, false) }}</td>
        <td class="right">@if($canOp && $p->status !== 'completed')<form method="post" action="{{ route('owner.capex.unlink', [$p, $a]) }}" data-confirm="Remove {{ $a->asset_no }} from the project?">@csrf @method('DELETE')<button class="btn btn-sm btn-ghost">Unlink</button></form>@endif</td></tr>
    @empty<tr><td colspan="4" class="empty">No assets linked.</td></tr>@endforelse</tbody>
  </table></div>
  @if($canOp && $p->status === 'approved')
    <div class="card-body"><form method="post" action="{{ route('owner.capex.link', $p) }}" class="filters" id="linkForm">@csrf
      <div class="field" style="flex:1"><label for="linkAsset">Link a fixed asset from the register</label><select name="asset_id" id="linkAsset" required><option value="">Choose…</option>@foreach($linkable as $a)<option value="{{ $a->id }}">{{ $a->asset_no }} {{ $a->name }} · {{ money($a->cost) }} · {{ $a->purchase_date?->format('d M Y') }}</option>@endforeach</select></div>
      <button class="btn">Link</button></form>
      <p class="tiny muted">@if($p->funding === 'reserve')Linking a reserve-funded asset draws its cost from the FF&E reserve.@else The owner / operating cash pays for this project – nothing is drawn from the reserve.@endif Spend without a fixed asset (e.g. repairs) is recorded as a reserve drawdown.</p></div>
  @endif
  </div>
</div>

<div>
  <div class="card" id="spendCard"><div class="card-head"><h3><x-icon name="money"/> Spend</h3></div><div class="card-body">
    <div class="between-row"><span>Spent</span><b id="spent">{{ money($spent) }}</b></div>
    <div class="cp-bar mt-1"><span style="width:{{ $pctSpent }}%;background:{{ $spent > (float) $p->budget ? '#dc2626' : '#16a34a' }}"></span></div>
    <div class="between-row tiny muted mt-1"><span>{{ $pctSpent }}% of {{ money($p->budget) }}</span><span class="{{ $spent > (float) $p->budget ? 'text-red' : '' }}">{{ $spent > (float) $p->budget ? money($spent - (float) $p->budget).' over' : money((float) $p->budget - $spent).' left' }}</span></div>
  </div></div>
  <div class="card mt-1" id="drawCard"><div class="card-head"><h3><x-icon name="wallet"/> Reserve drawdowns</h3></div><div class="card-body">
    @forelse($p->entries as $e)
      <div class="between-row small"><span>{{ $e->date->format('d M Y') }} · {{ $e->memo ?: $FE::TYPES[$e->type] }}</span><b>{{ money(-$e->amount) }}</b></div>
    @empty<div class="tiny muted">Nothing drawn from the reserve.</div>@endforelse
  </div></div>
</div>
</div>
@if($canOp && in_array($p->status, ['draft', 'rejected'], true))
  @include('owner._project_modal', ['p' => $p])
@endif
@endsection
