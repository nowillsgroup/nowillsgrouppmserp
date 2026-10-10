<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>BEO {{ $e->event_no }}</title>@include('banquet._docstyle')</head><body>
<button onclick="window.print()" style="float:right">Print</button>
<div class="head">
  <div><h1>{{ $hotel->name }}</h1><div class="m">{{ $hotel->address ?? '' }}</div></div>
  <div class="doc"><div class="m">BANQUET EVENT ORDER</div><h2>{{ $e->event_no }}</h2><div class="stamp">{{ strtoupper(\App\Models\BanquetEvent::STATUSES[$e->status] ?? $e->status) }}</div></div>
</div>
<div class="kv">
  <div style="grid-column:span 2"><span>Event</span><b>{{ $e->title }}</b> · {{ \App\Models\BanquetEvent::TYPES[$e->event_type] ?? $e->event_type }}</div>
  <div><span>Date(s)</span>{{ $e->event_date->format('D d M Y') }}{{ $e->end_date && ! $e->end_date->eq($e->event_date) ? ' – '.$e->end_date->format('D d M') : '' }}</div>
  <div><span>Issued</span>{{ now()->format('d M Y H:i') }}</div>
  <div><span>Client / bill to</span>{{ $e->company?->name ?? $e->contact_name }}</div>
  <div><span>On-site contact</span>{{ $e->contact_name }}{{ $e->contact_phone ? ' · '.$e->contact_phone : '' }}</div>
  <div><span>Sales person</span>{{ $e->salesUser?->name ?? '—' }}</div>
  <div><span>Accommodation</span>{{ $e->roomBlock ? $e->roomBlock->block_no.' ('.$e->roomBlock->name.')' : 'None' }}</div>
</div>
@if($e->requirements)<div class="pre"><span class="lbl">Client requirements</span>{{ $e->requirements }}</div>@endif

@foreach($e->functions as $f)
<div class="fn" id="beo{{ $f->id }}">
  <h3><span>{{ $f->name }} – {{ $f->hall->name }}</span><span>{{ $f->date->format('D d M') }} · {{ $f->timeRange() }}</span></h3>
  <div class="body">
    <div class="kv" style="margin:0 0 6px">
      <div><span>Set-up</span>{{ \App\Models\BanquetFunction::SETUPS[$f->setup_style] ?? $f->setup_style }}</div>
      <div><span>Expected</span>{{ $f->expected_pax }} pax</div>
      <div><span>Guaranteed</span><b>{{ $f->guaranteed_pax ?? 'TBC' }}</b>{{ $f->guaranteed_pax === null ? ' (due '.$f->guaranteeCutoff()->format('d M').')' : '' }}</div>
      <div><span>Set for</span><b class="big">{{ (int) ceil(($f->guaranteed_pax ?? $f->expected_pax) * 1.05) }}</b> <span class="note">(+5% overset)</span></div>
    </div>
    <div class="dept">
      <div><span class="lbl">Kitchen – menu</span>@if($f->package)<b>{{ $f->package->name }}</b>@if($f->package->itemList())<ul style="margin:2px 0 0;padding-left:18px">@foreach($f->package->itemList() as $i)<li>{{ $i }}</li>@endforeach</ul>@endif @elseif($f->rate_per_pax > 0) Custom menu – see notes @else No F&amp;B @endif</div>
      <div><span class="lbl">Notes – kitchen / service / set-up</span><div class="pre">{{ $f->notes ?: '—' }}</div></div>
      @foreach($f->items->groupBy(fn ($i) => $i->service?->department?->name ?? 'Banquet operations') as $dept => $items)
        <div><span class="lbl">{{ $dept }}</span>@foreach($items as $i){{ rtrim(rtrim(number_format($i->qty, 2), '0'), '.') }} × {{ $i->description }}<br>@endforeach</div>
      @endforeach
    </div>
  </div>
</div>
@endforeach

<table><thead><tr><th>Billing instructions</th><th class="r">Amount</th></tr></thead><tbody>
@foreach($e->functions as $f)<tr><td>{{ $f->name }} ({{ $f->date->format('d M') }}) – F&amp;B {{ $f->billablePax() }} × {{ money($f->rate_per_pax, false) }}, venue {{ money($f->hall_rate, false) }}{{ $f->items->count() ? ', '.$f->items->count().' extra(s)' : '' }}</td><td class="r">{{ money($f->net(), false) }}</td></tr>@endforeach
<tr><td>Taxes</td><td class="r">{{ money($e->tax(), false) }}</td></tr>
</tbody><tfoot><tr><td>Estimated total · deposits received {{ money($deposits, false) }} · bill to {{ $e->company?->name ?? 'client (direct payment)' }}</td><td class="r">{{ money($e->total()) }}</td></tr></tfoot></table>
<p class="note">Charged on the guaranteed number or the actual attendance, whichever is higher. Changes after the guarantee cut-off must be approved by the banquet manager and re-issued as a revised BEO.</p>
<div class="sig"><div>Banquet manager</div><div>Executive chef</div><div>F&amp;B / operations</div></div>
<p class="note" style="margin-top:14px">Distribution: Kitchen · F&amp;B service · Housekeeping · Engineering · Front office · Security · Accounts</p>
</body></html>
