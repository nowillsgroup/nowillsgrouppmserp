@extends('layouts.app')
@section('title', 'Allergen Matrix')
@section('crumbs') <a href="{{ route('fs.index') }}">Food Safety</a> / Allergen matrix @endsection
@section('actions')
<a class="btn" href="{{ route('fs.allergens.print') }}" target="_blank" id="printMatrix"><x-icon name="print"/> Print matrix</a>
@endsection
@section('content')
@php $A = \App\Services\AllergenService::class; $manage = can_do('foodsafety.manage'); @endphp
<div class="stats" id="alStats">
  <x-stat label="Dishes on sale" :value="$att['total']" icon="cup"/>
  <x-stat label="Undeclared recipe allergens" :value="$att['undeclared']" icon="alert" :tone="$att['undeclared'] ? 'red' : 'green'" sub="ingredient allergens missing from the dish declaration"/>
  <x-stat label="Not yet verified" :value="$att['unverified']" icon="check" :tone="$att['unverified'] ? 'amber' : 'green'" sub="a chef has not confirmed the allergen information"/>
</div>
<div data-tabwrap>
<div class="tabs" data-tabs>
  <button class="{{ $tab === 'menu' ? 'active' : '' }}" data-tab="menu">Menu items</button>
  <button class="{{ $tab === 'ingredients' ? 'active' : '' }}" data-tab="ingredients">Ingredients</button>
  <button data-tab="legend">About the 14 allergens</button>
</div>

<div class="tab-pane {{ $tab === 'menu' ? 'active' : '' }}" id="menu">
  <div class="flex justify-between items-center mb-1 gap-1" style="flex-wrap:wrap">
    <input class="input" placeholder="Filter dishes…" data-filter-table="#menuMatrix" style="max-width:260px">
    <span class="small muted">@if($manage)Click a cell: <b>●</b> contains → <b>◐</b> may contain (cross-contact) → blank. @endif<span class="allergen-codes" style="margin:0">R</span> = comes from a recipe ingredient; a red outline means the dish declaration is missing it.</span>
  </div>
  <form method="post" action="{{ route('fs.allergens.menu') }}" id="menuForm">@csrf
  <div class="card flush"><div class="card-body table-wrap"><table class="table fs-matrix" id="menuMatrix">
    <thead><tr><th>Dish</th>@foreach($A::ALLERGENS as $k => [$l, $code])<th class="al" title="{{ $l }}"><span>{{ $A::short($k) }}</span></th>@endforeach<th>Verified</th></tr></thead>
    <tbody>
    @foreach($menu->groupBy(fn ($m) => $m->category?->name ?? 'Other') as $cat => $items)
      <tr class="muted"><td colspan="{{ count($A::ALLERGENS) + 2 }}" class="tiny bold" style="background:var(--c-bg);text-transform:uppercase;letter-spacing:.05em">{{ $cat }}</td></tr>
      @foreach($items as $m)
        @php $declared = $A::clean($m->allergens); $may = $A::clean($m->may_contain); $recipe = $A::fromRecipe($m); @endphp
        <tr data-dish="{{ $m->id }}" class="{{ $m->is_available ? '' : 'muted' }}"><td><b class="small">{{ $m->name }}</b>@if(! $m->is_available)<span class="tiny muted"> (off menu)</span>@endif
            @if($map[$m->id]['undeclared'])<div class="tiny text-red bold" data-undeclared>Recipe adds: {{ $A::names($map[$m->id]['undeclared']) }}</div>@endif<input type="hidden" name="ids[]" value="{{ $m->id }}"></td>
          @foreach($A::ALLERGENS as $k => $_)
            @php $v = in_array($k, $declared) ? 'c' : (in_array($k, $may) ? 'm' : ''); $fromR = in_array($k, $recipe); @endphp
            <td class="al {{ $fromR ? ($v === 'c' ? 'recipe' : 'miss') : '' }}" data-k="{{ $k }}"><input type="hidden" name="rows[{{ $m->id }}][{{ $k }}]" value="{{ $v }}">
              <button type="button" class="alc" data-v="{{ $v }}" @disabled(! $manage) aria-label="{{ $m->name }} – {{ $A::label($k) }}" title="{{ $A::label($k) }}{{ $fromR ? ' – in recipe' : '' }}">{{ $v === 'c' ? '●' : ($v === 'm' ? '◐' : '') }}</button>@if($fromR)<sup class="tiny" style="color:#92400e">R</sup>@endif</td>
          @endforeach
          <td class="small" style="white-space:nowrap">@if($m->allergens_verified_at)<span class="text-green">✓</span> {{ $m->allergens_verified_at->format('d/m/y') }}@else<span class="text-amber">not verified</span>@endif
            @if($manage)<label class="check tiny" style="padding:0"><input type="checkbox" name="verify[{{ $m->id }}]" value="1"> confirm</label>@endif</td></tr>
      @endforeach
    @endforeach
    </tbody></table></div>
    @if($manage)<div class="card-foot flex justify-between items-center"><span class="small muted">Changed rows and rows ticked "confirm" are stamped as verified by {{ auth()->user()->name }}.</span><button class="btn btn-primary" id="menuSave"><x-icon name="check"/> Save allergen information</button></div>@endif
  </div>
  </form>
