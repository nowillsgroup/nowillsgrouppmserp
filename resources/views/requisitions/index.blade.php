@extends('layouts.app')
@section('title', 'Store Requisitions')
@section('crumbs') Inventory / Requisitions @endsection
@section('actions')
<a class="btn" href="{{ route('req.setup') }}"><x-icon name="wallet"/> Budgets &amp; par levels</a>
@if(can_do('requisitions.raise'))<a class="btn btn-primary" href="{{ route('req.create') }}" id="newReq"><x-icon name="plus"/> New requisition</a>@endif
@endsection
@section('content')

{{-- v9.18 the header now carries the age of each queue and the money that has left the store unsigned --}}
<div class="stats wide">
  <x-stat label="Needs my action" :value="$counts['action']" icon="bell" :tone="$counts['action'] ? 'red' : 'green'" sub="approve, issue or acknowledge" href="?show=action"/>
  <x-stat label="Awaiting approval" :value="$counts['submitted']" icon="clock" tone="amber"
    :sub="$age['head']['count'] ? 'with the head of department · oldest '.$age['head']['oldest_days'].' day'.($age['head']['oldest_days'] === 1 ? '' : 's') : 'nothing is with a head of department'" href="?show=submitted"/>
  <x-stat label="To issue" :value="$counts['approved'] + $counts['partial']" icon="box" tone="blue"
    :sub="$counts['partial'].' part issued'.($age['store']['oldest_days'] ? ' · oldest '.$age['store']['oldest_days'].' day'.($age['store']['oldest_days'] === 1 ? '' : 's') : '')" href="?show=approved"/>
  <x-stat label="Awaiting receipt" :value="$counts['receipt']" icon="check" tone="purple"
    :sub="$handover['count'] ? $handover['count'].' issue'.($handover['count'] === 1 ? '' : 's').' · '.money($handover['value']).' unsigned' : 'every issue has been signed for'" href="?show=receipt"/>
  <x-stat label="Issued this month" :value="money($monthIssued)" icon="money" tone="slate" :sub="$monthIssues.' issue note'.($monthIssues === 1 ? '' : 's')"/>
</div>

{{-- v9.18 the queue ageing: a requisition is waiting on a person, and how long it has waited is the whole service level --}}
<div class="card" id="reqAge">
  <div class="card-head"><h3>How long has each queue waited?</h3>
    <span class="muted small">{{ $age['head']['count'] }} with a head of department · {{ $age['store']['count'] }} with the store · oldest {{ $age['store']['oldest_days'] > $age['head']['oldest_days'] ? $age['store']['oldest_days'] : $age['head']['oldest_days'] }} day{{ ($age['store']['oldest_days'] > $age['head']['oldest_days'] ? $age['store']['oldest_days'] : $age['head']['oldest_days']) === 1 ? '' : 's' }}</span></div>
  <div class="card-body">
    <div class="table-wrap"><table class="table table-compact" id="reqAgeTable">
      <thead><tr><th>Queue</th><th class="num">Requisitions</th><th class="num">Value</th><th class="num">Oldest</th><th>Waiting longest</th></tr></thead><tbody>
      <tr data-queue="head"><td>With the head of department</td>
        <td class="num">{{ $age['head']['count'] }}</td>
        <td class="num">{{ $age['head']['count'] ? money($age['head']['value'], false) : '—' }}</td>
        <td class="num {{ $age['head']['oldest_days'] > 7 ? 'text-red bold' : '' }}">{{ $age['head']['count'] ? $age['head']['oldest_days'].' d' : '—' }}</td>
        <td class="small">{{ $age['head']['oldest'] ? $age['head']['oldest']['req_no'].' · '.$age['head']['oldest']['department'] : '—' }}</td></tr>
      <tr data-queue="store"><td>With the store, to issue</td>
        <td class="num">{{ $age['store']['count'] }}</td>
        <td class="num">{{ $age['store']['count'] ? money($age['store']['value'], false) : '—' }}</td>
        <td class="num {{ $age['store']['oldest_days'] > 7 ? 'text-red bold' : '' }}">{{ $age['store']['count'] ? $age['store']['oldest_days'].' d' : '—' }}</td>
        <td class="small">{{ $age['store']['oldest'] ? $age['store']['oldest']['req_no'].' · '.$age['store']['oldest']['department'] : '—' }}</td></tr>
      </tbody></table></div>
    <p class="small {{ ($age['head']['oldest_days'] > 7 || $age['store']['oldest_days'] > 7) ? 'text-red' : '' }}" id="reqAgeState" style="margin:.6rem 0 0">
      @if($age['head']['oldest'] && $age['store']['oldest'])
        <x-icon name="clock"/>The oldest with the head of department is {{ $age['head']['oldest']['req_no'] }} from {{ $age['head']['oldest']['department'] }} —
        {{ money($age['head']['oldest']['value']) }}, raised on {{ dmy($age['head']['oldest']['since']) }} and waiting {{ $age['head']['oldest']['days'] }} days.
        The oldest with the store is {{ $age['store']['oldest']['req_no'] }} from {{ $age['store']['oldest']['department'] }} — {{ money($age['store']['oldest']['value']) }},
        approved on {{ dmy($age['store']['oldest']['since']) }} and still open for {{ $age['store']['oldest']['days'] }} days.
      @elseif($age['head']['oldest'])
        <x-icon name="clock"/>The oldest with the head of department is {{ $age['head']['oldest']['req_no'] }} from {{ $age['head']['oldest']['department'] }} —
        {{ money($age['head']['oldest']['value']) }}, waiting {{ $age['head']['oldest']['days'] }} days. The store owes nobody an issue.
      @elseif($age['store']['oldest'])
        <x-icon name="clock"/>The oldest with the store is {{ $age['store']['oldest']['req_no'] }} from {{ $age['store']['oldest']['department'] }} —
        {{ money($age['store']['oldest']['value']) }}, approved on {{ dmy($age['store']['oldest']['since']) }} and still open for {{ $age['store']['oldest']['days'] }} days.
      @else
        <x-icon name="check"/>Nothing is waiting on a person: every requisition on the book has been issued, signed or closed.
      @endif
      @if($age['head']['pending'] + $age['store']['pending'])
        {{ $age['head']['pending'] + $age['store']['pending'] }} of them are past the date the department asked for the goods by.
      @endif
    </p>
  </div>
