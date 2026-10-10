@extends('layouts.app')
@section('title', 'Recipe · '.$item->name)
@section('crumbs') Inventory / <a href="{{ route('recipes.index') }}">Recipes</a> / {{ $item->name }} @endsection
@section('content')
<form method="post" action="{{ route('recipes.update', $item) }}" class="card" style="max-width:860px">@csrf @method('PUT')
  <div class="card-head"><h3>{{ $item->name }} – ingredients per portion</h3><span class="small muted">Selling price {{ money($item->price) }}</span></div>
  <div class="card-body">
    <table class="table table-compact" id="rlines"><thead><tr><th>Stock item</th><th style="width:140px">Qty / portion</th><th style="width:70px">Unit</th><th class="num" style="width:110px">Cost</th><th style="width:40px"></th></tr></thead><tbody>
    @php $rows = $item->recipes->map(fn($r) => ['id' => $r->inventory_item_id, 'qty' => (float) $r->qty])->all(); if (!count($rows)) $rows = [['id' => null, 'qty' => null]]; @endphp
    @foreach($rows as $i => $r)
      <tr><td><select name="lines[{{ $i }}][inventory_item_id]" onchange="rc()"><option value="">— select —</option>@foreach($stock as $s)<option value="{{ $s->id }}" data-unit="{{ $s->unit }}" data-cost="{{ (float) $s->last_cost }}" @selected($r['id'] == $s->id)>{{ $s->name }}</option>@endforeach</select></td>
        <td><input name="lines[{{ $i }}][qty]" type="number" step="0.001" min="0" value="{{ $r['qty'] }}" oninput="rc()"></td><td class="u small muted"></td><td class="num c"></td>
        <td><button type="button" class="btn btn-ghost btn-sm" onclick="this.closest('tr').remove(); rc()" title="Remove"><x-icon name="x" size="sm"/></button></td></tr>
    @endforeach
    </tbody><tfoot><tr><td colspan="3"><button type="button" class="btn btn-sm" onclick="addLine()">+ Add ingredient</button></td><td class="num bold" id="rtot"></td><td></td></tr>
      <tr><td colspan="3" class="right muted">Food cost % of selling price</td><td class="num bold" id="rpct"></td><td></td></tr></tfoot></table>
    <p class="hint mt-1">Costs use each stock item's last purchase rate. When a bill containing this item is settled, these quantities × portions are deducted from the outlet's store as a <b>consumption</b> movement. Leave empty to stop deducting.</p>
  </div>
  <div class="card-foot"><a class="btn" href="{{ route('recipes.index') }}">Cancel</a><button class="btn btn-primary">Save recipe</button></div>
</form>
@endsection
@push('scripts')
<script>
const PRICE = {{ (float) $item->price }};
let li = {{ count($rows) }};
function rc() {
  let tot = 0;
  document.querySelectorAll('#rlines tbody tr').forEach(tr => {
    const o = tr.querySelector('select').selectedOptions[0]; const q = parseFloat(tr.querySelector('input').value) || 0;
    const cost = o && o.value ? q * parseFloat(o.dataset.cost || 0) : 0; tot += cost;
    tr.querySelector('.u').textContent = o && o.value ? o.dataset.unit : ''; tr.querySelector('.c').textContent = cost ? cost.toFixed(2) : '';
  });
  document.getElementById('rtot').textContent = tot.toFixed(2);
  document.getElementById('rpct').textContent = PRICE > 0 ? (tot / PRICE * 100).toFixed(1) + '%' : '—';
}
function addLine() {
  const tb = document.querySelector('#rlines tbody'); const tr = tb.rows[0].cloneNode(true);
  tr.querySelectorAll('[name]').forEach(el => { el.name = el.name.replace(/\[\d+\]/, '[' + li + ']'); el.value = ''; });
  li++; tb.appendChild(tr); rc();
}
rc();
</script>
@endpush
