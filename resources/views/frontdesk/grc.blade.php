@extends('layouts.print')
@section('title', 'GRC '.$stay->reservation->reservation_no)
@section('doctitle', 'Guest Registration Card')
@section('docmeta')<div class="small">Res: <b>{{ $stay->reservation->reservation_no }}</b><br>Room: <b>{{ $stay->room?->room_no }}</b> ({{ $stay->roomType->name }})</div>@endsection
@section('content')
<table class="table">
  <tr><th style="width:25%">Guest name</th><td>{{ $g->full_name }}</td><th>Gender</th><td>{{ $g->gender }}</td></tr>
  <tr><th>Mobile</th><td>{{ $g->phone }}</td><th>Email</th><td>{{ $g->email }}</td></tr>
  <tr><th>Address</th><td colspan="3">{{ $g->address }} {{ $g->city }} {{ $g->state }} {{ $g->country }} {{ $g->pincode }}</td></tr>
  <tr><th>Nationality</th><td>{{ $g->nationality }}</td><th>Date of birth</th><td>{{ dmy($g->dob) }}</td></tr>
  <tr><th>ID proof</th><td>{{ config('pms.id_types.'.$g->id_type) }} – {{ $g->id_number }}</td><th>Passport / Visa exp.</th><td>{{ $g->passport_no }} {{ $g->visa_expiry ? '/ '.dmy($g->visa_expiry) : '' }}</td></tr>
  <tr><th>Company</th><td>{{ $stay->reservation->company?->name }}</td><th>TIN</th><td>{{ $g->tax_number }}</td></tr>
  <tr><th>Arrival</th><td>{{ $stay->checked_in_at?->format('d M Y H:i') ?? $stay->arrival_date->format('d M Y') }}</td><th>Departure</th><td>{{ $stay->departure_date->format('d M Y') }}</td></tr>
  <tr><th>Pax</th><td>{{ $stay->adults }} Adults / {{ $stay->children }} Children</td><th>Tariff</th><td>{{ money($stay->rate) }} / night ({{ $stay->ratePlan?->meal_plan ?? 'EP' }})</td></tr>
  <tr><th>Purpose</th><td>{{ $stay->reservation->purpose }}</td><th>Source</th><td>{{ $stay->reservation->source?->name }}</td></tr>
</table>
<p class="small mt-2">@if(setting('grc_terms')){!! nl2br(e(setting('grc_terms'))) !!}<br>@endif I agree to abide by the hotel's terms & conditions. I am responsible for all charges incurred during my stay. The management is not responsible for valuables not deposited at the front desk. Check-out time is {{ substr($currentHotel->checkout_time,0,5) }}.</p>
@php $sig = $stay->grc_signature_path && \Illuminate\Support\Facades\Storage::disk('local')->exists($stay->grc_signature_path) ? base64_encode(\Illuminate\Support\Facades\Storage::disk('local')->get($stay->grc_signature_path)) : null; @endphp
@if($g->documents()->exists())<p class="small">ID documents on file: {{ $g->documents->map(fn($d) => \App\Models\GuestDocument::TYPES[$d->type] ?? $d->type)->unique()->join(', ') }}</p>@endif
<div class="flex justify-between mt-3" style="padding-top:{{ $sig ? '0.5rem' : '2.5rem' }};align-items:flex-end">
  <div>@if($sig)<img src="data:image/png;base64,{{ $sig }}" alt="Guest signature" style="height:70px;display:block">@endif ______________________<br>Guest signature @if($stay->grc_signed_at)<span class="small">(e-signed {{ $stay->grc_signed_at->format('d M Y H:i') }})</span>@endif</div>
  <div>______________________<br>Front office</div></div>
@endsection
