@extends('layouts.app')
@section('title', 'Yield Tests')
@section('crumbs') F&amp;B Cost Control / Yield tests @endsection
@php $canCost = \App\Services\FbCostService::canCost(auth()->user()); @endphp
@section('actions')
  @if($canCost)<button class="btn btn-primary" data-modal="fbcYieldModal" id="fbcYieldNew"><x-icon name="plus"/> Record yield test</button>@endif
@endsection
@section('content')
<div class="g-2-1">
  <div class="card flush"><div class="card-head"><h3>Butcher &amp; yield tests</h3></div><div class="card-body table-wrap"><table class="table table-compact" id="fbcYieldTable">
    <thead><tr><th>Test</th><th>Ingredient</th><th class="num">Gross</th><th class="num">Trim</th><th class="num">Cooking loss</th><th class="num">Usable</th><th class="num">Yield</th><th class="num">Cost as bought</th><th class="num">Cost usable</th><th></th></tr></thead><tbody>
    @forelse($tests as $t)<tr>
      <td><b class="mono">{{ $t->number }}</b><div class="tiny muted">{{ $t->tested_on->format('d M Y') }} · {{ $t->user?->name }}</div></td>
      <td>{{ $t->item?->name }}@if($t->notes)<div class="tiny muted">{{ $t->notes }}</div>@endif</td>
      <td class="num">{{ rtrim(rtrim(number_format($t->gross_qty, 3), '0'), '.') }} {{ $t->item?->unit }}</td><td class="num">{{ rtrim(rtrim(number_format($t->trim_qty, 3), '0'), '.') }}</td>
      <td class="num">{{ rtrim(rtrim(number_format($t->cooking_loss_qty, 3), '0'), '.') }}</td><td class="num">{{ rtrim(rtrim(number_format($t->usable_qty, 3), '0'), '.') }}</td>
      <td class="num"><b>{{ $t->yield_pct }}%</b></td><td class="num">{{ money($t->unit_cost) }}</td><td class="num">{{ money($t->usableCost()) }}</td>
      <td class="nowrap">@if($t->applied)<span class="badge badge-green">in use</span>@elseif($canCost)<form method="post" action="{{ route('fbc.yields.apply', $t) }}" onsubmit="return confirm('Use {{ $t->yield_pct }}% as the yield of {{ addslashes($t->item?->name) }} in every recipe?')">@csrf<button class="btn btn-sm">Apply</button></form>@endif</td></tr>
    @empty<tr><td colspan="10" class="muted">No yield tests yet.</td></tr>@endforelse
    </tbody></table></div>@if($tests->hasPages())<div class="card-body">{{ $tests->links() }}</div>@endif</div>
  <div class="card flush"><div class="card-head"><h3>Ingredients with a trim yield</h3></div><div class="card-body table-wrap"><table class="table table-compact" id="fbcTrimmed"><tbody>
    @forelse($trimmed as $i)<tr><td>{{ $i->name }}</td><td class="num"><b>{{ rtrim(rtrim(number_format($i->yield_pct, 2), '0'), '.') }}%</b></td><td class="num small">{{ money($i->last_cost) }} → {{ money($i->last_cost * 100 / max(1, $i->yield_pct)) }}</td></tr>
    @empty<tr><td class="muted">Every ingredient is costed at 100 % yield.</td></tr>@endforelse
  </tbody></table><p class="tiny muted" style="padding:0 .8rem">Plate costs and recipe consumption use the yield: a recipe calls for the net (cleaned) quantity, stock is drawn as purchased.</p></div></div>
</div>
@if($canCost)
<div class="modal" id="fbcYieldModal"><div class="modal-box"><form method="post" action="{{ route('fbc.yields.store') }}" data-reset>@csrf
  <div class="modal-head"><h3>Record yield test</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field span-all"><label>Ingredient</label><select name="inventory_item_id" required><option value="">—</option>@foreach($ingredients as $i)<option value="{{ $i->id }}">{{ $i->name }} ({{ $i->unit }})</option>@endforeach</select></div>
    <div class="field"><label>Date</label><input type="date" name="tested_on" value="{{ bdate()->toDateString() }}" max="{{ bdate()->toDateString() }}"></div>
    <div class="field"><label>Gross as purchased</label><input type="number" step="0.001" min="0.001" name="gross_qty" required></div>
    <div class="field"><label>Trim (bones, skin, peel…)</label><input type="number" step="0.001" min="0" name="trim_qty" value="0"></div>
    <div class="field"><label>Cooking loss</label><input type="number" step="0.001" min="0" name="cooking_loss_qty" value="0"></div>
    <div class="field"><label>Usable (blank = gross − trim − loss)</label><input type="number" step="0.001" min="0" name="usable_qty"></div>
    <div class="field span-all"><label>Notes</label><input name="notes" maxlength="255" placeholder="supplier, cut, method"></div>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Record test</button></div>
</form></div></div>
@endif
@endsection
