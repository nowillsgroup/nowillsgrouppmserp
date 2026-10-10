@extends('layouts.app')
@section('title', 'Stock Take '.$take->take_no)
@section('crumbs') <a href="{{ route('stocktakes.index') }}">Stock Take</a> / {{ $take->take_no }} @endsection
@section('actions')
  {!! status_badge($take->status) !!}
  <a class="btn" href="{{ route('stocktakes.print', [$take, 'blind' => 1]) }}" target="_blank"><x-icon name="print"/> Blind count sheet</a>
  <a class="btn" href="{{ route('stocktakes.print', $take) }}" target="_blank"><x-icon name="print"/> Variance report</a>
  @if($take->status === 'draft' && can_do('inventory.manage'))<form method="post" action="{{ route('stocktakes.cancel', $take) }}" data-confirm="Cancel this count sheet?">@csrf<button class="btn btn-danger">Cancel</button></form>@endif
@endsection
@section('content')
@php $editable = $take->status === 'draft' && can_do('inventory.manage'); @endphp
<div class="stats">
  <x-stat label="Store" :value="$take->store->name" icon="box" tone="blue"/>
  <x-stat label="Count date" :value="$take->count_date->format('d M Y')" icon="calendar" tone="slate"/>
  <x-stat label="Counted" :value="$take->lines->whereNotNull('counted_qty')->count().' / '.$take->lines->count()" icon="check" tone="green"/>
  <x-stat label="Variance value" :value="money($take->status === 'posted' ? $take->variance_value : $take->lines->sum(fn($l) => $l->varianceValue()))" icon="money" tone="amber" id="varTotal"/>
</div>
@if($take->status === 'posted')<div class="alert alert-success"><x-icon name="check"/><div>Posted by {{ $take->poster?->name }} on {{ $take->posted_at->format('d M Y H:i') }}. Variances were booked as stock adjustments (ref. {{ $take->take_no }}).</div></div>@endif
<form method="post" action="{{ route('stocktakes.update', $take) }}" id="stForm">@csrf @method('PUT')
<div class="card flush"><div class="card-head"><h3>Count sheet</h3><input class="input input-sm" placeholder="Filter items…" data-filter-table="#stTable" style="max-width:240px"></div>
<div class="card-body table-wrap"><table class="table table-compact" id="stTable">
  <thead><tr><th>Item</th><th>Category</th><th>Unit</th><th class="num">System qty</th><th style="width:120px">Counted</th><th class="num">Variance</th><th class="num">Unit cost</th><th class="num">Value</th><th>Remarks</th></tr></thead><tbody>
  @foreach($take->lines->sortBy(fn($l) => ($l->item->category->name ?? 'zzz').$l->item->name) as $l)
  <tr data-sys="{{ $l->system_qty }}" data-cost="{{ $l->unit_cost }}">
    <td class="bold">{{ $l->item->name }} <span class="tiny muted">{{ $l->item->code }}</span></td><td class="small">{{ $l->item->category->name ?? '—' }}</td><td class="small">{{ $l->item->unit }}</td>
    <td class="num">{{ rtrim(rtrim(number_format($l->system_qty, 2), '0'), '.') }}</td>
    <td>@if($editable)<input type="number" step="0.01" min="0" name="counts[{{ $l->id }}]" value="{{ $l->counted_qty === null ? '' : (float) $l->counted_qty }}" class="st-count" style="width:100px">@else{{ $l->counted_qty === null ? '—' : (float) $l->counted_qty }}@endif</td>
    <td class="num st-var {{ ($l->varianceQty() ?? 0) < 0 ? 'text-red' : (($l->varianceQty() ?? 0) > 0 ? 'text-green' : '') }}">{{ $l->varianceQty() === null ? '' : ($l->varianceQty() > 0 ? '+' : '').$l->varianceQty() }}</td>
    <td class="num">{{ money($l->unit_cost, false) }}</td><td class="num st-val">{{ $l->varianceQty() ? money($l->varianceValue(), false) : '' }}</td>
    <td>@if($editable)<input name="remarks[{{ $l->id }}]" value="{{ $l->remarks }}" maxlength="190" style="width:140px" placeholder="e.g. breakage">@else<span class="small">{{ $l->remarks }}</span>@endif</td>
  </tr>
  @endforeach
  </tbody></table></div></div>
@if($editable)
<div class="card"><div class="card-body flex gap-1 wrap items-center">
  <button class="btn" name="action" value="save"><x-icon name="check"/> Save counts</button>
  <label class="check"><input type="checkbox" name="blank_as_zero" value="1"> Treat blank counts as zero (full count)</label>
  <span style="flex:1"></span>
  <button class="btn btn-primary" name="action" value="post" onclick="return confirm('Post the stock take? Variances will be booked as stock adjustments and the sheet will be locked.')"><x-icon name="layers"/> Post variances</button>
</div></div>
@endif
</form>
@endsection
@push('scripts')
<script>
document.querySelectorAll('.st-count').forEach(inp => inp.addEventListener('input', () => {
  const tr = inp.closest('tr'), sys = +tr.dataset.sys, cost = +tr.dataset.cost, v = inp.value === '' ? null : Math.round((+inp.value - sys) * 100) / 100;
  const vc = tr.querySelector('.st-var'), vv = tr.querySelector('.st-val');
  vc.textContent = v === null ? '' : (v > 0 ? '+' : '') + v; vc.className = 'num st-var ' + (v < 0 ? 'text-red' : (v > 0 ? 'text-green' : ''));
  vv.textContent = v ? (v * cost).toFixed(2) : '';
}));
</script>
@endpush