</div>

{{-- v9.18 the allowance: issued this month plus what is still committed on open requisitions, against the budget it answers to --}}
<div class="card" id="reqBudget">
  <div class="card-head"><h3>What is left of each department's month?</h3>
    <span class="muted small">{{ $budget['count'] }} department{{ $budget['count'] === 1 ? '' : 's' }} with an allowance · {{ $budget['over'] }} over it</span></div>
  <div class="card-body">
    <div class="stats" style="margin:0">
      <x-stat label="Allowance, this month" :value="money($budget['total_budget'])" icon="wallet" tone="slate" sub="the budgets the departments answer to for this month"/>
      <x-stat label="Used" :value="money($budget['total_used'])" icon="box" tone="slate" sub="issued this month plus what the open requisitions will still take"/>
      <x-stat label="Over the allowance" :value="money($budget['over_value'])" icon="alert" :tone="$budget['over'] ? 'red' : 'green'"
        :sub="$budget['over'] ? $budget['over'].' department'.($budget['over'] === 1 ? ' is' : 's are').' over this month' : 'no department is over its allowance'"/>
    </div>
    <div class="table-wrap" style="margin-top:.6rem"><table class="table table-compact" id="budgetTable">
      <thead><tr><th>Department</th><th class="num">Allowance</th><th class="num">Issued</th><th class="num">Committed</th><th class="num">Used</th><th class="num">Left</th><th class="num">Used %</th></tr></thead><tbody>
      @forelse($budget['rows'] as $b)<tr data-over="{{ $b['left'] < 0 ? 1 : 0 }}">
        <td class="bold">{{ $b['name'] }}@if($b['note'])<div class="tiny muted">{{ $b['note'] }}</div>@endif</td>
        <td class="num">{{ money($b['budget'], false) }}</td><td class="num">{{ money($b['issued'], false) }}</td>
        <td class="num">{{ money($b['committed'], false) }}</td><td class="num">{{ money($b['used'], false) }}</td>
        <td class="num {{ $b['left'] < 0 ? 'text-red bold' : '' }}">{{ money($b['left'], false) }}</td>
        <td class="num {{ $b['pct'] > 100 ? 'text-red bold' : ($b['pct'] > 90 ? 'bold' : '') }}">{{ $b['pct'] }} %</td></tr>
      @empty<tr><td colspan="7" class="empty">No department has a stores allowance set for this month.</td></tr>@endforelse
      </tbody></table></div>
    <p class="small {{ $budget['over'] ? 'text-red' : '' }}" id="reqBudgetState" style="margin:.6rem 0 0">
      @if($budget['over'])
        <x-icon name="alert"/>{{ $budget['over'] }} department{{ $budget['over'] === 1 ? ' is' : 's are' }} over the month's allowance, {{ money($budget['over_value']) }} in all —
        {{ $budget['over_rows'][0]['name'] }} at {{ $budget['over_rows'][0]['pct'] }} %, {{ money(abs($budget['over_rows'][0]['left'])) }} past its {{ money($budget['over_rows'][0]['budget']) }}.
        Anything further is a decision for the head of department, not a surprise at month end.
      @elseif($budget['worst'])
        <x-icon name="check"/>No department is over its allowance. The least room left is {{ $budget['worst']['name'] }} — {{ $budget['worst']['pct'] }} % used,
        {{ money($budget['worst']['left']) }} left of {{ money($budget['worst']['budget']) }}.@if($budget['no_budget']) {{ $budget['no_budget'] }} department{{ $budget['no_budget'] === 1 ? ' has' : 's have' }} no allowance set, so nothing is held to {{ $budget['no_budget'] === 1 ? 'it' : 'them' }}.@endif
      @else
        <x-icon name="info"/>No department has an allowance for this month, so no requisition can be measured against one.
      @endif
    </p>
  </div>
