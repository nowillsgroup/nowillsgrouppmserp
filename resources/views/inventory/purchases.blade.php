@extends('layouts.app')
@section('title', 'Purchase Orders')
@section('actions')
@if(can_do('settings.manage'))<button class="btn" data-modal="procSettings" id="procSettingsBtn"><x-icon name="shield"/> Approval matrix</button>@endif
@if(can_do('purchasing.rfq'))<a class="btn" href="{{ route('rfq.create') }}"><x-icon name="mail"/> New RFQ</a>@endif
@if(can_do('inventory.manage'))<a class="btn btn-primary" href="{{ route('purchases.create') }}"><x-icon name="plus"/> New PO</a>@endif
@endsection
@section('content')

{{-- v9.17 an order is money promised and not yet posted, so the header carries the commitment and the two things that can be wrong with it --}}
<div class="stats">
  <x-stat label="On order" :value="money($commit['value'])" icon="cart" :tone="$commit['value'] > 0 ? 'amber' : 'green'" sub="approved or part-received, with goods still to arrive — a commitment the ledger does not carry"/>
  <x-stat label="Past the delivery date" :value="$commit['late']" icon="clock" :tone="$commit['late'] ? 'red' : 'green'" href="?late=1"
    :sub="$commit['late'] ? money($commit['late_value']).' still to arrive beyond the date the order named' : 'every open order is inside its date'"/>
  <x-stat label="Waiting for approval" :value="$gate['approvable']" icon="shield" :tone="$gate['approvable'] ? 'amber' : 'green'"
    :sub="$gate['approvable'] ? money($gate['approvable_value']).' you can approve now'.($gate['blocked'] ? ' · '.$gate['blocked'].' you cannot' : '') : 'nothing is waiting on you'"/>
  <x-stat label="Received and billed" :value="money($match['received'])" icon="receipt" :tone="$match['breaks'] ? 'red' : 'green'"
    :sub="$match['orders'].' orders with a receipt'.($match['breaks'] ? ' · '.$match['breaks'].' where the bill disagrees' : ', each billed for what the shelf took in')"/>
</div>

{{-- v9.17 the commitment: approved or part-received and not yet arrived — the number the accounts do not hold --}}
<div class="card" id="poCommit">
  <div class="card-head"><h3>What has been promised and not yet delivered?</h3>
    <span class="muted small">{{ $commit['orders'] }} order{{ $commit['orders'] === 1 ? '' : 's' }} · {{ money($commit['value']) }} still to arrive · {{ $commit['late'] }} past the date</span></div>
  <div class="card-body">
    <div class="stats" style="margin:0">
      <x-stat label="Goods still to come" :value="money($commit['value'])" icon="cart" tone="slate" sub="ordered value less what the goods receipts have already taken in"/>
      <x-stat label="Past the delivery date" :value="money($commit['late_value'])" icon="clock" :tone="$commit['late'] ? 'red' : 'green'"
        :sub="$commit['late'] ? $commit['late'].' order'.($commit['late'] === 1 ? '' : 's').' owed after the date they were promised' : 'nothing is owed past its date'"/>
      <x-stat label="Oldest lateness" :value="$commit['worst'] ? $commit['worst']['late'].' day'.($commit['worst']['late'] === 1 ? '' : 's') : '—'" icon="alert"
        :tone="$commit['worst'] ? 'red' : 'slate'" sub="counted from the day the order was due"/>
    </div>
    @if($commit['worst'])
      <p class="small text-red" id="poCommitState" style="margin:.6rem 0 0"><x-icon name="alert"/>{{ $commit['late'] }} order{{ $commit['late'] === 1 ? ' is' : 's are' }} past the date
        they were due, {{ money($commit['late_value']) }} of goods still to arrive. The oldest is {{ $commit['worst']['po_no'] }} from {{ $commit['worst']['supplier'] }} —
        {{ money($commit['worst']['open']) }} still to come against {{ money($commit['worst']['total']) }} ordered, due {{ dmy($commit['worst']['expected']) }},
        {{ $commit['worst']['late'] }} days ago. Nothing is on the ledger for it: the commitment is ours, the receipt is not.</p>
    @else
      <p class="small" id="poCommitState" style="margin:.6rem 0 0"><x-icon name="check"/>Nothing is past its date: {{ $commit['orders'] }} orders, {{ money($commit['value']) }} of goods still
        to arrive, and the next is due {{ dmy($commit['soonest']['expected']) }}. Every promise on this list is still inside the date it was given.</p>
    @endif
    <div class="table-wrap" style="margin-top:.6rem"><table class="table table-compact" id="commitTable">
      <thead><tr><th>PO</th><th>Supplier</th><th>Due</th><th class="num">Late</th><th class="num">Ordered</th><th class="num">Received</th><th class="num">Still to come</th></tr></thead><tbody>
      @forelse($commit['rows'] as $r)<tr data-open="{{ $r['late'] > 0 ? 'late' : 'open' }}">
        <td class="bold">{{ $r['po_no'] }}</td><td class="small">{{ $r['supplier'] }}</td>
        <td class="small">{{ dmy($r['expected']) }}</td>
        <td class="num {{ $r['late'] ? 'text-red' : 'muted' }}">{{ $r['late'] ? $r['late'].' d' : '—' }}</td>
        <td class="num">{{ money($r['total'], false) }}</td><td class="num muted">{{ money($r['received'], false) }}</td>
        <td class="num bold">{{ money($r['open'], false) }}</td></tr>
      @empty<tr><td colspan="7" class="empty">Nothing is on order: every purchase order has been received or closed.</td></tr>@endforelse
      </tbody></table></div>
  </div>
