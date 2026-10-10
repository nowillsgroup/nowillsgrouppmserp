@extends('layouts.app')
@section('title', 'Management Fees')
@section('crumbs') Owner Reporting / Management fees @endsection
@php
  $F = \App\Models\OwnerFee::class;
  $canOp = can_do('owner.operate');
  $unpaid = $fees->where('status', 'accrued');
  $live = $fees->where('status', '!=', 'reversed');
  $latest = $live->sortByDesc('period')->first();
@endphp
@section('actions')
  <a class="btn" href="{{ route('reports.show', ['report' => 'management_fees']) }}"><x-icon name="download"/> Report</a>
@endsection
@section('content')
@if(! $agreement)
  <div class="alert alert-info"><x-icon name="alert"/> Set up the management agreement before accruing fees. @if(can_do('owner.manage'))<a href="{{ route('owner.setup') }}">Agreement & mapping ›</a>@endif</div>
@else
<div class="card" id="agreementCard"><div class="card-body between-row wrap">
  <div><b>{{ $agreement->operator_name }}</b> manages the hotel for <b>{{ $agreement->owner_name }}</b>@if($agreement->contract_ref) · {{ $agreement->contract_ref }}@endif<div class="tiny muted">from {{ $agreement->effective_from->format('d M Y') }}{{ $agreement->ends_on ? ' to '.$agreement->ends_on->format('d M Y') : '' }} · fees payable {{ $agreement->pay_days }} days after month end</div></div>
  <div class="small">Base fee <b>{{ rtrim(rtrim($agreement->base_fee_pct, '0'), '.') }}%</b> of total operating revenue · incentive <b>{{ rtrim(rtrim($agreement->incentive_pct, '0'), '.') }}%</b> of {{ $agreement->incentive_basis === 'agop' ? 'adjusted GOP' : 'GOP' }}@if((float) $agreement->owner_priority) above an owner's priority of <b>{{ money($agreement->owner_priority) }}</b> a year @endif · FF&E reserve {{ $agreement->scheduleLabel() }}</div>
</div></div>
@endif

<div class="stats tr-stats mt-1">
  <x-stat label="Accrued, unpaid" :value="money($unpaid->sum('total_fee'))" icon="clock" :tone="$unpaid->count() ? 'amber' : 'green'" :sub="$unpaid->count().' month(s)'.($unpaid->count() ? ' · oldest due '.$unpaid->sortBy('period')->first()->dueOn()->format('d M') : '')"/>
  <x-stat label="Base fees (all time)" :value="money($live->sum('base_fee'))" icon="money" tone="blue" :sub="$live->count().' month(s) accrued'"/>
  <x-stat label="Incentive fees (all time)" :value="money($live->sum('incentive_fee'))" icon="star" tone="purple" :sub="$latest ? 'fee-year AGOP to '.$latest->label().': '.money($latest->agop_ytd) : ''"/>
  <x-stat label="FF&E contributions" :value="money($live->sum('ffe_amount'))" icon="wallet" tone="green" sub="credited to the reserve with each accrual" :href="route('owner.reserve')"/>
</div>

