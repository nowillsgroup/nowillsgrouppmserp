@extends('layouts.app')
@section('title', 'FF&E Reserve & Capex')
@section('crumbs') Owner Reporting / FF&E reserve & capex @endsection
@php
  $CP = \App\Models\CapexProject::class; $FE = \App\Models\FfeEntry::class; $U = \App\Services\UsaliService::class;
  $canOp = can_do('owner.operate');
  $gap = $funded === null ? null : round($ledger['closing'] - $funded, 2);
  $committed = $projects->where('status', 'approved')->where('funding', 'reserve')->sum(fn ($p) => max(0, (float) $p->budget - $p->spent()));
@endphp
@section('actions')
  <a class="btn" href="{{ route('reports.show', ['report' => 'ffe_reserve']) }}"><x-icon name="download"/> Report</a>
  @if($canOp)<button class="btn" data-modal="entryModal" id="newEntryBtn"><x-icon name="plus"/> Reserve entry</button>
  <button class="btn btn-primary" data-modal="projectModal" id="newProjectBtn" data-action="{{ route('owner.capex.store') }}"><x-icon name="tool"/> New capex request</button>@endif
@endsection
@section('content')
@if($gap !== null && $gap > 0.005)
  <div class="alert alert-warn" id="fundAlert"><x-icon name="alert"/> The reserve ledger shows {{ money($ledger['closing']) }} but the reserve bank account holds {{ money($funded) }} – {{ money($gap) }} still has to be moved into it.@if($fundUrl && can_do('treasury.operate')) <a href="{{ $fundUrl }}" id="fundLink">Transfer the difference ›</a>@endif</div>
@endif
<div class="stats tr-stats">
  <x-stat label="Reserve balance" :value="money($ledger['closing'])" icon="wallet" tone="blue" sub="contributions + interest − capex drawdowns"/>
  <x-stat label="Cash in reserve account" :value="$funded === null ? '—' : money($funded)" icon="building" :tone="$gap === null ? 'slate' : ($gap > 0.005 ? 'red' : 'green')" :sub="$agreement?->reserveAccount?->name ?? 'link a bank account in Agreement & mapping'"/>
  <x-stat label="Committed to approved projects" :value="money($committed)" icon="tool" :tone="$ledger['closing'] - $committed < 0 ? 'red' : 'amber'" :sub="($ledger['closing'] - $committed < 0 ? 'short by ' : 'available after commitments ').money(abs($ledger['closing'] - $committed))"/>
  <x-stat label="Awaiting owner approval" :value="money($projects->where('status', 'submitted')->sum('budget'))" icon="clock" tone="purple" :sub="$projects->where('status', 'submitted')->count().' project(s)'"/>
</div>

<div class="card flush mt-1" id="projectsCard"><div class="card-head"><h3><x-icon name="tool"/> Capital projects</h3></div>
<div class="card-body table-wrap"><table class="table table-compact" id="projectsTable">
  <thead><tr><th>Project</th><th>Category</th><th>Funding</th><th class="num">Budget</th><th class="num">Spent</th><th class="num">Left</th><th>Status</th></tr></thead>
  <tbody>@forelse($projects as $p) @php $sp = $p->spent(); @endphp
    <tr data-project="{{ $p->number }}" class="{{ $p->status === 'submitted' ? 'row-due' : '' }}">
      <td><a href="{{ route('owner.capex.show', $p) }}"><b>{{ $p->number }}</b></a> {{ $p->title }}<div class="tiny muted">{{ $p->requester?->name }}{{ $p->needed_by ? ' · needed by '.$p->needed_by->format('d M Y') : '' }}</div></td>
      <td class="small">{{ $CP::CATEGORIES[$p->category] ?? $p->category }}@if($p->usali_dept)<div class="tiny muted">{{ $U::label($p->usali_dept) }}</div>@endif</td>
      <td class="small">{{ $CP::FUNDING[$p->funding] }}</td>
      <td class="num">{{ money($p->budget, false) }}</td><td class="num">{{ money($sp, false) }}</td>
      <td class="num {{ $sp > (float) $p->budget ? 'text-red' : '' }}">{{ money((float) $p->budget - $sp, false) }}</td>
      <td><span class="badge badge-{{ $CP::TONES[$p->status] }}">{{ $CP::STATUSES[$p->status] }}</span></td>
    </tr>
  @empty<tr><td colspan="7" class="empty">No capital projects yet.</td></tr>@endforelse</tbody>
