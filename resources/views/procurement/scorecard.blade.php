@extends('layouts.app')
@section('title', 'Supplier Scorecard')
@section('crumbs') Inventory / Supplier scorecard @endsection
@section('actions')
<a class="btn" href="{{ route('reports.show', 'supplier_scorecard') }}?from={{ $from }}&to={{ $to }}"><x-icon name="download"/> Report / export</a>
@endsection
@section('content')
<form class="card" method="get" id="scFilter"><div class="card-body flex gap-1 wrap items-end">
  <div class="field"><label>From</label><input type="date" name="from" value="{{ $from }}" class="input"></div>
  <div class="field"><label>To</label><input type="date" name="to" value="{{ $to }}" class="input"></div>
  <button class="btn">Show</button>
  <span class="small muted" style="margin-left:auto;max-width:520px">Score = on-time 35% · fill rate 25% · quality (100 − rejection %) 25% · price compliance 15%, over the measures available. A ≥ 90 · B ≥ 75 · C ≥ 60 · D below. A supplier with no deliveries is not scored, only graded on what it did.</span>
</div></form>

{{-- v9.19 the score is one number; the header says how much of it is earned and how much of the period's money is still open --}}
<div class="stats wide">
  <x-stat label="Suppliers scored" :value="$header['suppliers']" icon="users" tone="slate"
    :sub="$header['grades']['A'].' at A · '.$header['grades']['B'].' at B · '.$header['grades']['C'].' at C · '.$header['grades']['D'].' at D'"/>
  <x-stat label="Average score" :value="$header['average'] ?? '—'" icon="chart" :tone="($header['average'] ?? 0) >= 75 ? 'green' : 'amber'" sub="weighted over the measures each supplier actually has"/>
  <x-stat label="Late deliveries" :value="$delivery['late']" icon="clock" :tone="$delivery['late'] ? 'red' : 'green'"
    :sub="$delivery['late_sub']" href="#scDelivery"/>
  <x-stat label="Billed vs received" :value="money($money['diff'])" icon="receipt" :tone="abs($money['diff']) > 0.005 ? 'amber' : 'green'"
    :sub="$money['gaps'] ? $money['gaps'].' supplier'.($money['gaps'] === 1 ? '' : 's').' where the two disagree' : 'every bill is for what the shelf took in'" href="#scMoney"/>
  <x-stat label="Still owed" :value="money($money['unpaid'])" icon="wallet" tone="amber"
    :sub="$money['owed_sub']" href="#scMoney"/>
</div>

{{-- v9.19 the delivery record: an on-time percentage says nothing about the one delivery that held up a service --}}
<div class="card" id="scDelivery">
  <div class="card-head"><h3>Who delivers when they say they will?</h3>
    <span class="muted small">{{ $delivery['count'] }} deliveries · {{ $delivery['late'] }} late · oldest {{ $delivery['worst'] ? $delivery['worst']['days'].' day'.($delivery['worst']['days'] === 1 ? '' : 's') : '—' }} over</span></div>
  <div class="card-body">
    <div class="stats" style="margin:0">
      <x-stat label="Deliveries accepted" :value="$delivery['count']" icon="box" tone="slate" :sub="money($delivery['value']).' taken into the store'"/>
      <x-stat label="Late" :value="$delivery['late']" icon="clock" :tone="$delivery['late'] ? 'red' : 'green'"
        :sub="$delivery['late'] ? money($delivery['late_value']).' arrived after the date' : 'nothing arrived after the date it was given'"/>
      <x-stat label="Lead time" :value="$delivery['lead'] !== null ? $delivery['lead'].' d' : '—'" icon="calendar" tone="slate" sub="order date to arrival, averaged over the deliveries"/>
      <x-stat label="Turned away" :value="money($delivery['rejected'])" icon="alert" :tone="$delivery['rejected'] > 0 ? 'amber' : 'green'" sub="rejected at the door and never entered stock"/>
    </div>
    @if($delivery['late'])
      <p class="small text-red" id="scDeliveryState" style="margin:.6rem 0 0"><x-icon name="clock"/>
        The worst is {{ $delivery['worst']['supplier'] }} on {{ $delivery['worst']['grn'] }} against {{ $delivery['worst']['po'] }} —
        {{ money($delivery['worst']['value']) }} arrived on {{ dmy($delivery['worst']['arrived']) }}, {{ $delivery['worst']['days'] }} days after the
        {{ dmy($delivery['worst']['expected']) }} it was promised for. {{ $delivery['late'] }} of the {{ $delivery['count'] }} deliveries in this period
        came late, {{ money($delivery['late_value']) }} of the {{ money($delivery['value']) }} accepted.
      </p>
      <div class="table-wrap" style="margin-top:.6rem"><table class="table table-compact" id="lateTable">
        <thead><tr><th>Supplier</th><th>GRN</th><th>Order</th><th>Promised</th><th>Arrived</th><th class="num">Late</th><th class="num">Value</th></tr></thead><tbody>
        @foreach($delivery['rows'] as $g)<tr data-late="{{ $g['days'] }}">
          <td class="bold">{{ $g['supplier'] }}</td><td class="small">{{ $g['grn'] }}</td><td class="small">{{ $g['po'] }}</td>
          <td class="small">{{ dmy($g['expected']) }}</td><td class="small">{{ dmy($g['arrived']) }}</td>
          <td class="num {{ $g['days'] > 7 ? 'text-red bold' : '' }}">{{ $g['days'] }} d</td>
          <td class="num">{{ money($g['value'], false) }}</td></tr>@endforeach
        </tbody></table></div>
    @else
      <p class="small" id="scDeliveryState" style="margin:.6rem 0 0"><x-icon name="check"/>Every delivery in this period arrived on or before the date
        the order gave it. Nothing was turned away at the door.</p>
    @endif
  </div>
</div>

{{-- v9.19 the money record: the shelf and the invoice have to agree before the payment is anything but a guess --}}
<div class="card" id="scMoney">
  <div class="card-head"><h3>Did the supplier bill what arrived?</h3>
    <span class="muted small">{{ $money['count'] }} suppliers · {{ $money['gaps'] }} where the bill and the receipt disagree</span></div>
  <div class="card-body">
    <div class="stats" style="margin:0">
      <x-stat label="Received" :value="money($money['received'])" icon="box" tone="slate" sub="accepted into the store in this period"/>
      <x-stat label="Billed" :value="money($money['billed'])" icon="receipt" tone="slate" sub="supplier bills dated in the same period, excluding debit notes"/>
      <x-stat label="Difference" :value="money($money['diff'])" icon="alert" :tone="abs($money['diff']) > 0.005 ? 'red' : 'green'"
        :sub="$money['gaps'] ? 'billed less received, over the suppliers that disagree' : 'the two are the same money'"/>
      <x-stat label="Still owed" :value="money($money['unpaid'])" icon="wallet" :tone="$money['unpaid'] > 0 ? 'amber' : 'green'"
        :sub="$money['owed_sub']"/>
    </div>
    @if($money['count'])
      <p class="small {{ $money['gaps'] ? 'text-red' : '' }}" id="scMoneyState" style="margin:.6rem 0 0">
        @if($money['gaps'])
          <x-icon name="alert"/>{{ $money['gaps'] }} supplier{{ $money['gaps'] === 1 ? '' : 's' }} where the bill and the receipt disagree, {{ money(abs($money['diff'])) }} in all —
          the widest is {{ $money['worst']['supplier'] }}, billed {{ money($money['worst']['billed']) }} against {{ money($money['worst']['received']) }} received,
          {{ money($money['worst']['diff']) }}. Until one of the two is corrected, the payable is a number nobody has agreed to.
        @else
          <x-icon name="check"/>Every supplier billed exactly what the shelf took in for the period, order for order.
        @endif
        @if($money['unpaid'] > 0)
          {{ money($money['unpaid']) }} is still owed across {{ $money['open_bills'] }} open bill{{ $money['open_bills'] === 1 ? '' : 's' }};
          the oldest is {{ $money['oldest']['supplier'] }} at {{ $money['oldest']['oldest_days'] }} days past its due date.
        @endif
      </p>
      <div class="table-wrap" style="margin-top:.6rem"><table class="table table-compact" id="moneyTable">
        <thead><tr><th>Supplier</th><th class="num">Received</th><th class="num">Billed</th><th class="num">Difference</th><th class="num">Open bills</th><th class="num">Unpaid</th><th class="num">Oldest</th></tr></thead><tbody>
        @foreach($money['rows'] as $m)<tr data-gap="{{ abs($m['diff']) > 0.005 ? 1 : 0 }}">
          <td class="bold">{{ $m['supplier'] }}</td>
          <td class="num">{{ money($m['received'], false) }}</td><td class="num">{{ money($m['billed'], false) }}</td>
          <td class="num {{ abs($m['diff']) > 0.005 ? 'text-red bold' : '' }}">{{ money($m['diff'], false) }}</td>
          <td class="num">{{ $m['open_bills'] }}</td><td class="num">{{ money($m['unpaid'], false) }}</td>
          <td class="num {{ $m['oldest_days'] > 30 ? 'text-red bold' : '' }}">{{ $m['open_bills'] ? $m['oldest_days'].' d' : '—' }}</td></tr>@endforeach
        </tbody></table></div>
    @else
      <p class="small" id="scMoneyState" style="margin:.6rem 0 0"><x-icon name="info"/>No goods were received and no bill was posted in this period,
        so there is nothing to hold to anything.</p>
    @endif
  </div>
</div>

{{-- v9.19 the bid record: a response rate is only useful if the silence behind it has a name --}}
<div class="card" id="scBid">
  <div class="card-head"><h3>Who answers when we ask them to quote?</h3>
    <span class="muted small">{{ $bid['invited'] }} invitation{{ $bid['invited'] === 1 ? '' : 's' }} · {{ $bid['answered'] }} answered · {{ $bid['silent'] }} still silent past the date</span></div>
  <div class="card-body">
    <div class="stats" style="margin:0">
      <x-stat label="Invitations" :value="$bid['invited']" icon="mail" tone="slate" :sub="$bid['count'].' supplier'.($bid['count'] === 1 ? '' : 's').' asked to quote in this period'"/>
      <x-stat label="Answered" :value="$bid['answered']" icon="check" :tone="($bid['rate'] ?? 0) >= 60 ? 'green' : 'amber'" :sub="$bid['answered_sub']"/>
      <x-stat label="Still silent" :value="$bid['silent']" icon="clock" :tone="$bid['silent'] ? 'red' : 'green'" sub="past the date the quotation was asked for, with neither a price nor a decline"/>
      <x-stat label="Above the agreed rate" :value="$bid['above']" icon="alert" :tone="$bid['above'] ? 'red' : 'green'"
        :sub="$bid['priced'] ? 'of '.$bid['priced'].' order lines that carry a list rate' : 'no order line in this period carries a list rate'"/>
    </div>
    @if($bid['count'])
      <p class="small {{ $bid['silent'] ? 'text-red' : '' }}" id="scBidState" style="margin:.6rem 0 0">
        @if($bid['silent'])
          <x-icon name="alert"/>{{ $bid['worst']['supplier'] }} was invited {{ $bid['worst']['invited'] }} time{{ $bid['worst']['invited'] === 1 ? '' : 's' }}
          and answered {{ $bid['worst']['answered'] }} — {{ $bid['worst']['silent'] }} went past the date without a price or a decline.
          A supplier who will not quote is not a cheap supplier; it is a supplier the buyer cannot compare.
        @else
          <x-icon name="check"/>Every supplier invited in this period either quoted or declined before the date. {{ money($bid['quoted']) }} of quotations are on record.
        @endif
        @if($bid['above']) {{ $bid['above'] }} order line{{ $bid['above'] === 1 ? '' : 's' }} came in above the agreed rate, which is the one place the score and the money disagree.@endif
      </p>
      <div class="table-wrap" style="margin-top:.6rem"><table class="table table-compact" id="bidTable">
        <thead><tr><th>Supplier</th><th class="num">Invited</th><th class="num">Answered</th><th class="num">Declined</th><th class="num">Silent</th><th class="num">Response</th><th class="num">Quoted</th></tr></thead><tbody>
        @foreach($bid['rows'] as $b)<tr data-silent="{{ $b['silent'] }}">
          <td class="bold">{{ $b['supplier'] }}</td><td class="num">{{ $b['invited'] }}</td><td class="num">{{ $b['answered'] }}</td>
          <td class="num">{{ $b['declined'] }}</td><td class="num {{ $b['silent'] ? 'text-red bold' : '' }}">{{ $b['silent'] }}</td>
          <td class="num">{{ $b['rate'] === null ? '—' : $b['rate'].' %' }}</td><td class="num">{{ money($b['quoted'], false) }}</td></tr>@endforeach
        </tbody></table></div>
    @else
      <p class="small" id="scBidState" style="margin:.6rem 0 0"><x-icon name="info"/>Nobody was asked to quote in this period, so there is no response to score.</p>
    @endif
  </div>
</div>

<div class="card flush">
  <div class="card-head"><h3>The score, supplier by supplier</h3>
    <span class="muted small">{{ $header['suppliers'] }} supplier{{ $header['suppliers'] === 1 ? '' : 's' }} · {{ money($header['value']) }} ordered · average {{ $header['average'] ?? '—' }}</span></div>
  <div class="card-body table-wrap"><table class="table" id="scoreTable">
<thead><tr><th>Supplier</th><th class="num">POs</th><th class="num">Value</th><th class="num">Deliveries</th><th class="num">On time</th><th class="num">Fill rate</th><th class="num">Quality</th><th class="num">Price compliance</th><th class="num">RFQ response</th><th class="num">Lead (d)</th><th class="num">Score</th></tr></thead><tbody>
@forelse($rows as $r)<tr data-supplier="{{ $r['supplier_id'] }}" data-grade="{{ $r['grade'] }}"><td class="bold">{{ $r['supplier'] }}</td><td class="num">{{ $r['orders'] }}</td><td class="num">{{ money($r['value'], false) }}</td><td class="num">{{ $r['deliveries'] }}</td>
  <td class="num">{{ $r['on_time_label'] }}</td><td class="num">{{ $r['fill_label'] }}</td><td class="num">{{ $r['quality_label'] }}@if($r['rejected_qty'] > 0)<div class="tiny text-red">{{ $r['rejected_qty'] }} rejected</div>@endif</td>
  <td class="num">{{ $r['price_label'] }}@if($r['above_list'])<div class="tiny text-red">{{ $r['above_list'] }} above agreed</div>@endif</td>
  <td class="num">{{ $r['response_label'] }}@if($r['invited'])<div class="tiny muted">{{ $r['quoted'] }}/{{ $r['invited'] }}</div>@endif</td><td class="num">{{ $r['lead_days'] ?? '—' }}</td>
  <td class="num"><span class="badge badge-{{ $r['tone'] }}" data-grade>{{ $r['grade'] }}</span> {{ $r['score'] ?? '' }}</td></tr>
@empty<tr><td colspan="11"><x-empty text="No purchasing activity in this period." icon="chart"/></td></tr>@endforelse
</tbody></table></div></div>
<p class="tiny muted mt-1" id="scChain">The score is the last thing the chain can tell you: a requisition became an order, the order became a delivery, the delivery became a bill and the bill was paid. On-time and lead time are read off the <a href="{{ route('grn.index') }}">goods receipts</a>, the fill rate and the price compliance off the <a href="{{ route('purchases.index') }}">orders</a>, the money off the bills and the payments, and the response rate off the quotations. A measure a supplier has no evidence for is left out of the score rather than set to zero, so a new supplier is judged on what it has done, not on what it has not.</p>
@endsection