</div>

{{-- v9.17 the gate: the approval matrix the approve button itself tests — level by level, and who may not sign --}}
<div class="card" id="poGate">
  <div class="card-head"><h3>Who has to approve what, and may they?</h3>
    <span class="muted small">{{ $gate['drafts'] }} draft{{ $gate['drafts'] === 1 ? '' : 's' }} · {{ money($gate['value']) }} · {{ $gate['blocked'] }} that you cannot approve</span></div>
  <div class="card-body">
    <div class="stats" style="margin:0">
      <x-stat label="Waiting for a signature" :value="money($gate['value'])" icon="shield" tone="slate" :sub="$gate['drafts'].' draft purchase orders above zero'"/>
      <x-stat label="You can approve" :value="money($gate['approvable_value'])" icon="check" :tone="$gate['approvable'] ? 'amber' : 'green'"
        :sub="$gate['approvable'] ? $gate['approvable'].' order'.($gate['approvable'] === 1 ? '' : 's').($gate['largest_approvable'] ? ', the largest '.$gate['largest_approvable']->po_no.' at '.money($gate['largest_approvable']->total) : '') : 'nothing is waiting on your level'"/>
      <x-stat label="You cannot approve" :value="money($gate['blocked_value'])" icon="alert" :tone="$gate['blocked'] ? 'red' : 'green'"
        :sub="$gate['blocked'] ? $gate['blocked'].' order'.($gate['blocked'] === 1 ? '' : 's').' held by the matrix or by segregation' : 'no draft is held against you'"/>
    </div>
    <div class="table-wrap" style="margin-top:.6rem"><table class="table table-compact" id="gateLevels">
      <thead><tr><th>Approval level</th><th>Who signs</th><th class="num">Orders</th><th class="num">Value</th><th>Largest</th></tr></thead><tbody>
      @foreach($gate['levels'] as $l)<tr data-level="{{ $l['level'] }}">
        <td class="bold">Level {{ $l['level'] }}</td>
        <td class="small">{{ $l['label'] }}<div class="tiny muted">@if($l['level'] === 1)up to {{ money($gate['limits'][0]) }}@elseif($l['level'] === 2)up to {{ money($gate['limits'][1]) }}@else{{ 'above '.money($gate['limits'][1]) }}@endif</div></td>
        <td class="num">{{ $l['count'] }}</td>
        <td class="num">{{ $l['count'] ? money($l['value'], false) : '—' }}</td>
        <td class="small">{{ $l['largest'] ?? '—' }}@if($l['largest'])<div class="tiny muted">{{ money($l['largest_value']) }}</div>@endif</td></tr>
      @endforeach
      </tbody></table></div>
    <p class="small {{ $gate['blocked'] ? 'text-red' : '' }}" id="poGateState" style="margin:.6rem 0 0">
      @if($gate['blocked'])
        <x-icon name="alert"/>{{ $gate['approvable'] }} of the {{ $gate['drafts'] }} drafts can be approved by you now{{ $gate['largest_approvable'] ? ', the largest '.$gate['largest_approvable']->po_no.' at '.money($gate['largest_approvable']->total).' needing '.$gate['levels'][2]['label'] : '' }}.
        {{ $gate['blocked'] }} cannot: {{ $gate['blocked_rows'][0]['po_no'] }} from {{ $gate['blocked_rows'][0]['supplier'] }} — {{ $gate['blocked_rows'][0]['why'] }}
      @else
        <x-icon name="check"/>Every draft on the list can be approved at your level, and nothing is held back by segregation of duties,
        {{ $gate['segregation'] ? 'which is switched on' : 'which is switched off' }}.
      @endif
    </p>
    @if($gate['blocked'])
      <div class="table-wrap" style="margin-top:.6rem"><table class="table table-compact" id="gateBlocked">
        <thead><tr><th>PO</th><th>Supplier</th><th class="num">Total</th><th>Why it cannot be approved</th></tr></thead><tbody>
        @foreach($gate['blocked_rows'] as $r)<tr data-blocked="1">
          <td class="bold">{{ $r['po_no'] }}</td><td class="small">{{ $r['supplier'] }}</td>
          <td class="num">{{ money($r['total'], false) }}</td><td class="small text-red">{{ $r['why'] }}</td></tr>@endforeach
        </tbody></table></div>
    @endif
  </div>
