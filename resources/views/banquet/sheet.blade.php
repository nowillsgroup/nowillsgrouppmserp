<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Function sheet {{ $date->format('d M Y') }}</title>@include('banquet._docstyle')
<style>body{max-width:1100px}td{font-size:12px}</style></head><body>
<div class="noprint" style="float:right;display:flex;gap:6px;align-items:center">
  <a href="?date={{ $date->copy()->subDay()->toDateString() }}">‹ Previous day</a>
  <form method="get" style="display:inline"><input type="date" name="date" value="{{ $date->toDateString() }}" onchange="this.form.submit()"></form>
  <a href="?date={{ $date->copy()->addDay()->toDateString() }}">Next day ›</a>
  <button onclick="window.print()">Print</button> <a href="{{ route('banquet.diary') }}">Back to diary</a>
</div>
<div class="head">
  <div><h1>{{ $hotel->name }}</h1><div class="m">Daily function sheet – for kitchen, service, housekeeping, engineering, front office &amp; security</div></div>
  <div class="doc"><div class="m">FUNCTIONS ON</div><h2>{{ $date->format('D d M Y') }}</h2><div class="m">{{ $fns->count() }} function(s) · {{ $fns->sum(fn ($f) => $f->guaranteed_pax ?? $f->expected_pax) }} covers</div></div>
</div>
<table id="sheetTable"><thead><tr><th>Time</th><th>Venue / set-up</th><th>Event</th><th class="r">Pax</th><th>Menu</th><th>Services &amp; equipment</th><th>Notes</th></tr></thead><tbody>
@forelse($fns as $f)
<tr>
  <td><b>{{ $f->timeRange() }}</b></td>
  <td><b>{{ $f->hall->name }}</b><br><span class="note">{{ \App\Models\BanquetFunction::SETUPS[$f->setup_style] ?? $f->setup_style }}</span></td>
  <td><b>{{ $f->event->event_no }}</b> {{ $f->name }}<br>{{ $f->event->title }}<br><span class="note">{{ $f->event->contact_name }}{{ $f->event->contact_phone ? ' · '.$f->event->contact_phone : '' }}</span>@if($f->event->status === 'tentative')<br><b>TENTATIVE</b>@endif</td>
  <td class="r"><b>{{ $f->guaranteed_pax ?? $f->expected_pax }}</b>{!! $f->guaranteed_pax === null ? '<br><span class="note">exp.</span>' : '<br><span class="note">guar.</span>' !!}@if($f->actual_pax !== null)<br><span class="note">act. {{ $f->actual_pax }}</span>@endif</td>
  <td>{{ $f->package?->name ?? ($f->rate_per_pax > 0 ? 'Custom menu' : '—') }}@if($f->package && $f->package->itemList())<br><span class="note">{{ implode(', ', $f->package->itemList()) }}</span>@endif</td>
  <td>@forelse($f->items as $i){{ rtrim(rtrim(number_format($i->qty, 2), '0'), '.') }} × {{ $i->description }}{!! $i->service?->department ? ' <span class="note">('.e($i->service->department->name).')</span>' : '' !!}<br>@empty — @endforelse</td>
  <td class="pre">{{ $f->notes }}</td>
</tr>
@empty<tr><td colspan="7" class="muted" style="text-align:center;padding:24px">No functions on this day.</td></tr>@endforelse
</tbody></table>
</body></html>
