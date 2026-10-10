@extends('emails.layout')
@section('body')
@php $sym = $hotel->currency_symbol; @endphp
<p style="font-size:16px;margin:0 0 12px">Dear {{ $folio->guest?->full_name ?? $folio->company?->name ?? 'Guest' }},</p>
<p>Thank you for staying with us. Please find your {{ $folio->invoice_no ? 'invoice' : 'folio statement' }} below.</p>
<table width="100%" cellpadding="6" cellspacing="0" style="margin:12px 0;background:#f8fafc">
  <tr><td style="color:#64748b">{{ $folio->invoice_no ? 'Invoice no.' : 'Folio no.' }}</td><td><b>{{ $folio->invoice_no ?? $folio->folio_no }}</b></td>
      <td style="color:#64748b">Date</td><td>{{ ($folio->closed_at ?? now())->format('d M Y') }}</td></tr>
  @if($folio->stay)<tr><td style="color:#64748b">Room</td><td>{{ $folio->stay->room?->room_no }}</td><td style="color:#64748b">Stay</td><td>{{ $folio->stay->arrival_date->format('d M') }} – {{ $folio->stay->departure_date->format('d M Y') }}</td></tr>@endif
  @if($hotel->tax_number)<tr><td style="color:#64748b">Tax no.</td><td colspan="3">{{ $hotel->tax_number }}</td></tr>@endif
</table>
<table width="100%" cellpadding="7" cellspacing="0" style="border-collapse:collapse;font-size:13px">
  <tr style="background:#1e293b;color:#fff"><th align="left">Date</th><th align="left">Description</th><th align="right">Debit</th><th align="right">Credit</th></tr>
  @foreach($tx as $t)
  <tr style="border-bottom:1px solid #e2e8f0"><td>{{ $t->business_date->format('d M') }}</td><td>{{ $t->description }}@if($t->paymentMode) ({{ $t->paymentMode->name }})@endif</td>
    <td align="right">{{ in_array($t->type, ['charge', 'refund']) ? number_format($t->total, 2) : '' }}</td><td align="right">{{ in_array($t->type, ['payment', 'discount', 'allowance']) ? number_format($t->amount, 2) : '' }}</td></tr>
  @endforeach
  <tr><td colspan="2" align="right">Total charges (incl. tax {{ $sym }}{{ number_format($totals['tax'] ?? 0, 2) }})</td><td align="right"><b>{{ number_format($totals['charges'] + $totals['refunds'], 2) }}</b></td><td></td></tr>
  <tr><td colspan="2" align="right">Payments &amp; discounts</td><td></td><td align="right"><b>{{ number_format($totals['payments'] + $totals['discount'], 2) }}</b></td></tr>
  <tr style="background:#f1f5f9"><td colspan="2" align="right"><b>Balance {{ $totals['balance'] > 0 ? 'due' : '' }}</b></td><td colspan="2" align="right"><b style="font-size:15px">{{ $sym }}{{ number_format($totals['balance'], 2) }}</b></td></tr>
</table>
@if(setting('invoice_terms'))<p style="font-size:12px;color:#64748b;margin-top:16px">{!! nl2br(e(setting('invoice_terms'))) !!}</p>@endif
<p style="margin-top:18px">We hope to see you again soon.<br><b>{{ $hotel->name }}</b></p>
@endsection
