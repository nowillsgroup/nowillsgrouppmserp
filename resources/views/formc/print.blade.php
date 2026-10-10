@extends('layouts.print')
@section('title', 'Form C '.$f->form_no)
@section('doctitle', 'Form C')
@section('docmeta')<div class="small"><b>{{ $f->form_no }}</b></div><div class="small">Arrival report of a foreign national</div><div class="small">Rule 14, Registration of Foreigners Rules 1992</div>@endsection
@section('content')
@php $d = fn ($x) => $x ? $x->format('d M Y') : '—'; @endphp
<table class="table" style="margin-top:1rem" id="formcPrintTable">
  <tr><th colspan="4" style="background:#f1f5f9">Particulars of the foreigner</th></tr>
  <tr><th style="width:22%">Surname</th><td style="width:28%">{{ $f->surname ?: '—' }}</td><th style="width:22%">Given name(s)</th><td>{{ $f->given_name ?: '—' }}</td></tr>
  <tr><th>Gender</th><td>{{ \App\Models\FormCRecord::GENDERS[$f->gender] ?? '—' }}</td><th>Date of birth</th><td>{{ $d($f->dob) }}</td></tr>
  <tr><th>Nationality</th><td>{{ $f->nationality ?: '—' }}</td><th>Phone</th><td>{{ $f->phone ?: '—' }}</td></tr>
  <tr><th>Address abroad</th><td colspan="3">{{ $f->address_abroad ?: '—' }}</td></tr>
  <tr><th colspan="4" style="background:#f1f5f9">Passport &amp; visa</th></tr>
  <tr><th>Passport no.</th><td>{{ $f->passport_no ?: '—' }}</td><th>Place of issue</th><td>{{ $f->passport_place ?: '—' }}</td></tr>
  <tr><th>Date of issue</th><td>{{ $d($f->passport_issued_on) }}</td><th>Valid until</th><td>{{ $d($f->passport_expires_on) }}</td></tr>
  <tr><th>Visa no.</th><td>{{ $f->visa_no ?: '—' }}</td><th>Visa type</th><td>{{ \App\Models\FormCRecord::VISA_TYPES[$f->visa_type] ?? '—' }}</td></tr>
  <tr><th>Place of issue</th><td>{{ $f->visa_place ?: '—' }}</td><th>Issue · valid until</th><td>{{ $d($f->visa_issued_on) }} · {{ $d($f->visa_expires_on) }}</td></tr>
  <tr><th colspan="4" style="background:#f1f5f9">Travel &amp; stay</th></tr>
  <tr><th>Arrived in India</th><td>{{ $d($f->arrived_india_on) }}</td><th>Port of arrival</th><td>{{ $f->port_of_arrival ?: '—' }}</td></tr>
  <tr><th>Arrived from</th><td>{{ $f->arrived_from ?: '—' }}</td><th>Next destination</th><td>{{ $f->next_destination ?: '—' }}</td></tr>
  <tr><th>Purpose of visit</th><td>{{ \App\Models\FormCRecord::PURPOSES[$f->purpose] ?? '—' }}</td><th>Room</th><td>{{ $f->stay?->room?->room_no ?? '—' }}</td></tr>
  <tr><th>Arrival at hotel</th><td>{{ $f->checked_in_at?->format('d M Y H:i') ?? '—' }}</td><th>Expected departure</th><td>{{ $d($f->expected_checkout) }}</td></tr>
  <tr><th colspan="4" style="background:#f1f5f9">Filing</th></tr>
  <tr><th>Status</th><td>{{ \App\Models\FormCRecord::STATUSES[$f->status] ?? $f->status }}</td><th>FRRO reference</th><td><b>{{ $f->frro_ref ?: '—' }}</b></td></tr>
  <tr><th>Filed at</th><td>{{ $f->submitted_at?->format('d M Y H:i') ?? '—' }}</td><th>Filed by</th><td>{{ $f->submitter?->name ?? '—' }}</td></tr>
  @if($f->departed_at)<tr><th>Checked out</th><td>{{ $f->departed_at->format('d M Y H:i') }}</td><th>Departure reported</th><td>{{ $f->departure_submitted_at?->format('d M Y H:i') ?? '—' }}</td></tr>@endif
</table>
<div class="flex" style="justify-content:space-between;margin-top:2.5rem">
  <div class="small">Signature of the foreigner<br><br>______________________</div>
  <div class="small right">For {{ $hotel->name }} – signature of the manager<br><br>______________________</div>
</div>
<p class="tiny muted" style="margin-top:1rem">Office copy. The Form C is filed online at indianfrro.gov.in within 24 hours of arrival. Keep with the Form B register.</p>
@endsection
