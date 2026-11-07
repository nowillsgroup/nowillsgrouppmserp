@extends('layouts.app')
@section('title', 'Re-order Suggestions')
@section('crumbs') Inventory / Re-order Suggestions @endsection
@section('actions')
<a class="btn" href="{{ route('inventory.ledger') }}"><x-icon name="list"/> Stock ledger</a>
@endsection
@section('content')

{{-- v9.20 the suggestion is the end of a chain, not the start: what the shelf is short of, what is already coming, and how long what is left will last --}}
<div class="stats wide">
  <x-stat label="At or below the level" :value="$gap['count']" icon="box" :tone="$gap['count'] ? 'amber' : 'green'"
    :sub="$gap['count'].' item'.($gap['count'] === 1 ? '' : 's').' on the shelf would take a new order today'" href="#roGap"/>
  <x-stat label="At zero" :value="$gap['zero']" icon="alert" :tone="$gap['zero'] ? 'red' : 'green'"
    :sub="$gap['zero'] ? $gap['zero'].' item'.($gap['zero'] === 1 ? ' has' : 's have').' nothing left to issue' : 'nothing has run out'" href="#roGap"/>
  <x-stat label="To twice the level" :value="money($gap['short_value'])" icon="money" tone="slate"
    :sub="'the money to put '.$gap['short_qty_label'].' unit'.($gap['short_qty'] === 1 ? '' : 's').' back on the shelf'" href="#roGap"/>
  <x-stat label="Nothing coming" :value="$pipeline['nothing']" icon="clock" :tone="$pipeline['nothing'] ? 'amber' : 'green'"
    :sub="$pipeline['nothing'] ? 'no order and no requisition against them' : 'every shortage already has something on its way'" href="#roPipeline"/>
</div>

{{-- v9.20 the gap --}}
<div class="card" id="roGap">
  <div class="card-head"><h3>How far below the level is the shelf?</h3>
    <span class="muted small">{{ $gap['count'] }} items · {{ $gap['zero'] }} at zero · {{ money($gap['short_value']) }} to twice the level</span></div>
  <div class="card-body">
    @if($gap['count'])
      <p class="small {{ $gap['zero'] ? 'text-red' : '' }}" id="roGapState" style="margin:0 0 .6rem">
        <x-icon name="alert"/>The largest is {{ $gap['worst']->item->name }} — {{ $gap['worst']->stock }} {{ $gap['worst']->item->unit }} on hand against a
        re-order level of {{ $gap['worst']->level }}, so {{ $gap['worst']->short }} {{ $gap['worst']->item->unit }} brings it back to twice that,
        {{ money($gap['worst']->short_value) }} at the last rate paid. {{ $gap['zero'] }} item{{ $gap['zero'] === 1 ? ' is' : 's are' }} at zero,
        which is a service waiting to be refused rather than a number on a report.
      </p>
      <div class="table-wrap"><table class="table table-compact" id="gapTable">
        <thead><tr><th>Item</th><th class="num">In stock</th><th class="num">Re-order level</th><th class="num">To twice the level</th><th class="num">Value</th></tr></thead><tbody>
        @foreach($gap['rows'] as $g)<tr data-short="{{ $g->short_value }}">
          <td class="bold">{{ $g->item->name }} <span class="tiny muted">{{ $g->item->category->name ?? '' }}</span></td>
          <td class="num {{ $g->stock <= 0 ? 'text-red bold' : 'text-amber' }}">{{ $g->stock }} {{ $g->item->unit }}</td>
          <td class="num">{{ $g->level }}</td><td class="num">{{ $g->short }}</td>
          <td class="num">{{ money($g->short_value, false) }}</td></tr>@endforeach
        </tbody></table></div>
    @else
      <p class="small" id="roGapState" style="margin:0"><x-icon name="check"/>Nothing is at or below its re-order level. Every item on the shelf has room before the next order.</p>
    @endif
  </div>
</div>

