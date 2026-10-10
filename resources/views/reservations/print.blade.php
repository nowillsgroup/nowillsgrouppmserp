@extends('layouts.print')
@section('title', 'Reservation '.$r->reservation_no)
@section('doctitle', 'Booking Confirmation')
@section('docmeta')<div class="small">No: <b>{{ $r->reservation_no }}</b><br>Date: {{ $r->created_at->format('d M Y') }}<br>Status: {{ ucwords(str_replace('_',' ',$r->status)) }}</div>@endsection
@section('content')
<p>Dear {{ $r->guest->full_name }},</p>
<p>Thank you for choosing {{ $currentHotel->name }}. We are pleased to confirm your reservation as below.</p>
<div class="grid g-2 mb-2">
  <dl class="kv"><dt>Guest</dt><dd>{{ $r->guest->full_name }}</dd><dt>Mobile</dt><dd>{{ $r->guest->phone }}</dd><dt>Email</dt><dd>{{ $r->guest->email }}</dd>@if($r->company)<dt>Company</dt><dd>{{ $r->company->name }}</dd>@endif</dl>
  <dl class="kv"><dt>Arrival</dt><dd>{{ $r->arrival_date->format('D, d M Y') }} (Check-in {{ substr($currentHotel->checkin_time,0,5) }})</dd><dt>Departure</dt><dd>{{ $r->departure_date->format('D, d M Y') }} (Check-out {{ substr($currentHotel->checkout_time,0,5) }})</dd><dt>Nights</dt><dd>{{ $r->nights }}</dd><dt>Guests</dt><dd>{{ $r->adults }} Adults, {{ $r->children }} Children</dd></dl>
</div>
<table class="table"><thead><tr><th>#</th><th>Room Type</th><th>Meal Plan</th><th>Pax</th><th class="num">Rate/Night</th><th class="num">Nights</th><th class="num">Amount</th></tr></thead><tbody>
@php $tot = 0; @endphp
@foreach($r->rooms->whereNotIn('status',['cancelled']) as $i => $s) @php $amt = ($s->rate + $s->extra_charge) * $s->nights; $tot += $amt; @endphp
<tr><td>{{ $loop->iteration }}</td><td>{{ $s->roomType->name }} {{ $s->room ? '('.$s->room->room_no.')' : '' }}</td><td>{{ $s->ratePlan?->meal_plan ?? 'EP' }}</td><td>{{ $s->adults }}/{{ $s->children }}</td><td class="num">{{ money($s->rate + $s->extra_charge) }}</td><td class="num">{{ $s->nights }}</td><td class="num">{{ money($amt) }}</td></tr>
@endforeach
</tbody><tfoot>
<tr><td colspan="6" class="right">Total (excl. taxes)</td><td class="num">{{ money($tot) }}</td></tr>
@php $adv = $r->folios->flatMap->transactions->where('is_void',false)->where('type','payment')->sum('amount'); @endphp
<tr><td colspan="6" class="right">Advance received</td><td class="num">{{ money($adv) }}</td></tr>
</tfoot></table>
@if($r->special_request)<p class="mt-2"><b>Special requests:</b> {{ $r->special_request }}</p>@endif
<p class="small text-2 mt-2">Taxes applicable as per government norms. Valid photo ID is mandatory at check-in for all guests. Cancellation policy: free cancellation up to 48 hours prior to arrival.</p>
<p class="mt-3">Warm regards,<br><b>Reservations Team</b><br>{{ $currentHotel->name }}</p>
@endsection
