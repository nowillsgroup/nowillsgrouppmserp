@php $pol = collect(explode(',', setting('banquet_cancel_policy', \App\Services\BanquetSalesService::DEFAULTS['banquet_cancel_policy'])))->map(fn ($t) => array_map('trim', explode(':', $t)))->sortByDesc(fn ($p) => (int) $p[0])->values(); @endphp
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Proposal {{ $e->event_no }}</title>@include('banquet._docstyle')</head><body>
<button onclick="window.print()" style="float:right">Print</button>
<div class="head">
  <div><h1>{{ $hotel->name }}</h1><div class="m">{{ $hotel->address ?? '' }}{{ $hotel->phone ? ' · '.$hotel->phone : '' }}{{ $hotel->email ? ' · '.$hotel->email : '' }}</div></div>
  <div class="doc"><div class="m">{{ $e->status === 'definite' || $e->status === 'completed' ? 'BANQUET CONTRACT' : 'EVENT PROPOSAL' }}</div><h2>{{ $e->event_no }}</h2><div class="m">{{ now()->format('d M Y') }}</div></div>
</div>
<p style="margin-top:16px">To: <b>{{ $e->contact_name }}</b>{{ $e->company ? ', '.$e->company->name : '' }}{!! $e->contact_email ? '<br><span class="muted">'.e($e->contact_email).'</span>' : '' !!}</p>
<p>Thank you for considering {{ $hotel->name }} for <b>{{ $e->title }}</b>. We are pleased to propose the following arrangements{{ $e->status === 'tentative' && $e->option_date ? ', with the venue held for you until '.$e->option_date->format('l d F Y') : '' }}.</p>
<table><thead><tr><th>Function</th><th>Venue &amp; set-up</th><th class="r">Guests</th><th>Description</th><th class="r">Amount</th></tr></thead><tbody>
@foreach($e->functions as $f)
  @php $rows = collect(); if ($f->rate_per_pax > 0) $rows->push([$f->package?->name ?? 'Food & beverage', $f->billablePax().' × '.money($f->rate_per_pax, false), $f->fnbAmount()]); $rows->push(['Venue rental', $f->hours().' hours', (float) $f->hall_rate]); foreach ($f->items as $i) $rows->push([$i->description, rtrim(rtrim(number_format($i->qty, 2), '0'), '.').' × '.money($i->unit_price, false), $i->amount()]); @endphp
  @foreach($rows as $k => [$d, $calc, $amt])
  <tr>@if($k === 0)<td rowspan="{{ $rows->count() }}"><b>{{ $f->name }}</b><br><span class="note">{{ $f->date->format('D d M Y') }}<br>{{ $f->timeRange() }}</span></td><td rowspan="{{ $rows->count() }}">{{ $f->hall->name }}<br><span class="note">{{ \App\Models\BanquetFunction::SETUPS[$f->setup_style] ?? '' }}</span></td><td rowspan="{{ $rows->count() }}" class="r">{{ $f->billablePax() }}</td>@endif<td>{{ $d }} <span class="note">{{ $calc }}</span></td><td class="r">{{ money($amt, false) }}</td></tr>
  @endforeach
  @if($f->package && $f->package->itemList())<tr><td colspan="2"></td><td></td><td colspan="2" class="note">Menu: {{ implode(' · ', $f->package->itemList()) }}</td></tr>@endif
@endforeach
</tbody><tfoot>
<tr><td colspan="4" class="r" style="font-weight:500;border-top:2px solid #111">Sub-total</td><td class="r">{{ money($e->net(), false) }}</td></tr>
<tr><td colspan="4" class="r" style="font-weight:500;border-top:0">Taxes</td><td class="r" style="border-top:0">{{ money($e->tax(), false) }}</td></tr>
<tr><td colspan="4" class="r" style="border-top:0">Estimated total</td><td class="r" style="border-top:0" id="quoteTotal">{{ money($e->total()) }}</td></tr>
</tfoot></table>
<div class="kv" style="grid-template-columns:1fr 1fr;margin-top:16px">
  <div><span>Deposit to confirm</span><b>{{ money($e->deposit_due > 0 ? $e->deposit_due : round($e->total() * (float) setting('banquet_deposit_pct', '25') / 100 / 100) * 100) }}</b>{{ $e->deposit_due_date ? ' by '.$e->deposit_due_date->format('d M Y') : '' }}{!! $deposits > 0 ? '<br><span class="note">Received so far: '.money($deposits).'</span>' : '' !!}</div>
  <div><span>Cancellation charges (of the estimated value)</span>@foreach($pol as $k => $p){{ $k === 0 ? $p[0].'+ days' : $p[0].'–'.((int) $pol[$k - 1][0] - 1).' days' }}: <b>{{ $p[1] }}%</b>@if(! $loop->last) · @endif @endforeach</div>
</div>
<div class="pre note" style="margin-top:10px"><span class="lbl">Terms &amp; conditions</span>{{ $terms }}</div>
<div class="sig" style="grid-template-columns:1fr 1fr"><div>For {{ $hotel->name }}<br>{{ $e->salesUser?->name }}</div><div>Accepted by client (name, signature, date)</div></div>
</body></html>
