@extends('layouts.app')
@section('title', 'Stock Issue / Transfer / Adjustment')
@section('crumbs') <a href="{{ route('inventory.index') }}">Inventory</a> / Movement @endsection
@section('actions')<a class="btn" href="{{ route('inventory.ledger') }}"><x-icon name="list"/> Stock ledger</a>@endsection
@section('content')

{{-- v9.25 the only document that writes a stock movement by hand: what is in the store, what has left it, and what nobody can answer for --}}
<div class="stats wide">
  <x-stat label="In the store" :value="$position['items']" icon="box" tone="blue" :sub="$position['sub']" href="#mvStock"/>
  <x-stat label="Its value" :value="money($position['value'])" icon="money" tone="green" sub="quantity at the last cost each item carries — what an issue from here is taking out" href="#mvStock"/>
  <x-stat label="At or below level" :value="$position['below']" icon="alert" :tone="$position['below'] ? 'red' : 'green'" :sub="$position['below'] ? 'issuing these makes a shortage worse before it makes it better' : 'nothing in this store is short of its level'"/>
  <x-stat label="Out this month" :value="money($month['value'])" icon="arrow-up" tone="slate" :sub="$month['sub'].' — every one of them a ledger line'" href="#mvOut"/>
  <x-stat label="No document" :value="$gaps['count']" icon="alert" :tone="$gaps['tone']" :sub="$gaps['head_sub']" href="#mvGaps"/>
</div>

{{-- v9.25 what is actually in the store you are taking from --}}
<div class="card" id="mvStock">
  <div class="card-head"><h3>What is in the store you are taking from?</h3>
    <span class="muted small">{{ $position['store'] }} · {{ $position['items'] }} items · {{ money($position['value']) }}</span></div>
  <div class="card-body">
    <div class="stats" style="margin:0">
      <x-stat label="Items held" :value="$position['items']" icon="box" tone="blue" :sub="$position['zero_sub']"/>
      <x-stat label="Value on the shelf" :value="money($position['value'])" icon="money" tone="green" sub="at last cost — the amount a full issue from this store would take out"/>
      <x-stat label="Largest holding" :value="money($position['top']['value'] ?? 0)" icon="alert" tone="slate"
        :sub="$position['top'] ? $position['top']['item'].' — '.$position['top']['qty'].' '.$position['top']['unit'].', the most there is to issue' : 'the store is empty'"/>
      <x-stat label="Tightest line" :value="$position['tightest'] ? $position['tightest']['qty'].' '.$position['tightest']['unit'] : '—'" icon="alert" :tone="$position['tightest'] ? 'red' : 'green'"
        :sub="$position['tightest'] ? $position['tightest']['item'].' against a level of '.$position['tightest']['level'] : 'nothing is under its level'"/>
    </div>
    <p class="small" id="mvStockState" style="margin:.6rem 0 0"><x-icon name="info"/>
      @if($position['tightest'])
        {{ $position['tightest']['item'] }} is the tightest line in this store: {{ $position['tightest']['qty'] }} {{ $position['tightest']['unit'] }}
        against a level of {{ $position['tightest']['level'] }}, and {{ $position['below'] }} items are at or below their level.
      @endif
      The form below checks the shelf before it posts anything: an issue for more than the store holds is refused rather than
      posted as a negative quantity, which is why the quantity here is the one that matters, not the quantity on order.
    </p>
    <div class="table-wrap" style="margin-top:.6rem"><table class="table table-compact" id="holdTable">
      <thead><tr><th>Item</th><th class="num">In the store</th><th class="num">Level</th><th class="num">Value</th><th>Standing</th></tr></thead><tbody>
      @foreach($position['rows'] as $r)<tr data-qty="{{ $r['qty'] }}" data-standing="{{ $r['standing'] }}">
        <td class="bold">{{ $r['item'] }}</td>
        <td class="num">{{ $r['qty'] }} {{ $r['unit'] }}</td>
        <td class="num">{{ $r['level'] }}</td>
        <td class="num">{{ money($r['value'], false) }}</td>
        <td><span class="badge badge-{{ $r['standing'] === 'in stock' ? 'green' : ($r['standing'] === 'at zero' ? 'red' : 'amber') }}">{{ $r['standing'] }}</span></td></tr>@endforeach
      </tbody></table></div>
  </div>
</div>

