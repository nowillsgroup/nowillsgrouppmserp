@extends('layouts.print')
@section('title', 'Proposal '.$o->number)
@section('doctitle', 'Proposal')
@section('docmeta') {{ $o->number }} · {{ bdate()->format('d M Y') }} @endsection
@section('content')
@php $h = $currentHotel; @endphp
<p><b>{{ $o->contact?->name ?? $o->company->contact_person ?? 'The Travel Manager' }}</b>@if($o->contact?->title)<br>{{ $o->contact->title }}@endif<br>{{ $o->company->name }}@if($o->company->address)<br>{{ $o->company->address }}@endif{{ $o->company->city ? ', '.$o->company->city : '' }}</p>
<p>Dear {{ $o->contact ? \Illuminate\Support\Str::before($o->contact->name, ' ') : 'Sir / Madam' }},</p>
<p>Thank you for considering {{ $h->name }}. We are pleased to propose the following for <b>{{ $o->name }}</b>.</p>
<table class="table" id="proposalTable">
  <tr><th style="width:35%">Type of business</th><td>{{ $o->typeLabel() }}</td></tr>
  @if($o->arrival)<tr><th>{{ in_array($o->type, ['corporate', 'agency', 'long_stay']) ? 'Validity' : 'Dates' }}</th><td>{{ $o->arrival->format('d M Y') }} – {{ $o->departure->format('d M Y') }}</td></tr>@endif
  @if($o->room_nights)<tr><th>Room nights</th><td>{{ number_format($o->room_nights) }}</td></tr><tr><th>Average room rate</th><td>{{ money($o->adr()) }} per room per night</td></tr>@endif
  @if((float) $o->rooms_revenue)<tr><th>Accommodation</th><td>{{ money($o->rooms_revenue) }}</td></tr>@endif
  @if((float) $o->fb_revenue)<tr><th>Food, beverage &amp; banqueting</th><td>{{ money($o->fb_revenue) }}</td></tr>@endif
  @if((float) $o->other_revenue)<tr><th>Other services</th><td>{{ money($o->other_revenue) }}</td></tr>@endif
  <tr><th>Estimated total</th><td><b>{{ money($o->total()) }}</b></td></tr>
</table>
<p class="small">Rates are quoted in Ghana cedis and are subject to VAT 15%, NHIL 2.5%, GETFund levy 2.5% and the 1% tourism levy where applicable. This proposal is valid until {{ $o->expected_close->format('d M Y') }} and is subject to availability at the time of confirmation.</p>
@if($o->notes)<p><b>Requirements noted:</b> {{ $o->notes }}</p>@endif
<p>We would be delighted to arrange a site inspection at your convenience.</p>
<p>Yours sincerely,<br><br><b>{{ $o->owner?->name }}</b><br>Sales, {{ $h->name }}@if($o->owner?->email)<br>{{ $o->owner->email }}@endif</p>
@endsection
