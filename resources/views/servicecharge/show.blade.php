@extends('layouts.app')
@section('title', $d->dist_no)
@section('crumbs') <a href="{{ route('sc.index') }}">Service charge</a> / {{ $d->dist_no }} @endsection
@section('actions')
<a class="btn" href="{{ route('sc.print', $d) }}" target="_blank"><x-icon name="print"/> Print sheet</a>
@if($d->status === 'draft' && $canManage)<form method="post" action="{{ route('sc.recompute', $d) }}" style="display:inline">@csrf<button class="btn" id="recompute"><x-icon name="refresh"/> Recalculate</button></form>@endif
@if($d->status === 'draft' && $canApprove)<form method="post" action="{{ route('sc.approve', $d) }}" style="display:inline" data-confirm="Approve {{ $d->dist_no }}? The house share is posted to income.">@csrf<button class="btn btn-primary" id="approveDist"><x-icon name="check"/> Approve</button></form>@endif
@if($d->status !== 'cancelled' && ($canApprove || $d->status === 'draft'))<button class="btn btn-danger" data-modal="cancelModal" id="cancelDist">Cancel</button>@endif
@endsection
@section('content')
@php $srcLabels = ['pos' => 'POS service charge', 'folio_tx' => 'Folio service charge', 'tip' => 'Tips', 'voucher' => 'Vouchers'] + \App\Models\GlJournal::SOURCES; @endphp
<div class="stats" id="distStats">
  <x-stat label="Collected" :value="money($d->collected)" icon="trend" :sub="collect($bySource)->map(fn ($v, $k) => ($srcLabels[$k] ?? $k).' '.money($v, false))->implode(' · ') ?: '—'"/>
  <x-stat :label="'House retention '.rtrim(rtrim(number_format((float) $d->retention_pct, 2), '0'), '.').'%'" :value="money($d->retained)" icon="building"/>
  <x-stat label="Pool" :value="money($d->pool)" icon="wallet" :sub="(float) $d->carried_in ? 'incl. '.money($d->carried_in).' carried in' : ''"/>
  <x-stat label="Distributed" :value="money($d->distributed)" icon="users" tone="green" :sub="$d->lines->where('amount', '>', 0)->count().' staff · '.money($d->carry_out).' rounding carried forward'"/>
</div>

<div class="card mt-1"><div class="card-body flex g-2" style="flex-wrap:wrap;align-items:center;justify-content:space-between">
  <div><b id="distStatus" class="badge {{ ['draft' => 'badge-slate', 'approved' => 'badge-amber', 'posted' => 'badge-green', 'cancelled' => 'badge-red'][$d->status] }}">{{ strtoupper($d->status) }}</b>
    <span class="small">{{ $d->label() }} · {{ \App\Models\ScDistribution::BASES[$d->basis] ?? $d->basis }} · prepared by {{ $d->creator?->name }}{{ $d->approver ? ' · approved by '.$d->approver->name.' on '.dmy($d->approved_on) : '' }}{{ $d->run ? ' · payroll '.$d->run->run_no : '' }}</span>
    @if($d->cancel_reason)<div class="tiny text-red">Cancelled: {{ $d->cancel_reason }}</div>@endif @if($d->notes)<div class="tiny muted">{{ $d->notes }}</div>@endif</div>
  @if($d->status === 'approved' && $canApprove)
  <form method="post" action="{{ route('sc.post', $d) }}" class="flex g-1" id="postForm" style="align-items:flex-end">@csrf
    <div class="field" style="margin:0"><label for="f_run">Add to payroll run</label><select name="payroll_run_id" id="f_run" required>@forelse($runs as $r)<option value="{{ $r->id }}">{{ $r->run_no }} · {{ dmy($r->period_start) }} – {{ dmy($r->period_end) }}</option>@empty<option value="">No draft run – generate next month's payroll first</option>@endforelse</select></div>
    <button class="btn btn-primary" id="postPayroll" @disabled($runs->isEmpty())><x-icon name="money"/> Post to payroll</button>
  </form>
  @endif
</div></div>

