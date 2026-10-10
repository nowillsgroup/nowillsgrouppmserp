@extends('layouts.app')
@section('title', 'Service Charge & Tips')
@section('crumbs') HR &amp; Payroll / Service charge &amp; tips @endsection
@section('actions')
@if(can_do('servicecharge.manage'))<button class="btn" data-modal="tipModal" id="addTip"><x-icon name="money"/> Record tips</button>
<button class="btn btn-primary" data-modal="distModal" id="newDist" @disabled(! $suggest['ok'])><x-icon name="plus"/> New distribution</button>@endif
@if(can_do('reports.view'))<a class="btn" href="{{ route('reports.show', ['service_charge_distribution']) }}"><x-icon name="chart"/> Report</a>@endif
@endsection
@section('content')
@php $srcLabels = ['pos' => 'POS service charge', 'folio_tx' => 'Folio service charge', 'tip' => 'Tips', 'voucher' => 'Vouchers'] + \App\Models\GlJournal::SOURCES; @endphp
<div class="stats" id="scStats">
  <x-stat label="Undistributed pool (2110)" :value="money($available)" icon="wallet" tone="green" :sub="'posted up to '.dmy($cutoff)"/>
  <x-stat :label="'Collected '.\Carbon\Carbon::parse($month.'-01')->format('M Y')" :value="money(array_sum($bySource))" icon="trend" :sub="collect($bySource)->map(fn ($v, $k) => ($srcLabels[$k] ?? $k).' '.money($v, false))->implode(' · ') ?: 'nothing posted yet'"/>
  <x-stat label="House retention" :value="rtrim(rtrim(number_format($retention, 2), '0'), '.').'%'" icon="building" sub="kept by the hotel for breakages / admin"/>
  <x-stat label="Awaiting approval / payroll" :value="$dists->where('status', 'draft')->count().' / '.$dists->where('status', 'approved')->count()" icon="check" tone="amber"/>
</div>

<div data-tabwrap class="mt-1">
<div class="tabs" data-tabs>
  <button class="{{ $tab === 'distributions' ? 'active' : '' }}" data-tab="distributions">Distributions</button>
  <button class="{{ $tab === 'tips' ? 'active' : '' }}" data-tab="tips">Tips register</button>
  <button class="{{ $tab === 'staff' ? 'active' : '' }}" data-tab="staff">Staff points</button>
</div>

<div class="tab-pane {{ $tab === 'distributions' ? 'active' : '' }}" id="distributions">
  <div class="card flush"><div class="card-body table-wrap"><table class="table" id="distTable">
    <thead><tr><th>No.</th><th>Period</th><th class="num">Collected</th><th class="num">House</th><th class="num">Pool</th><th class="num">Distributed</th><th class="num">Staff</th><th>Status</th><th>Payroll</th></tr></thead>
    <tbody>
    @forelse($dists as $d)
      <tr data-dist="{{ $d->dist_no }}"><td><a class="bold" href="{{ route('sc.show', $d) }}">{{ $d->dist_no }}</a></td><td>{{ $d->label() }}<div class="tiny muted">{{ \App\Models\ScDistribution::BASES[$d->basis] ?? $d->basis }}</div></td>
        <td class="num">{{ money($d->collected, false) }}</td><td class="num">{{ money($d->retained, false) }}</td><td class="num">{{ money($d->pool, false) }}</td><td class="num bold">{{ money($d->distributed, false) }}</td><td class="num">{{ $d->paid_count }}</td>
        <td><span class="badge {{ ['draft' => 'badge-slate', 'approved' => 'badge-amber', 'posted' => 'badge-green', 'cancelled' => 'badge-red'][$d->status] }}">{{ $d->status }}</span><div class="tiny muted">{{ $d->approver ? 'by '.$d->approver->name : 'prepared by '.$d->creator?->name }}</div></td>
        <td class="small">{{ $d->run ? $d->run->run_no.' ('.$d->run->status.')' : '—' }}</td></tr>
    @empty
      <tr><td colspan="9"><x-empty text="No distributions yet. Prepare one after the month is closed." icon="wallet"/></td></tr>
    @endforelse
    </tbody></table></div></div>
  <p class="tiny muted">Service charge on POS bills and folios and any tips recorded here are credited to <b>2110 Service Charge Payable</b>. After a period is closed, the pool (collected − {{ rtrim(rtrim(number_format($retention, 2), '0'), '.') }}% house retention + rounding carried in) is shared among eligible staff and paid through payroll as a taxable earning (PAYE applies; not part of SSNIT basic salary). A second person approves.</p>
</div>

