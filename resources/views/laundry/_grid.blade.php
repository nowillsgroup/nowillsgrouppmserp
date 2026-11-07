{{-- Price-list grid: qty per item × service. Vars: $items (grouped by category), $current [item][service] => qty, $remarks [item] => text, $expressPct, $readonly --}}
<div class="table-wrap"><table class="table table-compact laundry-grid" id="laundryGrid" data-express="{{ $expressPct }}"
  data-tax-rate="{{ $tax['rate'] ?? 0 }}" data-tax-inclusive="{{ ! empty($tax['inclusive']) ? 1 : 0 }}" data-tax-label="{{ $tax['label'] ?? '' }}">
<thead><tr><th>Item</th>@foreach(\App\Models\LaundryItem::SERVICES as $sk => $sl)<th class="num" style="width:130px">{{ $sl }}</th>@endforeach<th>Remark (stain, damage…)</th></tr></thead>
<tbody>
@foreach($items as $cat => $rows)
<tr class="group-row"><td colspan="5">{{ \App\Models\LaundryItem::CATEGORIES[$cat] ?? ucfirst($cat) }}</td></tr>
@foreach($rows as $it)
<tr><td>{{ $it->name }}</td>
@foreach(array_keys(\App\Models\LaundryItem::SERVICES) as $sk)
<td class="num">@if(($r = $it->rate($sk)) !== null)<div class="flex items-center gap-1" style="justify-content:flex-end"><span class="tiny muted">{{ money($r, false) }} ×</span><input type="number" min="0" max="99" class="input input-sm lg-qty" style="width:58px" name="q[{{ $it->id }}][{{ $sk }}]" data-rate="{{ $r }}" value="{{ ($current[$it->id][$sk] ?? 0) ?: '' }}" @disabled($readonly ?? false)></div>@else<span class="muted">—</span>@endif</td>
@endforeach
<td><input class="input input-sm" name="r[{{ $it->id }}]" maxlength="150" value="{{ $remarks[$it->id] ?? '' }}" @disabled($readonly ?? false)></td></tr>
@endforeach
@endforeach
</tbody>
<tfoot><tr><td class="right">Total</td><td colspan="3" class="num"><span id="lgPcs">0</span> pcs · <b id="lgSub">0.00</b><span id="lgExp" class="muted"></span><span id="lgTax" class="muted"></span></td><td class="small muted">The tax line is carried on the bill.</td></tr></tfoot>
</table></div>
<script>
(() => {
  const grid = document.getElementById('laundryGrid'); if (!grid) return;
  const fmt = n => n.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
  const calc = () => {
    let pcs = 0, sub = 0;
    grid.querySelectorAll('.lg-qty').forEach(i => { const q = parseInt(i.value || 0, 10) || 0; pcs += q; sub += q * parseFloat(i.dataset.rate); });
    const sp = document.querySelector('[name=speed]:checked') || document.querySelector('select[name=speed]');
    const exp = sp && sp.value === 'express' ? Math.round(sub * parseFloat(grid.dataset.express)) / 100 : 0;
    const rate = parseFloat(grid.dataset.taxRate || 0);
    const inclusive = grid.dataset.taxInclusive === '1';
    const listed = sub + exp;
    const net = inclusive && rate > 0 ? listed / (1 + rate / 100) : listed;
    const tax = inclusive && rate > 0 ? listed - net : listed * rate / 100;
    const payable = inclusive && rate > 0 ? listed : listed + tax;
    document.getElementById('lgPcs').textContent = pcs;
    document.getElementById('lgSub').textContent = fmt(listed) + (rate <= 0 ? '' : inclusive ? ' incl. tax' : '');
    document.getElementById('lgExp').textContent = exp ? ' (incl. express ' + fmt(exp) + ')' : '';
    const taxLine = document.getElementById('lgTax');
    if (taxLine) {
      taxLine.textContent = rate <= 0
        ? ' · no laundry tax set'
        : ' · ' + grid.dataset.taxLabel + ' ' + (inclusive ? 'already included: ' : '') + fmt(tax) + ' → payable ' + fmt(payable);
    }
  };
  document.addEventListener('input', e => { if (e.target.matches('.lg-qty')) calc(); });
  document.addEventListener('change', e => { if (e.target.name === 'speed') calc(); });
  calc();
})();
</script>
