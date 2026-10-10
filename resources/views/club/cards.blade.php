@extends('layouts.print')
@section('title', 'Member cards – '.$m->membership_no)
@section('nohead', true)
@section('content')
<style>@media print { @page { size: A4; margin: 12mm } .print-page{box-shadow:none;padding:0} }</style>
<h3 class="no-print" style="margin-top:0">{{ $m->membership_no }} – {{ $m->holder_name }} · CR80 cards (85.6 × 54 mm)</h3>
<div class="club-cards" id="cardSheet">
@foreach($persons as $p)
  <div class="club-card" data-card="{{ $p->card_no }}">
    <div class="top"><b>{{ $hotel->name }}</b><span>Health Club</span></div>
    <div class="mid">
      @if($photos[$p->id])<img class="ph" src="{{ $photos[$p->id] }}" alt="">@else<div class="ph">{{ $p->initials() }}</div>@endif
      <div class="who"><div class="nm">{{ $p->name }}</div><div>{{ $m->plan->name }}</div><div>{{ \App\Models\ClubMember::RELATIONS[$p->relation] }} · {{ $m->membership_no }}</div>@if($m->plan->hoursLabel())<div>Access {{ $m->plan->hoursLabel() }}</div>@endif</div>
      <div class="qr">{!! $qr[$p->id] !!}</div>
    </div>
    <div class="no">{{ trim(chunk_split($p->card_no, 4, ' ')) }}</div>
    <div class="exp">Member since<br><b>{{ $m->start_date->format('m/Y') }}</b></div>
  </div>
@endforeach
</div>
<p class="tiny muted no-print" style="margin-top:6mm">The QR code holds the card number – scan it into the check-in desk search box. Validity is checked live at the desk, so the card does not show an expiry date and stays valid across renewals. Report lost cards at reception: a new number is issued and the old card stops working.</p>
@endsection
