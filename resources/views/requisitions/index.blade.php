@extends('layouts.app')
@section('title', 'Store Requisitions')
@section('crumbs') Inventory / Requisitions @endsection
@section('actions')
<a class="btn" href="{{ route('req.setup') }}"><x-icon name="wallet"/> Budgets &amp; par levels</a>
@if(can_do('requisitions.raise'))<a class="btn btn-primary" href="{{ route('req.create') }}" id="newReq"><x-icon name="plus"/> New requisition</a>@endif
@endsection
@section('content')
<div class="stats wide">
  <x-stat label="Needs my action" :value="$counts['action']" icon="bell" :tone="$counts['action'] ? 'red' : 'green'" sub="approve, issue or acknowledge" href="?show=action"/>
  <x-stat label="Awaiting approval" :value="$counts['submitted']" icon="clock" tone="amber" sub="with the head of department" href="?show=submitted"/>
  <x-stat label="To issue" :value="$counts['approved'] + $counts['partial']" icon="box" tone="blue" :sub="$counts['partial'].' part issued'" href="?show=approved"/>
  <x-stat label="Awaiting receipt" :value="$counts['receipt']" icon="check" tone="purple" sub="issued, not yet acknowledged" href="?show=receipt"/>
  <x-stat label="Issued this month" :value="money($monthIssued)" icon="money" tone="slate" :sub="$monthIssues.' issue note(s)'"/>
</div>
<div class="tabs">@foreach(\App\Http\Controllers\RequisitionController::TABS as $k => $v)<a href="?{{ http_build_query(array_filter(['show' => $k, 'department' => request('department'), 'mine' => request('mine')])) }}" class="{{ $tab === $k ? 'active' : '' }}">{{ $v }}@if($k !== 'all' && $k !== 'done' && $counts[$k]) ({{ $counts[$k] }})@endif</a>@endforeach</div>
<form class="filters" method="get"><input type="hidden" name="show" value="{{ $tab }}">
  <input name="q" class="input" placeholder="Requisition no. or purpose" value="{{ request('q') }}">
  <select name="department" class="input"><option value="">All departments</option>@foreach($departments as $id => $n)<option value="{{ $id }}" @selected((string) request('department') === (string) $id)>{{ $n }}</option>@endforeach</select>
  <label class="check"><input type="checkbox" name="mine" value="1" @checked(request('mine'))> Raised by me</label>
  <button class="btn"><x-icon name="search"/> Filter</button>
</form>
<div class="card flush"><div class="card-body table-wrap"><table class="table" id="reqTable">
<thead><tr><th>Requisition</th><th>Department</th><th>From → to</th><th>Items</th><th class="num">Value</th><th>Needed by</th><th>Raised / approved</th><th>Status</th></tr></thead><tbody>
@forelse($rows as $r)
@php $unack = $r->issues->filter(fn ($i) => ! $i->received_at)->count(); @endphp
<tr class="{{ $r->isOverdue() ? 'row-warn' : '' }}">
  <td><a class="bold" href="{{ route('req.show', $r) }}">{{ $r->req_no }}</a>@if($r->priority === 'urgent') <span class="badge badge-red">Urgent</span>@endif<div class="tiny muted">{{ $r->req_date->format('d M Y') }}{{ $r->purpose ? ' · '.\Illuminate\Support\Str::limit($r->purpose, 42) : '' }}</div></td>
  <td class="small">{{ $r->department?->name }}</td>
  <td class="small">{{ $r->fromStore?->name }}<div class="tiny muted">→ {{ $r->destination() }}</div></td>
  <td class="small">{{ $r->lines->count() }} item(s)@if($r->isOpenForIssue())<div class="tiny muted">{{ rtrim(rtrim(number_format($r->outstandingQty(), 2), '0'), '.') }} unit(s) to issue</div>@endif</td>
  <td class="num">{{ money($r->est_value, false) }}@if($r->issues->count())<div class="tiny muted">issued {{ money($r->issues->sum('value'), false) }}</div>@endif</td>
  <td class="small">@if($r->required_by)<span class="{{ $r->isOverdue() ? 'text-red bold' : '' }}">{{ $r->required_by->format('d M') }}</span>@else<span class="muted">—</span>@endif</td>
  <td class="small">{{ $r->raiser?->name }}<div class="tiny muted">{{ $r->approver ? ($r->status === 'rejected' ? 'rejected by ' : 'approved by ').$r->approver->name : ($r->approval_note && $r->approved_at ? 'auto-approved' : '') }}@if($r->over_budget) · <span class="text-red">over budget</span>@endif</div></td>
  <td>{!! $r->statusBadge() !!}@if($unack)<div class="tiny text-purple">{{ $unack }} to acknowledge</div>@endif</td>
</tr>
@empty<tr><td colspan="8"><x-empty text="No requisitions here." icon="box"/></td></tr>@endforelse
</tbody></table></div></div>
<p class="tiny muted mt-1">A department raises a requisition → its head approves (quantities can be cut; nobody approves their own) → the store issues, in part or in full → the department acknowledges receipt. Issues to a department's own store (kitchen, bar) are transfers; everything else is expensed to the department's cost account.</p>
@endsection
