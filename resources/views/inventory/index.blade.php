@extends('layouts.app')
@section('title', 'Stock Summary')
@section('crumbs') Inventory / Stock on Hand @endsection
@section('actions')
@if(can_do('inventory.manage'))<a class="btn" href="{{ route('inventory.movement') }}"><x-icon name="swap"/> Issue / Adjust</a><a class="btn" href="{{ route('inventory.ledger') }}"><x-icon name="list"/> Ledger</a><a class="btn btn-primary" href="{{ route('purchases.create') }}"><x-icon name="plus"/> Purchase Order</a>@endif
@endsection
@section('content')

{{-- v9.23 how many is not the question a balance sheet asks: what is it worth, where is it sitting, and what has stopped moving --}}
<div class="stats wide">
  <x-stat label="Items in stock" :value="$shelf['items']" icon="box" tone="blue" :sub="$shelf['sub']" href="#shShelf"/>
  <x-stat label="Value on the shelf" :value="money($shelf['value'])" icon="money" tone="green" sub="quantity at the last cost each item carries — the figure the counts and the ledger are both checking" href="#shShelf"/>
  <x-stat label="Stores holding it" :value="$shelf['stores']" icon="list" tone="slate" :sub="$where['split_sub']" href="#shWhere"/>
  <x-stat label="Largest holding" :value="money($shelf['top']['value'] ?? 0)" icon="alert" tone="slate"
    :sub="$shelf['top'] ? $shelf['top']['item'].' — '.$shelf['top']['qty_label'].' '.$shelf['top']['unit'].', '.$shelf['top']['share'].' % of the shelf' : 'nothing is on hand'"/>
  <x-stat label="Not moved in 90 days" :value="$still['count']" icon="clock" :tone="$still['tone']" :sub="$still['share_label']" href="#shStill"/>
</div>

{{-- v9.23 what is on the shelf tonight --}}
<div class="card" id="shShelf">
  <div class="card-head"><h3>What is on the shelf tonight?</h3>
    <span class="muted small">{{ $shelf['items'] }} items · {{ money($shelf['value']) }} · the three largest are {{ $shelf['top_three'] }} % of it</span></div>
  <div class="card-body">
    <div class="stats" style="margin:0">
      <x-stat label="Stock value" :value="money($shelf['value'])" icon="money" tone="green" sub="every item at its last cost, the way the balance sheet would carry it"/>
      <x-stat label="Items held" :value="$shelf['items']" icon="box" tone="blue" :sub="$shelf['zero_sub']"/>
      <x-stat label="Top three items" :value="$shelf['top_three'].' %'" icon="alert" tone="amber" sub="of the value sits in three lines, which is what makes a store fragile"/>
      <x-stat label="Below zero" :value="$shelf['negative']" icon="alert" :tone="$shelf['negative'] ? 'red' : 'green'" sub="items carrying a negative quantity — stock issued that the book never received"/>
    </div>
    <p class="small" id="shShelfState" style="margin:.6rem 0 0"><x-icon name="info"/>
      @if($shelf['top'])
        The largest holding is {{ $shelf['top']['item'] }}: {{ $shelf['top']['qty_label'] }} {{ $shelf['top']['unit'] }} in the
        {{ $shelf['top']['store'] }}, {{ money($shelf['top']['value']) }} — {{ $shelf['top']['share'] }} % of everything on the shelf.
      @endif
      Units are not added across items, because a litre of diesel and a piece of towel are not the same thing; money is the only
      column that can be totalled, and it is quantity at the last cost, so a stale cost understates the shelf rather than overstating it.
    </p>
    @if($shelf['rows'])
      <div class="table-wrap" style="margin-top:.6rem"><table class="table table-compact" id="shelfTable">
        <thead><tr><th>Item</th><th>Category</th><th class="num">Quantity</th><th class="num">Value</th><th class="num">Share</th></tr></thead><tbody>
        @foreach($shelf['rows'] as $r)<tr data-value="{{ $r['value'] }}">
          <td class="bold">{{ $r['item'] }}</td><td class="small">{{ $r['category'] }}</td>
          <td class="num">{{ $r['qty'] }} {{ $r['unit'] }}</td>
          <td class="num {{ $r['value'] < 0 ? 'text-red' : '' }}">{{ money($r['value'], false) }}</td>
          <td class="num">{{ $r['share'] }} %</td></tr>@endforeach
        </tbody></table></div>
    @else
      <p class="small muted" style="margin:.6rem 0 0">Nothing is on hand with these filters.</p>
    @endif
  </div>
