<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Running-cost statement {{ $month->format('F Y') }} · Fleet</title>
@include('fleet._docstyle')
</head><body>
<div class="document-actions noprint">
  <a href="{{ route('tr.fleet.cost-statement', ['month' => $prev->format('Y-m')]) }}">&larr; {{ $prev->format('M Y') }}</a>
  @if($next)
    <a href="{{ route('tr.fleet.cost-statement', ['month' => $next->format('Y-m')]) }}">{{ $next->format('M Y') }} &rarr;</a>
  @endif
  <a href="{{ route('tr.fleet.cost-statement.export', ['month' => $month->format('Y-m')]) }}">Download CSV</a>
  <a href="{{ route('tr.fleet.fuel') }}">Back to fuel &amp; running costs</a>
  <button type="button" onclick="window.print()">Print</button>
</div>

<div class="head">
  <div>
    <h1>{{ $hotel->name ?? config('app.name') }}</h1>
    <div class="m">{{ $hotel->address ?? '' }}</div>
    <div class="m">Transport · Fleet accounts</div>
  </div>
  <div class="doc">
    <div class="m">FLEET RUNNING-COST STATEMENT</div>
    <h2>{{ $month->format('F Y') }}</h2>
    <div class="m">{{ $statement['from']->format('d M Y') }} – {{ $statement['to']->format('d M Y') }}</div>
    <div class="m">Printed {{ now()->format('d M Y H:i') }}</div>
  </div>
</div>

<div class="kv">
  <div><span>Vehicles with spend</span><b>{{ count($statement['lines']) }}</b></div>
  <div><span>Fuel</span>{{ money($statement['totals']['fuel']) }}</div>
  <div><span>Service &amp; repair</span>{{ money($statement['totals']['service']) }}</div>
  <div><span>Tolls, parking &amp; other</span>{{ money($statement['totals']['other']) }}</div>
  <div><span>Total running cost</span><b>{{ money($statement['totals']['total']) }}</b></div>
  <div><span>Fuel drawn</span>{{ $statement['totals']['litres'] > 0 ? number_format($statement['totals']['litres'], 2).' l · '.$statement['totals']['fills'].' fill(s)' : '—' }}</div>
  <div><span>Distance (GPS)</span>{{ $statement['totals']['tracked'] > 0 ? number_format((int) $statement['totals']['km']).' km · '.$statement['totals']['tracked'].' car(s)' : 'No trail' }}</div>
  <div><span>Fleet cost per km</span>{{ $statement['totals']['cost_per_km'] !== null ? money($statement['totals']['cost_per_km']) : '—' }}</div>
</div>

@if(empty($statement['lines']))
  <div class="box alert">
    <span class="lbl">Nothing to state</span>
    <div>No running cost was recorded for any vehicle in {{ $month->format('F Y') }}, so this statement has no lines.
      Fuel fills, service, repairs, tolls and parking are recorded on the <a href="{{ route('tr.fleet.fuel') }}">fuel &amp; running costs</a>
      and <a href="{{ route('tr.fleet.maintenance') }}">maintenance</a> screens.</div>
  </div>
@else
  <table>
    <caption class="sr-only">Running cost per vehicle for {{ $month->format('F Y') }}</caption>
    <thead>
      <tr>
        <th scope="col">Vehicle</th>
        <th scope="col">Registration</th>
        <th scope="col" class="r">Fuel</th>
        <th scope="col" class="r">Service &amp; repair</th>
        <th scope="col" class="r">Other</th>
        <th scope="col" class="r">Total</th>
        <th scope="col" class="r">Litres</th>
        <th scope="col" class="r">Distance</th>
        <th scope="col" class="r">Cost / km</th>
      </tr>
    </thead>
    <tbody>
      @foreach($statement['lines'] as $l)
        <tr>
          <td><b>{{ $l['vehicle']->code }}</b>
            <div class="muted">{{ trim(($l['vehicle']->make ?? '').' '.($l['vehicle']->model ?? '')) ?: '—' }}</div></td>
          <td>{{ $l['vehicle']->reg_no ?: '—' }}</td>
          <td class="r">{{ $l['fuel'] > 0 ? money($l['fuel']) : '—' }}</td>
          <td class="r">{{ $l['service'] > 0 ? money($l['service']) : '—' }}</td>
          <td class="r">{{ $l['other'] > 0 ? money($l['other']) : '—' }}</td>
          <td class="r"><b>{{ money($l['total']) }}</b></td>
          <td class="r">{{ $l['litres'] > 0 ? number_format($l['litres'], 2) : '—' }}{{ $l['fills'] > 0 ? ' · '.$l['fills'] : '' }}</td>
          <td class="r">{{ $l['km'] !== null ? number_format((int) $l['km']).' km' : 'No trail' }}</td>
          <td class="r">{{ $l['cost_per_km'] !== null ? money($l['cost_per_km']) : '—' }}</td>
        </tr>
      @endforeach
    </tbody>
    <tfoot>
      <tr>
        <td colspan="2">{{ count($statement['lines']) }} vehicle(s)</td>
        <td class="r">{{ money($statement['totals']['fuel']) }}</td>
        <td class="r">{{ money($statement['totals']['service']) }}</td>
        <td class="r">{{ money($statement['totals']['other']) }}</td>
        <td class="r">{{ money($statement['totals']['total']) }}</td>
        <td class="r">{{ $statement['totals']['litres'] > 0 ? number_format($statement['totals']['litres'], 2) : '—' }}</td>
        <td class="r">{{ $statement['totals']['tracked'] > 0 ? number_format((int) $statement['totals']['km']).' km' : '—' }}</td>
        <td class="r">{{ $statement['totals']['cost_per_km'] !== null ? money($statement['totals']['cost_per_km']) : '—' }}</td>
      </tr>
    </tfoot>
  </table>
@endif

<div class="box">
  <span class="lbl">How to read this statement</span>
  <ul class="checks">
    <li>Only vehicles with something recorded in {{ $month->format('F Y') }} appear — a quiet car is left off rather than printed as a row of zeroes.</li>
    <li>Distance is the GPS trail from the paired trackers, measured fix to fix and ignoring hops under 25 m or over 5 km. <b>No trail</b> means the car reported no fixes at all that month; cost per km is then blank, because dividing by zero would invent a number.</li>
    <li>Fleet cost per km covers only the cars that were measurable ({{ $statement['totals']['tracked'] }} of {{ count($statement['lines']) }} this month).</li>
    <li>Figures are the expenses recorded against each vehicle. They are not a purchase ledger and not a tax invoice — reconcile against the garage and filling-station receipts before posting to accounts.</li>
  </ul>
</div>

<div class="sig">
  <div>Prepared by<br><span class="muted">Transport / fleet office · name, signature and date</span></div>
  <div>Checked by<br><span class="muted">Workshop supervisor · name, signature and date</span></div>
  <div>Received by<br><span class="muted">Accounts · name, signature and date</span></div>
</div>
</body></html>
