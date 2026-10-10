@extends('layouts.app')
@section('title', 'Accruals & Prepayments')
@section('crumbs') <a href="{{ route('close.index') }}">Period close</a> / Accruals &amp; prepayments @endsection
@php $A = \App\Models\Accrual::class; $prep = can_do('close.prepare'); @endphp
@section('actions')
  @if($prep)<button class="btn btn-primary" data-modal="accrualModal" id="newAccrualBtn"><x-icon name="plus"/> New schedule</button>@endif
@endsection
@section('content')
<div class="stats">
  <x-stat label="Awaiting approval" :value="$stats['pending']" icon="shield" :tone="$stats['pending'] ? 'amber' : 'green'" :sub="$stats['active'].' active schedule(s)'"/>
  <x-stat label="Prepaid expenses" :value="money($stats['prepaid'])" icon="wallet" tone="blue" sub="asset – released to expense monthly"/>
  <x-stat label="Accrued expenses" :value="money($stats['accrued'])" icon="receipt" tone="amber" sub="liability – reversed on the 1st"/>
  <x-stat label="Deferred income" :value="money($stats['deferred'])" icon="layers" tone="purple" :sub="money($stats['remaining']).' still to release on active schedules'"/>
</div>

<form class="filters card mt-1" method="get" id="accFilters"><div class="card-body flex gap-1 wrap items-end">
  <div class="field"><label>Type</label><select name="type" onchange="this.form.submit()"><option value="">All types</option>@foreach($A::TYPES as $k => $v)<option value="{{ $k }}" @selected(request('type') === $k)>{{ $v }}</option>@endforeach</select></div>
  <div class="field"><label>Status</label><select name="status" onchange="this.form.submit()"><option value="">Current (not rejected)</option>@foreach($A::STATUSES as $k => $v)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $v }}</option>@endforeach</select></div>
</div></form>

<div class="card flush"><div class="card-body table-wrap"><table class="table" id="accrualTable">
  <thead><tr><th>No.</th><th>Type</th><th>Description</th><th>Accounts</th><th>Months</th><th class="num">Amount</th><th class="num">Posted</th><th class="num">Remaining</th><th>Status</th></tr></thead>
  <tbody>@forelse($rows as $a)
    <tr data-accrual="{{ $a->number }}" data-status="{{ $a->status }}">
      <td><a class="bold" href="{{ route('close.accruals.show', $a) }}">{{ $a->number }}</a></td>
      <td class="small">{{ $A::TYPES[$a->type] }}</td>
      <td class="small"><b>{{ $a->description }}</b>@if($a->supplier)<div class="tiny muted">{{ $a->supplier->name }}</div>@endif @if($a->reference)<div class="tiny muted">ref {{ $a->reference }}</div>@endif</td>
      <td class="tiny">{{ $a->plAccount?->code }} {{ $a->plAccount?->name }}<div class="muted">{{ $a->balanceAccount?->code }} {{ $a->balanceAccount?->name }}</div></td>
      <td class="small">{{ \Illuminate\Support\Carbon::parse($a->start_period.'-01')->format('M Y') }}@if($a->months > 1) – {{ \Illuminate\Support\Carbon::parse($a->endPeriod().'-01')->format('M Y') }}<div class="tiny muted">{{ $a->months }} months</div>@endif</td>
      <td class="num">{{ money($a->amount, false) }}</td>
      <td class="num">{{ money($a->posted, false) }}</td>
      <td class="num {{ $a->status === 'active' ? 'bold' : 'muted' }}">{{ money($a->remaining(), false) }}</td>
      <td><span class="badge badge-{{ $A::TONES[$a->status] }}">{{ $A::STATUSES[$a->status] }}</span><div class="tiny muted">{{ $a->creator?->name }}@if($a->approver) → {{ $a->approver->name }}@endif</div></td>
    </tr>
  @empty <tr><td colspan="9"><x-empty text="No schedules yet – add accrued expenses, prepaid expenses or deferred income." icon="layers"/></td></tr> @endforelse</tbody>
</table></div></div>

