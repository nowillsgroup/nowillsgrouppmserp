@extends('layouts.app')
@section('title', 'Edit recipe – '.$m->name)
@section('crumbs') <a href="{{ route('fbc.recipes') }}">Recipe cards</a> / <a href="{{ route('fbc.card', $m) }}">{{ $m->name }}</a> / Edit @endsection
@php
  $rows = collect(old('lines', $lines->map(fn ($l) => ['inventory_item_id' => $l->item->id, 'qty' => rtrim(rtrim(number_format($l->qty, 3, '.', ''), '0'), '.')])->all()));
  $blank = max(4, 10 - $rows->count());
  for ($i = 0; $i < $blank; $i++) { $rows->push(['inventory_item_id' => '', 'qty' => '']); }
  $opts = $ingredients->map(fn ($i) => ['id' => $i->id, 'label' => $i->name.' ('.$i->unit.')', 'cat' => $i->category?->name ?? 'Other',
      'cost' => \App\Services\FbCostService::unitCost($i), 'yield' => (float) $i->yield_pct])->groupBy('cat');
@endphp
@section('content')
<form method="post" action="{{ route('fbc.card.save', $m) }}" id="fbcCardForm">@csrf
<div class="g-2-1">
  <div>
    <div class="card"><div class="card-head"><h3>Ingredients – net quantity per portion</h3><span class="small muted">in the stock unit; the yield is added automatically</span></div>
      <div class="card-body table-wrap"><table class="table table-compact" id="fbcEditLines"><thead><tr><th style="width:55%">Ingredient</th><th class="num">Qty / portion</th><th class="num">Yield</th><th class="num">Cost</th></tr></thead><tbody>
      @foreach($rows->values() as $i => $row)<tr>
        <td><select name="lines[{{ $i }}][inventory_item_id]" class="fbc-ing" aria-label="Ingredient {{ $i + 1 }}"><option value="">—</option>
          @foreach($opts as $cat => $list)<optgroup label="{{ $cat }}">@foreach($list as $o)<option value="{{ $o['id'] }}" data-cost="{{ $o['cost'] }}" data-yield="{{ $o['yield'] }}" @selected((int) ($row['inventory_item_id'] ?? 0) === $o['id'])>{{ $o['label'] }}</option>@endforeach</optgroup>@endforeach
        </select></td>
        <td class="num"><input type="number" step="0.001" min="0" name="lines[{{ $i }}][qty]" value="{{ $row['qty'] ?? '' }}" class="fbc-qty" style="width:7rem" aria-label="Quantity {{ $i + 1 }}"></td>
        <td class="num small fbc-y">—</td><td class="num fbc-c">—</td>
      </tr>@endforeach
      </tbody><tfoot><tr class="bold-row"><td colspan="3">Plate cost</td><td class="num" id="fbcEditTotal">—</td></tr></tfoot></table>
      <p class="tiny muted">Save with more blank rows filled to add ingredients; clear a row to remove it. Changing ingredients creates a new version that needs approval again.</p></div></div>
    <div class="card"><div class="card-head"><h3>Method</h3></div><div class="card-body">
      <div class="field"><label>Method (step by step)</label><textarea name="method" rows="8">{{ old('method', $card?->method) }}</textarea></div>
      <div class="field"><label>Garnish / presentation</label><input name="garnish" value="{{ old('garnish', $card?->garnish) }}" maxlength="190"></div>
    </div></div>
  </div>
  <div>
    <div class="card"><div class="card-head"><h3>Card</h3></div><div class="card-body"><div class="form-grid">
      <div class="field"><label>Portion size</label><input name="portion_size" value="{{ old('portion_size', $card?->portion_size) }}" maxlength="60" placeholder="e.g. 250 g, 1 bowl, 60 ml"></div>
      <div class="field"><label>Batch makes (portions)</label><input type="number" name="batch_portions" min="1" max="500" value="{{ old('batch_portions', $card?->batch_portions ?? 1) }}"></div>
      <div class="field"><label>Preparation (min)</label><input type="number" name="prep_minutes" min="0" max="1440" value="{{ old('prep_minutes', $card?->prep_minutes) }}"></div>
      <div class="field"><label>Station</label><input name="station" value="{{ old('station', $card?->station ?? $m->category?->kitchen_station) }}" maxlength="40"></div>
      <div class="field span-all"><label>Own target cost % (blank = {{ $cls }} target {{ $cls === 'beverage' ? $cfg['bev_target'] : $cfg['food_target'] }}%)</label><input type="number" step="0.5" name="target_pct" min="5" max="80" value="{{ old('target_pct', $card?->target_pct) }}" id="fbcTarget"></div>
    </div></div></div>
    <div class="card"><div class="card-head"><h3>Costing</h3></div><div class="card-body">
      <div class="kv-grid" id="fbcEditSummary" data-price="{{ $price }}" data-target="{{ $target }}" data-step="{{ $cfg['round'] }}">
        <div><span>Menu price</span>{{ money($price) }}</div>
        <div><span>Plate cost</span><b id="fbcSumCost">—</b></div>
        <div><span>Cost %</span><b id="fbcSumPct">—</b></div>
        <div><span>Price at target</span><b id="fbcSumSuggest">—</b></div>
      </div>
    </div></div>
    <button class="btn btn-primary" id="fbcCardSave"><x-icon name="check"/> Save recipe card</button>
    <a class="btn" href="{{ route('fbc.card', $m) }}">Cancel</a>
  </div>
</div>
</form>
<script>
(function () {
  const form = document.getElementById('fbcCardForm'); const sum = document.getElementById('fbcEditSummary');
  const price = parseFloat(sum.dataset.price) || 0, step = parseInt(sum.dataset.step, 10) || 1;
  const fmt = v => 'GH\u20B5' + v.toLocaleString('en-GH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  function calc() {
    let total = 0;
    form.querySelectorAll('#fbcEditLines tbody tr').forEach(tr => {
      const sel = tr.querySelector('.fbc-ing'); const opt = sel.options[sel.selectedIndex]; const q = parseFloat(tr.querySelector('.fbc-qty').value) || 0;
      if (!opt || !opt.value) { tr.querySelector('.fbc-y').textContent = '—'; tr.querySelector('.fbc-c').textContent = '—'; return; }
      const y = parseFloat(opt.dataset.yield) || 100, c = q * 100 / y * (parseFloat(opt.dataset.cost) || 0);
      total += c; tr.querySelector('.fbc-y').textContent = y + '%'; tr.querySelector('.fbc-c').textContent = fmt(c);
    });
    const tgtIn = parseFloat(document.getElementById('fbcTarget').value); const tgt = tgtIn > 0 ? tgtIn : parseFloat(sum.dataset.target);
    document.getElementById('fbcEditTotal').textContent = fmt(total); document.getElementById('fbcSumCost').textContent = fmt(total);
    document.getElementById('fbcSumPct').textContent = price > 0 ? (total / price * 100).toFixed(1) + '%' : '—';
    document.getElementById('fbcSumSuggest').textContent = total > 0 && tgt > 0 ? fmt(Math.ceil(total * 100 / tgt / step) * step) : '—';
  }
  form.addEventListener('input', calc); form.addEventListener('change', calc); calc();
})();
</script>
@endsection
