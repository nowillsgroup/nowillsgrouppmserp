@extends('layouts.app')
@section('title', $a->number.' – '.$a->description)
@section('crumbs') <a href="{{ route('close.index') }}">Period close</a> / <a href="{{ route('close.accruals') }}">Accruals &amp; prepayments</a> / {{ $a->number }} @endsection
@php
  $A = \App\Models\Accrual::class; $me = auth()->id(); $appr = can_do('close.approve');
  $canCancel = in_array($a->status, ['pending', 'active']) && ($appr || ($a->status === 'pending' && (int) $a->created_by === (int) $me));
@endphp
@section('actions')
  @if($canCancel)<button class="btn" data-modal="cancelModal" id="cancelBtn">Cancel schedule</button>@endif
@endsection
@section('content')
<div class="mb-1"><span class="badge badge-{{ $A::TONES[$a->status] }}" id="accStatus">{{ $A::STATUSES[$a->status] }}</span> <span class="small muted">· {{ $A::TYPES[$a->type] }} · entered by {{ $a->creator?->name }} {{ $a->created_at?->format('d M Y') }}@if($a->approver) · {{ $a->status === 'rejected' ? 'rejected' : 'approved' }} by {{ $a->approver->name }} {{ $a->approved_at?->format('d M Y') }}@endif</span></div>
@if($a->decision_note)<div class="alert alert-info">{{ in_array($a->status, ['cancelled']) ? 'Cancelled' : 'Approver' }}: “{{ $a->decision_note }}”</div>@endif

@if($a->status === 'pending' && $appr)
<div class="card mb-1" id="decideCard"><div class="card-body">
  @if((int) $a->created_by === (int) $me)<p class="small" style="margin:0"><x-icon name="shield"/> You entered this schedule – another approver must decide on it (four eyes).</p>
  @else
  <form method="post" action="{{ route('close.accruals.decide', $a) }}" class="flex gap-1 wrap items-end" id="decideForm">@csrf
    <div class="field" style="flex:1;min-width:16rem"><label for="dNote">Note (required to reject)</label><input name="note" id="dNote" maxlength="255"></div>
    <button class="btn" name="decision" value="reject" id="rejectAccrual">Reject</button>
    <button class="btn btn-primary" name="decision" value="approve" id="approveAccrual"><x-icon name="check"/> Approve</button>
  </form>
  @endif
</div></div>
@endif

<div class="stats">
  <x-stat label="Total" :value="money($a->amount)" icon="money" tone="blue" :sub="$a->months > 1 ? $a->months.' months × '.money(round($a->amount / $a->months, 2)) : 'one month'"/>
  <x-stat label="Posted" :value="money($a->posted)" icon="check" tone="green" :sub="$a->last_period ? 'to '.\Illuminate\Support\Carbon::parse($a->last_period.'-01')->format('M Y') : 'nothing yet'"/>
  <x-stat label="Remaining" :value="money($a->remaining())" icon="layers" tone="amber" :sub="$a->balanceAccount?->name"/>
  <x-stat label="Expense / income" :value="$a->plAccount?->code" icon="book" tone="purple" :sub="$a->plAccount?->name"/>
</div>

<div class="grid g-2-1 mt-1">
  <div class="card flush"><div class="card-head"><h3><x-icon name="calendar"/> Schedule</h3></div><div class="card-body table-wrap"><table class="table" id="scheduleTable">
    <thead><tr><th>Month</th><th class="num">Amount</th><th>Status</th><th>Journal</th>@if($a->type === 'accrual')<th>Reversal</th>@endif</tr></thead>
    <tbody>@foreach($schedule as $m => $amt) @php $pp = $posted[$m] ?? null; $rv = $reversals[$m] ?? null; $end = \Illuminate\Support\Carbon::parse($m.'-01')->endOfMonth()->toDateString(); @endphp
      <tr data-month="{{ $m }}"><td class="small">{{ \Illuminate\Support\Carbon::parse($m.'-01')->format('F Y') }}</td><td class="num">{{ money($amt, false) }}</td>
        <td>@if($pp)<span class="badge badge-green">posted {{ $pp->date->format('d M') }}</span>@elseif(in_array($a->status, ['cancelled', 'rejected']))<span class="badge badge-slate">not posted</span>@elseif($lock && $end <= $lock)<span class="badge badge-amber">catch-up due</span>@elseif($end < bdate()->toDateString())<span class="badge badge-amber">due</span>@else<span class="badge badge-slate">future</span>@endif</td>
        <td class="small">@if($pp?->journal)<a href="{{ route('gl.journals.show', $pp->journal) }}">{{ $pp->journal->journal_no }}</a>@endif</td>
        @if($a->type === 'accrual')<td class="small">@if($rv?->journal)<a href="{{ route('gl.journals.show', $rv->journal) }}">{{ $rv->journal->journal_no }}</a> <span class="muted">{{ $rv->date->format('d M') }}</span>@endif</td>@endif
      </tr>@endforeach</tbody>
  </table></div></div>
  <div class="card"><div class="card-head"><h3><x-icon name="book"/> Details</h3></div><div class="card-body small">
    <p><b>{{ $a->description }}</b></p>
    @if($a->supplier)<p>Supplier / customer: {{ $a->supplier->name }}</p>@endif
    @if($a->reference)<p>Reference: {{ $a->reference }}</p>@endif
    <p>Each month: @if($a->type === 'deferral')Dr {{ $a->balanceAccount?->code }} {{ $a->balanceAccount?->name }} / Cr {{ $a->plAccount?->code }} {{ $a->plAccount?->name }}@else Dr {{ $a->plAccount?->code }} {{ $a->plAccount?->name }} / Cr {{ $a->balanceAccount?->code }} {{ $a->balanceAccount?->name }}@endif @if($a->type === 'accrual'), reversed on the 1st of the next month @endif</p>
    @if($a->notes)<p class="muted">{{ $a->notes }}</p>@endif
  </div></div>
</div>

@if($canCancel)
<div class="modal" id="cancelModal"><div class="modal-box"><form method="post" action="{{ route('close.accruals.cancel', $a) }}">@csrf
  <div class="modal-head"><h3>Cancel {{ $a->number }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><p class="small">Months already posted stay posted; {{ money($a->remaining()) }} will not be posted.</p><div class="form-grid"><div class="field span-all"><label for="cReason">Reason <span class="req">*</span></label><input name="reason" id="cReason" minlength="5" maxlength="255" required></div></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="cancelSave">Cancel schedule</button></div>
</form></div></div>
@endif
@endsection
