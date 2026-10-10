@extends('layouts.app')
@section('title', 'Agreement & USALI Mapping')
@section('crumbs') Owner Reporting / Agreement & mapping @endsection
@php
  $U = \App\Services\UsaliService::class; $MA = \App\Models\ManagementAgreement::class;
  $a = $agreement; $sched = array_pad((array) ($a->ffe_schedule ?? []), 5, '');
  $opts = ['income' => [], 'expense' => []];
  foreach ($U::depts() as $d => $dl) {
      foreach (['income', 'expense'] as $t) {
          if ($d === 'excluded') { $opts[$t]['excluded:other'] = 'Excluded from the statement'; continue; }
          foreach ($U::allowedCats($d, $t) as $c) { $opts[$t][$d.':'.$c] = $dl.' · '.$U::CATS[$c]; }
      }
  }
  $groupLabel = function ($d) use ($U) {
      return match (true) { isset($U::OPERATED[$d]) => 'Operated departments', isset($U::OOD[$d]) => 'Other operated departments', isset($U::UNDISTRIBUTED[$d]) => 'Undistributed', in_array($d, ['misc'], true) => 'Miscellaneous income', in_array($d, ['mgmt'], true) => 'Management fees', isset($U::NONOP[$d]) => 'Non-operating', isset($U::BELOW[$d]) => 'Below EBITDA', default => 'Other' };
  };
  $manual = $heads->where('u_auto', false)->count();
@endphp
@section('content')
<div class="tabs" data-tabs><button class="active" data-tab="tAgreement" type="button">Management agreement</button><button data-tab="tDepts" type="button">Payroll departments</button><button data-tab="tMap" type="button">Account mapping ({{ $manual }} manual)</button></div>