{{-- v9.20 the pipeline: an order in draft is not coming, and a requisition is not an order --}}
<div class="card" id="roPipeline">
  <div class="card-head"><h3>What is already on its way?</h3>
    <span class="muted small">{{ money($pipeline['value']) }} on order · {{ $pipeline['draft_items'] }} of them still in draft · {{ money($pipeline['requested_value']) }} requested by departments</span></div>
  <div class="card-body">
    <div class="stats" style="margin:0">
      <x-stat label="On order" :value="money($pipeline['value'])" icon="truck" tone="slate" :sub="$pipeline['items'].' item'.($pipeline['items'] === 1 ? '' : 's').' with an approved or part-received order'"/>
      <x-stat label="Still in draft" :value="money($pipeline['draft_value'])" icon="edit" :tone="$pipeline['draft_value'] > 0 ? 'red' : 'green'"
        :sub="$pipeline['draft_items'] ? $pipeline['draft_items'].' draft order line'.($pipeline['draft_items'] === 1 ? '' : 's').' nobody has approved, so nothing is coming' : 'every order on the books has been approved'"/>
      <x-stat label="Requested by departments" :value="money($pipeline['requested_value'])" icon="users" tone="amber"
        :sub="$pipeline['requested_items'].' approved requisition line'.($pipeline['requested_items'] === 1 ? '' : 's').' the store has not raised an order for'"/>
      <x-stat label="Nothing coming" :value="$pipeline['nothing']" icon="clock" :tone="$pipeline['nothing'] ? 'amber' : 'green'"
        :sub="'of the '.$gap['count'].' item'.($gap['count'] === 1 ? '' : 's').' below the level, with no order and no request against them'"/>
    </div>
    @if($pipeline['rows'])
      <p class="small" id="roPipelineState" style="margin:.6rem 0 0">
        @if($pipeline['draft_items'])
          <x-icon name="alert"/>{{ $pipeline['worst']->item->name }} has {{ $pipeline['worst']->draft_qty }} {{ $pipeline['worst']->item->unit }}
          sitting in a draft order — {{ money($pipeline['worst']->draft_qty * $pipeline['worst']->rate) }} that will not arrive until somebody approves it.
          The suggestion below already nets off the approved orders, so what is left is only what nothing else covers.
        @else
          <x-icon name="check"/>Every order behind these shortages has been approved, so the suggestion below is only the remainder.
        @endif
        @if($pipeline['requested_items']) {{ $pipeline['requested_items'] }} of the items have been asked for by a department and not yet ordered: a request is a claim on the store, not a delivery date.@endif
      </p>
      <div class="table-wrap" style="margin-top:.6rem"><table class="table table-compact" id="pipeTable">
        <thead><tr><th>Item</th><th class="num">On order</th><th class="num">In draft</th><th class="num">Requested</th><th class="num">Suggest after</th></tr></thead><tbody>
        @foreach($pipeline['rows'] as $p)<tr data-draft="{{ $p->draft_qty > 0 ? 1 : 0 }}">
          <td class="bold">{{ $p->item->name }}</td>
          <td class="num">{{ $p->on_order ?: '—' }} {{ $p->on_order ? $p->item->unit : '' }}</td>
          <td class="num {{ $p->draft_qty > 0 ? 'text-red bold' : '' }}">{{ $p->draft_qty ?: '—' }}</td>
          <td class="num">{{ $p->requested_qty ?: '—' }}</td>
          <td class="num">{{ $p->suggest ?: '—' }}</td></tr>@endforeach
        </tbody></table></div>
    @else
      <p class="small" id="roPipelineState" style="margin:.6rem 0 0"><x-icon name="info"/>Nothing is on order and no department has asked for any of these items, so every shortage below is uncovered.</p>
    @endif
  </div>
</div>

{{-- v9.20 the cover: six weeks of movement says more about what to buy than the level on the card ever did --}}
<div class="card" id="roCover">
  <div class="card-head"><h3>How many days of stock is left?</h3>
    <span class="muted small">usage to {{ dmy(bdate()) }} · {{ $cover['silent'] }} item{{ $cover['silent'] === 1 ? '' : 's' }} with no movement at all</span></div>
  <div class="card-body">
    <div class="stats" style="margin:0">
      <x-stat label="Used in 60 days" :value="money($cover['usage_value'])" icon="trend" tone="slate"
        :sub="$cover['usage_qty_label'].' units issued out of the store, at the rates they left at'"/>
      <x-stat label="No movement" :value="$cover['silent']" icon="clock" :tone="$cover['silent'] ? 'amber' : 'green'"
        :sub="$cover['aged_sub']"/>
      <x-stat label="Runs out first" :value="($cover['fastest']->days_cover ?? 0).' d'" icon="alert"
        :tone="($cover['fastest']->days_cover ?? 0) <= 2 ? 'red' : 'amber'" :sub="$cover['fastest']->item->name ?? 'nothing is at risk'"/>
      <x-stat label="Covered by usage" :value="$cover['rows'] ? count($cover['rows']) : 0" icon="check" tone="slate"
        :sub="'of the '.$gap['count'].' item'.($gap['count'] === 1 ? '' : 's').' below the level, with a usage figure to measure against'"/>
    </div>
    @if($cover['rows'])
      <p class="small {{ ($cover['fastest']->days_cover ?? 0) <= 2 ? 'text-red' : '' }}" id="roCoverState" style="margin:.6rem 0 0"><x-icon name="clock"/>
        {{ $cover['fastest']->item->name }} has {{ $cover['fastest']->stock }} {{ $cover['fastest']->item->unit }} left and leaves the shelf at
        {{ $cover['fastest']->per_day }} a day — {{ $cover['fastest']->days_cover }} days' cover — so it runs out on {{ dmy($cover['fastest']->runout) }}.
        Over the sixty days the store issued {{ $cover['fastest']->used60 }} {{ $cover['fastest']->item->unit }} of it.
        @if($cover['silent'])
          {{ $cover['silent'] }} item{{ $cover['silent'] === 1 ? ' has' : 's have' }} no movement at all in the sixty days, so whatever the level says about
          {{ $cover['silent'] === 1 ? 'it' : 'them' }} is not evidence of demand.
        @endif
      </p>
      <div class="table-wrap" style="margin-top:.6rem"><table class="table table-compact" id="coverTable">
        <thead><tr><th>Item</th><th class="num">Used (60 d)</th><th class="num">Per day</th><th class="num">On hand</th><th class="num">Days left</th><th>Runs out</th></tr></thead><tbody>
        @foreach($cover['rows'] as $c)<tr data-cover="{{ $c->days_cover }}">
          <td class="bold">{{ $c->item->name }}</td>
          <td class="num">{{ $c->used_label }}</td><td class="num">{{ $c->per_day }}</td><td class="num">{{ $c->stock }}</td>
          <td class="num {{ $c->days_cover <= 2 ? 'text-red bold' : '' }}">{{ $c->days_cover }} d</td>
          <td class="small">{{ dmy($c->runout) }}</td></tr>@endforeach
        </tbody></table></div>
    @else
      <p class="small" id="roCoverState" style="margin:.6rem 0 0"><x-icon name="info"/>Nothing below the level has moved in sixty days, so there is no usage to measure the stock against.</p>
    @endif
  </div>
