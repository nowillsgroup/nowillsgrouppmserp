@extends('layouts.app')
@section('title', 'Stock Ledger')
@section('crumbs') <a href="{{ route('inventory.index') }}">Inventory</a> / Stock Ledger @endsection
@section('actions')<a class="btn" href="{{ route('inventory.index') }}"><x-icon name="box"/> Stock on hand</a>@if(can_do('inventory.manage'))<a class="btn btn-primary" href="{{ route('inventory.movement') }}"><x-icon name="swap"/> Issue / Adjust</a>@endif@endsection
@section('content')

{{-- v9.22 the ledger is the book every other inventory document writes into, so it is read three ways: what moved and what it was worth, whether the window rolls forward, and what has no document behind it --}}
<div class="stats wide">
  <x-stat label="Movements" :value="$flow['count']" icon="list" tone="slate" :sub="$flow['sub']" href="#ldFlow"/>
  <x-stat label="Received" :value="money($flow['value_in'])" icon="arrow-down" tone="green"
    :sub="number_format($flow['units_in']).' units on '.$flow['in_count'].' inward movements'" href="#ldFlow"/>
  <x-stat label="Issued" :value="money($flow['value_out'])" icon="arrow-up" tone="amber"
    :sub="number_format($flow['units_out']).' units left the stores in the window'" href="#ldFlow"/>
  <x-stat label="Net movement" :value="money($flow['net'])" icon="swap" :tone="$flow['net_tone']"
    sub="value received less value issued, at the rate each line carries" href="#ldFlow"/>
  <x-stat label="Below zero" :value="$balance['below']" icon="alert" :tone="$balance['tone']" :sub="$balance['below_sub']" href="#ldBalance"/>
</div>

{{-- v9.22 what moved, and what it was worth --}}
<div class="card" id="ldFlow">
  <div class="card-head"><h3>What moved, and what was it worth?</h3>
    <span class="muted small">{{ $flow['count'] }} movements · {{ money($flow['value_in']) }} in · {{ money($flow['value_out']) }} out · {{ money($flow['net']) }} net</span></div>
  <div class="card-body">
    <div class="stats" style="margin:0">
      <x-stat label="Value received" :value="money($flow['value_in'])" icon="arrow-down" tone="green" :sub="$flow['in_count'].' movements brought stock into a store'"/>
      <x-stat label="Value issued" :value="money($flow['value_out'])" icon="arrow-up" tone="amber" :sub="$flow['out_count'].' movements took it out again'"/>
      <x-stat label="Net" :value="money($flow['net'])" icon="swap" :tone="$flow['net_tone']" sub="what the window added to the stores, or took out of them"/>
      <x-stat label="Largest line" :value="money($flow['biggest']['value'] ?? 0)" icon="alert" tone="slate" :sub="$flow['biggest'] ? $flow['biggest']['item'].' · '.dmy($flow['biggest']['date']) : 'nothing moved in the window'"/>
    </div>
    @if($flow['biggest'])
      <p class="small" id="ldFlowState" style="margin:.6rem 0 0"><x-icon name="info"/>
        The largest single line is the {{ $flow['biggest']['qty_label'] }} {{ $flow['biggest']['unit'] }} {{ $flow['biggest']['item'] }}
        {{ $flow['biggest']['inward'] ? 'receipt' : 'issue' }} on {{ dmy($flow['biggest']['date']) }} —
        {{ money($flow['biggest']['value']) }} {{ $flow['biggest']['inward'] ? 'into' : 'out of' }} the {{ $flow['biggest']['store'] }}.
        A ledger that lists quantities and totals nothing cannot be audited: {{ money($flow['value_in']) }} came in and
        {{ money($flow['value_out']) }} went out, so the stores are {{ money($flow['net']) }} ahead on the month.
      </p>
      <div class="table-wrap" style="margin-top:.6rem"><table class="table table-compact" id="flowTable">
        <thead><tr><th>Type</th><th class="num">Movements</th><th class="num">Units in</th><th class="num">Units out</th><th class="num">Value in</th><th class="num">Value out</th><th class="num">Net</th></tr></thead><tbody>
        @foreach($flow['rows'] as $r)<tr data-type="{{ $r['type'] }}" data-net="{{ $r['net'] }}">
          <td><span class="badge badge-{{ $r['tone'] }}">{{ $r['label'] }}</span></td>
          <td class="num">{{ $r['count'] }}</td>
          <td class="num">{{ $r['units_in'] ? number_format($r['units_in']) : '—' }}</td><td class="num">{{ $r['units_out'] ? number_format($r['units_out']) : '—' }}</td>
          <td class="num">{{ $r['value_in'] ? money($r['value_in'], false) : '—' }}</td>
          <td class="num">{{ $r['value_out'] ? money($r['value_out'], false) : '—' }}</td>
          <td class="num {{ $r['net'] < 0 ? 'text-red' : '' }}">{{ money($r['net'], false) }}</td></tr>@endforeach
        </tbody>
        <tfoot><tr><th>All types</th><th class="num">{{ $flow['count'] }}</th><th class="num">{{ number_format($flow['units_in']) }}</th><th class="num">{{ number_format($flow['units_out']) }}</th>
          <th class="num">{{ money($flow['value_in'], false) }}</th><th class="num">{{ money($flow['value_out'], false) }}</th><th class="num">{{ money($flow['net'], false) }}</th></tr></tfoot>
      </table></div>
    @else
      <p class="small" id="ldFlowState" style="margin:.6rem 0 0"><x-icon name="info"/>Nothing moved in this window, with these filters.</p>
    @endif
  </div>