<div class="tab-pane active" id="tAgreement">
<div class="card"><div class="card-body">
<form method="post" action="{{ route('owner.setup.agreement') }}" id="agreementForm">@csrf @method('PUT')
  <div class="form-grid">
    <div class="field"><label for="aOperator">Operator (management company)</label><input name="operator_name" id="aOperator" maxlength="150" value="{{ old('operator_name', $a->operator_name) }}" required></div>
    <div class="field"><label for="aOwner">Owner</label><input name="owner_name" id="aOwner" maxlength="150" value="{{ old('owner_name', $a->owner_name) }}" required></div>
    <div class="field"><label for="aRef">Contract reference</label><input name="contract_ref" id="aRef" maxlength="60" value="{{ old('contract_ref', $a->contract_ref) }}"></div>
    <div class="field"><label for="aFrom">Effective from</label><input type="date" name="effective_from" id="aFrom" value="{{ old('effective_from', $a->effective_from?->toDateString()) }}" required @if($accrued) readonly title="Fees have been accrued – the start date is fixed" @endif></div>
    <div class="field"><label for="aTo">Ends on</label><input type="date" name="ends_on" id="aTo" value="{{ old('ends_on', $a->ends_on?->toDateString()) }}"></div>
    <div class="field"><label for="aPay">Fees payable (days after month end)</label><input type="number" min="0" max="90" name="pay_days" id="aPay" value="{{ old('pay_days', $a->pay_days ?? 15) }}"></div>
    <div class="field"><label for="aBase">Base fee (% of total operating revenue)</label><input type="number" step="0.01" min="0" max="10" name="base_fee_pct" id="aBase" value="{{ old('base_fee_pct', (float) $a->base_fee_pct) }}" required></div>
    <div class="field"><label for="aInc">Incentive fee (%)</label><input type="number" step="0.01" min="0" max="30" name="incentive_pct" id="aInc" value="{{ old('incentive_pct', (float) $a->incentive_pct) }}" required></div>
    <div class="field"><label for="aBasis">Incentive basis</label><select name="incentive_basis" id="aBasis">@foreach($MA::BASES as $k => $l)<option value="{{ $k }}" @selected(old('incentive_basis', $a->incentive_basis) === $k)>{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label for="aPrio">Owner's priority (GH₵ a year)</label><input type="number" step="0.01" min="0" name="owner_priority" id="aPrio" value="{{ old('owner_priority', (float) $a->owner_priority) }}"><div class="tiny muted">incentive is earned only on profit above this return, pro rata through the fee year</div></div>
    <div class="field"><label for="aRes">FF&E reserve bank account</label><select name="reserve_account_id" id="aRes"><option value="">—</option>@foreach($banks as $b)<option value="{{ $b->id }}" @selected((int) old('reserve_account_id', $a->reserve_account_id) === $b->id)>{{ $b->name }}</option>@endforeach</select></div>
    <div class="field span-all"><label>FF&E reserve (% of revenue) by contract year</label><div class="flex gap-1 wrap">@foreach($sched as $i => $v)<label class="small ffe-step">Year {{ $i + 1 }}{{ $i === 4 ? '+' : '' }}<input type="number" step="0.01" min="0" max="10" name="ffe_schedule[]" value="{{ $v === '' ? '' : (float) $v }}" aria-label="FF&E year {{ $i + 1 }}"></label>@endforeach</div>
      <div class="tiny muted">The last step applies to every later year. Typical: 2% in year 1, 3% in year 2, 4% thereafter.</div></div>
    <div class="field span-all"><label for="aNotes">Notes</label><input name="notes" id="aNotes" maxlength="255" value="{{ old('notes', $a->notes) }}"></div>
  </div>
  <button class="btn btn-primary mt-1" id="agreementSave">Save agreement</button>
</form>
</div></div>
</div>

<div class="tab-pane" id="tDepts">
<div class="card"><div class="card-body">
  <p class="small muted" style="margin-top:0">Salaries, employer contributions and staff welfare are posted to one ledger account each; the USALI statement shares them across departments in proportion to each HR department's payroll for the month.</p>
  <form method="post" action="{{ route('owner.setup.departments') }}" id="deptForm">@csrf @method('PUT')
    <table class="table table-compact"><thead><tr><th>HR department</th><th>Reported under</th></tr></thead><tbody>
    @foreach($departments as $d)<tr><td>{{ $d->name }}</td><td><select name="dept[{{ $d->id }}]" aria-label="{{ $d->name }}">@foreach($U::payrollDepts() as $k => $l)<option value="{{ $k }}" @selected(($d->usali_dept ?: $U::deptDefault($d)) === $k)>{{ $l }}</option>@endforeach</select></td></tr>@endforeach
    </tbody></table>
    <button class="btn btn-primary mt-1">Save departments</button>
  </form>
</div></div>
</div>

<div class="tab-pane" id="tMap">
<div class="card flush"><div class="card-head"><h3>Where each ledger account is reported</h3><input type="search" id="mapFilter" placeholder="Filter accounts…" aria-label="Filter accounts" style="max-width:16rem"></div>
<div class="card-body">
  <p class="small muted" style="margin-top:0">System accounts map automatically (USALI 11th edition); change any line to override. Miscellaneous income is net-basis revenue with no direct costs (cancellation fees, breakage, commissions, space rental). Excluded accounts are left out of the statement (their total is shown under it).</p>
  <form method="post" action="{{ route('owner.setup.mapping') }}" id="mapForm">@csrf @method('PUT')
  <div class="table-wrap"><table class="table table-compact" id="mapTable"><thead><tr><th>Account</th><th>Type</th><th>Reported under</th></tr></thead><tbody>
  @foreach($heads as $h)
    <tr data-code="{{ $h->code }}" data-name="{{ strtolower($h->name) }}" class="{{ $h->u_auto ? '' : 'row-hl' }}"><td><b>{{ $h->code }}</b> {{ $h->name }}<div class="tiny muted">{{ $h->group_name }}</div></td><td class="small">{{ ucfirst($h->type) }}</td>
      <td><select name="map[{{ $h->id }}]" aria-label="Mapping for {{ $h->code }}">
        <option value="">Automatic – {{ $U::label($h->u_autodef[0]) }} · {{ $U::CATS[$h->u_autodef[1]] ?? $h->u_autodef[1] }}</option>
        @php $cur = $h->u_auto ? '' : $h->u_dept.':'.$h->u_cat; $lastGroup = null; @endphp
        @foreach($opts[$h->type] as $v => $l) @php $g = $groupLabel(explode(':', $v)[0]); @endphp
          @if($g !== $lastGroup)@if($lastGroup !== null)</optgroup>@endif<optgroup label="{{ $g }}">@php $lastGroup = $g; @endphp @endif
          <option value="{{ $v }}" @selected($cur === $v)>{{ $l }}</option>
        @endforeach
        @if($lastGroup !== null)</optgroup>@endif
      </select></td></tr>
  @endforeach
  </tbody></table></div>
  <button class="btn btn-primary mt-1" id="mapSave">Save mapping</button>
  </form>
</div></div>
</div>
@push('scripts')
<script>
document.getElementById('mapFilter')?.addEventListener('input', e => { const q = e.target.value.toLowerCase(); document.querySelectorAll('#mapTable tbody tr').forEach(r => { r.style.display = !q || r.dataset.code.includes(q) || r.dataset.name.includes(q) ? '' : 'none'; }); });
</script>
@endpush
@endsection