@if($due && $preview)
<div class="card mt-1" id="accrueCard"><div class="card-head"><h3><x-icon name="receipt"/> Accrue {{ \Illuminate\Support\Carbon::parse($preview['period'].'-01')->format('F Y') }}</h3>
  @if(count($due) > 1)<span class="tiny muted">also due: {{ collect($due)->slice(1)->map(fn ($p) => \Illuminate\Support\Carbon::parse($p.'-01')->format('M Y'))->implode(', ') }}</span>@endif</div><div class="card-body">
  @if($preview['incentive_fee'] < 0)<div class="alert alert-info" id="trueDown"><x-icon name="alert"/> {{ ($preview['agreement']->incentive_basis === 'agop' ? 'Adjusted GOP' : 'GOP') }} for the fee year to date ({{ money($preview['agop_ytd']) }}) is {{ $preview['agop_ytd'] < $preview['priority_ytd'] ? 'below the owner\'s priority ('.money($preview['priority_ytd']).')' : 'lower than before' }}, so {{ money(-$preview['incentive_fee']) }} of incentive accrued earlier this fee year is reversed.@if($preview['total_fee'] < 0) The month is a net credit to the owner – it is settled by paying it together with another month.@endif</div>@endif
  @if($preview['missing'])<div class="alert alert-warn"><x-icon name="alert"/> Earlier months of the fee year are not accrued ({{ implode(', ', $preview['missing']) }}) – months must be accrued in order.</div>@endif
  <div class="grid g-2">
    <table class="table table-compact" id="feePreview"><tbody>
      <tr><td>Total operating revenue</td><td class="num">{{ money($preview['total_revenue'], false) }}</td></tr>
      <tr><td>Base fee ({{ rtrim(rtrim($preview['agreement']->base_fee_pct, '0'), '.') }}% of revenue)</td><td class="num bold" data-v="base">{{ money($preview['base_fee'], false) }}</td></tr>
      <tr><td>FF&E reserve contribution ({{ rtrim(rtrim(number_format($preview['ffe_pct'], 2), '0'), '.') }}%)</td><td class="num" data-v="ffe">{{ money($preview['ffe_amount'], false) }}</td></tr>
      <tr><td>Gross operating profit</td><td class="num">{{ money($preview['gop'], false) }}</td></tr>
      <tr><td>{{ $preview['agreement']->incentive_basis === 'agop' ? 'Adjusted GOP (GOP − base fee − FF&E)' : 'GOP' }}</td><td class="num">{{ money($preview['agop'], false) }}</td></tr>
    </tbody></table>
    <table class="table table-compact"><tbody>
      <tr><td>Fee-year {{ $preview['agreement']->incentive_basis === 'agop' ? 'AGOP' : 'GOP' }} to date ({{ $preview['months_in_year'] }} month{{ $preview['months_in_year'] > 1 ? 's' : '' }} from {{ \Illuminate\Support\Carbon::parse($preview['year_start'].'-01')->format('M Y') }})</td><td class="num">{{ money($preview['agop_ytd'], false) }}</td></tr>
      @if(abs($preview['restated']) >= 0.01)<tr><td class="small muted">includes earlier months re-measured on the current books</td><td class="num small {{ $preview['restated'] < 0 ? 'text-red' : '' }}" data-v="restated">{{ money($preview['restated'], false) }}</td></tr>@endif
      <tr><td>Owner's priority to date</td><td class="num">{{ money($preview['priority_ytd'], false) }}</td></tr>
      <tr><td>Incentive earned to date ({{ rtrim(rtrim($preview['agreement']->incentive_pct, '0'), '.') }}% of the excess)</td><td class="num">{{ money($preview['incentive_ytd'], false) }}</td></tr>
      <tr><td>Less incentive already accrued this fee year</td><td class="num">{{ money(-$preview['incentive_prior'], false) }}</td></tr>
      <tr><td>Incentive fee this month</td><td class="num bold" data-v="incentive">{{ money($preview['incentive_fee'], false) }}</td></tr>
      <tr class="bold-row"><td>Total management fees</td><td class="num" data-v="total">{{ money($preview['total_fee'], false) }}</td></tr>
    </tbody></table>
  </div>
  @php $codes = \App\Models\AccountHead::whereIn('system_key', ['mgmt_base_fee', 'mgmt_incentive_fee', 'mgmt_fees_payable'])->pluck('code', 'system_key'); @endphp
  <p class="tiny muted">The accrual posts Dr {{ $codes['mgmt_base_fee'] ?? '' }} base fee / {{ $codes['mgmt_incentive_fee'] ?? '' }} incentive fee, Cr {{ $codes['mgmt_fees_payable'] ?? '' }} management fees payable on the last day of the month, and credits the FF&E contribution to the reserve ledger. The incentive is cumulative: a weak month can reduce it (a negative incentive line reverses part of what was accrued earlier in the fee year).</p>
  @if($canOp)<form method="post" action="{{ route('owner.fees.accrue') }}" data-confirm="Accrue the management fees for {{ \Illuminate\Support\Carbon::parse($preview['period'].'-01')->format('F Y') }}?" id="accrueForm">@csrf
    <input type="hidden" name="period" value="{{ $preview['period'] }}"><button class="btn btn-primary" @disabled($preview['missing'])><x-icon name="check"/> {{ $preview['total_fee'] < 0 ? 'Accrue – net credit '.money(-$preview['total_fee']) : 'Accrue '.money($preview['total_fee']) }}</button></form>@endif
</div></div>
@elseif($agreement)
  <div class="alert alert-success mt-1"><x-icon name="check"/> Every completed month under the agreement is accrued.@if($lock) The ledger is locked to {{ dmy($lock) }}.@endif</div>
@endif

<div class="card flush mt-1" id="feesCard"><div class="card-head"><h3><x-icon name="money"/> Monthly fees</h3>
  @if($canOp && $unpaid->count())<button class="btn btn-sm btn-primary" data-modal="payModal" id="payBtn"><x-icon name="wallet"/> Pay selected</button>@endif</div>
