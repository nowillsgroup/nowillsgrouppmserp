<div class="card" id="reservationPostedBalance">
  <div class="card-head"><h3>Reservation posted balance</h3></div>
  <div class="card-body">
    <p class="small muted">Posted entries across all {{ $summary['folios'] }} reservation-linked folio(s), including advances. Unposted room nights and service charges are not included.</p>
    <ul class="list">
      <li><span>Charges including tax</span><b>{{ money($summary['charges']) }}</b></li>
      @if($summary['opening'] != 0)<li><span>Opening balances</span><b>{{ money($summary['opening']) }}</b></li>@endif
      <li><span>Discounts and allowances (subtracted)</span><b>{{ money($summary['discount']) }}</b></li>
      <li><span>Payments and transfers (subtracted)</span><b>{{ money($summary['payments']) }}</b></li>
      @if($summary['refunds'] != 0)<li><span>Refunds</span><b>{{ money($summary['refunds']) }}</b></li>@endif
      <li><span class="bold">{{ $summary['balance'] < 0 ? 'Posted credit' : 'Posted balance' }}</span><b>{{ money(abs($summary['balance'])) }}</b></li>
    </ul>
    <p class="tiny muted">Read-only overview, not a final bill or checkout quote. Room folios are still settled separately.</p>
  </div>
</div>
