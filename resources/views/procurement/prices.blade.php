@extends('layouts.app')
@section('title', 'Supplier Price Lists')
@section('actions')@if(can_do('purchasing.prices'))<button class="btn btn-primary" data-modal="priceModal" onclick="editPrice(null)" id="addPrice"><x-icon name="plus"/> Add price</button>@endif @endsection
@section('content')
<p class="small muted" style="margin-top:0">Agreed rates (rate contracts) per supplier and item. New purchase orders take the rate from here, lines ordered above it (beyond {{ setting('po_price_tolerance_pct', '2') }}%) are flagged and need the purchasing manager's approval. Awarding an RFQ can add its rates automatically.</p>
@if($expiring)<div class="alert alert-warn" id="pricesExpiring"><x-icon name="clock"/><div>{{ $expiring }} price(s) expire within 14 days – renew them or run an RFQ.</div></div>@endif
<form class="card" method="get"><div class="card-body flex gap-1 wrap items-end">
  <div class="field"><label>Supplier</label><select name="supplier" class="input"><option value="">All</option>@foreach($suppliers as $id => $n)<option value="{{ $id }}" @selected(request('supplier') == $id)>{{ $n }}</option>@endforeach</select></div>
  <div class="field"><label>Item</label><select name="item" class="input"><option value="">All</option>@foreach($items as $i)<option value="{{ $i->id }}" @selected(request('item') == $i->id)>{{ $i->name }}</option>@endforeach</select></div>
  <div class="field"><label>Validity</label><select name="state" class="input">@foreach(['active' => 'Valid today', 'future' => 'Starting later', 'expired' => 'Expired', 'all' => 'All'] as $k => $v)<option value="{{ $k }}" @selected(request('state', 'active') === $k)>{{ $v }}</option>@endforeach</select></div>
  <button class="btn">Filter</button></div></form>
<div class="card flush"><div class="card-body table-wrap"><table class="table" id="priceTable">
<thead><tr><th>Item</th><th>Supplier</th><th class="num">Agreed rate</th><th class="num">Last cost</th><th class="num">Min qty</th><th class="num">Lead (d)</th><th>Valid</th><th>Source</th><th></th></tr></thead><tbody>
@forelse($prices as $p)@php $best = isset($best[$p->inventory_item_id]) && (float) $best[$p->inventory_item_id] === (float) $p->rate && $p->state() === 'active'; @endphp
<tr><td class="bold">{{ $p->item->name }} <span class="tiny muted">{{ $p->item->unit }}</span></td><td>{{ $p->supplier->name }}</td>
  <td class="num">{{ money($p->rate, false) }}@if($best) <span class="badge badge-green" title="Lowest valid rate for this item">best</span>@endif</td><td class="num muted">{{ money($p->item->last_cost, false) }}</td>
  <td class="num">{{ (float) $p->min_qty ?: '—' }}</td><td class="num">{{ $p->lead_days ?? '—' }}</td>
  <td class="small">{{ $p->valid_from->format('d M Y') }} → {{ $p->valid_to?->format('d M Y') ?? 'open' }} {!! status_badge($p->state()) !!}</td>
  <td class="small">{{ $p->source === 'rfq' ? 'RFQ award' : 'manual' }}</td>
  <td class="right nowrap">@if(can_do('purchasing.prices'))@php $pj = json_encode(['id' => $p->id, 'supplier_id' => $p->supplier_id, 'inventory_item_id' => $p->inventory_item_id, 'rate' => (float) $p->rate, 'min_qty' => (float) $p->min_qty, 'lead_days' => $p->lead_days, 'valid_from' => $p->valid_from->toDateString(), 'valid_to' => $p->valid_to?->toDateString(), 'notes' => $p->notes]); @endphp<button class="btn btn-sm btn-ghost" data-modal="priceModal" data-price="{{ $pj }}" onclick="editPrice(JSON.parse(this.dataset.price))"><x-icon name="edit" size="sm"/></button>
    @if($p->state() !== 'expired')<form method="post" action="{{ route('prices.end', ['sprice' => $p->id]) }}" style="display:inline" data-confirm="End this price as of yesterday?">@csrf<button class="btn btn-sm btn-ghost" title="End price"><x-icon name="x" size="sm"/></button></form>@endif @endif</td></tr>
@empty<tr><td colspan="9"><x-empty text="No prices match." icon="receipt"/></td></tr>@endforelse
</tbody></table>{{ $prices->links() }}</div></div>
@if(can_do('purchasing.prices'))
<div class="modal" id="priceModal"><div class="modal-box"><form method="post" action="{{ route('prices.store') }}" id="priceForm">@csrf
<div class="modal-head"><h3 id="priceTitle">Add price</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
<div class="modal-body form-grid">
  <x-field name="supplier_id" label="Supplier" type="select" :options="$suppliers" required/>
  <x-field name="inventory_item_id" label="Item" type="select" :options="$items->pluck('name', 'id')" required/>
  <x-field name="rate" label="Agreed rate (GH₵, excl. tax)" type="number" step="0.01" min="0.01" required/>
  <x-field name="min_qty" label="Minimum order qty" type="number" step="0.01" min="0" value="0"/>
  <x-field name="lead_days" label="Lead time (days)" type="number" min="0"/>
  <x-field name="valid_from" label="Valid from" type="date" :value="bdate()->toDateString()" required/>
  <x-field name="valid_to" label="Valid to" type="date" hint="blank = until replaced"/>
  <x-field name="notes" label="Notes / contract ref" class="span-all"/>
</div>
<div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Save</button></div></form></div></div>
@endif
@endsection
@push('scripts')<script>
function editPrice(p){ const f=document.getElementById('priceForm'); f.action = p ? '{{ url('inventory/supplier-prices') }}/'+p.id : '{{ route('prices.store') }}';
  document.getElementById('priceTitle').textContent = p ? 'Edit price' : 'Add price';
  ['supplier_id','inventory_item_id','rate','min_qty','lead_days','valid_from','valid_to','notes'].forEach(k=>{ const el=f.querySelector('[name='+k+']'); if(el) el.value = p ? (p[k] ?? '') : (k==='min_qty' ? 0 : (k==='valid_from' ? '{{ bdate()->toDateString() }}' : '')); }); }
</script>@endpush