<div class="card-body table-wrap"><table class="table table-compact" id="feesTable">
  <thead><tr>@if($canOp)<th></th>@endif<th>Month</th><th class="num">Revenue</th><th class="num">GOP</th><th class="num">Base fee</th><th class="num">AGOP</th><th class="num">Incentive</th><th class="num">Total fee</th><th class="num">FF&E</th><th>Status</th><th></th></tr></thead>
  <tbody>@forelse($fees as $f)
    <tr data-fee="{{ $f->id }}" data-period="{{ $f->period }}" class="{{ $f->status === 'reversed' ? 'muted' : '' }}">
      @if($canOp)<td>@if($f->status === 'accrued')<input type="checkbox" name="fee_ids[]" value="{{ $f->id }}" form="payForm" checked aria-label="Pay {{ $f->label() }}" data-amt="{{ $f->total_fee }}">@endif</td>@endif
      <td><b>{{ $f->label() }}</b>@if($f->journal)<div class="tiny"><a href="{{ route('gl.journals.show', $f->journal) }}">{{ $f->journal->journal_no }}</a></div>@endif</td>
      <td class="num">{{ money($f->total_revenue, false) }}</td><td class="num">{{ money($f->gop, false) }}</td>
      <td class="num">{{ money($f->base_fee, false) }}</td><td class="num">{{ money($f->agop, false) }}</td>
      <td class="num">{{ money($f->incentive_fee, false) }}</td><td class="num bold">{{ money($f->total_fee, false) }}</td><td class="num">{{ money($f->ffe_amount, false) }}</td>
      <td><span class="badge badge-{{ $F::TONES[$f->status] }}">{{ $F::STATUSES[$f->status] }}</span>
        @if($f->status === 'paid')<div class="tiny muted">{{ $f->paid_on?->format('d M Y') }} · {{ $f->paidAccount?->name }}{{ $f->paid_ref ? ' · '.$f->paid_ref : '' }}</div>
        @elseif($f->status === 'accrued')<div class="tiny {{ $f->dueOn()->lt(bdate()) ? 'text-red' : 'muted' }}">due {{ $f->dueOn()->format('d M Y') }}</div>
        @elseif($f->reversal_note)<div class="tiny muted">{{ $f->reversal_note }}</div>@endif</td>
      <td class="right">@if($canOp && $f->status === 'accrued' && $latest && $latest->id === $f->id)
        <form method="post" action="{{ route('owner.fees.reverse', $f) }}" onsubmit="const r = prompt('Reason for reversing the {{ $f->label() }} fees'); if (! r) return false; this.reason.value = r;">@csrf<input type="hidden" name="reason"><button class="btn btn-sm btn-ghost">Reverse</button></form>@endif</td>
    </tr>
  @empty<tr><td colspan="11" class="empty">No fees accrued yet.</td></tr>@endforelse</tbody>
</table></div></div>

@if($canOp && $unpaid->count())
<div class="modal" id="payModal"><div class="modal-box" style="max-width:34rem"><form method="post" action="{{ route('owner.fees.pay') }}" id="payForm">@csrf
  <div class="modal-head"><h3>Pay management fees</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field span-all small">Pays the months ticked in the table (<b id="payTotal">{{ money($unpaid->sum('total_fee')) }}</b>). Posts Dr management fees payable, Cr the bank account.</div>
    <div class="field"><label for="payAcct">Paid from</label><select name="account_id" id="payAcct" required>@foreach($payAccounts as $a)<option value="{{ $a->id }}" @selected($a->name === 'Ecobank – Collections')>{{ $a->name }}</option>@endforeach</select></div>
    <div class="field"><label for="payDate">Date</label><input type="date" name="date" id="payDate" value="{{ bdate()->toDateString() }}" max="{{ bdate()->toDateString() }}" required></div>
    <div class="field span-all"><label for="payRef">Reference</label><input name="reference" id="payRef" maxlength="60" placeholder="transfer / cheque no."></div>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Record payment</button></div>
</form></div></div>
@push('scripts')
<script>
(() => { const t = document.getElementById('payTotal'); const upd = () => { let s = 0; document.querySelectorAll('input[form=payForm][name="fee_ids[]"]:checked').forEach(c => s += parseFloat(c.dataset.amt)); t.textContent = 'GH₵' + s.toLocaleString('en-GH', {minimumFractionDigits: 2, maximumFractionDigits: 2}); };
  document.querySelectorAll('input[form=payForm]').forEach(c => c.addEventListener('change', upd)); })();
</script>
@endpush
@endif
@endsection
