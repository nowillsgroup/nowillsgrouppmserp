@extends('layouts.print')
@section('title', 'Encashment certificate '.$x->exchange_no)
@section('doctitle', 'Encashment Certificate')
@section('docmeta')<div class="small">No. <b>{{ $x->exchange_no }}</b></div><div class="small">{{ $x->business_date->format('d M Y') }}</div>@if($x->certificate_prints > 1)<div class="small text-red">Duplicate ({{ $x->certificate_prints }})</div>@endif @endsection
@section('content')
<p style="margin-top:1rem">Certified that we have purchased the foreign currency below from <b>{{ $x->guest_name }}</b>{{ $x->nationality ? ', a national of '.$x->nationality : '' }}, holder of passport no. <b>{{ $x->passport_no }}</b>{{ $x->stay?->room ? ', staying in room '.$x->stay->room->room_no : '' }}.</p>
<table class="table" id="certTable">
  <thead><tr><th>Currency</th><th>Instrument</th><th class="num">Amount</th><th class="num">Rate ({{ hotel()->currency_symbol }})</th><th class="num">Cedi equivalent</th></tr></thead>
  <tr><td>{{ $x->currency?->name }} ({{ $x->currency?->code }})</td><td>{{ $x->instrument === 'tc' ? 'Travellers’ cheques' : 'Currency notes' }}</td><td class="num">{{ number_format($x->fc_amount, 2) }}</td>
    <td class="num">{{ rtrim(rtrim(number_format($x->rate, 4), '0'), '.') }}{{ $x->unit > 1 ? ' / '.$x->unit : '' }}</td><td class="num">{{ money($x->inr_amount) }}</td></tr>
  @if($x->fee > 0)<tr><td colspan="4" class="right">Less service fee</td><td class="num">{{ money($x->fee) }}</td></tr>@endif
  @if($x->gst > 0)<tr><td colspan="4" class="right">Less tax (pre-v4.3 record)</td><td class="num">{{ money($x->gst) }}</td></tr>@endif
  <tr><th colspan="4" class="right">{{ $x->payout === 'cash' ? 'Paid in cash' : 'Credited to the room bill' }}</th><th class="num">{{ money($x->net_amount) }}</th></tr>
</table>
@if(class_exists('NumberFormatter'))<p class="small" style="margin-top:.8rem">Amount in words: <b>{{ ucfirst((new \NumberFormatter('en_GB', \NumberFormatter::SPELLOUT))->format(floor((float) $x->net_amount))) }} Ghana cedis{{ fmod((float) $x->net_amount, 1) > 0 ? ' and '.round(fmod((float) $x->net_amount, 1) * 100).' pesewas' : '' }} only</b></p>@endif
<p class="small">This encashment certificate is issued under the Bank of Ghana rules on foreign exchange dealings. Keep it with your travel documents as proof of the exchange.</p>
@if($cfg['licence'] || $cfg['franchiser'])<p class="small">{{ $cfg['licence'] ? 'BoG licence / agency no. '.$cfg['licence'] : '' }}{{ $cfg['licence'] && $cfg['franchiser'] ? ' · ' : '' }}{{ $cfg['franchiser'] ? 'On behalf of: '.$cfg['franchiser'] : '' }}</p>@endif
<div class="flex" style="justify-content:space-between;margin-top:2.5rem">
  <div class="small">Guest's signature<br><br>______________________</div>
  <div class="small right">For {{ $hotel->name }}<br>{{ $x->creator?->name }}<br>______________________</div>
</div>
@endsection
