{{-- Price-list grid: qty per item × service. Vars: $items (grouped by category), $current [item][service] => qty, $remarks [item] => text, $expressPct, $readonly --}}
<div class="table-wrap"><table class="table table-compact laundry-grid" id="laundryGrid" data-express="{{ $expressPct }}">
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
<tfoot><tr><td class="right">Total</td><td colspan="3" class="num"><span id="lgPcs">0</span> pcs · <b id="lgSub">0.00</b><span id="lgExp" class="muted"></span></td><td class="small muted">Tax is added when it's posted to the bill.</td></tr></tfoot>
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
    document.getElementById('lgPcs').textContent = pcs;
    document.getElementById('lgSub').textContent = fmt(sub + exp);
    document.getElementById('lgExp').textContent = exp ? ' (incl. express ' + fmt(exp) + ')' : '';
  };
  document.addEventListener('input', e => { if (e.target.matches('.lg-qty')) calc(); });
  document.addEventListener('change', e => { if (e.target.name === 'speed') calc(); });
  calc();
})();
</script>
