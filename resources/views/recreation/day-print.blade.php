<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Z report · {{ dmy($report['date']) }}</title>
<style>
@page{margin:12mm}
body{font:12px/1.45 ui-monospace,Menlo,Consolas,monospace;color:#111;margin:0;padding:12px;max-width:520px}
h1{font-size:16px;margin:0;text-align:center}h2{font-size:13px;margin:14px 0 4px;border-bottom:1px solid #111;padding-bottom:2px}
.m{color:#555;font-size:10.5px}.r{text-align:right}.c{text-align:center}
table{width:100%;border-collapse:collapse}td,th{padding:2px 0;vertical-align:top}th{font-size:10.5px;color:#555;text-align:left}
.big{font-size:14px;font-weight:700}.row{display:flex;justify-content:space-between;gap:10px}
.band{border:2px solid #111;padding:6px 8px;margin:10px 0;border-radius:6px}
@media print{button{display:none}body{padding:0}}
</style></head><body>
<button onclick="window.print()" style="float:right">Print</button>
<h1>{{ hotel()?->name ?? "Nowills PMS" }}</h1>
<div class="c m">Recreation outlet · end-of-day (Z) report</div>
<div class="c m">{{ \Illuminate\Support\Carbon::parse($report['date'])->format('l, d F Y') }} · printed {{ now()->format('d M Y H:i') }} by {{ auth()->user()->name ?? '—' }}</div>

<div class="band">
  <div class="row big"><span>Takings</span><span>{{ money($report['total'], false) }}</span></div>
  <div class="row m"><span>Net {{ money($report['net'], false) }} · tax {{ money($report['tax'], false) }}</span><span>{{ $report['sales'] }} sale(s)</span></div>
</div>

<h2>Taken by tender</h2>
<table><tbody>
@forelse($report['tenders'] as $name => $row)
  <tr><td>{{ $name }}{{ $row['is_cash'] ? ' (drawer)' : '' }}</td><td class="r">{{ $row['count'] }}</td><td class="r">{{ money($row['amount'], false) }}</td></tr>
@empty<tr><td colspan="3" class="m">Nothing taken.</td></tr>@endforelse
<tr><td colspan="2" class="r"><b>Collections</b></td><td class="r"><b>{{ money($report['total'], false) }}</b></td></tr>
@if($report['room_total'] > 0)<tr><td colspan="2" class="r">Charged to rooms</td><td class="r">{{ money($report['room_total'], false) }}</td></tr>@endif
</tbody></table>

<h2>Revenue by category</h2>
<table><tbody>
@forelse($report['by_category'] as $key => $row)
  <tr><td>{{ \App\Models\ClubTicketType::CATEGORIES[$key] ?? ucfirst($key) }}</td><td class="r">{{ $row['qty'] }}</td><td class="r">{{ money($row['net'], false) }}</td><td class="r">{{ money($row['tax'], false) }}</td><td class="r">{{ money($row['net'] + $row['tax'], false) }}</td></tr>
@empty<tr><td class="m">No sales.</td></tr>@endforelse
</tbody></table>

<h2>What sold</h2>
<table><tbody>
@forelse($report['by_type'] as $name => $row)
  <tr><td>{{ $name }}</td><td class="r">{{ $row['qty'] }}</td><td class="r">{{ money($row['total'], false) }}</td></tr>
@empty<tr><td class="m">Nothing sold.</td></tr>@endforelse
</tbody></table>

<h2>Shifts and drawers</h2>
<table><thead><tr><th>Cashier</th><th>Shift</th><th class="r">Expected</th><th class="r">Counted</th><th class="r">Var.</th></tr></thead><tbody>
@forelse($report['shifts'] as $row)
  @php $s = $row['session']; @endphp
  <tr><td>{{ $s->user?->name ?? '—' }}</td><td>{{ $s->shift }} {{ $s->opened_at->format('H:i') }}–{{ $s->closed_at?->format('H:i') ?? 'open' }}</td>
    <td class="r">{{ money($s->expected_cash ?? ($row['summary']['expected_cash'] ?? 0), false) }}</td>
    <td class="r">{{ $s->counted_cash !== null ? money($s->counted_cash, false) : '—' }}</td>
    <td class="r">{{ $s->variance !== null ? money($s->variance, false) : '—' }}</td></tr>
@empty<tr><td colspan="5" class="m">No shifts opened.</td></tr>@endforelse
</tbody></table>

<h2>Totals</h2>
<table><tbody>
  <tr><td>Tickets issued</td><td class="r">{{ $report['tickets'] }}</td></tr>
  <tr><td>Items sold</td><td class="r">{{ $report['items'] }}</td></tr>
  <tr><td>Cash taken</td><td class="r">{{ money($report['cash_in'], false) }}</td></tr>
  <tr><td>Change given</td><td class="r">{{ money($report['change'], false) }}</td></tr>
  <tr><td>Voids</td><td class="r">{{ $report['void_sales'] }} · {{ money($report['void_total'], false) }}</td></tr>
  <tr><td>Admissions</td><td class="r">{{ $report['admissions']['admitted'] }} of {{ $report['admissions']['issued'] }} ticket(s) · {{ $report['admissions']['pax_admitted'] }} of {{ $report['admissions']['pax_issued'] }} guest(s)</td></tr>
  <tr><td>Still to arrive</td><td class="r">{{ $report['admissions']['waiting'] }}</td></tr>
  <tr><td>Tax components</td><td class="r">@forelse($report['components'] as $code => $rate){{ (\App\Services\TaxService::COMPONENTS[$code][0] ?? $code).' '.rtrim(rtrim(number_format($rate, 2), '0'), '.').'%' }}@if(! $loop->last) + @endif @empty none @endforelse</td></tr>
</tbody></table>

<div class="m c" style="margin-top:16px">Counted by the outlet cashier · checked by the duty manager<br>{{ hotel()?->name ?? "Nowills PMS" }} · Recreation outlet · {{ money($report['total'], false) }} taken</div>
</body></html>