{{-- v9.25 what has already left this store this month, by the kind of movement it was --}}
<div class="card" id="mvOut">
  <div class="card-head"><h3>What has left this store this month?</h3>
    <span class="muted small">{{ $month['count'] }} movements · {{ $month['units'] }} units · {{ money($month['value']) }}</span></div>
  <div class="card-body">
    <div class="stats" style="margin:0">
      <x-stat label="Movements out" :value="$month['count']" icon="list" tone="slate" :sub="$month['sub'].' — every one of them a ledger line'"/>
      <x-stat label="Value out" :value="money($month['value'])" icon="arrow-up" tone="amber" sub="at the rate each line carries — the cost the department is charged"/>
      <x-stat label="Largest" :value="money($month['biggest']['value'] ?? 0)" icon="alert" tone="slate"
        :sub="$month['biggest'] ? $month['biggest']['qty'].' '.$month['biggest']['unit'].' of '.$month['biggest']['item'].($month['biggest']['department'] ? ' to '.$month['biggest']['department'] : '') : 'nothing has left the store this month'"/>
      <x-stat label="Corrections" :value="$month['corrections']" icon="edit" :tone="$month['corrections'] ? 'amber' : 'green'" :sub="$month['corrections_sub']"/>
    </div>
    @if($month['rows'])
      <p class="small" id="mvOutState" style="margin:.6rem 0 0"><x-icon name="info"/>
        @if($month['biggest'])
          The largest single movement out is {{ $month['biggest']['qty'] }} {{ $month['biggest']['unit'] }} of {{ $month['biggest']['item'] }}
          on {{ dmy($month['biggest']['date']) }}, {{ money($month['biggest']['value']) }}@if($month['biggest']['department']) to the {{ $month['biggest']['department'] }}@endif.
        @endif
        Every line here is already in the stock ledger and cannot be taken back — a wrong issue is corrected by another movement,
        which is why the type on the form decides which account it lands in and the department decides whose budget it hits.
      </p>
      <div class="table-wrap" style="margin-top:.6rem"><table class="table table-compact" id="outTable">
        <thead><tr><th>Type</th><th class="num">Movements</th><th class="num">Units</th><th class="num">Value</th></tr></thead><tbody>
        @foreach($month['rows'] as $r)<tr data-type="{{ $r['type'] }}" data-value="{{ $r['value'] }}">
          <td><span class="badge badge-{{ $r['tone'] }}">{{ $r['label'] }}</span></td>
          <td class="num">{{ $r['count'] }}</td><td class="num">{{ $r['units'] }}</td>
          <td class="num">{{ money($r['value'], false) }}</td></tr>@endforeach
        </tbody><tfoot><tr><th>All types</th><th class="num">{{ $month['count'] }}</th><th class="num">{{ $month['units'] }}</th>
          <th class="num">{{ money($month['value'], false) }}</th></tr></tfoot></table></div>
    @else
      <p class="small" id="mvOutState" style="margin:.6rem 0 0"><x-icon name="info"/>Nothing has left this store since the first of the month.</p>
    @endif
  </div>
</div>

{{-- v9.25 the movements nobody can account for — the reason the form now asks for a document --}}
<div class="card" id="mvGaps">
  <div class="card-head"><h3>What can nobody answer for?</h3>
    <span class="muted small">{{ $gaps['sub'] }}{{ $gaps['count'] ? ' · '.money($gaps['value']) : '' }}</span></div>
  <div class="card-body">
    <div class="stats" style="margin:0">
      <x-stat label="No reference" :value="$gaps['count']" icon="alert" :tone="$gaps['tone']" :sub="$gaps['sub']"/>
      <x-stat label="Their value" :value="money($gaps['value'])" icon="money" :tone="$gaps['tone']" sub="stock that left the store and cannot be tied to an issue note, a transfer sheet or a report"/>
      <x-stat label="Largest" :value="money($gaps['worst']['value'] ?? 0)" icon="alert" tone="amber"
        :sub="$gaps['worst'] ? $gaps['worst']['qty'].' '.$gaps['worst']['unit'].' of '.$gaps['worst']['item'].' on '.dmy($gaps['worst']['date']) : 'nothing is unexplained'"/>
      <x-stat label="Wastage in them" :value="$gaps['waste']" icon="trash" tone="slate" sub="breakage and spoilage are legitimate movements — without a reference nobody can tell them from a write-off"/>
    </div>
    @if($gaps['count'])
      <p class="small" id="mvGapsState" style="margin:.6rem 0 0"><x-icon name="alert"/>
        Until this version the form had nowhere to record the document a movement rested on, so every issue, transfer and
        adjustment written here reached the <a href="{{ route('inventory.ledger') }}">stock ledger</a> with no reference and, where no
        department was picked, no name against it either. {{ $gaps['count'] }} such movement{{ $gaps['count'] === 1 ? '' : 's' }}
        worth {{ money($gaps['value']) }} have left this store this month. The reference field below is what lets the ledger answer for the next one.
      </p>
      <div class="table-wrap" style="margin-top:.6rem"><table class="table table-compact" id="gapTable">
        <thead><tr><th>Date</th><th>Item</th><th>Type</th><th class="num">Qty</th><th class="num">Value</th><th>By</th></tr></thead><tbody>
        @foreach($gaps['rows'] as $r)<tr data-value="{{ $r['value'] }}">
          <td class="small">{{ dmy($r['date']) }}</td><td class="bold">{{ $r['item'] }}</td>
          <td><span class="badge badge-amber">{{ $r['type'] }}</span></td>
          <td class="num">{{ $r['qty'] }} {{ $r['unit'] }}</td>
          <td class="num">{{ money($r['value'], false) }}</td>
          <td class="small">{{ $r['user'] }}</td></tr>@endforeach
      </tbody></table></div>
    @else
      <p class="small" id="mvGapsState" style="margin:.6rem 0 0"><x-icon name="check"/>
        Every movement out of this store this month names a document or a department, so each one can be asked about.
      </p>
    @endif
  </div>
