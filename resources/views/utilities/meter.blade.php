@extends('layouts.app')
@section('title', 'Meter '.$m->code)
@section('crumbs') <a href="{{ route('utilities.index', ['tab' => 'meters']) }}">Utilities & Energy</a> / {{ $m->code }} @endsection
@section('actions')
  @if(can_do('utilities.record'))<button class="btn btn-primary" data-modal="readingModal" data-title="Add / correct a reading" data-f-date="{{ bdate()->toDateString() }}" data-f-reading="" id="addReadingBtn"><x-icon name="edit"/> Add / correct reading</button>@endif
@endsection
@section('content')
@php $avg = $sum['days'] ? $sum['cons'] / $sum['days'] : 0; @endphp
<div class="stats">
  <x-stat label="Last reading" :value="$m->lastReading ? number_format($m->lastReading->reading, 2) : '—'" icon="edit" tone="blue" :sub="$m->lastReading ? $m->lastReading->reading_date->format('d M Y') : 'no readings yet'"/>
  <x-stat label="Consumption (period)" :value="number_format($sum['cons'], 1).' '.$m->unitShort()" icon="chart" tone="purple" :sub="number_format($avg, 1).' '.$m->unitShort().' per day'"/>
  <x-stat label="Cost (period)" :value="money($sum['cost'])" icon="money" tone="green" :sub="'tariff '.number_format($m->rate, 2).' / '.$m->unitShort()"/>
  <x-stat label="Flags" :value="$readings->whereNotNull('anomaly')->count()" icon="alert" :tone="$readings->whereNotNull('anomaly')->whereNull('anomaly_ack_at')->count() ? 'red' : 'green'" sub="anomalies in the period"/>
</div>

