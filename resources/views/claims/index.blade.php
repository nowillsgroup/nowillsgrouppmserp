@extends('layouts.app')
@section('title', $mine ? 'My Expense Claims' : 'Expense Claims')
@section('crumbs') {{ $mine ? 'HR / My expense claims' : 'Accounts / Expense claims' }} @endsection
@section('actions')
@if($me || can_do('claims.approve'))<button class="btn btn-primary" data-modal="claimModal" id="newClaimBtn"><x-icon name="plus"/> New claim</button>@endif
@if(! $mine && can_do('reports.view'))<a class="btn" href="{{ route('reports.show', ['expense_claims']) }}"><x-icon name="chart"/> Claims register</a>@endif
@if(! $mine && can_do('claims.submit') && $me)<a class="btn" href="{{ route('claims.mine') }}"><x-icon name="user"/> My claims</a>@endif
@endsection
@section('content')
@php $qTotal = collect($queue); @endphp

@if(! $mine)
{{-- v9.14 a claim is money the employee has already spent, so the header is about waiting rather than about volume --}}
<div class="stats" id="claimStats">
  <x-stat label="To approve" :value="(int) ($counts['submitted']->n ?? 0)" icon="clock" tone="purple"
    :sub="money($counts['submitted']->t ?? 0).($queue['submitted']['oldest'] ? ' · oldest waiting '.$queue['submitted']['oldest'].' days' : '')"
    :href="route('claims.index', ['status' => 'submitted'])"/>
  <x-stat label="To pay" :value="(int) ($counts['approved']->n ?? 0)" icon="money" tone="blue"
    :sub="money($counts['approved']->t ?? 0).($queue['approved']['oldest'] ? ' · oldest approved '.$queue['approved']['oldest'].' days' : '')"
    :href="route('claims.index', ['status' => 'approved'])"/>
  <x-stat label="Sent back" :value="(int) ($counts['rejected']->n ?? 0)" icon="x" tone="red"
    :sub="money($counts['rejected']->t ?? 0).' waiting on the staff member'" :href="route('claims.index', ['status' => 'rejected'])"/>
  <x-stat label="Paid" :value="(int) ($counts['paid']->n ?? 0)" icon="check" tone="green"
    :sub="money($counts['paid']->t ?? 0).' reimbursed'" :href="route('claims.index', ['status' => 'paid'])"/>
</div>

{{-- v9.14 the two queues, aged. Approval waits on submitted_at, payment waits on approved_on, and a month in either is a
     message nobody answered --}}
<div class="card" id="claimQueue">
  <div class="card-head"><h3>How long has each claim waited?</h3>
    <span class="muted small">{{ $qTotal->sum('claims') }} claims · {{ money($qTotal->sum('amount')) }} in the two queues · oldest {{ $qTotal->max('oldest') }} days</span></div>
  <div class="card-body">
    <div class="table-wrap"><table class="table table-compact" id="queueTable">
      <thead><tr><th>Queue</th><th class="num">Claims</th>
        @foreach($waitBuckets as $b => $l)<th class="num">{{ $l }}</th>@endforeach
        <th class="num">Money</th><th class="num">Oldest</th></tr></thead><tbody>
      @foreach($queue as $key => $row)<tr data-queue="{{ $key }}">
        <td class="bold">{{ $row['label'] }}<div class="tiny muted">{{ $key === 'submitted' ? 'ageing from the day it was sent in' : 'ageing from the day it was approved' }}</div></td>
        <td class="num">{{ $row['claims'] }}</td>
        @foreach(array_keys($waitBuckets) as $b)<td class="num {{ $row[$b] == 0 ? 'muted' : ($b === 'w3' ? 'text-red' : '') }}">{{ $row[$b] ? money($row[$b], false) : '—' }}<div class="tiny muted">{{ $row['n'.$b] ? $row['n'.$b].' claim'.($row['n'.$b] === 1 ? '' : 's') : '' }}</div></td>@endforeach
        <td class="num bold">{{ money($row['amount'], false) }}</td>
        <td class="num {{ $row['oldest'] > 30 ? 'text-red bold' : '' }}">{{ $row['oldest'] }} d</td></tr>
      @endforeach
      </tbody>
      <tfoot><tr class="bold"><td>Total — {{ $qTotal->sum('claims') }} claims</td>
        <td class="num" id="queueSumClaims">{{ $qTotal->sum('claims') }}</td>
        @foreach(array_keys($waitBuckets) as $b)<td class="num" id="queueSum{{ $b }}">{{ money($qTotal->sum($b), false) }}</td>@endforeach
        <td class="num" id="queueSumAmount">{{ money($qTotal->sum('amount'), false) }}</td>
        <td class="num" id="queueSumOldest">{{ $qTotal->max('oldest') }} d</td></tr></tfoot>
    </table></div>
    <p class="small" id="claimQueueState" style="margin:.6rem 0 0">
      @if($queue['submitted']['worst'] && $queue['submitted']['worst']['days'] > 30)
        <x-icon name="alert"/><span class="text-red">The oldest claim to approve has waited {{ $queue['submitted']['worst']['days'] }} days:
        {{ $queue['submitted']['worst']['claim_no'] }} from {{ $queue['submitted']['worst']['staff'] }} for {{ money($queue['submitted']['worst']['amount']) }}.</span>
      @else
        <x-icon name="check"/>The oldest claim to approve has waited {{ $queue['submitted']['oldest'] }} days.
      @endif
      @if($queue['approved']['oldest'] > 14)
        The oldest approved claim has been waiting {{ $queue['approved']['oldest'] }} days for its money{{ $queue['approved']['worst'] ? ' — '.$queue['approved']['worst']['claim_no'].' from '.$queue['approved']['worst']['staff'].' for '.money($queue['approved']['worst']['amount']) : '' }}.
      @else
        The oldest approved claim has been waiting {{ $queue['approved']['oldest'] }} days for its money.
      @endif
    </p>
    {{-- the claim window, read back over the live claims rather than trusted to have been right at entry --}}
    <p class="small {{ $windowDoc['late'] ? 'text-red' : '' }}" id="claimWindow" style="margin:.3rem 0 0">
      @if($windowDoc['late'] === 0)
        <x-icon name="check"/>No line on any live claim was older than {{ $windowDoc['oldest'] }} days when it was submitted; the window is {{ $windowDoc['window'] }} days.
      @else
        <x-icon name="alert"/>{{ $windowDoc['late'] }} line{{ $windowDoc['late'] === 1 ? '' : 's' }} totalling {{ money($windowDoc['late_amount']) }} {{ $windowDoc['late'] === 1 ? 'was' : 'were' }} older than the {{ $windowDoc['window'] }}-day window when the claim was submitted:
        @foreach($windowDoc['rows'] as $l){{ $l['claim_no'] }} {{ $l['staff'] }} {{ money($l['amount'], false) }} ({{ $l['age'] }} days) @if(! $loop->last), @endif @endforeach.
      @endif
    </p>
  </div>
