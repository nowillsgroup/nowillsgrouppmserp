@extends('layouts.app')
@section('title', 'Corrective Actions')
@section('crumbs') Quality Assurance / Corrective Actions @endsection
@php $X = \App\Models\QaAction::class; $me = auth()->user(); @endphp
@section('actions')
  <a class="btn" href="{{ route('reports.show', ['report' => 'qa_actions']) }}"><x-icon name="download"/> Action log</a>
  @if($canAudit)<button class="btn btn-primary" data-modal="qaActionModal" id="raiseActionBtn"><x-icon name="plus"/> Raise action</button>@endif
@endsection
@section('content')
<div class="tabs" id="actionTabs">@foreach(['open' => 'Open', 'overdue' => 'Overdue', 'verify' => 'To verify', 'mine' => 'Mine', 'unassigned' => 'Unassigned', 'closed' => 'Closed', 'all' => 'All'] as $k => $l)<a class="{{ $view === $k ? 'active' : '' }}" href="{{ route('qa.actions', ['view' => $k]) }}">{{ $l }} <span class="{{ in_array($k, ['overdue', 'verify'], true) && $tabs[$k] ? 'text-red' : 'muted' }}">{{ $tabs[$k] }}</span></a>@endforeach</div>
<div class="card"><div class="card-body"><form method="get" class="flex gap-1 wrap" style="align-items:center"><input type="hidden" name="view" value="{{ $view }}">
  <select name="dept" aria-label="Department"><option value="">All departments</option>@foreach($departments as $d)<option value="{{ $d->id }}" @selected((int) request('dept') === $d->id)>{{ $d->name }}</option>@endforeach</select>
  <select name="priority" aria-label="Priority"><option value="">Any priority</option>@foreach($X::PRIORITIES as $k => $p)<option value="{{ $k }}" @selected(request('priority') === $k)>{{ $p[0] }}</option>@endforeach</select>
  <input name="q" value="{{ request('q') }}" placeholder="CA no. or text" aria-label="Search"><button class="btn btn-sm">Filter</button>
  <span class="tiny muted">Owners record the root cause and fix; quality verifies it on the floor – never the owner or whoever completed it.</span>
</form></div></div>
<div class="card flush"><div class="card-body table-wrap"><table class="table table-compact" id="actionTable"><thead><tr><th>Action</th><th>Department</th><th>Owner</th><th>Due</th><th>Priority</th><th>Status</th><th></th></tr></thead><tbody>
  @forelse($actions as $x)@php $own = $x->owner_id === $me->id; $open = in_array($x->status, $X::OPEN, true); @endphp
  <tr data-action="{{ $x->number }}" class="{{ $x->isOverdue() ? 'row-alert' : '' }}">
    <td><b>{{ $x->number }}</b> {{ $x->title }}
      <div class="tiny muted">@if($x->audit)<a href="{{ route('qa.audit', $x->audit) }}">{{ $x->audit->number }}</a> · @endif{{ $x->created_at->format('d M') }}@if($x->reopened) · <span class="text-red">reopened {{ $x->reopened }}×</span>@endif @if($x->ticket) · ticket {{ $x->ticket->ticket_no }} ({{ $x->ticket->status }})@endif</div>
      @if($x->root_cause)<div class="tiny"><b>Cause:</b> {{ \Illuminate\Support\Str::limit($x->root_cause, 120) }}</div>@endif
      @if($x->action_plan)<div class="tiny"><b>Plan:</b> {{ \Illuminate\Support\Str::limit($x->action_plan, 120) }}</div>@endif
      @if($x->completion_note)<div class="tiny"><b>Done:</b> {{ \Illuminate\Support\Str::limit($x->completion_note, 120) }}</div>@endif
      @if($x->verify_note)<div class="tiny {{ $x->status === 'verified' ? 'text-green' : 'text-red' }}"><b>{{ $x->status === 'verified' ? 'Verified' : ($x->status === 'cancelled' ? 'Cancelled' : 'Not accepted') }}:</b> {{ $x->verify_note }}</div>@endif</td>
    <td class="small">{{ $x->department?->name ?? '—' }}</td><td class="small">{{ $x->owner?->name ?? '—' }}</td>
    <td class="small nowrap {{ $x->isOverdue() ? 'text-red' : '' }}">{{ $x->due_date->format('d M Y') }}</td>
    <td><span class="badge badge-{{ $x->priorityTone() }}">{{ $x->priorityLabel() }}</span></td><td><span class="badge badge-{{ $x->statusTone() }}">{{ $x->statusLabel() }}</span></td>
    <td class="nowrap">
      @if($open && ($own || $canManage))<button class="btn btn-sm" data-modal="qaEditModal" data-action="{{ route('qa.action.update', $x) }}" data-title="{{ $x->number }} – plan" data-froot_cause="{{ $x->root_cause }}" data-faction_plan="{{ $x->action_plan }}" data-fowner_id="{{ $x->owner_id }}" data-fdue_date="{{ $x->due_date->toDateString() }}" data-fpriority="{{ $x->priority }}">{{ $canManage ? 'Assign / plan' : 'Plan' }}</button>
        @if($x->owner_id)<button class="btn btn-sm btn-primary" data-modal="qaDoneModal" data-action="{{ route('qa.action.done', $x) }}" data-title="{{ $x->number }} – done">Done</button>@endif
        @if(! $x->maintenance_request_id)<button class="btn btn-sm" data-modal="qaTicketModal" data-action="{{ route('qa.action.ticket', $x) }}" title="Raise an engineering ticket"><x-icon name="tool"/></button>@endif @endif
      @if($x->status === 'done' && $canManage)@if($x->owner_id !== $me->id && $x->completed_by !== $me->id)<button class="btn btn-sm btn-primary" data-modal="qaVerifyModal" data-action="{{ route('qa.action.verify', $x) }}" data-title="Verify {{ $x->number }}">Verify</button>@else<span class="tiny muted">another manager verifies</span>@endif @endif
      @if($canManage && in_array($x->status, ['open', 'in_progress', 'done'], true))<button class="btn btn-sm btn-ghost" data-modal="qaCancelActionModal" data-action="{{ route('qa.action.cancel', $x) }}" title="Cancel"><x-icon name="x"/></button>@endif
    </td></tr>
  @empty<tr><td colspan="7" class="muted">No corrective actions here.</td></tr>@endforelse