</div>

<div class="tab-pane {{ $tab === 'ingredients' ? 'active' : '' }}" id="ingredients">
  <form method="post" action="{{ route('fs.allergens.ingredients') }}" id="ingForm">@csrf
  <div class="card flush"><div class="card-body table-wrap"><table class="table fs-matrix" id="ingMatrix">
    <thead><tr><th>Ingredient (stock item)</th>@foreach($A::ALLERGENS as $k => [$l])<th class="al" title="{{ $l }}"><span>{{ $A::short($k) }}</span></th>@endforeach<th class="num">Used in</th></tr></thead>
    <tbody>@forelse($food as $i)
      <tr data-ing="{{ $i->id }}"><td><b class="small">{{ $i->name }}</b><div class="tiny muted">{{ $i->category?->name }}</div><input type="hidden" name="ids[]" value="{{ $i->id }}"></td>
        @foreach($A::ALLERGENS as $k => $_)<td class="al"><input type="checkbox" name="rows[{{ $i->id }}][]" value="{{ $k }}" @checked(in_array($k, (array) $i->allergens)) @disabled(! $manage) aria-label="{{ $i->name }} – {{ $A::label($k) }}"></td>@endforeach
        <td class="num small">{{ $i->recipes()->count() }} dish(es)</td></tr>
    @empty <tr><td colspan="16"><x-empty text="No food stock items." icon="box"/></td></tr> @endforelse</tbody></table></div>
    @if($manage)<div class="card-foot flex justify-between items-center"><span class="small muted">Allergens set here flow to every dish whose recipe uses the ingredient – read the supplier's label or specification.</span><button class="btn btn-primary" id="ingSave">Save ingredients</button></div>@endif
  </div></form>
</div>

<div class="tab-pane" id="legend">
  <div class="card"><div class="card-body"><div class="allergen-picks">@foreach($A::ALLERGENS as $k => [$l, $code])<div class="small"><span class="allergen-codes" style="margin:0 .4rem 0 0">{{ $code }}</span>{{ $l }}</div>@endforeach</div>
  <p class="small muted">The 14 major allergens of the Codex Alimentarius General Standard for the Labelling of Pre-packaged Foods, which the Ghana FDA labelling guidelines follow (the same list as EU Regulation 1169/2011 Annex II). Staff must be able to tell a guest which dishes contain them; the printed matrix is kept at the pass and at each service station. Guest allergies recorded on a POS order are checked against every dish sent to the kitchen, and the KOT and kitchen display carry the warning.</p></div></div>
</div>
</div>
@endsection
@push('head')
<style>.alc{width:1.7rem;height:1.7rem;border:1px solid var(--c-border);border-radius:6px;background:#fff;font-size:1rem;line-height:1;cursor:pointer;color:var(--c-red);padding:0}.alc[data-v=m]{color:var(--c-amber)}.alc:disabled{cursor:default;border-color:transparent;background:transparent}</style>
@endpush
@push('scripts')
<script>
document.querySelectorAll('.alc').forEach(b => b.addEventListener('click', () => {
  const next = {'': 'c', c: 'm', m: ''}[b.dataset.v]; b.dataset.v = next; b.textContent = next === 'c' ? '●' : (next === 'm' ? '◐' : '');
  b.previousElementSibling.value = next; const td = b.closest('td');
  if (td.querySelector('sup')) { td.classList.toggle('recipe', next === 'c'); td.classList.toggle('miss', next !== 'c'); }
}));
</script>
@endpush
