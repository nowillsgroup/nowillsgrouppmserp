@extends('layouts.app')
@section('title', $def['title'])
@section('crumbs') Masters / {{ $def['title'] }} @endsection
@section('actions')
  @if(can_do('masters.manage'))
    <a class="btn" href="{{ route('masters.index', 'menu-categories') }}"><x-icon name="layers"/> Categories</a>
    <a class="btn" href="{{ route('pos.modifiers') }}"><x-icon name="plus"/> Modifiers</a>
  @endif
  @if(can_do('pos.printers'))<a class="btn" href="{{ route('pos.printers') }}"><x-icon name="print"/> KOT printers</a>@endif
  @if(can_do('inventory.manage'))<a class="btn" href="{{ route('recipes.index') }}"><x-icon name="layers"/> Recipe cards</a>@endif
  <a class="btn btn-primary" href="{{ route('masters.create', $def['slug']) }}"><x-icon name="plus"/> Add menu item</a>
@endsection
@section('content')
@if(!empty($def['hint']))<div class="alert alert-info menu-items-hint"><x-icon name="alert"/><div>{{ $def['hint'] }}</div></div>@endif

<div class="stats menu-items-stats" aria-label="Menu item counts">
  <x-stat label="Catalog items" :value="$summary->total_count" icon="grid" tone="blue" sub="for the selected search, category and outlet"/>
  <x-stat label="Available on POS" :value="$summary->available_count" icon="check" tone="green"/>
  <x-stat label="Hidden from POS" :value="$summary->hidden_count" icon="eye" tone="slate"/>
</div>

<section class="card menu-items-filter-card" aria-labelledby="menu-items-filter-title">
  <div class="card-head"><div><h3 id="menu-items-filter-title">Find menu items</h3><div class="small muted">Search by item name, code or description, then narrow by category, outlet or availability.</div></div></div>
  <div class="card-body">
    <form method="get" action="{{ route('masters.index', $def['slug']) }}" class="menu-items-filters">
      <div class="field menu-items-search"><label for="menuItemSearch">Search</label><input id="menuItemSearch" type="search" name="q" value="{{ $q }}" placeholder="Name, item code or description" autocomplete="off"></div>
      <div class="field"><label for="menuItemCategory">Category</label><select id="menuItemCategory" name="category"><option value="">All categories</option>@foreach($categories as $id => $name)<option value="{{ $id }}" @selected((string)($filters['category'] ?? '') === (string)$id)>{{ $name }}</option>@endforeach</select></div>
      <div class="field"><label for="menuItemOutlet">Outlet</label><select id="menuItemOutlet" name="outlet"><option value="">All outlets</option>@foreach($outlets as $id => $name)<option value="{{ $id }}" @selected((string)($filters['outlet'] ?? '') === (string)$id)>{{ $name }}</option>@endforeach</select></div>
      <div class="field"><label for="menuItemAvailability">Availability</label><select id="menuItemAvailability" name="availability"><option value="">All items</option><option value="available" @selected(($filters['availability'] ?? '') === 'available')>Available on POS</option><option value="hidden" @selected(($filters['availability'] ?? '') === 'hidden')>Hidden from POS</option></select></div>
      <div class="menu-items-filter-actions"><button class="btn btn-primary"><x-icon name="search"/> Apply filters</button><a class="btn btn-ghost" href="{{ route('masters.index', $def['slug']) }}">Reset</a></div>
    </form>
  </div>
</section>

