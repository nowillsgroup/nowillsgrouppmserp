@extends('layouts.print')
@section('title', 'Booking confirmation · '.$r->reservation_no)
@section('doctitle', 'Booking Confirmation')
@section('pageclass', 'booking-confirmation')
@section('backurl', route('reservations.show', $r))
@section('docmeta')
  <div class="bc-docmeta">
    <span class="bc-status bc-status--{{ $r->status }}">{{ ucwords(str_replace('_', ' ', $r->status)) }}</span>
    <span>Booked on {{ $r->created_at?->format('d M Y') ?? bdate()->format('d M Y') }}</span>
    <strong>{{ $r->reservation_no }}</strong>
  </div>
@endsection
@push('head')
<style>
  .print-page.booking-confirmation { max-width: 900px; padding: 2rem 2.35rem; }
  .booking-confirmation .print-head { align-items: center; border-bottom: 2px solid #166534; }
  .booking-confirmation .print-head h2 { color: #14532d; }
  .bc-docmeta { display: grid; justify-items: end; gap: .35rem; margin-top: .45rem; font-size: .8rem; color: #475569; }
  .bc-status { display: inline-flex; align-items: center; border: 1px solid #bbf7d0; border-radius: 999px; padding: .16rem .65rem; background: #f0fdf4; color: #166534; font-size: .72rem; font-weight: 750; text-transform: uppercase; letter-spacing: .045em; }
  .bc-status--tentative { background: #fffbeb; border-color: #fde68a; color: #92400e; }
  .bc-status--cancelled, .bc-status--no_show { background: #fef2f2; border-color: #fecaca; color: #991b1b; }
  .bc-intro { margin: 1.15rem 0; }
  .bc-intro p { margin-bottom: .4rem; }
  .bc-ref { display: flex; align-items: center; justify-content: space-between; gap: 1rem; border: 1px solid #bbd9c1; border-left: 4px solid #166534; border-radius: 8px; background: #f4faf5; padding: .7rem 1rem; margin: 1rem 0 1.1rem; }
  .bc-ref-label, .bc-kicker { color: #54705d; font-size: .68rem; font-weight: 750; letter-spacing: .09em; text-transform: uppercase; }
  .bc-ref-number { color: #14532d; font-size: 1.2rem; font-weight: 800; letter-spacing: .035em; }
  .bc-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .85rem; margin: .9rem 0 1.25rem; }
  .bc-card { min-width: 0; border: 1px solid #d9e2da; border-radius: 8px; padding: .85rem 1rem; background: #fbfdfb; }
  .bc-card h3, .bc-section-title { margin: 0 0 .65rem; color: #14532d; font-size: .76rem; font-weight: 800; letter-spacing: .075em; text-transform: uppercase; }
  .bc-details { display: grid; grid-template-columns: minmax(5.8rem, auto) minmax(0, 1fr); gap: .32rem .65rem; margin: 0; font-size: .84rem; }
  .bc-details dt { color: #64748b; }
  .bc-details dd { margin: 0; overflow-wrap: anywhere; font-weight: 600; }
  .bc-rooms-wrap { margin-top: 1.15rem; }
  .bc-rooms { width: 100%; border-collapse: collapse; font-size: .79rem; }
  .bc-rooms th { padding: .55rem .48rem; background: #eef5ef; color: #244b31; border-top: 1px solid #cdddcf; border-bottom: 1px solid #cdddcf; font-size: .68rem; text-align: left; }
  .bc-rooms td { padding: .55rem .48rem; border-bottom: 1px solid #e2e8e3; vertical-align: top; }
  .bc-rooms tbody tr:nth-child(even) { background: #fbfdfb; }
  .bc-rooms .num { text-align: right; white-space: nowrap; }
  .bc-rooms .room-name { font-weight: 700; color: #1f3526; }
  .bc-rooms tfoot th, .bc-rooms tfoot td { border-top: 1px solid #d9e2da; padding: .5rem .48rem; text-align: right; }
  .bc-rooms tfoot tr:first-child th, .bc-rooms tfoot tr:first-child td { border-top: 2px solid #166534; font-weight: 800; }
  .bc-rooms tfoot tr.bc-tax-component th, .bc-rooms tfoot tr.bc-tax-component td { border-top: 0; padding-top: .25rem; padding-bottom: .25rem; color: #475569; font-weight: 500; }
  .bc-rooms tfoot tr.bc-tax-subtotal th, .bc-rooms tfoot tr.bc-tax-subtotal td { border-top: 1px solid #d9e2da; color: #334155; font-weight: 700; }
  .bc-rooms tfoot tr.bc-tax-total th, .bc-rooms tfoot tr.bc-tax-total td { border-top: 2px solid #166534; color: #14532d; font-weight: 800; }
  .bc-tax-note { margin: .45rem 0 0; color: #64748b; font-size: .7rem; }
  .bc-money-summary { display: flex; justify-content: flex-end; margin-top: .7rem; }
  .bc-money-summary dl { display: grid; grid-template-columns: minmax(9rem, auto) minmax(6rem, auto); gap: .35rem .9rem; margin: 0; font-size: .8rem; }
  .bc-money-summary dt { color: #64748b; }
  .bc-money-summary dd { margin: 0; text-align: right; font-weight: 700; }
  .bc-note { margin-top: 1rem; padding: .75rem .9rem; border-left: 3px solid #85b891; background: #f5faf5; font-size: .82rem; overflow-wrap: anywhere; }
  .bc-note strong { color: #14532d; }
  .bc-footer { margin-top: 1.4rem; padding-top: .8rem; border-top: 1px solid #d9e2da; color: #475569; font-size: .78rem; }
  @media (max-width: 620px) {
    .print-page.booking-confirmation { padding: 1.2rem; }
    .booking-confirmation .print-head { align-items: flex-start; flex-direction: column; }
    .bc-docmeta { justify-items: start; }
    .bc-grid { grid-template-columns: 1fr; }
    .bc-rooms { font-size: .7rem; }
    .bc-rooms th, .bc-rooms td { padding: .4rem .28rem; }
  }
  @page { size: A4; margin: 14mm; }
  @media print {
    .print-page.booking-confirmation { width: 100%; max-width: none; padding: 0; margin: 0; border: 0; border-radius: 0; }
    .booking-confirmation .print-head { margin-bottom: .85rem; }
    .bc-card, .bc-ref, .bc-note { break-inside: avoid; page-break-inside: avoid; }
    .bc-rooms thead { display: table-header-group; }
    .bc-rooms tr { break-inside: avoid; page-break-inside: avoid; }
    .bc-status, .bc-rooms th { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  }
</style>
@endpush
@section('content')
@php
  $activeRooms = $r->rooms->where('status', '!=', 'cancelled')->values();
  $taxSummary = \App\Support\BookingConfirmationTax::summarize($activeRooms);
  $roomTotal = $taxSummary['subtotal'];
  $transactions = $r->folios->flatMap->transactions->where('is_void', false);
  $paymentsReceived = (float) $transactions->where('type', 'payment')->sum('amount');
  $refundsIssued = (float) $transactions->where('type', 'refund')->sum('amount');
  $bookingPolicy = trim((string) setting('booking_policy'));
  $arrivalTime = substr((string) ($currentHotel->checkin_time ?? ''), 0, 5) ?: '—';
  $departureTime = substr((string) ($currentHotel->checkout_time ?? ''), 0, 5) ?: '—';
@endphp
<div class="bc-intro">
  <p><strong>Dear {{ $r->guest?->full_name ?? 'Guest' }},</strong></p>
  @if($r->status === 'cancelled')
    <p>This reservation has been cancelled. Keep this confirmation as a record of the booking.</p>
  @elseif($r->status === 'tentative')
    <p>Thank you for choosing {{ $currentHotel->name }}. We are holding this reservation while it awaits confirmation.</p>
  @elseif($r->status === 'no_show')
    <p>This reservation was marked as a no-show. The details below are provided for your records.</p>
  @else
    <p>Thank you for choosing {{ $currentHotel->name }}. This document confirms the details of your reservation.</p>
  @endif
</div>
<div class="bc-ref" aria-label="Reservation reference">
  <span><span class="bc-ref-label">Confirmation number</span><br><span class="bc-ref-number">{{ $r->reservation_no }}</span></span>
  <span class="bc-kicker">{{ ucwords(str_replace('_', ' ', $r->status)) }}</span>
</div>
<div class="bc-grid">
  <section class="bc-card" aria-labelledby="bc-guest-title">
    <h3 id="bc-guest-title">Guest details</h3>
    <dl class="bc-details">
      <dt>Guest</dt><dd>{{ $r->guest?->full_name ?? '—' }}</dd>
      <dt>Mobile</dt><dd>{{ $r->guest?->phone ?: 'Not provided' }}</dd>
      <dt>Email</dt><dd>{{ $r->guest?->email ?: 'Not provided' }}</dd>
      @if($r->company?->name)<dt>Company</dt><dd>{{ $r->company->name }}</dd>@endif
    </dl>
  </section>
  <section class="bc-card" aria-labelledby="bc-stay-title">
    <h3 id="bc-stay-title">Stay summary</h3>
    <dl class="bc-details">
      <dt>Arrival</dt><dd>{{ $r->arrival_date?->format('D, d M Y') ?? '—' }} · Check-in {{ $arrivalTime }}</dd>
      <dt>Departure</dt><dd>{{ $r->departure_date?->format('D, d M Y') ?? '—' }} · Check-out {{ $departureTime }}</dd>
      <dt>Nights</dt><dd>@if($r->isDayUse())Day use{{ $r->rooms->first()?->dayUseHoursLabel() ? ' · '.$r->rooms->first()->dayUseHoursLabel() : '' }} · no night sold @else{{ $r->nights }}@endif</dd>
      <dt>Guests</dt><dd>{{ $r->adults }} adults{{ $r->children ? ', '.$r->children.' children' : '' }}</dd>
    </dl>
  </section>
</div>
<section class="bc-rooms-wrap" aria-labelledby="bc-rooms-title">
  <h3 class="bc-section-title" id="bc-rooms-title">Room allocation and rates</h3>
  <table class="bc-rooms">
    <caption class="sr-only">Room allocation, meal plan, guest count, nightly rate and room total</caption>
    <thead><tr>
      <th scope="col">#</th><th scope="col">Room / type</th><th scope="col">Meal plan</th><th scope="col">Guests</th>
      <th scope="col" class="num">Rate / night</th><th scope="col" class="num">Nights</th><th scope="col" class="num">Room total</th>
    </tr></thead>
    <tbody>
      @forelse($activeRooms as $stay)
        @php $nightlyRate = (float) $stay->rate + (float) $stay->extra_charge; $stayTotal = $nightlyRate * max(0, (int) $stay->nights); @endphp
        <tr>
          <td>{{ $loop->iteration }}</td>
          <td><span class="room-name">{{ $stay->roomType?->name ?? 'Room stay' }}</span><br><span class="small text-2">{{ $stay->room ? 'Room '.$stay->room->room_no : 'Room not yet assigned' }}</span></td>
          <td>{{ $stay->ratePlan?->meal_plan ?? 'EP' }}</td>
          <td>{{ $stay->adults }} adults{{ $stay->children ? ', '.$stay->children.' children' : '' }}</td>
          <td class="num">{{ money($nightlyRate) }}</td><td class="num">{{ $stay->is_day_use ? 'Day use' : $stay->nights }}</td><td class="num">{{ money($stayTotal) }}</td>
        </tr>
      @empty
        <tr><td colspan="7" class="text-2">No active room stays are listed for this reservation.</td></tr>
      @endforelse
    </tbody>
    <tfoot>
      <tr><th scope="row" colspan="6">Room total (excluding taxes)</th><td>{{ money($roomTotal) }}</td></tr>
      @forelse($taxSummary['components'] as $component)
        <tr class="bc-tax-component"><th scope="row" colspan="6">{{ $component['label'] }}</th><td>{{ money($component['amount']) }}</td></tr>
      @empty
        <tr class="bc-tax-component"><th scope="row" colspan="6">Room taxes</th><td>{{ money(0) }}</td></tr>
      @endforelse
      <tr class="bc-tax-subtotal"><th scope="row" colspan="6">Total room taxes</th><td>{{ money($taxSummary['tax_total']) }}</td></tr>
      <tr class="bc-tax-total"><th scope="row" colspan="6">Estimated room total (including taxes)</th><td>{{ money($taxSummary['total']) }}</td></tr>
    </tfoot>
  </table>
  <p class="bc-tax-note">Tax components are estimates based on the property’s current room-tax setup; actual taxes posted to the folio may differ.</p>
</section>
@if($paymentsReceived > 0 || $refundsIssued > 0)
  <div class="bc-money-summary" aria-label="Payments recorded on reservation folios"><dl>
    <dt>Payments received</dt><dd>{{ money($paymentsReceived) }}</dd>
    @if($refundsIssued > 0)<dt>Refunds recorded</dt><dd>{{ money($refundsIssued) }}</dd>@endif
  </dl></div>
@endif
@if($r->special_request)
  <div class="bc-note"><strong>Special requests</strong><br>{{ $r->special_request }}</div>
@endif
@if(in_array($r->status, ['confirmed', 'tentative']))
  <div class="bc-note"><strong>Before you arrive</strong><br>Please bring valid photo identification for each guest. Check-in is from {{ $arrivalTime }} and check-out is by {{ $departureTime }}.</div>
@endif
@if($bookingPolicy !== '')
  <div class="bc-note"><strong>Booking and cancellation policy</strong><br>{!! nl2br(e($bookingPolicy)) !!}</div>
@endif
<footer class="bc-footer">
  @if($r->status === 'checked_out')Thank you for staying with us.@elseif(in_array($r->status, ['cancelled', 'no_show']))For questions about this reservation, please contact the hotel using the details above.@else We look forward to welcoming you.@endif
  <br>Warm regards,<br><strong>Reservations Team · {{ $currentHotel->name }}</strong>
</footer>
@endsection