</div>

{{-- v9.14 the receipt rule the service enforces when a line is added, read back over the claims that were already in --}}
<div class="card" id="claimEvidence">
  <div class="card-head"><h3>Is there a receipt behind every cedi claimed?</h3>
    <span class="muted small">{{ $receipt['needing'] }} line{{ $receipt['needing'] === 1 ? '' : 's' }} over {{ money($receipt['threshold']) }} · {{ $receipt['with_receipt'] }} on file · {{ $receipt['with_ref'] }} by reference only · {{ $receipt['missing'] }} with neither</span></div>
  <div class="card-body">
    <div class="stats" style="margin:0">
      <x-stat label="Claimed over the threshold" :value="money($receipt['amount'])" icon="receipt" tone="slate" :sub="$receipt['needing'].' lines on live claims'"/>
      <x-stat label="With a receipt" :value="$receipt['with_receipt']" icon="check" tone="slate" sub="a scan or photo is attached"/>
      <x-stat label="By reference only" :value="$receipt['with_ref']" icon="edit" tone="amber" sub="a transaction number stands in for the scan"/>
      <x-stat label="With neither" :value="money($receipt['missing_amount'])" icon="alert" :tone="$receipt['missing'] ? 'red' : 'green'"
        :sub="$receipt['missing'] ? $receipt['missing'].' line'.($receipt['missing'] === 1 ? '' : 's').' with nothing behind '.($receipt['missing'] === 1 ? 'it' : 'them') : 'every line is evidenced'"/>
    </div>
    @if($receipt['missing'] === 0)
      <p class="small" id="claimEvidenceState" style="margin:.6rem 0 0"><x-icon name="check"/>Every one of the {{ $receipt['needing'] }} lines over {{ money($receipt['threshold']) }}
        carries a receipt or a transaction number, covering {{ money($receipt['amount']) }}. Mileage is paid at {{ money($cfg['claims_mileage_rate']) }}/km and needs no receipt.</p>
    @else
      <p class="small text-red" id="claimEvidenceState" style="margin:.6rem 0 0"><x-icon name="alert"/>{{ $receipt['missing'] }} line{{ $receipt['missing'] === 1 ? '' : 's' }} over {{ money($receipt['threshold']) }}
        {{ $receipt['missing'] === 1 ? 'has' : 'have' }} neither a receipt nor a transaction number, {{ money($receipt['missing_amount']) }} in all. Ask the staff member for the receipt before the claim is paid —
        the same rule that stops the line being entered is the rule the payment rests on.</p>
      <div class="table-wrap" style="margin-top:.6rem"><table class="table table-compact" id="gapTable">
        <thead><tr><th>Claim</th><th>Staff</th><th>Expense date</th><th class="num">Age</th><th>Line</th><th class="num">Amount</th></tr></thead><tbody>
        @foreach($receipt['gap_rows'] as $g)<tr data-evidence="none">
          <td class="bold">{{ $g['claim_no'] }}</td><td class="small">{{ $g['staff'] }}</td>
          <td class="small">{{ dmy($g['date']) }}</td><td class="num">{{ $g['age'] }} d</td>
          <td class="small">{{ $g['description'] }}<div class="tiny muted">{{ $g['status'] }}</div></td>
          <td class="num text-red">{{ money($g['amount'], false) }}</td></tr>@endforeach
        </tbody></table></div>
    @endif
  </div>