</div>

{{-- v9.17 the match: the shelf took the goods in and the supplier billed for them; the two figures have to be the same money --}}
<div class="card" id="poMatch">
  <div class="card-head"><h3>Is the bill for what the shelf actually took in?</h3>
    <span class="muted small">{{ $match['orders'] }} orders with a receipt · {{ $match['breaks'] ? $match['breaks'].' where the bill disagrees' : 'no bill disagrees' }}</span></div>
  <div class="card-body">
    <div class="stats" style="margin:0">
      <x-stat label="Received" :value="money($match['received'])" icon="box" tone="slate" :sub="$match['orders'].' orders whose goods the receipts took in'"/>
      <x-stat label="Billed" :value="money($match['billed'])" icon="receipt" tone="slate" sub="the bills on the ledger against those same orders"/>
      <x-stat label="Difference" :value="money($match['diff'])" icon="alert" :tone="$match['breaks'] ? 'red' : 'green'"
        :sub="$match['breaks'] ? 'received less billed, order by order' : 'every receipt has a bill for the same money'"/>
    </div>
    @if($match['breaks'] === 0)
      <p class="small" id="poMatchState" style="margin:.6rem 0 0"><x-icon name="check"/>Every one of the {{ $match['orders'] }} orders with a receipt has a bill on the ledger for the value the
        shelf took in — the largest, {{ $match['largest']['po_no'] }} from {{ $match['largest']['supplier'] }}, {{ money($match['largest']['received']) }} received and billed the same.
        Nothing received is uninvoiced, and nothing is billed that was not received.</p>
    @else
      <p class="small text-red" id="poMatchState" style="margin:.6rem 0 0"><x-icon name="alert"/>{{ $match['breaks'] }} order{{ $match['breaks'] === 1 ? '' : 's' }} where the bill and the receipt
        disagree, {{ money(abs($match['diff'])) }} apart in all{{ $match['unbilled'] ? ', '.$match['unbilled'].' of them with no bill at all' : '' }}. Whatever the cause, one of the two records is
        wrong: either the supplier has not invoiced goods we hold, or we have been billed for goods the shelf never took in.</p>
      <div class="table-wrap" style="margin-top:.6rem"><table class="table table-compact" id="matchGap">
        <thead><tr><th>PO</th><th>Supplier</th><th>Bill</th><th class="num">Received</th><th class="num">Billed</th><th class="num">Difference</th></tr></thead><tbody>
        @foreach($match['rows'] as $r)<tr data-gap="match">
          <td class="bold">{{ $r['po_no'] }}</td><td class="small">{{ $r['supplier'] }}</td>
          <td class="small">{{ $r['bill_no'] ?? 'no bill' }}</td><td class="num">{{ money($r['received'], false) }}</td>
          <td class="num">{{ money($r['billed'], false) }}</td><td class="num text-red">{{ money($r['diff'], false) }}</td></tr>@endforeach
        </tbody></table></div>
    @endif
  </div>
</div>

