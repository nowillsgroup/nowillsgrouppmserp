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
    @if($summary['folios'])<button type="button" class="btn btn-sm" data-modal="reservationBalanceDetails" aria-haspopup="dialog" aria-controls="reservationBalanceDetails">View folio breakdown</button>@endif
  </div>
</div>
@if($summary['folios'])
<div class="modal" id="reservationBalanceDetails" role="dialog" aria-modal="true" aria-labelledby="reservationBalanceDetailsTitle"><div class="modal-box">
  <div class="modal-head"><h3 id="reservationBalanceDetailsTitle">Reservation folio breakdown</h3><button type="button" class="btn btn-ghost btn-icon" data-close aria-label="Close dialog" title="Close dialog"><x-icon name="x"/></button></div>
  <div class="modal-body">
    <p class="small muted">These are posted balances, not checkout quotes. Existing invoices remain unchanged.</p>
    @if($summary['room_invoices'])<p class="alert alert-info">{{ $summary['room_invoices'] }} room folio(s) have an invoice number. They remain on the existing room-by-room workflow.</p>@endif
    <div class="table-wrap"><table class="table"><thead><tr><th scope="col">Folio</th><th scope="col">Context</th><th scope="col">Status / document</th><th scope="col" class="num">Posted balance</th></tr></thead><tbody>
      @foreach($summary['details'] as $detail)
      <tr><td><a href="{{ route('folios.show', $detail['id']) }}">{{ $detail['number'] }}</a></td>
        <td>@if($detail['stay_id']){{ $detail['room_no'] ? 'Room '.$detail['room_no'] : 'Stay #'.$detail['stay_id'] }} @else Reservation / unassigned @endif</td>
        <td>{{ ucfirst($detail['status']) }} @if($detail['type'] === 'credit_note') · Credit note @endif @if($detail['invoice_no']) · {{ $detail['invoice_no'] }} @endif</td>
        <td class="num">{{ $detail['balance'] < 0 ? money(abs($detail['balance'])).' credit' : money($detail['balance']) }}</td></tr>
      @endforeach
    </tbody></table></div>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button></div>
</div></div>
@endif
