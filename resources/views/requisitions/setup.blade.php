@extends('layouts.app')
@section('title', 'Department Budgets')
@section('crumbs') <a href="{{ route('req.index') }}">Requisitions</a> / Budgets &amp; setup @endsection
@section('actions')
<a class="btn" href="{{ route('req.pars') }}" id="parLink"><x-icon name="layers"/> Store par levels</a>
@endsection
@section('content')
@php $tot = $departments->filter(fn ($d) => $d->budget_status['budget'] !== null); @endphp
<div class="stats req5">
  <x-stat label="Departments with a budget" :value="$tot->count().' / '.$departments->count()" icon="users" tone="blue"/>
  <x-stat label="Total monthly budget" :value="money($tot->sum(fn ($d) => $d->budget_status['budget']))" icon="wallet" tone="slate" :sub="bdate()->format('F Y')"/>
  <x-stat label="Issued this month" :value="money($departments->sum(fn ($d) => $d->budget_status['issued']))" icon="box" tone="green"/>
  <x-stat label="Committed" :value="money($departments->sum(fn ($d) => $d->budget_status['committed']))" icon="clock" tone="amber" sub="approved, not yet issued"/>
  <x-stat label="Over budget" :value="$tot->filter(fn ($d) => $d->budget_status['left'] < 0)->count()" icon="alert" :tone="$tot->filter(fn ($d) => $d->budget_status['left'] < 0)->count() ? 'red' : 'green'" sub="department(s)"/>
</div>
<div class="grid g-2-1">
<div class="card flush"><div class="card-head"><h3>Departments</h3><span class="tiny muted">{{ bdate()->format('F Y') }}</span></div><div class="card-body table-wrap"><table class="table" id="deptTable">
<thead><tr><th>Department</th><th>Head (approver)</th><th>Cost account</th><th>Delivers to</th><th class="num">Budget</th><th style="min-width:180px">This month</th>@if(can_do('requisitions.manage'))<th></th>@endif</tr></thead><tbody>
@foreach($departments as $d)
@php $b = $d->budget_status; @endphp
<tr>
  <td class="bold">{{ $d->name }}</td>
  <td class="small">{{ $d->hod?->name ?? '—' }}</td>
  <td class="small">{{ $d->expenseHead ? $d->expenseHead->code.' '.$d->expenseHead->name : '— store default' }}</td>
  <td class="small">{{ $d->defaultStore?->name ?? 'Expensed on issue' }}</td>
  <td class="num">{{ $b['budget'] !== null ? money($b['budget'], false) : '—' }}</td>
  <td>@if($b['budget'] !== null)
    <div class="budget-bar sm"><span class="bb-used" style="width:{{ min(100, $b['issued'] / $b['budget'] * 100) }}%"></span><span class="bb-this {{ $b['left'] < 0 ? 'over' : '' }}" style="width:{{ max(0, min(100, $b['used'] / $b['budget'] * 100) - min(100, $b['issued'] / $b['budget'] * 100)) }}%"></span></div>
    <div class="tiny {{ $b['left'] < 0 ? 'text-red' : 'muted' }}">{{ $b['pct'] }} % · {{ $b['left'] < 0 ? 'over by '.money(-$b['left'], false) : money($b['left'], false).' left' }}</div>
  @else<span class="tiny muted">issued {{ money($b['issued'], false) }}{{ $b['committed'] ? ' · committed '.money($b['committed'], false) : '' }}</span>@endif</td>
  @if(can_do('requisitions.manage'))<td><button class="btn btn-sm" data-modal="dept{{ $d->id }}">Edit</button></td>@endif
</tr>
@endforeach
</tbody></table></div></div>
<div>
  <div class="card"><div class="card-head"><h3>Approval rules</h3></div><div class="card-body">
  @if(can_do('requisitions.manage'))
    <form method="post" action="{{ route('req.settings') }}" class="form-grid">@csrf
      <div class="field span-all"><label>Auto-approve below (value)</label><input type="number" name="req_auto_approve_below" min="0" step="1" class="input" value="{{ old('req_auto_approve_below', $settings['req_auto_approve_below'] ?? 0) }}"><span class="hint">Small requisitions within budget skip approval. 0 = every requisition needs approval.</span></div>
      <div class="field span-all"><label>Over budget</label><select name="req_over_budget" class="input"><option value="warn" @selected(($settings['req_over_budget'] ?? 'warn') === 'warn')>Head of department may approve with a reason</option><option value="block" @selected(($settings['req_over_budget'] ?? 'warn') === 'block')>Only a user with setup rights may approve</option></select></div>
      <div class="span-all"><button class="btn btn-primary" id="saveReqSettings">Save rules</button></div>
    </form>
  @else
    <ul class="list"><li><span>Auto-approve below</span><b>{{ (float) ($settings['req_auto_approve_below'] ?? 0) ? money($settings['req_auto_approve_below']) : 'off' }}</b></li><li><span>Over budget</span><b>{{ ($settings['req_over_budget'] ?? 'warn') === 'block' ? 'setup rights only' : 'HOD with reason' }}</b></li></ul>
  @endif
  </div></div>
  <div class="card"><div class="card-body small muted">
    <p class="m-0 mb-1"><b>Head (approver)</b> – only this user (or someone with setup rights) approves the department's requisitions. With no head set, anyone with approval rights can. Nobody approves their own.</p>
    <p class="m-0 mb-1"><b>Cost account</b> – where direct issues are expensed in the general ledger. Blank = by store type (F&amp;B / guest supplies / general stores).</p>
    <p class="m-0 mb-1"><b>Delivers to</b> – the department's own store. Requisitions default to a transfer into it (no expense until it is used there).</p>
    <p class="m-0"><b>Budget</b> – monthly value of stock issued to the department. Approvals count what is issued this month plus what is approved but not yet issued.</p>
  </div></div>
</div>
</div>
@if(can_do('requisitions.manage'))
@foreach($departments as $d)
<div class="modal" id="dept{{ $d->id }}"><div class="modal-box"><form method="post" action="{{ route('req.dept.update', $d) }}">@csrf @method('PUT')
  <div class="modal-head"><h3>{{ $d->name }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    <div class="field"><label>Head of department</label><select name="hod_user_id" class="input"><option value="">— none (any approver) —</option>@foreach($users as $id => $n)<option value="{{ $id }}" @selected($d->hod_user_id == $id)>{{ $n }}</option>@endforeach</select></div>
    <div class="field"><label>Monthly stores budget</label><input type="number" name="monthly_budget" min="0" step="100" class="input" value="{{ $d->monthly_budget !== null ? (float) $d->monthly_budget : '' }}" placeholder="no budget"></div>
    <div class="field"><label>Cost account</label><select name="expense_head_id" class="input"><option value="">— by store type —</option>@foreach($heads as $id => $n)<option value="{{ $id }}" @selected($d->expense_head_id == $id)>{{ $n }}</option>@endforeach</select></div>
    <div class="field"><label>Delivers to (own store)</label><select name="default_store_id" class="input"><option value="">— expensed on issue —</option>@foreach($stores as $id => $n)<option value="{{ $id }}" @selected($d->default_store_id == $id)>{{ $n }}</option>@endforeach</select></div>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Save</button></div>
</form></div></div>
@endforeach
@endif
@endsection
