@extends('layouts.app')
@section('title', 'POS Modifiers & Add-ons')
@section('crumbs') Food & Beverage / POS / Modifiers & Add-ons @endsection
@section('content')
<div class="alert alert-info"><x-icon name="info"/><div>Modifier choices are validated at the till and copied onto each POS line as a price snapshot. Changing a modifier later does not alter historic bills.</div></div>
<div class="grid g-2">
  <div class="card"><div class="card-head"><h3>Modifier groups</h3><span class="tiny muted">e.g. Size, Extras, Cooking</span></div>
    <form method="post" action="{{ route('pos.modifiers.groups.store') }}" class="card-body form-grid">@csrf
      <div class="field"><label>Name</label><input name="name" required maxlength="80" placeholder="Extra toppings"></div>
      <div class="field"><label>Outlet</label><select name="outlet_id"><option value="">All outlets</option>@foreach($outlets as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></div>
      <div class="field"><label>Minimum choices</label><input name="min_selections" type="number" min="0" max="10" value="0" required></div>
      <div class="field"><label>Maximum choices</label><input name="max_selections" type="number" min="1" max="10" value="1" required></div>
      <div class="field"><label>Sort order</label><input name="sort_order" type="number" min="0" max="999" value="0"></div>
      <label class="check"><input type="checkbox" name="is_required" value="1"> Required group</label>
      <label class="check"><input type="checkbox" name="is_active" value="1" checked> Active</label>
      <div class="span-all"><button class="btn btn-primary">Add group</button></div>
    </form>
    <div class="card-body table-wrap"><table class="table table-compact"><thead><tr><th>Group</th><th>Outlet</th><th>Choices</th><th>Status</th><th></th></tr></thead><tbody>
      @forelse($groups as $group)<tr><td><b>{{ $group->name }}</b><div class="tiny muted">{{ $group->modifiers->count() }} option(s)</div></td><td>{{ $group->outlet?->name ?? 'All' }}</td><td>{{ $group->min_selections }}–{{ $group->max_selections }}{{ $group->is_required ? ' · required' : '' }}</td><td>{!! $group->is_active ? '<span class="badge badge-green">Active</span>' : '<span class="badge badge-slate">Off</span>' !!}</td><td class="right"><form method="post" action="{{ route('pos.modifiers.groups.destroy', $group) }}" data-confirm="Delete {{ $group->name }} and its options?">@csrf @method('DELETE')<button class="btn btn-sm btn-ghost"><x-icon name="trash" size="sm"/></button></form></td></tr>
        @if($group->modifiers->isNotEmpty())<tr><td colspan="5" style="padding:0 19px 12px"><div class="small muted">@foreach($group->modifiers as $modifier)<span class="badge {{ $modifier->is_available ? 'badge-blue' : 'badge-slate' }}" style="margin:2px">{{ $modifier->name }} {{ (float)$modifier->price_delta >= 0 ? '+' : '' }}{{ money($modifier->price_delta, false) }}</span>@endforeach</div></td></tr>@endif
      @empty<tr><td colspan="5" class="muted">No modifier groups configured.</td></tr>@endforelse
    </tbody></table></div>
  </div>
  <div class="card"><div class="card-head"><h3>Add modifier option</h3><span class="tiny muted">Price delta is added to the menu item</span></div>
    <form method="post" action="{{ route('pos.modifiers.store') }}" class="card-body form-grid">@csrf
      <div class="field wide"><label>Group</label><select name="menu_modifier_group_id" required><option value="">Select group</option>@foreach($groups as $group)<option value="{{ $group->id }}">{{ $group->name }}</option>@endforeach</select></div>
      <div class="field"><label>Option name</label><input name="name" required maxlength="100" placeholder="Extra cheese"></div>
      <div class="field"><label>Price delta</label><input name="price_delta" type="number" step="0.01" value="0" required></div>
      <div class="field"><label>Sort order</label><input name="sort_order" type="number" min="0" max="999" value="0"></div>
      <label class="check"><input type="checkbox" name="is_available" value="1" checked> Available</label>
      <div class="span-all"><button class="btn btn-primary">Add option</button></div>
    </form>
    <div class="card-head"><h3>Attach groups to menu items</h3></div>
    <div class="card-body table-wrap"><table class="table table-compact"><thead><tr><th>Menu item</th><th>Assigned groups</th><th></th></tr></thead><tbody>
      @forelse($items as $item)<tr><td>{{ $item->name }}<div class="tiny muted">{{ $item->category?->name }}{{ $item->outlet ? ' · '.$item->outlet->name : '' }}</div></td><td colspan="2"><form method="post" action="{{ route('pos.modifiers.assign', $item) }}" class="flex gap-1 items-center">@csrf
        <select name="groups[]" multiple size="3" style="min-width:190px">@foreach($groups as $group)<option value="{{ $group->id }}" @selected($item->modifierGroups->contains($group->id))>{{ $group->name }}</option>@endforeach</select><button class="btn btn-sm btn-primary">Save</button>
      </form></td></tr>
      @empty<tr><td colspan="3" class="muted">No menu items configured.</td></tr>@endforelse
    </tbody></table></div>
  </div>
</div>
@endsection