</div>

{{-- v9.22 the window rolls forward: opening + in − out = closing, and an item that closes below zero was issued stock the book never received --}}
<div class="card" id="ldBalance">
  <div class="card-head"><h3>Does the window add up?</h3>
    <span class="muted small">{{ $balance['items'] }} item{{ $balance['items'] === 1 ? '' : 's' }} · {{ money($balance['opening_value']) }} opening · {{ money($balance['closing_value']) }} closing</span></div>
  <div class="card-body">
    <div class="stats" style="margin:0">
      <x-stat label="Opening value" :value="money($balance['opening_value'])" icon="clock" tone="slate" sub="the book at the rate each item carries, the day the window opened"/>
      <x-stat label="Closing value" :value="money($balance['closing_value'])" icon="box" tone="blue" sub="the same items at the end of the window"/>
      <x-stat label="Moved" :value="money($balance['net'])" icon="swap" :tone="$balance['net'] < 0 ? 'red' : 'green'" sub="closing less opening — what the window did to the value on the shelf"/>
      <x-stat label="Below zero" :value="$balance['below']" icon="alert" :tone="$balance['tone']"
        :sub="$balance['worst'] ? $balance['worst']['item'].' at '.$balance['worst']['closing'].' '.$balance['worst']['unit'] : 'every item closed the window at or above zero'"/>
    </div>
    @if($balance['below'])
      <p class="small text-red" id="ldBalanceState" style="margin:.6rem 0 0"><x-icon name="alert"/>
        {{ $balance['worst']['item'] }} closed the window at {{ $balance['worst']['closing'] }} {{ $balance['worst']['unit'] }}
        ({{ money($balance['worst']['value']) }}): {{ $balance['worst']['opening'] }} {{ $balance['worst']['unit'] }} on the book when it opened,
        {{ $balance['worst']['in'] }} received and {{ $balance['worst']['out'] }} issued against it. Stock cannot leave a store it was never in —
        the issue was cut from a shelf the ledger has no receipt for. Opening plus in less out is closing on every row below, which is what
        makes the {{ $balance['below'] }} item{{ $balance['below'] === 1 ? '' : 's' }} visible at all.
      </p>
    @else
      <p class="small" id="ldBalanceState" style="margin:.6rem 0 0"><x-icon name="check"/>
        Every item rolled forward from its opening balance: nothing closed the window below zero, so no issue was cut against stock the book never received.
      </p>
    @endif
    @if($balance['rows'])
      <div class="table-wrap" style="margin-top:.6rem"><table class="table table-compact" id="balanceTable">
        <thead><tr><th>Item</th><th class="num">Opening</th><th class="num">In</th><th class="num">Out</th><th class="num">Closing</th><th class="num">Value</th></tr></thead><tbody>
        @foreach($balance['rows'] as $r)<tr data-closing="{{ $r['closing'] }}">
          <td class="bold">{{ $r['item'] }}<div class="tiny muted">{{ $r['unit'] }} @ {{ money($r['rate'], false) }}</div></td>
          <td class="num">{{ $r['opening'] }}</td><td class="num">{{ $r['in'] ?: '—' }}</td><td class="num">{{ $r['out'] ?: '—' }}</td>
          <td class="num {{ $r['tone'] }}">{{ $r['closing'] }}</td>
          <td class="num {{ $r['closing'] < 0 ? 'text-red' : '' }}">{{ money($r['value'], false) }}</td></tr>@endforeach
        </tbody></table></div>
    @endif
  </div>
