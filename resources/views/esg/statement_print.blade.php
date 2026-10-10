@extends('layouts.print')
@section('title', 'Carbon statement')
@section('doctitle', 'CARBON & WATER STATEMENT')
@section('docmeta')<div class="small"><b>{{ $st['party'] }}</b></div><div class="small">{{ $st['period'] }}</div><div class="small">Issued {{ bdate()->format('d M Y') }}</div>@endsection
@section('content')
<div class="esg-report" id="esgStatementPrint">
  <h2>{{ $st['title'] }}</h2>
  <div class="kpis"><div><b>{{ number_format($st['kg'], 1) }} kg</b><span>carbon footprint (CO₂e)</span></div><div><b>{{ number_format($st['litres'] / 1000, 2) }} m³</b><span>water</span></div>
    <div><b>{{ $st['nights'] }}</b><span>room-nights</span></div><div><b>{{ number_format($st['m2h']) }}</b><span>m² × hours of meeting space</span></div></div>
  <table><thead><tr><th>Item</th><th>Quantity</th><th>kg CO₂e</th><th>Litres</th></tr></thead><tbody>
    @foreach($st['lines'] as $l)<tr><td>{{ $l['label'] }}</td><td class="n">{{ number_format($l['qty'], $l['kind'] === 'room' ? 0 : 1) }} {{ $l['unit'] }}</td><td class="n">{{ number_format($l['kg'], 1) }}</td><td class="n">{{ number_format($l['litres']) }}</td></tr>@endforeach
    <tr><th>Total</th><th></th><th class="n">{{ number_format($st['kg'], 1) }}</th><th class="n">{{ number_format($st['litres']) }}</th></tr>
  </tbody></table>
  <h2>Method</h2>
  <p class="small">Calculated with the Hotel Carbon Measurement Initiative (HCMI) and Hotel Water Measurement Initiative (HWMI) methods, using this hotel's measured energy, fuel, refrigerant and outsourced-laundry footprint
    from {{ \Carbon\Carbon::parse($st['bench']['from'])->format('d M Y') }} to {{ \Carbon\Carbon::parse($st['bench']['to'])->format('d M Y') }}:
    <b>{{ number_format($st['bench']['per_room'], 2) }} kg CO₂e</b> and {{ number_format($st['bench']['hwmi_room'] ?? 0) }} L water per occupied room-night{!! $st['bench']['per_m2h'] !== null ? '; <b>'.number_format($st['bench']['per_m2h'], 4).' kg CO₂e</b> per m² of meeting space per hour' : '' !!}.
    Scope 1 (fuels, refrigerants) and Scope 2 (grid electricity at the Ghana grid factor) plus outsourced laundry are included; the footprint is split between guest rooms and meeting space by floor area.
    Food, travel to and from the hotel and other Scope 3 sources are not included.</p>
</div>
@endsection
