@extends('layouts.app')
@section('title', 'Food Waste Log')
@section('crumbs') <a href="{{ route('fs.index') }}">Food Safety</a> / Food waste @endsection
@section('actions')
@if(can_do('foodsafety.record'))<button class="btn btn-primary" data-modal="wasteModal" data-f-source="raw" data-f-store_id="{{ $defaultStore }}" id="addWaste"><x-icon name="trash"/> Record waste</button>@endif
@if(can_do('reports.view'))<a class="btn" href="{{ route('reports.show', ['food_waste']) }}"><x-icon name="chart"/> Report</a>@endif
@endsection
@section('content')
<form method="get" class="filters"><div class="field"><label for="f_from">From</label><input type="date" name="from" id="f_from" value="{{ $from }}"></div><div class="field"><label for="f_to">To</label><input type="date" name="to" id="f_to" value="{{ $to }}"></div><div class="field" style="align-self:flex-end"><button class="btn">Show</button></div></form>
<div class="stats" id="wasteStats">
  <x-stat label="Waste taken out of stock" :value="money($summary['stock'])" icon="trash" tone="red" :sub="$summary['pct'] !== null ? $summary['pct'].'% of F&B cost (consumed + wasted) · posted to 5042 Food Waste' : 'posted to 5042 Food Waste'"/>
  <x-stat label="Plate waste (analysis)" :value="money($summary['plate'])" icon="cup" tone="amber" sub="already consumed by the sale"/>
  <x-stat label="Entries" :value="$summary['count']" icon="book"/>
  <x-stat label="Top item" :value="$summary['top'] ? array_key_first($summary['top']) : '—'" icon="chart" :sub="$summary['top'] ? money(reset($summary['top'])) : ''"/>
</div>
<div class="fs-split mt-1">
  <div class="card flush"><div class="card-body table-wrap"><table class="table" id="wasteTable">
    <thead><tr><th>No.</th><th>Date</th><th>Item</th><th class="num">Qty</th><th>Reason</th><th class="num">Cost</th><th>By</th><th></th></tr></thead>
    <tbody>@forelse($rows as $w)
      <tr data-waste="{{ $w->waste_no }}" class="{{ $w->cancelled_at ? 'muted' : '' }}"><td class="bold">{{ $w->waste_no }}</td><td class="small">{{ dmy($w->date) }}</td>
        <td>{{ $w->name() }}<div class="tiny muted">{{ \App\Models\FsWaste::SOURCES[$w->source] }}{{ $w->store ? ' · '.$w->store->name : '' }}{{ $w->outlet ? ' · '.$w->outlet->name : '' }}</div>@if($w->notes)<div class="tiny muted">{{ $w->notes }}</div>@endif @if($w->cancelled_at)<div class="tiny text-red">cancelled: {{ $w->cancel_reason }}</div>@endif</td>
        <td class="num">{{ rtrim(rtrim(number_format((float) $w->qty, 3), '0'), '.') }} {{ $w->unit }}</td><td class="small">{{ \App\Models\FsWaste::REASONS[$w->reason] }}</td>
        <td class="num">{{ $w->cancelled_at ? '' : money($w->cost, false) }}</td><td class="small">{{ $w->user?->name }}</td>
        <td>@if(! $w->cancelled_at && can_do('foodsafety.manage'))<button class="btn btn-sm" data-modal="cancelWasteModal" data-title="Cancel {{ $w->waste_no }}" data-action="{{ route('fs.waste.cancel', $w) }}">Cancel</button>@endif</td></tr>
    @empty <tr><td colspan="8"><x-empty text="No food waste recorded in this period." icon="trash"/></td></tr> @endforelse</tbody>
    @if($rows->isNotEmpty())<tfoot><tr><th colspan="5">Total</th><th class="num">{{ money($summary['total'], false) }}</th><th colspan="2"></th></tr></tfoot>@endif
  </table></div></div>
  <div class="card" id="wasteReasons"><div class="card-head"><h3>By reason</h3></div><div class="card-body">
    @if($summary['by_reason'])<ul class="list">@foreach($summary['by_reason'] as $r => $v)<li><span class="small">{{ \App\Models\FsWaste::REASONS[$r] ?? $r }}</span><span class="small bold">{{ money($v) }}</span></li>@endforeach</ul>@else<p class="muted small">Nothing yet.</p>@endif
    @if($summary['top'])<div class="tiny muted bold mt-1">Top items</div><ul class="list">@foreach($summary['top'] as $n => $v)<li><span class="small">{{ $n }}</span><span class="small">{{ money($v) }}</span></li>@endforeach</ul>@endif
  </div></div>