<div class="card mt-1"><div class="card-body small">
  <b>How schedules post</b> (at each month end, with the period-end postings):
  <ul style="margin:.3rem 0 0 1.1rem;padding:0">
    <li><b>Accrued expense</b> – a cost of the month not yet billed (ECG bill, audit fee): Dr expense / Cr 2150 Accrued Expenses at month end, reversed automatically on the 1st so the real bill posts normally.</li>
    <li><b>Prepaid expense</b> – paid in advance to 1320 Prepaid Expenses (insurance, software, licences): Dr expense / Cr 1320 in equal monthly parts.</li>
    <li><b>Deferred income</b> – received in advance to 2145 Deferred Income (annual ATM / shop space rent, sponsorship): Dr 2145 / Cr income in equal monthly parts.</li>
  </ul>
  <p class="tiny muted" style="margin:.4rem 0 0">Months already closed are caught up in the first open month. Every schedule needs a second person's approval before it posts.</p>
</div></div>

@if($prep)
<div class="modal" id="accrualModal"><div class="modal-box" style="max-width:44rem"><form method="post" action="{{ route('close.accruals.store') }}" id="accrualForm">@csrf
  <div class="modal-head"><h3>New schedule</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field"><label for="aType">Type <span class="req">*</span></label><select name="type" id="aType" required>@foreach($A::TYPES as $k => $v)<option value="{{ $k }}" @selected(old('type', 'prepayment') === $k)>{{ $v }}</option>@endforeach</select></div>
    <div class="field"><label for="aSupplier">Supplier / customer</label><select name="supplier_id" id="aSupplier"><option value="">—</option>@foreach($suppliers as $s)<option value="{{ $s->id }}" @selected((int) old('supplier_id') === $s->id)>{{ $s->name }}</option>@endforeach</select></div>
    <div class="field span-2"><label for="aDesc">Description <span class="req">*</span></label><input name="description" id="aDesc" maxlength="150" required value="{{ old('description') }}" placeholder="e.g. Fire & burglary insurance – Enterprise Insurance, Oct 2026 – Sep 2027"></div>
    <div class="field span-2"><label for="aPl">Expense / income account <span class="req">*</span></label><select name="pl_account_id" id="aPl" required><option value="">Choose…</option>
      <optgroup label="Expenses (accrued / prepaid)">@foreach($accounts['expense'] as $h)<option value="{{ $h->id }}" @selected((int) old('pl_account_id') === $h->id)>{{ $h->code }} · {{ $h->name }}</option>@endforeach</optgroup>
      <optgroup label="Income (deferred)">@foreach($accounts['income'] as $h)<option value="{{ $h->id }}" @selected((int) old('pl_account_id') === $h->id)>{{ $h->code }} · {{ $h->name }}</option>@endforeach</optgroup></select></div>
    <div class="field span-2"><label for="aBal">Balance-sheet account</label><select name="balance_account_id" id="aBal"><option value="">Standard for the type (2150 / 1320 / 2145)</option>@foreach($accounts['balance'] as $h)<option value="{{ $h->id }}" @selected((int) old('balance_account_id') === $h->id)>{{ $h->code }} · {{ $h->name }}</option>@endforeach</select></div>
    <div class="field"><label for="aAmount">Total amount (GH₵) <span class="req">*</span></label><input type="number" step="0.01" min="1" name="amount" id="aAmount" required value="{{ old('amount') }}"></div>
    <div class="field"><label for="aStart">First month <span class="req">*</span></label><input type="month" name="start_period" id="aStart" required min="{{ $minStart }}" value="{{ old('start_period', $curPeriod) }}"></div>
    <div class="field"><label for="aMonths">Spread over (months)</label><input type="number" min="1" max="60" name="months" id="aMonths" value="{{ old('months', 12) }}"></div>
    <div class="field"><label for="aRef">Reference</label><input name="reference" id="aRef" maxlength="80" value="{{ old('reference') }}" placeholder="policy / invoice no."></div>
    <div class="field span-all"><label for="aNotes">Notes</label><input name="notes" id="aNotes" maxlength="255" value="{{ old('notes') }}"></div>
  </div><p class="tiny muted" id="aHint">Accrued expenses cover one month and reverse on the 1st; prepaid expenses and deferred income are spread evenly (the last month takes the rounding).</p></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="accrualSave">Save for approval</button></div>
</form></div></div>
<script>
(function () {
  const t = document.getElementById('aType'), m = document.getElementById('aMonths');
  const sync = () => { const acc = t.value === 'accrual'; m.disabled = acc; if (acc) m.value = 1; else if (m.value === '1') m.value = 12; };
  t.addEventListener('change', sync); sync();
})();
</script>
@endif
@endsection