</div>

{{-- v9.18 the handover: goods leave the store on an issue note, and the note is only worth the signature on it --}}
<div class="card" id="reqHandover">
  <div class="card-head"><h3>What has left the store with nobody's signature?</h3>
    <span class="muted small">{{ $handover['count'] }} issue note{{ $handover['count'] === 1 ? '' : 's' }} unsigned · {{ money($handover['value']) }} · oldest {{ $handover['oldest'] ? $handover['oldest']['days'].' days' : '—' }}</span></div>
  <div class="card-body">
    <div class="stats" style="margin:0">
      <x-stat label="Unsigned" :value="money($handover['value'])" icon="alert" :tone="$handover['count'] ? 'red' : 'green'" :sub="$handover['count'].' issue note'.($handover['count'] === 1 ? '' : 's').' the receiving department has not acknowledged'"/>
      <x-stat label="Signed in the last 30 days" :value="$handover['signed']" icon="check" tone="slate"
        :sub="$handover['avg_days'] !== null ? 'acknowledged in '.$handover['avg_days'].($handover['avg_days'] == 1 ? ' day' : ' days').' on average' : 'nothing has been issued in the last thirty days'"/>
      <x-stat label="Oldest unsigned" :value="$handover['oldest'] ? $handover['oldest']['days'].' day'.($handover['oldest']['days'] === 1 ? '' : 's') : '—'" icon="clock"
        :tone="($handover['oldest']['days'] ?? 0) > 7 ? 'red' : 'slate'" sub="counted from the day the goods left the store"/>
    </div>
    @if($handover['count'])
      <p class="small {{ ($handover['oldest']['days'] ?? 0) > 7 ? 'text-red' : '' }}" id="reqHandoverState" style="margin:.6rem 0 0"><x-icon name="alert"/>
        The oldest is {{ $handover['oldest']['req_no'] }} for {{ $handover['oldest']['department'] }} — {{ money($handover['oldest']['value']) }} left the
        {{ $handover['oldest']['store'] }} on {{ dmy($handover['oldest']['date']) }} and nobody has signed for it, {{ $handover['oldest']['days'] }} days ago.
        Until the note is acknowledged the store has issued stock it cannot prove arrived, and the department's cost carries goods it may never have seen.
      </p>
      <div class="table-wrap" style="margin-top:.6rem"><table class="table table-compact" id="handoverTable">
        <thead><tr><th>Requisition</th><th>Department</th><th>Store</th><th>Issued</th><th class="num">Age</th><th class="num">Value</th><th>By</th></tr></thead><tbody>
        @foreach($handover['rows'] as $i)<tr data-gap="unsigned">
          <td class="bold">{{ $i['req_no'] ?? '—' }}</td><td class="small">{{ $i['department'] }}</td><td class="small">{{ $i['store'] }}</td>
          <td class="small">{{ dmy($i['date']) }}</td><td class="num {{ $i['days'] > 7 ? 'text-red bold' : '' }}">{{ $i['days'] }} d</td>
          <td class="num">{{ money($i['value'], false) }}</td><td class="small">{{ $i['by'] }}</td></tr>@endforeach
        </tbody></table></div>
    @else
      <p class="small" id="reqHandoverState" style="margin:.6rem 0 0"><x-icon name="check"/>Every issue note on the book has been acknowledged, {{ $handover['signed'] }} of them
        in the last thirty days. Nothing has left the store without the department saying it arrived.</p>
    @endif
  </div>
