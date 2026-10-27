@extends('emails.layout')
@section('body')
@php $r = $reservation; $sym = $hotel->currency_symbol; $paid = $r->folios->flatMap->transactions->where('is_void', false)->where('type', 'payment')->sum('amount'); $total = $r->rooms->where('status', '!=', 'cancelled')->sum(fn($s) => $s->rate * max(1, $s->arrival_date->diffInDays($s->departure_date))); @endphp
<p style="font-size:16px;margin:0 0 12px">Dear {{ $r->guest?->full_name ?? 'Guest' }},</p>
@if($r->status === 'cancelled')
<p>Your booking <b>{{ $r->reservation_no }}</b> has been <b style="color:#b91c1c">cancelled</b>. If this is unexpected, please contact us.</p>
@else
<p>Thank you for choosing {{ $hotel->name }}. We are pleased to {{ $r->status === 'tentative' ? 'hold' : 'confirm' }} your reservation:</p>
@endif
<table width="100%" cellpadding="6" cellspacing="0" style="border-collapse:collapse;margin:14px 0;background:#f8fafc;border-radius:8px">
  <tr><td style="color:#64748b;width:40%">Confirmation no.</td><td><b style="font-size:16px">{{ $r->reservation_no }}</b> <span style="font-size:11px;background:#dbeafe;color:#1d4ed8;padding:2px 8px;border-radius:10px">{{ ucfirst(str_replace('_', ' ', $r->status)) }}</span></td></tr>
  <tr><td style="color:#64748b">Arrival</td><td><b>{{ $r->arrival_date->format('D, d M Y') }}</b> from {{ \Carbon\Carbon::parse($hotel->checkin_time)->format('H:i') }}</td></tr>
  <tr><td style="color:#64748b">Departure</td><td><b>{{ $r->departure_date->format('D, d M Y') }}</b> by {{ \Carbon\Carbon::parse($hotel->checkout_time)->format('H:i') }}</td></tr>
  <tr><td style="color:#64748b">Nights</td><td>{{ $r->nights }}</td></tr>
</table>
<table width="100%" cellpadding="8" cellspacing="0" style="border-collapse:collapse;font-size:13px">
  <tr style="background:#1e293b;color:#fff"><th align="left">Room</th><th align="left">Guests</th><th align="right">Rate / night</th></tr>
  @foreach($r->rooms->where('status', '!=', 'cancelled') as $s)
  <tr style="border-bottom:1px solid #e2e8f0"><td>{{ $s->roomType?->name }}@if($s->room) · Room {{ $s->room->room_no }}@endif</td><td>{{ $s->adults }} adult(s){{ $s->children ? ', '.$s->children.' child' : '' }}</td><td align="right">{{ $sym }}{{ number_format($s->rate, 2) }}</td></tr>
  @endforeach
  <tr><td colspan="2" align="right" style="color:#64748b">Estimated room charges (before tax)</td><td align="right"><b>{{ $sym }}{{ number_format($total, 2) }}</b></td></tr>
  @if($paid > 0)<tr><td colspan="2" align="right" style="color:#64748b">Advance received</td><td align="right" style="color:#15803d"><b>{{ $sym }}{{ number_format($paid, 2) }}</b></td></tr>@endif
</table>
@if($r->status === 'cancelled' && $r->cancellation_fee > 0)<p>A cancellation charge of <b>{{ $sym }}{{ number_format($r->cancellation_fee, 2) }}</b> applies as per our policy.</p>@endif
@if($r->onlineCheckinOpen())
<table cellpadding="0" cellspacing="0" style="margin:18px 0"><tr><td style="background:#166534;border-radius:8px"><a href="{{ $r->checkinUrl() }}" style="display:inline-block;padding:11px 22px;color:#fff;text-decoration:none;font-weight:bold">Check in online now</a></td></tr></table>
<p style="font-size:12px;color:#64748b;margin-top:-8px">Save time at the front desk: confirm your details, upload your ID and sign your registration card before you arrive.</p>
@endif
@if(setting('booking_policy'))<p style="font-size:12px;color:#64748b;margin-top:18px"><b>Cancellation policy:</b> {!! nl2br(e(setting('booking_policy'))) !!}</p>@endif
<p style="margin-top:18px">We look forward to welcoming you.<br>Warm regards,<br><b>Reservations, {{ $hotel->name }}</b></p>
@endsection
