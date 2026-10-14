@extends('layouts.app')
@section('title', 'Supplier Payment '.$p->payment_no)
@section('crumbs') <a href="{{ route('ap.payments') }}">Supplier Payments</a> / {{ $p->payment_no }} @endsection
@section('actions')
@if($p->cancelled_at)<span class="badge badge-red">Cancelled</span>@endif
<a class="btn" href="{{ route('ap.payments.show', [$p, 'print' => 1]) }}" target="_blank"><x-icon name="print"/> Remittance advice</a>
@if($p->wht_cert_no && ! $p->cancelled_at)<a class="btn" id="whtCertBtn" href="{{ route('ap.payments.certificate', $p) }}" target="_blank"><x-icon name="receipt"/> WHT certificate</a>@endif
<a class="btn" href="{{ route('ap.statement', $p->supplier_id) }}"><x-icon name="book"/> Statement</a>
@if(! $p->cancelled_at && can_do('ap.pay'))<button class="btn btn-danger" data-modal="cancelModal"><x-icon name="x"/> Cancel payment</button>@endif
@endsection
@section('content')
@if($p->cancelled_at)<div class="alert alert-error"><x-icon name="x"/><div><b>Cancelled</b> {{ $p->cancelled_at->format('d M Y H:i') }} – {{ $p->cancel_reason }}</div></div>@endif
<div class="card"><div class="card-body grid g-3">
  <dl class="kv"><dt>Supplier</dt><dd><b>{{ $p->supplier->name }}</b></dd><dt>Date</dt><dd>{{ $p->date->format('d M Y') }}</dd><dt>Paid by</dt><dd>{{ $p->paymentMode?->name }}</dd><dt>Reference</dt><dd>{{ $p->reference ?? '—' }}</dd></dl>
  <dl class="kv"><dt>Recorded by</dt><dd>{{ $p->creator?->name }} · {{ $p->created_at->format('d M H:i') }}</dd>@if($p->notes)<dt>Notes</dt><dd>{{ $p->notes }}</dd>@endif
    <dt>Ledger</dt><dd>@forelse($journals as $j)<a href="{{ route('gl.journals.show', $j) }}">{{ $j->journal_no }}</a>{{ $j->reversal_of_id ? ' (reversal)' : '' }} @empty<span class="muted small">posts after the night audit closes {{ $p->date->format('d M') }}</span>@endforelse</dd></dl>
  @php $diff = $p->fxDifference(); @endphp
  <div class="ap-summary">
    <div><div class="l">{{ $p->isForeign() ? 'Cedis this cost' : 'Amount' }}</div><div class="v">{{ money($p->amount) }}</div>@if($p->isForeign())<div class="tiny muted">{{ $p->fxLabel() }} @ {{ number_format((float) $p->fx_rate, 4) }}</div>@endif</div>
    <div><div class="l">Allocated</div><div class="v green">{{ money($p->allocated) }}</div>@if($p->isForeign())<div class="tiny muted">what the bills were booked at</div>@endif</div>
    @if($p->isForeign())
    <div><div class="l">Realised exchange {{ $diff > 0 ? 'loss' : ($diff < 0 ? 'gain' : 'difference') }}</div><div class="v {{ $diff > 0 ? 'red' : 'green' }}">{{ money(abs($diff)) }}</div><div class="tiny muted">posted to Exchange Gain / Loss</div></div>
    @else
    <div><div class="l">Unapplied credit</div><div class="v" id="payUnapplied">{{ money($p->unapplied()) }}</div></div>
    @endif
  </div>
  @if($p->isForeign())
  <dl class="kv" id="fxPayPanel" style="grid-column:1/-1"><dt>Paid in</dt><dd class="bold">{{ $p->currency }}</dd>
    <dt>Bank's figure</dt><dd>{{ $p->fxLabel() }}</dd>
    <dt>Rate used</dt><dd>{{ number_format((float) $p->fx_rate, 4) }} {{ strtoupper((string) (hotel()?->currency ?: 'GHS')) }} per {{ $p->currency }} <span class="small muted">{{ $p->fx_basis === 'deal' ? "– the bank's deal rate, typed from its advice" : '– the '.($p->fx_basis === 'buy' ? 'buy' : 'reference').' board of '.$p->fx_date->format('d M Y') }}</span></dd>
    <dt>How it posted</dt><dd class="small">Dr Accounts Payable {{ money($p->allocated) }}@if($diff > 0) · Dr Exchange Gain / Loss {{ money($diff) }}@elseif($diff < 0) · Cr Exchange Gain / Loss {{ money(abs($diff)) }}@endif · Cr {{ $p->paymentMode?->name ?? 'bank' }} {{ money($p->netPaid()) }}@if($p->withheld() > 0) · Cr tax withheld {{ money($p->withheld()) }}@endif</dd>
    <dt>Why</dt><dd class="small muted">The GHS allocation is the sum of the bills at their own invoice-date rates; the bank transferred {{ $p->currency }} {{ number_format((float) $p->fx_amount, 2) }} at {{ number_format((float) $p->fx_rate, 4) }} on the payment date. Add any separately withheld GRA tax, then compare with those booked allocations: the difference is a realised {{ $diff > 0 ? 'loss' : 'gain' }}, so the supplier is owed nothing more and Accounts Payable still equals the sum of the bills.</dd></dl>
  @endif
  @if($p->wht_category || $p->withheld() > 0)
  <dl class="kv" id="whtPanel" style="grid-column:1/-1"><dt>Withholding tax</dt><dd>@if((float) $p->wht_amount > 0)<b>{{ money($p->wht_amount) }}</b> · {{ \App\Services\WhtService::label($p->wht_category) }} at {{ rtrim(rtrim(number_format($p->wht_rate, 2), '0'), '.') }}% of {{ money($p->wht_base) }}@else<span class="muted">none</span>@endif
      @if($p->wht_note)<div class="small muted">{{ $p->wht_note }}</div>@endif</dd>
    @if((float) $p->wvat_amount > 0)<dt>Withholding VAT</dt><dd>{{ money($p->wvat_amount) }} <span class="small muted">({{ money($p->wvat_base) }} taxable value)</span></dd>@endif
    @if($p->withheld() > 0)<dt>Paid to supplier</dt><dd class="bold" id="payNet">{{ money($p->netPaid()) }}</dd><dt>Certificate</dt><dd class="mono">{{ $p->wht_cert_no }}</dd>@endif</dl>
  @endif