<div class="card flush mt-1"><div class="card-body table-wrap"><table class="table" id="lineTable">
  <thead><tr><th>Employee</th><th>Department</th><th class="num">Points</th><th class="num">Days</th><th class="num">Weight</th><th class="num">Share %</th><th class="num">Amount</th><th></th></tr></thead>
  <tbody>
  @php $tw = max(0.0001, $d->lines->sum('weight')); @endphp
  @foreach($d->lines->sortBy(fn ($l) => [$l->department?->name, $l->employee?->name]) as $l)
    <tr class="{{ $l->excluded ? 'muted' : '' }}" data-emp="{{ $l->employee?->emp_code }}"><td><b>{{ $l->employee?->name }}</b> <span class="tiny muted">{{ $l->employee?->emp_code }}</span>@if($l->excluded)<div class="tiny text-red">excluded: {{ $l->exclude_reason }}</div>@endif</td>
      <td class="small">{{ $l->department?->name }}</td><td class="num">{{ rtrim(rtrim(number_format((float) $l->points, 2), '0'), '.') }}</td><td class="num">{{ rtrim(rtrim(number_format((float) $l->days, 1), '0'), '.') ?: '0' }}</td>
      <td class="num">{{ number_format((float) $l->weight, 2) }}</td><td class="num">{{ number_format($l->weight / $tw * 100, 2) }}</td><td class="num bold" data-amount>{{ money($l->amount, false) }}</td>
      <td class="nowrap">@if($d->status === 'draft' && $canManage)
        @if($l->excluded)<form method="post" action="{{ route('sc.exclude', [$d, $l]) }}">@csrf<input type="hidden" name="excluded" value="0"><button class="btn btn-sm">Include</button></form>
        @else<button class="btn btn-sm" data-modal="excludeModal" data-action="{{ route('sc.exclude', [$d, $l]) }}" data-title="Exclude {{ $l->employee?->name }}" data-exclude="{{ $l->employee?->emp_code }}">Exclude</button>@endif
      @endif</td></tr>
  @endforeach
  </tbody>
  <tfoot><tr><th colspan="4">Total</th><th class="num">{{ number_format($d->lines->sum('weight'), 2) }}</th><th></th><th class="num">{{ money($d->lines->sum('amount'), false) }}</th><th></th></tr></tfoot>
</table></div></div>

<div class="card mt-2"><div class="card-head"><h3><x-icon name="building"/> By department</h3></div><div class="card-body table-wrap"><table class="table" id="deptTable">
  <thead><tr><th>Department</th><th class="num">Staff paid</th><th class="num">Amount</th></tr></thead><tbody>
  @foreach($byDept as $n => $x)<tr><td>{{ $n }}</td><td class="num">{{ $x['n'] }}</td><td class="num">{{ money($x['amt'], false) }}</td></tr>@endforeach
  </tbody></table>
  <p class="tiny muted">Journal on approval: Dr 2110 Service Charge Payable / Cr 4095 Service Charge Retained for the house share. The staff shares are added to payslips as a taxable "Service charge" earning charged to 2110, so the payroll journal clears the pool.</p>
</div></div>

<div class="modal" id="excludeModal"><div class="modal-box"><form method="post" action="#" data-reset>@csrf<input type="hidden" name="excluded" value="1">
  <div class="modal-head"><h3 data-title>Exclude</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="reason" label="Reason" required maxlength="120" placeholder="e.g. resigned without notice / on disciplinary suspension"/><p class="tiny muted">Their share is redistributed among the others.</p></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="excludeSave">Exclude</button></div>
</form></div></div>
<div class="modal" id="cancelModal"><div class="modal-box"><form method="post" action="{{ route('sc.cancel', $d) }}">@csrf
  <div class="modal-head"><h3>Cancel {{ $d->dist_no }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="reason" label="Reason" required maxlength="255"/>@if($d->status === 'posted')<p class="tiny muted">The service charge lines are removed from the draft payroll run {{ $d->run?->run_no }}.</p>@endif</div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-danger" id="cancelSave">Cancel distribution</button></div>
</form></div></div>
@endsection