</div>

<form method="post" action="{{ route('inventory.movement.store') }}" class="card" id="mvForm">@csrf
<div class="card-body form-grid">
  <x-field name="type" label="Transaction" type="select" :options="['issue'=>'Issue to department','transfer'=>'Store transfer','adjustment'=>'Stock adjustment','wastage'=>'Wastage / breakage','opening'=>'Opening stock']" :placeholder="false"/>
  <div class="field">
    <label for="f_store_id">From store <span class="req">*</span></label>
    <select name="store_id" id="f_store_id" required>
      <option value="">— Select —</option>
      @foreach($stores as $id=>$n)<option value="{{ $id }}" @selected($storeId == $id)>{{ $n }}</option>@endforeach
    </select>
    <span class="hint">The three cards above are this store's — change it and the shelf they describe changes with it.</span>
  </div>
  <x-field name="to_store_id" label="To store (transfer)" type="select" :options="$stores"/>
  <x-field name="adjust_sign" label="Adjustment direction" type="select" :options="['minus'=>'Decrease (−)','plus'=>'Increase (+)']" :placeholder="false"/>
  <x-field name="date" label="Date" type="date" :value="bdate()->toDateString()" required/>
  <x-field name="department" label="Department" type="select" :options="$departments" hint="Issues are expensed to the department's cost account. For planned needs use a requisition."/>
  <x-field name="reference" label="Document" hint="The issue note, transfer sheet or breakage report this movement rests on. Without one the ledger carries a number nobody can ask about."/>
  <x-field name="remarks" label="Remarks" class="span-2"/>
</div>
<div class="card-body" style="border-top:1px solid var(--c-border)">
  <table class="table" id="mvLines"><thead><tr><th>Item</th><th style="width:160px">Qty</th><th></th></tr></thead><tbody></tbody></table>
  <button type="button" class="btn btn-sm btn-soft mt-1" onclick="addRow()"><x-icon name="plus" size="sm"/> Add item</button>
</div>
<div class="card-foot"><button class="btn btn-primary">Post Transaction</button></div>
</form>
<template id="mvRow"><tr><td><select class="input input-sm" data-k="item_id" required><option value="">Select item</option>@foreach($items as $i)<option value="{{ $i->id }}">{{ $i->name }} ({{ $i->unit }})</option>@endforeach</select></td><td><input class="input input-sm" type="number" step="0.01" min="0.01" data-k="qty" required></td><td><button type="button" class="btn btn-sm btn-ghost" onclick="this.closest('tr').remove();ri()"><x-icon name="trash" size="sm"/></button></td></tr></template>

<p class="tiny muted mt-1" id="mvChain">A movement is the only stock document written by hand, and it is the last one in the loop: the <a href="{{ route('grn.index') }}">receipt</a> puts stock on the book, the <a href="{{ route('req.index') }}">requisition issue</a> takes it off against an approved request, the <a href="{{ route('stocktakes.index') }}">stock take</a> posts the difference — and this form writes the ones that fit none of those. Every line here lands in the <a href="{{ route('inventory.ledger') }}">stock ledger</a> at the rate the item carries, so a movement with no document is stock the books can count and nobody can explain.</p>
@endsection
@push('scripts')<script>
const tb = document.querySelector('#mvLines tbody'); function ri(){ [...tb.rows].forEach((r,i)=>r.querySelectorAll('[data-k]').forEach(e=>e.name=`lines[${i}][${e.dataset.k}]`)); }
function addRow(){ tb.appendChild(document.getElementById('mvRow').content.firstElementChild.cloneNode(true)); ri(); } addRow();
</script>@endpush