<div class="tabs">@foreach([''=>'All','draft'=>'Draft','approved'=>'Approved','partial'=>'Part received','received'=>'Received','closed'=>'Short-closed','cancelled'=>'Cancelled'] as $k=>$v)<a href="?status={{ $k }}" class="{{ request('status','')===$k ? 'active' : '' }}">{{ $v }}</a>@endforeach<a href="?late=1" class="{{ request()->boolean('late') ? 'active' : '' }}">Past the date</a></div>
<div class="card flush"><div class="card-body table-wrap"><table class="table" id="poTable">
<thead><tr><th>PO No</th><th>Date</th><th>Supplier</th><th>Store</th><th>Expected</th><th class="num">Total</th><th class="num">Receipt</th><th>Bill</th><th>Status</th><th></th></tr></thead><tbody>
@forelse($orders as $po)<tr data-status="{{ $po->status }}" data-open="{{ in_array($po->status, ['approved', 'partial']) && $po->open_value > 0.005 ? 1 : 0 }}">
  <td><a href="{{ route('purchases.show', $po) }}" class="bold">{{ $po->po_no }}</a>@if($po->rfq_id)<div class="tiny muted">from RFQ</div>@endif</td>
  <td>{{ $po->po_date->format('d M Y') }}</td><td>{{ $po->supplier->name }}</td><td>{{ $po->store->name }}</td>
  <td>{{ dmy($po->expected_date) }}@if($po->isOverdue()) <span class="badge badge-red">late</span>@endif</td>
  <td class="num">{{ money($po->total) }}@if($po->isForeign())<div class="tiny muted">{{ $po->fxLabel() }}</div>@endif</td>
  <td class="num">@if($po->receipt_value <= 0.005)<span class="badge badge-slate">not received</span>@else{{ money($po->receipt_value, false) }}@if($po->receipt_value + 0.005 < (float) $po->total)<div class="tiny muted">of {{ money($po->total, false) }}</div>@endif @endif</td>
  <td>@if($po->bill)<a class="small" href="{{ route('ap.bills.show', $po->bill) }}">{{ $po->bill->doc_no }}</a>@elseif($po->receipt_value > 0.005)<span class="badge badge-amber">no bill</span>@else<span class="muted">—</span>@endif</td>
  <td>{!! status_badge($po->status) !!}</td>
  <td><a class="btn btn-sm" href="{{ route('purchases.show', $po) }}"><x-icon name="eye" size="sm"/></a></td></tr>
@empty<tr><td colspan="10"><x-empty text="No purchase orders." icon="box"/></td></tr>@endforelse</tbody></table>{{ $orders->links() }}</div></div>

<p class="small muted" id="poChain" style="margin:.8rem 0 0">The chain an order starts: a requisition is approved, the order goes to the supplier, the goods arrive on a
  receipt, and the receipt is what a bill may be raised against — so an order is a commitment until it is received, and a receipt is a claim until it is billed. The
  <a href="{{ route('grn.index') }}">goods receipts</a> screen reads the same documents from the shelf's side: it asks whether each receipt reached a bill, and this one asks
  whether the bill agrees with the receipt.</p>

@if(can_do('settings.manage'))
<div class="modal" id="procSettings"><div class="modal-box"><form method="post" action="{{ route('proc.settings') }}">@csrf
<div class="modal-head"><h3>Purchasing controls</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
<div class="modal-body"><div class="form-grid">
  <x-field name="po_limit_1" label="Level 1 limit – store (GH₵)" type="number" step="0.01" min="0" :value="$limits[0]" required hint="inventory.manage approves up to this PO total"/>
  <x-field name="po_limit_2" label="Level 2 limit – purchasing manager (GH₵)" type="number" step="0.01" min="0" :value="$limits[1]" required hint="purchasing.approve; above → purchasing.approve_high"/>
  <x-field name="po_price_tolerance_pct" label="Price tolerance vs agreed rate (%)" type="number" step="0.1" min="0" :value="setting('po_price_tolerance_pct', '2')" required/>
  <x-field name="po_over_receipt_pct" label="Over-delivery tolerance (%)" type="number" step="0.1" min="0" :value="setting('po_over_receipt_pct', '0')" required/>
  <x-field name="rfq_min_quotes" label="Minimum quotations per RFQ" type="number" min="1" :value="setting('rfq_min_quotes', '3')" required hint="fewer needs a written justification at award"/>
</div>
<label class="check mt-1"><input type="checkbox" name="po_segregation" value="1" @checked($segregation)> <span>Segregation of duties – the person who raised a PO cannot approve it</span></label></div>
<div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Save</button></div></form></div></div>
@endif
@endsection