<div class="grid g-1-2">
  <div class="card"><div class="card-head"><h3><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:{{ $m->color() }}"></span> {{ $m->name }}</h3></div>
    <div class="card-body"><dl class="kv" id="meterInfo">
      <dt>Code</dt><dd><b>{{ $m->code }}</b></dd>
      <dt>Utility</dt><dd>{{ $m->utilityLabel() }} ({{ $m->unitLabel() }})</dd>
      <dt>Meter no.</dt><dd>{{ $m->meter_no ?: '—' }}</dd>
      <dt>Area</dt><dd>{{ $m->area ?: '—' }}</dd>
      <dt>Multiplier</dt><dd>×{{ rtrim(rtrim(number_format($m->multiplier, 3), '0'), '.') }}</dd>
      <dt>Roll-over at</dt><dd>{{ number_format($m->rolloverAt()) }}</dd>
      <dt>Anomaly threshold</dt><dd>±{{ $m->alert_pct }}%</dd>
      <dt>Hotel total</dt><dd>{{ $m->is_main ? 'Counts toward the hotel total' : 'Sub-meter – informational' }}</dd>
      @if($m->parent)<dt>Sub-meter of</dt><dd><a href="{{ route('utilities.meter', $m->parent) }}">{{ $m->parent->code }}</a></dd>@endif
      @if($m->children->count())<dt>Sub-meters</dt><dd>@foreach($m->children as $c)<a href="{{ route('utilities.meter', $c) }}">{{ $c->code }}</a>@if(! $loop->last), @endif @endforeach</dd>@endif
      @if($m->notes)<dt>Notes</dt><dd>{{ $m->notes }}</dd>@endif
    </dl></div>
    @if($bills->count())
    <div class="card-head" style="border-top:1px solid var(--c-border)"><h3>Recent bills</h3></div>
    <div class="table-wrap"><table class="table table-compact">
      <thead><tr><th>Bill</th><th class="right">Billed</th><th class="right">Metered</th><th class="right">Total</th></tr></thead>
      <tbody>@foreach($bills as $b)<tr><td>{{ $b->bill_no }}<div class="tiny muted">{{ $b->period_from->format('d M') }} – {{ $b->period_to->format('d M') }}</div></td><td class="right">{{ number_format($b->units_billed) }}</td><td class="right">{{ number_format($b->metered_units) }}</td><td class="right">{{ money($b->total) }}</td></tr>@endforeach</tbody>
    </table></div>
    @endif
  </div>

  <div class="card flush">
    <div class="card-head"><h3>Readings</h3>
      <form method="get" class="flex" style="gap:.4rem"><input type="date" name="from" value="{{ $from }}"><input type="date" name="to" value="{{ $to }}"><button class="btn btn-sm">Show</button></form></div>
    @php $series = $readings->reverse()->values(); $maxC = max(0.001, $series->max(fn ($r) => $r->perDay())); $n = max(1, $series->count()); $bw = 600 / $n; @endphp
    @if($series->count() > 1)
    <div class="card-body" style="padding-bottom:0"><svg viewBox="0 0 600 120" width="100%" style="max-height:140px" id="meterChart">
      @foreach($series as $r)
        @php $bh = $r->perDay() / $maxC * 110; @endphp
        <rect x="{{ round($loop->index * $bw + 1, 1) }}" y="{{ round(115 - $bh, 1) }}" width="{{ round(max(1, $bw - 2), 1) }}" height="{{ round($bh, 1) }}" fill="{{ $r->anomaly ? '#dc2626' : $m->color() }}" opacity="{{ $r->is_estimated ? .5 : 1 }}"><title>{{ $r->reading_date->format('d M') }} · {{ number_format($r->perDay(), 1) }} {{ $m->unitShort() }}/day</title></rect>
      @endforeach
      <line x1="0" y1="115" x2="600" y2="115" stroke="#cbd5e1"/>
    </svg></div>
    @endif
    <div class="table-wrap"><table class="table table-compact" id="readingTable">
      <thead><tr><th>Date</th><th class="right">Reading</th><th class="right">Consumption</th><th class="right">Days</th><th class="right">Cost</th><th>Flags</th><th>By</th><th></th></tr></thead>
      <tbody>
      @forelse($readings as $r)
        <tr>
          <td class="nowrap">{{ $r->reading_date->format('D d M') }}</td>
          <td class="right">{{ number_format($r->reading, 2) }}</td>
          <td class="right">{{ $r->days ? number_format($r->consumption, 1) : 'opening' }}</td>
          <td class="right">{{ $r->days }}</td>
          <td class="right">{{ money($r->cost) }}</td>
          <td class="small">@if($r->anomaly)<span class="badge badge-{{ \App\Models\MeterReading::ANOMALIES[$r->anomaly][1] }}" title="{{ $r->anomaly_note }}">{{ \App\Models\MeterReading::ANOMALIES[$r->anomaly][0] }}</span>@if($r->anomaly_ack_at) <span class="tiny muted">ack.</span>@endif @endif
            @if($r->is_estimated)<span class="badge badge-slate">Estimated</span>@endif @if($r->meter_reset)<span class="badge badge-blue">Meter replaced</span>@endif @if($r->rolled_over)<span class="badge badge-blue">Rolled over</span>@endif
            @if($r->note)<div class="tiny muted">{{ $r->note }}</div>@endif</td>
          <td class="small">{{ $r->user?->name }}</td>
          <td class="right nowrap">
            @if(can_do('utilities.record'))<button class="btn btn-sm btn-ghost" data-modal="readingModal" data-title="Correct reading of {{ $r->reading_date->format('d M') }}" data-f-date="{{ $r->reading_date->toDateString() }}" data-f-reading="{{ $r->reading }}" data-f-note="{{ $r->note }}" data-f-estimated="{{ $r->is_estimated ? 1 : 0 }}" data-f-reset="{{ $r->meter_reset ? 1 : 0 }}"><x-icon name="edit"/></button>@endif
            @if($r->anomaly && ! $r->anomaly_ack_at && can_do('utilities.record'))<form method="post" action="{{ route('utilities.ack', $r) }}" style="display:inline">@csrf<button class="btn btn-sm btn-ghost" title="Acknowledge anomaly"><x-icon name="check"/></button></form>@endif
            @if(can_do('utilities.manage'))<form method="post" action="{{ route('utilities.reading.delete', $r) }}" style="display:inline" data-confirm="Delete the reading of {{ $r->reading_date->format('d M') }}? The next reading is recalculated.">@csrf<button class="btn btn-sm btn-ghost"><x-icon name="trash"/></button></form>@endif
          </td>
        </tr>
      @empty
        <tr><td colspan="8" class="muted center">No readings in this period.</td></tr>
      @endforelse
      </tbody>
    </table></div>
  </div>
</div>

@if(can_do('utilities.record'))
<div class="modal" id="readingModal"><div class="modal-box" style="max-width:480px"><form method="post" action="{{ route('utilities.reading', $m) }}">@csrf
  <div class="modal-head"><h3 data-title>Reading</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    <x-field name="date" label="Date" type="date" required :value="bdate()->toDateString()" max="{{ bdate()->toDateString() }}"/>
    <x-field name="reading" label="Register reading" type="number" step="0.001" min="0" required/>
    <div class="field span-2"><label class="check"><input type="hidden" name="estimated" value="0"><input type="checkbox" name="estimated" value="1"> Estimated (meter not accessible)</label>
      <label class="check"><input type="hidden" name="reset" value="0"><input type="checkbox" name="reset" value="1"> Meter replaced / reset – count from zero</label></div>
    <x-field name="note" label="Note" class="span-2" maxlength="190"/>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button type="submit" class="btn btn-primary">Save reading</button></div>
</form></div></div>
@endif
@endsection
