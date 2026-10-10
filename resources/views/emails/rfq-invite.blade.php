@extends('emails.layout')
@section('body')
<p style="font-size:16px;margin:0 0 4px"><b>Request for quotation {{ $rfq->rfq_no }}</b></p>
<p style="margin:0 0 14px;color:#64748b">{{ $rfq->title }} · please quote by <b>{{ $rfq->respond_by->format('D d M Y') }}</b>@if($rfq->needed_by) · delivery required by {{ $rfq->needed_by->format('d M Y') }}@endif</p>
<p>Dear {{ $invite->supplier->contact_person ?: $invite->supplier->name }},</p>
<p>{{ $hotel->name }} invites you to quote for the items below. Use the button to enter your prices online – no login is needed and you can revise your quote until the deadline.</p>
<table width="100%" cellpadding="6" cellspacing="0" style="border-collapse:collapse;font-size:13px">
  <tr style="background:#1e293b;color:#fff"><th align="left">#</th><th align="left">Item</th><th align="right">Quantity</th><th align="left">Specification</th></tr>
  @foreach($lines as $l)
  <tr style="border-bottom:1px solid #e2e8f0"><td>{{ $loop->iteration }}</td><td>{{ $l->item->name }}</td><td align="right">{{ (float) $l->qty }} {{ $l->item->unit }}</td><td>{{ $l->specification }}</td></tr>
  @endforeach
</table>
<p style="text-align:center;margin:22px 0"><a href="{{ $link }}" style="background:#0f766e;color:#fff;padding:11px 22px;border-radius:8px;text-decoration:none;font-weight:700">Submit your quotation</a></p>
@if($rfq->terms)<p style="font-size:12px;color:#475569;white-space:pre-line"><b>Terms:</b> {{ $rfq->terms }}</p>@endif
<p style="font-size:12px;color:#64748b">This link is private to your company. Prices in Ghana cedis (GH₵); state VAT / NHIL / GETFund separately as the tax %.</p>
@endsection