<section class="card flush menu-items-table-card" aria-labelledby="menu-items-catalog-title">
  <div class="card-head menu-items-catalog-head">
    <div><h3 id="menu-items-catalog-title">Menu catalog</h3><div class="small muted">{{ $rows->total() }} {{ \Illuminate\Support\Str::plural('item', $rows->total()) }} in this view · edit an item to change its price, routing, photo or POS availability.</div></div>
    <span class="badge badge-slate">Page {{ $rows->currentPage() }} of {{ max(1, $rows->lastPage()) }}</span>
  </div>
  <div class="card-body table-wrap menu-items-table-wrap">
    <table class="table menu-items-table">
      <caption class="sr-only">Menu items with category, outlet, kitchen routing, price, tax and availability</caption>
      <thead><tr><th scope="col">Item</th><th scope="col">Category</th><th scope="col">Outlet</th><th scope="col">KOT printer</th><th scope="col">Side dishes</th><th scope="col">Type</th><th scope="col" class="num">Price</th><th scope="col" class="num">Tax</th><th scope="col">POS status</th><th scope="col" class="right">Actions</th></tr></thead>
      <tbody>
      @forelse($rows as $item)
        @php
          $photo = $item->imageUrl();
          $foodType = ['veg' => 'Veg', 'non_veg' => 'Non-veg', 'egg' => 'Egg', 'beverage' => 'Beverage'][$item->food_type] ?? str_replace('_', ' ', ucfirst((string)$item->food_type));
        @endphp
        <tr>
          <td class="menu-items-name-cell">
            <div class="menu-items-item">
              @if($photo)<img src="{{ $photo }}" alt="" class="menu-items-thumb" loading="lazy">@else<span class="menu-items-thumb menu-items-thumb-empty" aria-hidden="true"><x-icon name="cup"/></span>@endif
              <div class="menu-items-item-copy"><a class="menu-items-name" href="{{ route('masters.edit', [$def['slug'], $item->id]) }}">{{ $item->name }}</a><div class="tiny muted">{{ $item->code ?: 'No item code' }}@if($item->description) · {{ $item->description }}@endif</div></div>
            </div>
          </td>
          <td>{{ $item->category?->name ?? '—' }}</td>
          <td>{{ $item->outlet?->name ?? 'All outlets' }}</td>
          <td>{{ $item->printer?->name ?? 'Category / station' }}</td>
          <td>@if($item->variants_count)<span class="badge badge-blue">{{ $item->variants_count }} side dish{{ $item->variants_count === 1 ? '' : 'es' }}</span>@else<span class="tiny muted">None</span>@endif</td>
          <td><span class="badge {{ $item->food_type === 'veg' ? 'badge-green' : ($item->food_type === 'non_veg' ? 'badge-amber' : 'badge-slate') }}">{{ $foodType }}</span></td>
          <td class="num menu-items-price">{{ money($item->price) }}</td>
          <td class="num">{{ number_format((float)$item->tax_rate, 2) }}%</td>
          <td><div class="menu-items-status-control">
            @if($item->is_available)<span class="badge badge-green">Available</span>@else<span class="badge badge-slate">Hidden</span>@endif
            <form method="post" action="{{ route('masters.availability', [$def['slug'], $item->id]) }}" class="menu-items-availability-form">@csrf @method('PATCH')<input type="hidden" name="is_available" value="{{ $item->is_available ? 0 : 1 }}"><button class="btn btn-sm btn-ghost" aria-label="{{ $item->is_available ? 'Hide '.$item->name.' from POS' : 'Make '.$item->name.' available on POS' }}">{{ $item->is_available ? 'Hide from POS' : 'Show on POS' }}</button></form>
          </div></td>
          <td class="right menu-items-row-actions">
            <a class="btn btn-sm" href="{{ route('masters.edit', [$def['slug'], $item->id]) }}" aria-label="Edit {{ $item->name }}"><x-icon name="edit" size="sm"/> Edit</a>
            @unless($item->getAttribute('system_key'))
              <form method="post" action="{{ route('masters.destroy', [$def['slug'], $item->id]) }}" class="inline" data-confirm="Remove {{ $item->name }}? Items used on past orders cannot be deleted.">@csrf @method('DELETE')<button class="btn btn-sm btn-ghost" aria-label="Delete {{ $item->name }}"><x-icon name="trash" size="sm"/></button></form>
            @endunless
          </td>
        </tr>
      @empty
        <tr><td colspan="10"><x-empty :text="$q || collect($filters)->filter()->isNotEmpty() ? 'No menu items match these filters.' : 'No menu items have been added yet.'" icon="cup">
          @if($q || collect($filters)->filter()->isNotEmpty())<a class="btn btn-ghost btn-sm mt-1" href="{{ route('masters.index', $def['slug']) }}">Clear filters</a>@else<a class="btn btn-primary btn-sm mt-1" href="{{ route('masters.create', $def['slug']) }}">Add the first menu item</a>@endif
        </x-empty></td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
  @if($rows->hasPages())<div class="card-foot menu-items-pagination">{{ $rows->links() }}</div>@endif
</section>

<style>
.menu-items-hint{display:flex;align-items:flex-start;gap:.6rem}.menu-items-hint svg{flex:0 0 auto;margin-top:.1rem}
.menu-items-stats{grid-template-columns:repeat(3,minmax(0,1fr))}
.menu-items-filter-card{margin-bottom:1rem}.menu-items-filters{display:grid;grid-template-columns:minmax(220px,2fr) repeat(3,minmax(145px,1fr)) auto;align-items:end;gap:.75rem}.menu-items-filters .field{margin:0;min-width:0}.menu-items-filters label{display:block;margin-bottom:.35rem;font-size:.78rem;font-weight:650;color:var(--muted,#718096)}.menu-items-filter-actions{display:flex;gap:.45rem;align-items:center;white-space:nowrap}
.menu-items-catalog-head{gap:1rem}.menu-items-table-wrap{overflow-x:auto}.menu-items-table{min-width:1080px}.menu-items-table th{white-space:nowrap}.menu-items-name-cell{min-width:255px}.menu-items-item{display:flex;align-items:center;gap:.7rem;min-width:0}.menu-items-thumb{width:42px;height:42px;flex:0 0 42px;object-fit:cover;border-radius:9px;background:var(--surface-2,#f1f5f9)}.menu-items-thumb-empty{display:grid;place-items:center;color:var(--muted,#718096)}.menu-items-thumb-empty svg{width:19px;height:19px}.menu-items-item-copy{min-width:0}.menu-items-name{font-weight:700;color:var(--text,#1f2937);text-decoration:none}.menu-items-name:hover{text-decoration:underline}.menu-items-item-copy .tiny{max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.menu-items-price{font-weight:650;white-space:nowrap}.menu-items-status-control{display:grid;justify-items:start;gap:.18rem}.menu-items-availability-form{margin:0}.menu-items-availability-form .btn{padding:.1rem .3rem;font-size:.68rem}.menu-items-row-actions{white-space:nowrap}.menu-items-pagination{display:flex;justify-content:flex-end}
@media(max-width:1100px){.menu-items-filters{grid-template-columns:repeat(2,minmax(0,1fr))}.menu-items-search{grid-column:span 2}.menu-items-filter-actions{grid-column:span 2}}
@media(max-width:640px){.menu-items-stats{grid-template-columns:1fr}.menu-items-filters{grid-template-columns:1fr}.menu-items-search,.menu-items-filter-actions{grid-column:auto}.menu-items-filter-actions .btn{flex:1;justify-content:center}.menu-items-catalog-head{align-items:flex-start;flex-direction:column}.menu-items-table{min-width:980px}}
</style>
@endsection