</div>
@elseif(! $me)
<div class="alert alert-info"><x-icon name="alert"/><div>Your user is not linked to an employee record, so you cannot claim for yourself. Ask HR to link it.</div></div>
@endif

<div class="card flush mt-1">
  <div class="card-head"><h3>{{ $mine ? 'My claims' : 'Claims' }}</h3>
    @if(! $mine)<form class="filters" style="margin:0"><select name="status" class="input" data-autosubmit>@foreach(['submitted' => 'To approve', 'approved' => 'To pay', 'draft' => 'Draft', 'rejected' => 'Sent back', 'paid' => 'Paid', 'all' => 'All'] as $k => $l)<option value="{{ $k }}" @selected($status === $k)>{{ $l }}</option>@endforeach</select>
      <input type="search" name="q" value="{{ $q }}" class="input" placeholder="Claim no., title, staff"> <button class="btn btn-sm">Search</button></form>@endif
  </div>
  <div class="card-body table-wrap"><table class="table" id="claimTable">
    <thead><tr><th>Claim</th><th>Staff</th><th>Title</th><th>Items</th><th class="num">Total</th><th class="num">Input VAT</th><th>Status</th><th class="num">Age</th><th>Updated</th></tr></thead>
    <tbody>
    @forelse($claims as $c)
      <tr data-claim="{{ $c->claim_no }}" data-status="{{ $c->status }}">
        <td><a href="{{ route('claims.show', $c) }}" class="bold">{{ $c->claim_no }}</a></td><td>{{ $c->employee?->name }}<div class="tiny muted">{{ $c->employee?->department?->name }}</div></td>
        <td class="small">{{ $c->title }} @if($c->status === 'rejected' && $c->reject_reason)<div class="tiny text-red">{{ $c->reject_reason }}</div>@endif</td><td class="small">{{ $c->lines_count }}</td>
        <td class="num">{{ money($c->total, false) }}</td><td class="num">{{ (float) $c->vat_total ? money($c->vat_total, false) : '' }}</td>
        <td>{!! status_badge($c->status) !!}</td>
        <td class="num {{ $c->waiting === null ? 'muted' : ($c->waiting > 30 ? 'text-red bold' : '') }}">
          @if($c->waiting === null)—@else{{ $c->waiting }} d<div class="tiny muted">{{ $c->status === 'submitted' ? 'waiting' : 'to pay' }}</div>@endif</td>
        <td class="small">{{ $c->updated_at->format('d M Y') }}</td></tr>
    @empty<tr><td colspan="9"><x-empty text="No claims here." icon="receipt"/></td></tr>@endforelse
    </tbody></table></div>
  @if($claims->hasPages())<div class="card-body">{{ $claims->links() }}</div>@endif
</div>
<p class="tiny muted">Claim back what you paid for the hotel from your own pocket: transport, mileage at {{ money($cfg['claims_mileage_rate']) }}/km, meals on duty, client entertainment, airtime, small purchases. Attach receipts above {{ money($cfg['claims_receipt_threshold']) }}. Expenses older than {{ $cfg['claims_max_age_days'] }} days cannot be claimed.</p>

@if(! $mine && can_do('claims.approve'))
<div class="card mt-2"><div class="card-head"><h3><x-icon name="tool"/> Settings</h3></div><div class="card-body">
  <form method="post" action="{{ route('claims.settings') }}" class="form-grid" id="claimSettings">@csrf
    <div class="field"><label for="f_claims_mileage_rate">Mileage rate (GH₵ per km)</label><input type="number" step="0.01" min="0" name="claims_mileage_rate" id="f_claims_mileage_rate" value="{{ $cfg['claims_mileage_rate'] }}" required></div>
    <div class="field"><label for="f_claims_receipt_threshold">Receipt required above (GH₵)</label><input type="number" step="0.01" min="0" name="claims_receipt_threshold" id="f_claims_receipt_threshold" value="{{ $cfg['claims_receipt_threshold'] }}" required></div>
    <div class="field"><label for="f_claims_max_age_days">Claim within (days)</label><input type="number" min="7" max="365" name="claims_max_age_days" id="f_claims_max_age_days" value="{{ $cfg['claims_max_age_days'] }}" required></div>
    <div><button class="btn btn-primary">Save</button></div>
  </form>
</div></div>
@endif

<div class="modal" id="claimModal"><div class="modal-box"><form method="post" action="{{ route('claims.store') }}">@csrf
  <div class="modal-head"><h3>New expense claim</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">
    <x-field name="title" label="Title" required maxlength="150" placeholder="e.g. Kumasi supplier visit, 14–15 Sep"/>
    @if(! $mine && can_do('claims.approve'))<x-field name="employee_id" label="For staff member" type="select" :options="$employees" :placeholder="$me ? '— myself —' : '— Select —'" :value="$me?->id"/>@endif
    <x-field name="notes" label="Notes" type="textarea" maxlength="500"/>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="claimSave">Start claim</button></div>
</form></div></div>
@endsection