</div>

{{-- v9.22 a movement out with neither a reference nor a department is stock nothing can be asked about --}}
<div class="card" id="ldGaps">
  <div class="card-head"><h3>What has no document behind it?</h3>
    <span class="muted small">{{ $gaps['count'] }} movement{{ $gaps['count'] === 1 ? '' : 's' }} out with no reference and no department · {{ money($gaps['value']) }} · {{ $gaps['share_label'] }}</span></div>
  <div class="card-body">
    <div class="stats" style="margin:0">
      <x-stat label="No document" :value="money($gaps['value'])" icon="alert" :tone="$gaps['tone']" :sub="$gaps['count'].' movements out that name neither a reference nor a department'"/>
      <x-stat label="Adjustments" :value="$gaps['adjust']" icon="edit" tone="slate" :sub="$gaps['adjust_sub']"/>
      <x-stat label="Wastage" :value="$gaps['waste']" icon="trash" tone="amber" :sub="money($gaps['waste_value']).' written off as spoiled or broken'"/>
      <x-stat label="Most in one hand" :value="$gaps['by'] ? $gaps['by'][0]['name'] : '—'" icon="user" tone="slate"
        :sub="$gaps['by'] ? $gaps['by'][0]['count'].' of them, '.money($gaps['by'][0]['value']) : 'nothing unexplained in the window'"/>
    </div>
    @if($gaps['worst'])
      <p class="small" id="ldGapsState" style="margin:.6rem 0 0"><x-icon name="alert"/>
        The largest is {{ $gaps['worst']['qty'] }} {{ $gaps['worst']['unit'] }} of {{ $gaps['worst']['item'] }} on
        {{ dmy($gaps['worst']['date']) }}, {{ $gaps['worst']['type'] }} {{ money($gaps['worst']['value']) }}@if($gaps['worst']['user'])
        by {{ $gaps['worst']['user'] }} @endif @if($gaps['worst']['remarks']) — “{{ $gaps['worst']['remarks'] }}” @endif.
        A wastage or an adjustment is a legitimate movement; one with no reference and no department is a number somebody typed,
        and it is {{ $gaps['share_label'] }} — {{ money($gaps['value']) }} with no receipt, issue note or supplier document to ask about.
      </p>
      <div class="table-wrap" style="margin-top:.6rem"><table class="table table-compact" id="gapTable">
        <thead><tr><th>Date</th><th>Item</th><th>Store</th><th>Type</th><th class="num">Qty</th><th class="num">Value</th><th>By</th></tr></thead><tbody>
        @foreach($gaps['rows'] as $r)<tr data-value="{{ $r['value'] }}">
          <td class="small">{{ dmy($r['date']) }}</td><td class="bold">{{ $r['item'] }}</td><td class="small">{{ $r['store'] }}</td>
          <td><span class="badge badge-amber">{{ $r['type'] }}</span></td>
          <td class="num">{{ $r['qty'] }} {{ $r['unit'] }}</td>
          <td class="num">{{ money($r['value'], false) }}</td>
          <td class="small">{{ $r['user'] }}</td></tr>@endforeach
        </tbody></table></div>
    @else
      <p class="small" id="ldGapsState" style="margin:.6rem 0 0"><x-icon name="check"/>
        Every movement out of a store in this window names a document or a department, so each one can be asked about.
      </p>
    @endif
  </div>