</div>

<div class="tabs">@foreach($tabs as $k => $v)<a href="?{{ http_build_query(array_filter(['show' => $k, 'department' => request('department'), 'mine' => request('mine')])) }}" class="{{ $tab === $k ? 'active' : '' }}">{{ $v }}@if($k !== 'all' && $k !== 'done' && $counts[$k]) ({{ $counts[$k] }})@endif</a>@endforeach</div>
<form class="filters" method="get"><input type="hidden" name="show" value="{{ $tab }}">
  <input name="q" class="input" placeholder="Requisition no. or purpose" value="{{ request('q') }}">
  <select name="department" class="input"><option value="">All departments</option>@foreach($departments as $id => $n)<option value="{{ $id }}" @selected((string) request('department') === (string) $id)>{{ $n }}</option>@endforeach</select>
  <label class="check"><input type="checkbox" name="mine" value="1" @checked(request('mine'))> Raised by me</label>
  <button class="btn"><x-icon name="search"/> Filter</button>
</form>
<div class="card flush"><div class="card-body table-wrap"><table class="table" id="reqTable">
<thead><tr><th>Requisition</th><th>Department</th><th>From → to</th><th>Items</th><th class="num">Value</th><th>Needed by</th><th>Raised / approved</th><th class="num">Age</th><th>Receipt</th><th>Status</th></tr></thead><tbody>
@forelse($rows as $r)
<tr class="{{ $r->isOverdue() ? 'row-warn' : '' }}" data-status="{{ $r->status }}" data-unsigned="{{ $r->unack_count ? 1 : 0 }}">
  <td><a class="bold" href="{{ route('req.show', $r) }}">{{ $r->req_no }}</a>@if($r->priority === 'urgent') <span class="badge badge-red">Urgent</span>@endif<div class="tiny muted">{{ $r->req_date->format('d M Y') }}{{ $r->purpose ? ' · '.\Illuminate\Support\Str::limit($r->purpose, 42) : '' }}</div></td>
  <td class="small">{{ $r->department?->name }}</td>
  <td class="small">{{ $r->fromStore?->name }}<div class="tiny muted">→ {{ $r->destination() }}</div></td>
  <td class="small">{{ count($r->lines) }} item{{ count($r->lines) === 1 ? '' : 's' }}@if($r->isOpenForIssue())<div class="tiny muted">{{ $r->outstanding_qty }} unit{{ $r->outstanding_qty == 1 ? '' : 's' }} to issue</div>@endif</td>
  <td class="num">{{ money($r->est_value, false) }}@if(count($r->issues))<div class="tiny muted">issued {{ money(collect($r->issues)->sum('value'), false) }}</div>@endif</td>
  <td class="small">@if($r->required_by)<span class="{{ $r->isOverdue() ? 'text-red bold' : '' }}">{{ $r->required_by->format('d M') }}</span>@else<span class="muted">—</span>@endif</td>
  <td class="small">{{ $r->raiser?->name }}<div class="tiny muted">{{ $r->approver ? ($r->status === 'rejected' ? 'rejected by ' : 'approved by ').$r->approver->name : ($r->approval_note && $r->approved_at ? 'auto-approved' : '') }}@if($r->over_budget) · <span class="text-red">over budget</span>@endif</div></td>
  <td class="num">{{ $r->age_days }} d<div class="tiny muted">{{ ['submitted' => 'with the head', 'approved' => 'to issue', 'partial' => 'part issued', 'issued' => 'unsigned', 'draft' => 'not submitted'][$r->status] ?? 'closed' }}</div></td>
  <td>@if($r->unack_count)<span class="badge badge-purple">{{ $r->unack_count }} unsigned</span>@elseif(count($r->issues))<span class="badge badge-green">signed</span>@else<span class="muted">—</span>@endif</td>
  <td>{!! $r->statusBadge() !!}</td>
</tr>
@empty<tr><td colspan="10"><x-empty text="No requisitions here." icon="box"/></td></tr>@endforelse
</tbody></table></div></div>
<p class="tiny muted mt-1" id="reqChain">A department raises a requisition → its head approves (quantities can be cut; nobody approves their own) → the store issues, in part or in full → the department acknowledges receipt. Issues to a department's own store (kitchen, bar) are transfers; everything else is expensed to the department's cost account. It is the mirror of the <a href="{{ route('grn.index') }}">goods receipts</a>: there, goods arrive and a bill follows; here, goods leave and a signature should.</p>
@endsection