<div class="tab-pane {{ $tab === 'tips' ? 'active' : '' }}" id="tips">
  <form method="get" class="filters"><input type="hidden" name="tab" value="tips"><div class="field"><label for="f_month">Month</label><input type="month" name="month" id="f_month" value="{{ $month }}" onchange="this.form.submit()"></div></form>
  <div class="card flush"><div class="card-body table-wrap"><table class="table" id="tipTable">
    <thead><tr><th>No.</th><th>Date</th><th>Source</th><th>Received in</th><th>Reference</th><th class="num">Amount</th><th></th></tr></thead>
    <tbody>
    @forelse($tips as $t)
      <tr class="{{ $t->cancelled_at ? 'muted' : '' }}" data-tip="{{ $t->tip_no }}"><td class="bold">{{ $t->tip_no }}</td><td>{{ dmy($t->date) }}</td><td>{{ \App\Models\TipCollection::SOURCES[$t->source] ?? $t->source }}</td><td class="small">{{ $t->paymentMode?->name }}</td>
        <td class="small">{{ $t->reference }}@if($t->notes)<div class="tiny muted">{{ $t->notes }}</div>@endif @if($t->cancelled_at)<div class="tiny text-red">cancelled: {{ $t->cancel_reason }}</div>@endif</td>
        <td class="num">{{ $t->cancelled_at ? '' : money($t->amount, false) }}</td>
        <td>@if(! $t->cancelled_at && can_do('servicecharge.manage'))<button class="btn btn-sm" data-modal="reasonModal" data-action="{{ route('sc.tip.cancel', $t) }}" data-title="Cancel {{ $t->tip_no }}">Cancel</button>@endif</td></tr>
    @empty
      <tr><td colspan="7"><x-empty text="No tips recorded this month." icon="money"/></td></tr>
    @endforelse
    </tbody>
    @if($tips->isNotEmpty())<tfoot><tr><th colspan="5">Total</th><th class="num">{{ money($tips->whereNull('cancelled_at')->sum('amount'), false) }}</th><th></th></tr></tfoot>@endif
  </table></div></div>
  <p class="tiny muted">Card and MoMo tips added to a bill, the cash tips box and banquet gratuities are paid into the pool here: Dr cash / card clearing / MoMo, Cr 2110 – posted at the night audit.</p>
</div>

<div class="tab-pane {{ $tab === 'staff' ? 'active' : '' }}" id="staff">
  <form method="post" action="{{ route('sc.points') }}" id="pointsForm">@csrf
  <div class="card flush"><div class="card-body table-wrap"><table class="table" id="pointsTable">
    <thead><tr><th>Employee</th><th>Department</th><th>Designation</th><th class="center">Eligible</th><th class="num">Points</th></tr></thead>
    <tbody>
    @foreach($staff as $e)
      <tr data-emp="{{ $e->emp_code }}"><td><b>{{ $e->name }}</b> <span class="tiny muted">{{ $e->emp_code }}</span></td><td class="small">{{ $e->department?->name }}</td><td class="small">{{ $e->designation }}</td>
        <td class="center"><input type="checkbox" name="eligible[{{ $e->id }}]" value="1" @checked($e->sc_eligible) @disabled(! can_do('servicecharge.manage')) aria-label="Eligible {{ $e->name }}"></td>
        <td class="num"><input type="number" step="0.5" min="0" max="20" name="points[{{ $e->id }}]" value="{{ rtrim(rtrim(number_format((float) $e->sc_points, 2), '0'), '.') }}" style="width:5rem;text-align:right" @disabled(! can_do('servicecharge.manage')) aria-label="Points {{ $e->name }}"></td></tr>
    @endforeach
    </tbody></table></div>
    @if(can_do('servicecharge.manage'))<div class="card-foot" style="padding:.75rem 1rem;text-align:right"><button class="btn btn-primary" id="pointsSave">Save points</button></div>@endif
  </div></form>
  @if(can_do('servicecharge.approve'))
  <div class="card mt-2"><div class="card-body"><form method="post" action="{{ route('sc.settings') }}" class="form-grid" id="scSettings">@csrf
    <x-field name="sc_retention_pct" label="House retention (%)" type="number" step="0.5" min="0" max="100" :value="rtrim(rtrim(number_format($retention, 2), '0'), '.')" required/>
    <div><button class="btn btn-primary">Save</button></div></form>
    <p class="tiny muted">Typical points: managers / heads of department 3, supervisors 2, line staff 1. Shares = points × days present (half days count ½) unless the distribution uses another basis.</p>
  </div></div>
  @endif
</div>
</div>

@if(can_do('servicecharge.manage'))
<div class="modal" id="tipModal"><div class="modal-box"><form method="post" action="{{ route('sc.tip') }}">@csrf
  <div class="modal-head"><h3>Record tips into the pool</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <x-field name="date" label="Date" type="date" :value="bdate()->toDateString()" :max="bdate()->toDateString()" required/>
    <x-field name="source" label="Source" type="select" :options="\App\Models\TipCollection::SOURCES" required/>
    <x-field name="payment_mode_id" label="Received in" type="select" :options="$modes" required/>
    <x-field name="amount" label="Amount (GH₵)" type="number" step="0.01" min="0.01" required/>
  </div>
  <x-field name="reference" label="Reference" maxlength="60" placeholder="e.g. POS batch 0921 / box count sheet"/>
  <x-field name="notes" label="Notes" maxlength="255"/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="tipSave">Record</button></div>
</form></div></div>
<div class="modal" id="distModal"><div class="modal-box"><form method="post" action="{{ route('sc.store') }}">@csrf
  <div class="modal-head"><h3>Prepare a distribution</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <x-field name="period_start" label="From" type="date" :value="$suggest['from']" required/>
    <x-field name="period_end" label="To" type="date" :value="$suggest['to']" :max="$cutoff" required/>
  </div>
  <x-field name="basis" label="Share by" type="select" :options="\App\Models\ScDistribution::BASES" value="points_days" :placeholder="false" required/>
  <x-field name="notes" label="Notes" maxlength="255"/>
  <p class="tiny muted">The period must be closed (up to {{ dmy($cutoff) }}) and must not overlap an earlier distribution. Approve the time clock days first – days worked come from attendance.</p></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="distCreate">Prepare</button></div>
</form></div></div>
<div class="modal" id="reasonModal"><div class="modal-box"><form method="post" action="#" data-reset>@csrf
  <div class="modal-head"><h3 data-title>Reason</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="reason" label="Reason" required maxlength="255"/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Confirm</button></div>
</form></div></div>
@endif
@endsection