</div>

<div class="card"><div class="card-body"><form class="filters">
  <div class="field"><label>From</label><input type="date" name="from" value="{{ $from }}"></div>
  <div class="field"><label>To</label><input type="date" name="to" value="{{ $to }}"></div>
  <div class="field"><label>Item</label><select name="item"><option value="">All</option>@foreach($items as $id=>$n)<option value="{{ $id }}" @selected(request('item')==$id)>{{ $n }}</option>@endforeach</select></div>
  <div class="field"><label>Store</label><select name="store"><option value="">All</option>@foreach($stores as $id=>$n)<option value="{{ $id }}" @selected(request('store')==$id)>{{ $n }}</option>@endforeach</select></div>
  <div class="field"><label>Type</label><select name="type"><option value="">All</option>@foreach(['opening','purchase','issue','transfer_in','transfer_out','adjustment','wastage','consumption'] as $t)<option value="{{ $t }}" @selected(request('type')==$t)>{{ str_replace('_',' ',$t) }}</option>@endforeach</select></div>
  <button class="btn btn-primary">Filter</button></form></div></div>

<div class="card flush"><div class="card-head"><h3>Movements</h3>
  <span class="muted small">{{ $moves->total() }} line{{ $moves->total() === 1 ? '' : 's' }} · the three readings above are worked out from exactly these rows</span></div>
<div class="card-body table-wrap"><table class="table" id="ledgerTable">
<thead><tr><th>Date</th><th>Item</th><th>Store</th><th>Type</th><th class="num">In</th><th class="num">Out</th><th class="num">Rate</th><th>Dept / Ref</th><th>Remarks</th><th>By</th></tr></thead><tbody>
@forelse($moves as $m)<tr data-type="{{ $m->type }}" data-qty="{{ $m->qty }}">
  <td>{{ $m->date->format('d M Y') }}</td><td class="bold">{{ $m->item->name }}</td><td>{{ $m->store->name }}</td>
  <td><span class="badge {{ $m->qty > 0 ? 'badge-green' : 'badge-red' }}">{{ str_replace('_',' ',$m->type) }}</span></td>
  <td class="num">{{ $m->qty > 0 ? (float)$m->qty : '' }}</td><td class="num">{{ $m->qty < 0 ? abs((float)$m->qty) : '' }}</td>
  <td class="num">{{ money($m->rate, false) }}</td><td>{{ $m->department }} {{ $m->reference }}</td><td class="small">{{ $m->remarks }}</td>
  <td class="tiny muted">{{ $m->user ? ucfirst($m->user->username) : '—' }}</td></tr>
@empty<tr><td colspan="10"><x-empty text="No movements." icon="box"/></td></tr>@endforelse</tbody></table>
{{ $moves->links() }}</div></div>

<p class="tiny muted mt-1" id="ldChain">Every other inventory document ends here: the <a href="{{ route('grn.index') }}">goods receipt</a> writes a purchase line, the <a href="{{ route('req.index') }}">issue</a> writes it out again, a <a href="{{ route('stocktakes.index') }}">stock take</a> posts the difference, and a <a href="{{ route('inventory.movement') }}">movement</a> writes a line by hand. The ledger is the book all four are checked against — so a line with no document behind it, or an item that closes below zero, is the difference between a book and a list of quantities.</p>
@endsection