</table></div></div>

<div class="card flush mt-1" id="ledgerCard"><div class="card-head"><h3><x-icon name="book"/> Reserve ledger</h3><span class="tiny muted">The reserve is a memorandum fund; the cash sits in the reserve bank account.</span></div>
<div class="card-body table-wrap"><table class="table table-compact" id="ledgerTable">
  <thead><tr><th>Date</th><th>Type</th><th>Detail</th><th class="num">In</th><th class="num">Out</th><th class="num">Balance</th><th></th></tr></thead>
  <tbody>@forelse(array_reverse($ledger['rows']) as $r) @php $e = $r['entry']; @endphp
    <tr data-entry="{{ $e->id }}" data-type="{{ $e->type }}">
      <td class="small">{{ $e->date->format('d M Y') }}</td><td class="small">{{ $FE::TYPES[$e->type] }}</td>
      <td class="small">@if($e->project)<a href="{{ route('owner.capex.show', $e->project) }}">{{ $e->project->number }}</a> @endif{{ $e->memo }}</td>
      <td class="num">{{ $e->amount > 0 ? money($e->amount, false) : '' }}</td><td class="num">{{ $e->amount < 0 ? money(-$e->amount, false) : '' }}</td>
      <td class="num bold">{{ money($r['balance'], false) }}</td>
      <td class="right">@if($canOp && $e->type !== 'contribution' && ! $e->asset_id)<form method="post" action="{{ route('owner.reserve.entry.delete', $e) }}" data-confirm="Remove this entry?">@csrf @method('DELETE')<button class="btn btn-sm btn-ghost btn-icon" aria-label="Remove"><x-icon name="trash"/></button></form>@endif</td>
    </tr>
  @empty<tr><td colspan="7" class="empty">No entries yet – record the opening balance.</td></tr>@endforelse</tbody>
</table></div></div>

@if($canOp)
<div class="modal" id="entryModal"><div class="modal-box" style="max-width:34rem"><form method="post" action="{{ route('owner.reserve.entry') }}" id="entryForm" data-reset>@csrf
  <div class="modal-head"><h3>FF&E reserve entry</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field"><label for="eType">Type</label><select name="type" id="eType" required>@foreach(['opening', 'interest', 'drawdown', 'adjustment'] as $t)<option value="{{ $t }}">{{ $FE::TYPES[$t] }}</option>@endforeach</select></div>
    <div class="field"><label for="eDate">Date</label><input type="date" name="date" id="eDate" value="{{ bdate()->toDateString() }}" max="{{ bdate()->toDateString() }}" required></div>
    <div class="field"><label for="eAmt">Amount (GH₵)</label><input type="number" step="0.01" name="amount" id="eAmt" required><div class="tiny muted">adjustments may be negative</div></div>
    <div class="field"><label for="eProj">Capex project</label><select name="capex_project_id" id="eProj"><option value="">—</option>@foreach($spendable->where('funding', 'reserve') as $p)<option value="{{ $p->id }}">{{ $p->number }} {{ $p->title }}</option>@endforeach</select><div class="tiny muted">drawdowns only (spend without a fixed asset)</div></div>
    <div class="field span-all"><label for="eMemo">Memo</label><input name="memo" id="eMemo" maxlength="255"></div>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Save</button></div>
</form></div></div>
@include('owner._project_modal', ['p' => null])
@endif
@endsection
