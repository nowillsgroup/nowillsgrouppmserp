@extends('layouts.print')
@section('title', 'Currency surrender '.$s->surrender_no)
@section('doctitle', 'Currency Surrender')
@section('docmeta')<div class="small"><b>{{ $s->surrender_no }}</b></div><div class="small">{{ $s->date->format('d M Y') }}</div>@endsection
@section('content')
<p style="margin-top:1rem">Foreign currency handed over to <b>{{ $s->counterparty }}</b>{{ $s->reference ? ' – reference '.$s->reference : '' }}. Cedis received into {{ $s->paymentMode?->name ?? '—' }}.</p>
<table class="table" id="surrLines">
  <thead><tr><th>Currency</th><th class="num">Amount</th><th class="num">Avg cost</th><th class="num">Cost {{ hotel()->currency_symbol }}</th><th class="num">Bank rate</th><th class="num">Realised {{ hotel()->currency_symbol }}</th><th class="num">Gain / loss</th></tr></thead>
  @foreach($s->lines as $l)
    <tr><td>{{ $l->currency?->code }} {{ $l->currency?->name }}</td><td class="num">{{ number_format($l->fc_amount, 2) }}</td><td class="num">{{ rtrim(rtrim(number_format($l->cost_rate, 4), '0'), '.') }}</td>
      <td class="num">{{ money($l->cost) }}</td><td class="num">{{ rtrim(rtrim(number_format($l->rate, 4), '0'), '.') }}</td><td class="num">{{ money($l->realised) }}</td><td class="num">{{ money($l->realised - $l->cost) }}</td></tr>
  @endforeach
  <tr><th colspan="3" class="right">Total</th><th class="num">{{ money($s->total_cost) }}</th><th></th><th class="num">{{ money($s->total_realised) }}</th><th class="num">{{ money($s->gain) }}</th></tr>
</table>
@if($s->notes)<p class="small">{{ $s->notes }}</p>@endif
<div class="flex" style="justify-content:space-between;margin-top:2.5rem">
  <div class="small">Handed over by {{ $s->creator?->name }}<br><br>______________________</div>
  <div class="small right">Received for {{ $s->counterparty }}<br><br>______________________</div>
</div>
<p class="no-print small" style="margin-top:1rem"><a href="{{ route('fg.position') }}">← Back to the position</a></p>
@endsection