</tbody></table></div></div>
{{ $actions->links() }}
<div class="modal" id="qaEditModal"><div class="modal-box" style="max-width:38rem"><form method="post" action="#">@csrf
  <div class="modal-head"><h3 data-title>Plan</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    @if($canManage)<div class="field"><label>Owner</label><select name="owner_id"><option value="">Unassigned</option>@foreach($people as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select></div>
    <div class="field"><label>Due</label><input type="date" name="due_date"></div>
    <div class="field"><label>Priority</label><select name="priority">@foreach($X::PRIORITIES as $k => $p)<option value="{{ $k }}">{{ $p[0] }}</option>@endforeach</select></div>@endif
    <div class="field span-all"><label>Root cause</label><textarea name="root_cause" rows="2" placeholder="Why did it happen? (training, equipment, staffing, process…)"></textarea></div>
    <div class="field span-all"><label>Action plan</label><textarea name="action_plan" rows="3"></textarea></div>
    <div class="field span-all"><label class="check"><input type="checkbox" name="start" value="1"> Start work now (in progress)</label></div>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Save</button></div>
</form></div></div>
<div class="modal" id="qaDoneModal"><div class="modal-box"><form method="post" action="#" data-reset>@csrf
  <div class="modal-head"><h3 data-title>Done</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="field"><label>What was done</label><textarea name="completion_note" rows="3" required placeholder="e.g. Carpet shampooed, staff re-briefed on SOP-HK-003 on 28 Sep"></textarea></div><p class="tiny muted">Quality will check the fix before it is closed.</p></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Mark done</button></div>
</form></div></div>
<div class="modal" id="qaTicketModal"><div class="modal-box"><form method="post" action="#" data-reset>@csrf
  <div class="modal-head"><h3>Engineering ticket</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid"><div class="field"><label>Category</label><select name="category">@foreach(['general', 'electrical', 'plumbing', 'ac', 'carpentry', 'it', 'civil'] as $c)<option value="{{ $c }}">{{ $c === 'ac' ? 'A/C' : ($c === 'it' ? 'IT' : ucfirst($c)) }}</option>@endforeach</select></div>
    <div class="field"><label>Location</label><input name="location" maxlength="120" placeholder="e.g. Room 204 bathroom"></div></div><p class="tiny muted">Priority and due date follow the action.</p></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Raise ticket</button></div>
</form></div></div>
@if($canManage)
<div class="modal" id="qaVerifyModal"><div class="modal-box"><form method="post" action="#" data-reset>@csrf
  <div class="modal-head"><h3 data-title>Verify</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="field"><label>Decision</label><select name="decision"><option value="accept">Verified – the fix holds</option><option value="reject">Not accepted – reopen</option></select></div>
    <div class="field"><label>Note</label><input name="verify_note" maxlength="255" placeholder="required when reopening"></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Save decision</button></div>
</form></div></div>
<div class="modal" id="qaCancelActionModal"><div class="modal-box"><form method="post" action="#" data-reset>@csrf
  <div class="modal-head"><h3>Cancel action</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="field"><label>Reason</label><input name="reason" required maxlength="255"></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-danger">Cancel action</button></div>
</form></div></div>
@endif
@if($canAudit)@include('qa._action_modal')@endif
@endsection