</div>

<div class="alert alert-info"><x-icon name="refresh"/><div>These items are at or below their re-order level across all stores. The suggested quantity brings stock up to twice the re-order level, less anything already on open POs. The supplier defaults to the last one used. Tick the items to order, and one draft PO is created per supplier.</div></div>
<form method="post" action="{{ route('inventory.reorder.store') }}">@csrf
<div class="card flush"><div class="card-body table-wrap"><table class="table table-compact" id="roTable">
  <thead><tr><th style="width:28px"><input type="checkbox" checked onclick="document.querySelectorAll('.ro-pick').forEach(c => c.checked = this.checked)"></th><th>Item</th><th class="num">In stock</th><th class="num">Re-order level</th><th class="num">On order</th><th class="num">Used (60 d)</th><th class="num">Days left</th><th>Supplier</th><th style="width:110px">Order qty</th><th style="width:110px">Rate</th></tr></thead><tbody>
  @forelse($rows as $i => $r)<tr data-item="{{ $r->item->id }}" data-days="{{ $r->days_cover ?? '' }}">
    <td><input type="hidden" name="lines[{{ $i }}][item_id]" value="{{ $r->item->id }}"><input type="checkbox" class="ro-pick" name="lines[{{ $i }}][pick]" value="1" @checked($r->suggest > 0)></td>
    <td class="bold">{{ $r->item->name }} <span class="tiny muted">{{ $r->item->category->name ?? '' }}</span></td>
    <td class="num {{ $r->stock <= 0 ? 'text-red bold' : 'text-amber' }}">{{ $r->stock }} {{ $r->item->unit }}</td><td class="num">{{ $r->level }}</td><td class="num">{{ $r->on_order ?: '—' }}</td>
    <td class="num">{{ $r->used60 ? $r->used_label : '—' }}</td><td class="num {{ $r->days_cover !== null && $r->days_cover <= 2 ? 'text-red bold' : '' }}">{{ $r->days_cover !== null ? $r->days_cover.' d' : '—' }}</td>
    <td><select name="lines[{{ $i }}][supplier_id]"><option value="">– choose –</option>@foreach($suppliers as $sid => $sn)<option value="{{ $sid }}" @selected($sid == $r->supplier_id)>{{ $sn }}</option>@endforeach</select></td>
    <td><input type="number" name="lines[{{ $i }}][qty]" value="{{ $r->suggest }}" min="0" step="0.01" style="width:90px"></td>
    <td><input type="number" name="lines[{{ $i }}][rate]" value="{{ $r->rate }}" min="0" step="0.01" style="width:90px"></td>
  </tr>@empty<tr><td colspan="10"><x-empty text="Nothing to re-order. All items are above their re-order level." icon="check"/></td></tr>@endforelse
  </tbody></table></div></div>
@if($rows->isNotEmpty() && can_do('inventory.manage'))
<div class="card"><div class="card-body flex gap-1 wrap items-end">
  <x-field name="store_id" label="Deliver to store" type="select" :options="$stores" required/>
  <x-field name="expected_date" label="Expected delivery" type="date" :value="bdate()->copy()->addDays(3)->toDateString()"/>
  <button class="btn btn-primary"><x-icon name="receipt"/> Create draft POs</button>
</div></div>
@endif
</form>
<p class="tiny muted mt-1" id="roChain">The suggestion closes one loop and opens the next: an approved <a href="{{ route('req.index') }}">requisition</a> is what a department asks for, an <a href="{{ route('purchases.index') }}">order</a> is what the buyer commits to, a <a href="{{ route('grn.index') }}">goods receipt</a> is what the shelf actually takes in, and the stock movement is what leaves it again. A level alone only says what somebody once thought was enough; the usage behind it says what the shelf is really doing.</p>
@endsection
