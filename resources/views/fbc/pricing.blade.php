@extends('layouts.app')
@section('title', 'Menu Pricing')
@section('crumbs') F&amp;B Cost Control / Menu pricing @endsection
@php $canPrice = \App\Services\FbCostService::canPrice(auth()->user()); @endphp
@section('content')
<div class="card"><div class="card-body"><form method="get" class="flex gap-1 wrap" style="align-items:center">
  <select name="outlet" aria-label="Outlet"><option value="">All outlets</option>@foreach($outlets as $o)<option value="{{ $o->id }}" @selected((int) request('outlet') === $o->id)>{{ $o->name }}</option>@endforeach</select>
  <select name="show" aria-label="Show"><option value="">All costed dishes</option><option value="over" @selected(request('show') === 'over')>Above target only</option></select>
  <button class="btn btn-sm">Filter</button>
  <span class="small muted">Type a price in "What if" to see the cost % and margin before changing it. Suggested prices round up to {{ $cfg['round'] }}.</span>
</form></div></div>
<div class="card flush"><div class="card-body table-wrap"><table class="table table-compact" id="fbcPriceTable">
  <thead><tr><th>Dish</th><th class="num">Plate cost</th><th class="num">Price</th><th class="num">Cost %</th><th class="num">Target</th><th class="num">Suggested</th><th class="num">What if</th><th class="num">New cost %</th><th class="num">New GP</th>@if($canPrice)<th></th>@endif</tr></thead><tbody>
  @forelse($dishes as $m)<tr class="{{ $m->st === 'over' ? 'row-amber' : '' }}" data-cost="{{ $m->cost }}" data-target="{{ $m->target }}">
    <td><a href="{{ route('fbc.card', $m) }}"><b>{{ $m->name }}</b></a><div class="tiny muted">{{ $m->category?->name }}</div></td>
    <td class="num">{{ money($m->cost) }}</td><td class="num">{{ money($m->price) }}</td><td class="num"><b>{{ $m->pct }}%</b></td><td class="num">{{ $m->target }}%</td>
    <td class="num">{{ money($m->suggested) }}</td>
    <td class="num"><input type="number" step="0.01" min="0" class="fbc-whatif" style="width:6.5rem" value="{{ $m->st === 'over' ? $m->suggested : '' }}" aria-label="What-if price for {{ $m->name }}"></td>
    <td class="num fbc-wpct">—</td><td class="num fbc-wgp">—</td>
    @if($canPrice)<td><button type="button" class="btn btn-sm" data-modal="fbcPriceModal" data-action="{{ route('fbc.pricing.change', $m) }}" data-title="Change price – {{ $m->name }}" data-fprice="{{ $m->st === 'over' ? $m->suggested : $m->price }}" data-freason="">Change</button></td>@endif
  </tr>
  @empty<tr><td colspan="10" class="muted">No costed dishes.</td></tr>@endforelse
  </tbody></table></div></div>
<div class="card flush"><div class="card-head"><h3>Price change log</h3></div><div class="card-body table-wrap"><table class="table table-compact" id="fbcPriceLog">
  <thead><tr><th>Date</th><th>Dish</th><th class="num">Old</th><th class="num">New</th><th class="num">Plate cost then</th><th class="num">Cost % after</th><th>Reason</th><th>By</th></tr></thead><tbody>
  @forelse($changes as $c)<tr><td class="nowrap">{{ $c->effective_on->format('d M Y') }}</td><td>{{ $c->menuItem?->name }}</td><td class="num">{{ money($c->old_price) }}</td><td class="num"><b>{{ money($c->new_price) }}</b></td>
    <td class="num">{{ money($c->cost_at) }}</td><td class="num">{{ $c->new_price > 0 ? round($c->cost_at / $c->new_price * 100, 1).'%' : '—' }}</td><td class="small">{{ $c->reason }}</td><td class="small">{{ $c->user?->name }}</td></tr>
  @empty<tr><td colspan="8" class="muted">No price changes yet.</td></tr>@endforelse
  </tbody></table></div></div>
@if($canPrice)
<div class="modal" id="fbcPriceModal"><div class="modal-box"><form method="post" action="">@csrf
  <div class="modal-head"><h3 data-title>Change price</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field"><label>New price (ex tax)</label><input type="number" step="0.01" min="0.01" name="price" required></div>
    <div class="field span-all"><label>Reason</label><input name="reason" maxlength="190" required placeholder="e.g. supplier price rise, menu review, bring to target"></div>
  </div><p class="tiny muted">The change applies at once on the POS and is logged with today's plate cost.</p></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Change price</button></div>
</form></div></div>
@endif
<script>
(function () {
  const fmt = v => 'GH\u20B5' + v.toLocaleString('en-GH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  function calc(tr) {
    const p = parseFloat(tr.querySelector('.fbc-whatif').value), c = parseFloat(tr.dataset.cost), t = parseFloat(tr.dataset.target);
    const pc = tr.querySelector('.fbc-wpct'), gp = tr.querySelector('.fbc-wgp');
    if (!(p > 0)) { pc.textContent = '—'; gp.textContent = '—'; pc.className = 'num fbc-wpct'; return; }
    const pct = c / p * 100; pc.textContent = pct.toFixed(1) + '%'; pc.className = 'num fbc-wpct ' + (pct > t ? 'text-red' : 'text-green'); gp.textContent = fmt(p - c);
  }
  document.querySelectorAll('#fbcPriceTable tbody tr[data-cost]').forEach(tr => { calc(tr); tr.querySelector('.fbc-whatif').addEventListener('input', () => calc(tr)); });
})();
</script>
@endsection