</div></div>
<div class="card flush"><div class="card-head"><h3>Bills settled</h3></div><div class="card-body table-wrap"><table class="table" id="payAllocs">
<thead><tr><th>Bill</th><th>Supplier invoice</th><th>Bill date</th><th class="num">Bill total</th><th class="num">Paid here</th><th>Bill status</th></tr></thead><tbody>
@forelse($p->allocations as $a)
<tr><td><a class="bold" href="{{ route('ap.bills.show', $a->invoice) }}">{{ $a->invoice->doc_no }}</a></td><td>{{ $a->invoice->supplier_ref }}</td><td>{{ $a->invoice->doc_date->format('d M Y') }}</td><td class="num">{{ money($a->invoice->total, false) }}</td><td class="num bold">{{ money($a->amount, false) }}</td><td>{!! status_badge($a->invoice->status) !!}</td></tr>
@empty<tr><td colspan="6" class="empty">{{ $p->cancelled_at ? 'Allocations were released when the payment was cancelled.' : 'Paid on account – apply the credit from a bill page.' }}</td></tr>@endforelse
</tbody></table></div></div>
<div class="modal" id="cancelModal"><div class="modal-box"><form method="post" action="{{ route('ap.payments.cancel', $p) }}">@csrf
<div class="modal-head"><h3>Cancel {{ $p->payment_no }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
<div class="modal-body"><p class="small">The bills it settled become open again and the ledger posting is reversed. Use this for bounced cheques or payments entered in error.</p>
<div class="field"><label>Reason <span class="req">*</span></label><input name="reason" required maxlength="200" class="input"></div></div>
<div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-danger">Cancel payment</button></div></form></div></div>
@endsection