</div>
<p class="tiny muted">Raw items and unserved dishes (through their recipe) leave the store as "wastage" at last cost: Dr 5042 Food Waste / Cr Inventory, posted with the stores journal. Plate waste is valued at portion cost for analysis only – the sale already consumed the stock. Waste up to 3 days back can be entered.</p>

@if(can_do('foodsafety.record'))
<div class="modal" id="wasteModal"><div class="modal-box" style="max-width:600px"><form method="post" action="{{ route('fs.waste.store') }}" data-reset id="wasteForm">@csrf
  <div class="modal-head"><h3>Record food waste</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <x-field name="source" label="What was wasted" type="select" :options="\App\Models\FsWaste::SOURCES" :placeholder="false" required/>
    <x-field name="date" label="Date" type="date" :value="bdate()->toDateString()" :max="bdate()->toDateString()"/>
    <div class="field span-2" data-src="raw"><label for="f_inventory_item_id">Stock item</label><select name="inventory_item_id" id="f_inventory_item_id"><option value="">— Select —</option>@foreach($stock as $i)<option value="{{ $i->id }}" data-unit="{{ $i->unit }}">{{ $i->name }} ({{ $i->unit }} · {{ money($i->last_cost, false) }})</option>@endforeach</select></div>
    <div class="field span-2" data-src="dish,plate"><label for="f_menu_item_id">Dish</label><select name="menu_item_id" id="f_menu_item_id"><option value="">— Select —</option>@foreach($dishes as $m)<option value="{{ $m->id }}" data-recipe="{{ $m->recipes_count }}">{{ $m->name }}{{ $m->recipes_count ? '' : ' (no recipe)' }}</option>@endforeach</select></div>
    <div class="field"><label for="f_qty">Quantity <span id="qtyUnit" class="muted"></span> <span class="req">*</span></label><input type="number" step="0.001" min="0.001" name="qty" id="f_qty" required></div>
    <x-field name="reason" label="Reason" type="select" :options="\App\Models\FsWaste::REASONS" required/>
    <div class="field" data-src="raw,dish"><label for="f_store_id">From store</label><select name="store_id" id="f_store_id">@foreach($stores as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></div>
    <x-field name="outlet_id" label="Outlet" type="select" :options="$outlets"/>
    <x-field name="notes" label="Notes" class="span-2" placeholder="e.g. walk-in 1 failed overnight"/>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="wasteSave">Save</button></div>
</form></div></div>
@endif
@if(can_do('foodsafety.manage'))
<div class="modal" id="cancelWasteModal"><div class="modal-box"><form method="post" action="" data-reset>@csrf
  <div class="modal-head"><h3 data-title>Cancel</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="reason" label="Reason" required placeholder="Entered twice"/><p class="tiny muted">Stock is put back with a reversing movement dated {{ dmy(bdate()) }}.</p></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-danger" id="cancelWasteSave">Cancel entry</button></div>
</form></div></div>
@endif
@endsection
@push('scripts')
<script>
(() => { const f = document.getElementById('wasteForm'); if (!f) return;
  const src = f.querySelector('[name=source]'), inv = f.querySelector('[name=inventory_item_id]'), qty = f.querySelector('[name=qty]');
  const sync = () => { f.querySelectorAll('[data-src]').forEach(el => el.hidden = !el.dataset.src.split(',').includes(src.value));
    document.getElementById('qtyUnit').textContent = src.value === 'raw' ? '(' + (inv.selectedOptions[0]?.dataset.unit || 'unit') + ')' : '(portions)'; qty.step = src.value === 'raw' ? '0.001' : '0.5'; };
  [src, inv].forEach(e => e.addEventListener('change', sync)); document.addEventListener('click', e => { if (e.target.closest('[data-modal=wasteModal]')) setTimeout(sync, 0); }); sync(); })();
</script>
@endpush