</div>

{{-- v9.23 where the value sits: a store's worth is not the number of lines it holds --}}
<div class="card" id="shWhere">
  <div class="card-head"><h3>Where does the value sit?</h3>
    <span class="muted small">{{ $where['sub'] }}</span></div>
  <div class="card-body">
    <div class="stats" style="margin:0">
      <x-stat label="Heaviest store" :value="money($where['top']['value'] ?? 0)" icon="box" tone="blue"
        :sub="$where['top'] ? $where['top']['store'].' — '.$where['top']['items'].' items, '.$where['top']['share'].' % of the value' : 'no store holds stock'"/>
      <x-stat label="Split across stores" :value="$where['multi']" icon="swap" tone="slate" sub="items carried in more than one store, and so worth transferring before they are bought again"/>
      <x-stat label="Stores counted" :value="count($where['rows'])" icon="list" tone="slate" sub="every store with a quantity in it, empty stores left off the list"/>
      <x-stat label="Against the book" :value="money($shelf['value'])" icon="check" tone="green" sub="the same total the shelf card prints, store by store"/>
    </div>
    @if($where['rows'])
      <p class="small" id="shWhereState" style="margin:.6rem 0 0"><x-icon name="info"/>
        The {{ $where['top']['store'] }} carries {{ money($where['top']['value']) }} of the {{ money($shelf['value']) }} on the shelf,
        {{ $where['top']['share'] }} % of it across {{ $where['top']['items'] }} items. A store is not worth what it holds but what
        it holds that the hotel will use: the chair covers nobody has touched since June count the same as the diesel until
        somebody reads the age.
      </p>
      <div class="table-wrap" style="margin-top:.6rem"><table class="table table-compact" id="storeTable">
        <thead><tr><th>Store</th><th class="num">Items</th><th class="num">Value</th><th class="num">Share</th></tr></thead><tbody>
        @foreach($where['rows'] as $r)<tr data-value="{{ $r['value'] }}">
          <td class="bold">{{ $r['store'] }}</td><td class="num">{{ $r['items'] }}</td>
          <td class="num {{ $r['tone'] }}">{{ money($r['value'], false) }}</td>
          <td class="num">{{ $r['share'] }} %</td></tr>@endforeach
        </tbody></table></div>
    @endif
  </div>
</div>

{{-- v9.23 what has stopped moving: stock on a shelf that nobody has drawn from is cash the hotel cannot spend --}}
<div class="card" id="shStill">
  <div class="card-head"><h3>What has stopped moving?</h3>
    <span class="muted small">{{ $still['sub'] }} · {{ money($still['value']) }} · {{ $still['share_label'] }}</span></div>
  <div class="card-body">
    <div class="stats" style="margin:0">
      <x-stat label="Asleep" :value="$still['count']" icon="clock" :tone="$still['tone']" :sub="$still['asleep_sub']"/>
      <x-stat label="Value asleep" :value="money($still['value'])" icon="money" tone="amber" :sub="$still['share_label'].' — capital a store is holding for no reason'"/>
      <x-stat label="Never moved" :value="$still['never']" icon="alert" tone="slate" sub="items on the shelf with no movement at all on the record"/>
      <x-stat label="Oldest" :value="$still['oldest'] ? $still['oldest']['days'].' d' : '—'" icon="alert" tone="red"
        :sub="$still['oldest'] ? $still['oldest']['item'].' — '.$still['oldest']['qty'].' '.$still['oldest']['unit'].', '.money($still['oldest']['value']) : 'everything on the shelf has moved this quarter'"/>
    </div>
    @if($still['rows'])
      <p class="small" id="shStillState" style="margin:.6rem 0 0"><x-icon name="clock"/>
        The oldest is {{ $still['oldest']['item'] }}: {{ $still['oldest']['qty'] }} {{ $still['oldest']['unit'] }} worth
        {{ money($still['oldest']['value']) }}, last touched {{ dmy($still['oldest']['last']) }} — {{ $still['oldest']['days'] }} days ago.
        Nothing here is a re-order problem; it is the opposite one, stock that was bought, is still counted, and is not being drawn down.
        A count will find it, the ledger will keep carrying it, and only an ageing write-down or a transfer moves it.
      </p>
      <div class="table-wrap" style="margin-top:.6rem"><table class="table table-compact" id="stillTable">
        <thead><tr><th>Item</th><th class="num">Quantity</th><th class="num">Value</th><th>Last movement</th><th class="num">Days</th></tr></thead><tbody>
        @foreach($still['rows'] as $r)<tr data-days="{{ $r['days'] }}">
          <td class="bold">{{ $r['item'] }}</td>
          <td class="num">{{ $r['qty'] }} {{ $r['unit'] }}</td>
          <td class="num">{{ money($r['value'], false) }}</td>
          <td class="small">{{ $r['date'] ? dmy($r['date']) : 'never' }}</td>
          <td class="num text-red">{{ $r['days'] ?? '—' }}</td></tr>@endforeach
        </tbody></table></div>
    @else
      <p class="small" id="shStillState" style="margin:.6rem 0 0"><x-icon name="check"/>
        Everything on the shelf has been drawn from in the last ninety days, so nothing here is capital sitting still.
      </p>
    @endif
  </div>
</div>

<div class="card"><div class="card-body"><form class="filters">
  <div class="field"><input name="q" value="{{ request('q') }}" placeholder="Search item"></div>
  <div class="field"><select name="category"><option value="">All categories</option>@foreach($categories as $id=>$n)<option value="{{ $id }}" @selected(request('category')==$id)>{{ $n }}</option>@endforeach</select></div>
  <label class="check"><input type="checkbox" name="low" value="1" @checked(request('low'))> Low stock only</label>
  <button class="btn btn-primary">Filter</button></form></div></div>

<div class="card flush"><div class="card-head"><h3>Stock on hand</h3>
  <span class="muted small">{{ $items->count() }} lines · the three readings above are worked out from these quantities</span></div>
<div class="card-body table-wrap"><table class="table" id="stockTable">
<thead><tr><th>Code</th><th>Item</th><th>Category</th><th>Unit</th>@foreach($stores as $s)<th class="num">{{ $s->name }}</th>@endforeach<th class="num">Total</th><th class="num">Reorder</th><th class="num">Value</th><th>Last moved</th></tr></thead><tbody>
@forelse($items as $i)
<tr data-total="{{ $i->total }}" data-days="{{ $i->days_still }}">
  <td class="small muted">{{ $i->code }}</td><td class="bold">{{ $i->name }}</td><td>{{ $i->category?->name }}</td><td>{{ $i->unit }}</td>
  @foreach($stores as $s)<td class="num">{{ $i->cells[$s->id] }}</td>@endforeach
  <td class="num bold {{ $i->total <= $i->reorder_level ? 'text-red' : '' }}">{{ $i->total_label }}</td>
  <td class="num">{{ (float) $i->reorder_level }}</td><td class="num">{{ money($i->value, false) }}</td>
  <td class="small {{ $i->days_still === null ? 'text-red' : '' }}">{{ $i->last_moved ? dmy($i->last_moved) : 'never' }}</td></tr>
@empty<tr><td colspan="{{ 9 + $stores->count() }}"><x-empty text="No items." icon="box"/></td></tr>@endforelse
</tbody></table></div></div>

<p class="tiny muted mt-1" id="shChain">Stock on hand is where the other four screens meet: the <a href="{{ route('grn.index') }}">receipt</a> puts it here, the <a href="{{ route('req.index') }}">issue</a> takes it away, the <a href="{{ route('inventory.ledger') }}">ledger</a> records both, and the <a href="{{ route('stocktakes.index') }}">stock take</a> is the only thing that checks any of it against a shelf. The <a href="{{ route('inventory.reorder') }}">re-order</a> screen asks what to buy because of it — and this screen asks the question that comes first: what is already here, and what is it worth.</p>
@endsection
