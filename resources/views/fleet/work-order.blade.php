<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Work order WO-{{ str_pad((string) $record->id, 5, '0', STR_PAD_LEFT) }} · {{ $record->vehicle?->code }}</title>
@include('fleet._docstyle')
</head><body>
<div class="document-actions">
  <a href="{{ route('tr.fleet.maintenance') }}">Back to maintenance</a>
  <button type="button" onclick="window.print()">Print</button>
</div>

<div class="head">
  <div>
    <h1>{{ $hotel->name ?? config('app.name') }}</h1>
    <div class="m">{{ $hotel->address ?? '' }}</div>
    <div class="m">Transport · Vehicle workshop</div>
  </div>
  <div class="doc">
    <div class="m">VEHICLE WORK ORDER</div>
    <h2>WO-{{ str_pad((string) $record->id, 5, '0', STR_PAD_LEFT) }}</h2>
    <span class="stamp {{ $record->status }}">{{ \App\Models\VehicleMaintenanceRecord::STATUSES[$record->status] ?? $record->status }}</span>
    <span class="stamp prio-{{ $record->priority }}">{{ \App\Models\VehicleMaintenanceRecord::PRIORITIES[$record->priority] ?? $record->priority }}</span>
    <div class="m">Issued {{ now()->format('d M Y H:i') }}</div>
  </div>
</div>

<div class="kv">
  <div><span>Vehicle</span><b>{{ $record->vehicle?->code ?? '—' }}</b></div>
  <div><span>Registration</span>{{ $record->vehicle?->reg_no ?? '—' }}</div>
  <div><span>Make &amp; model</span>{{ trim(($record->vehicle?->make ?? '').' '.($record->vehicle?->model ?? '')) ?: '—' }}</div>
  <div><span>Odometer at print</span>{{ $record->vehicle?->odometer ? number_format($record->vehicle->odometer).' km' : '—' }}</div>
  <div><span>Category</span>{{ \App\Models\VehicleMaintenanceRecord::CATEGORIES[$record->category] ?? $record->category }}</div>
  <div><span>Due date</span>{{ $record->due_date?->format('d M Y') ?? '—' }}</div>
  <div><span>Due odometer</span>{{ $record->due_odometer ? number_format($record->due_odometer).' km' : '—' }}</div>
  <div><span>Vendor / garage</span>{{ $record->vendor ?: '—' }}</div>
  <div><span>Started</span>{{ $record->started_at?->format('d M Y H:i') ?? '—' }}</div>
  <div><span>Completed</span>{{ $record->completed_at?->format('d M Y H:i') ?? '—' }}</div>
  <div><span>Estimate</span>{{ money($record->estimated_cost ?? 0) }}</div>
  <div><span>Actual</span>{{ $record->actual_cost !== null ? money($record->actual_cost) : '—' }}</div>
</div>

<div class="box">
  <span class="lbl">Work requested</span>
  <div><b>{{ $record->title }}</b></div>
  <div class="pre">{{ $record->description ?: 'No further description was recorded.' }}</div>
</div>

@if($record->plan)
<div class="box">
  <span class="lbl">Service plan this job came from</span>
  <div>{{ $record->plan->name ?? '—' }}
    <span class="muted">·
      @if($record->plan->interval_days)every {{ (int) $record->plan->interval_days }} day(s)@endif
      @if($record->plan->interval_days && $record->plan->interval_km) / @endif
      @if($record->plan->interval_km)every {{ number_format((int) $record->plan->interval_km) }} km @endif
      @if($record->plan->due_date) · next due {{ $record->plan->due_date->format('d M Y') }}@endif
    </span>
  </div>
</div>
@endif

@if($inspections->isNotEmpty())
<div class="box alert">
  <span class="lbl">Defects reported at inspection</span>
  @foreach($inspections as $i)
    <div style="margin-bottom:6px">
      <b>{{ $i->inspected_at->format('d M Y H:i') }}</b>
      <span class="muted">· {{ $i->inspector ?: 'driver / desk' }} · {{ \App\Models\VehicleInspection::RESULTS[$i->result] ?? $i->result }}</span>
      @if($i->defects)<div class="pre">{{ $i->defects }}</div>@endif
      @if($i->missingChecks())
        <div class="muted">Checks not confirmed: {{ implode(', ', $i->missingChecks()) }}</div>
      @endif
    </div>
  @endforeach
</div>
@endif

<div class="box">
  <span class="lbl">Costs posted against this job</span>
  @if($expenses->isEmpty())
    <div class="muted">No expense has been posted to this work order yet.{{ $record->actual_cost === null ? ' The final invoice is still outstanding.' : '' }}</div>
  @else
    <table>
      <caption class="sr-only">Expenses posted against this work order</caption>
      <thead><tr><th scope="col">Date</th><th scope="col">Kind</th><th scope="col">Vendor</th><th scope="col">Note</th><th scope="col" class="r">Amount</th></tr></thead>
      <tbody>
      @foreach($expenses as $e)
        <tr><td>{{ $e->date?->format('d M Y') ?? '—' }}</td>
          <td>{{ ucfirst($e->kind) }}</td>
          <td>{{ $e->vendor ?: '—' }}</td>
          <td>{{ \Illuminate\Support\Str::limit((string) $e->notes, 60) ?: '—' }}</td>
          <td class="r">{{ money($e->amount, false) }}</td></tr>
      @endforeach
      </tbody>
      <tfoot><tr><td colspan="4">Total posted</td><td class="r">{{ money($expenses->sum(fn ($e) => (float) $e->amount), false) }}</td></tr></tfoot>
    </table>
  @endif
</div>

@if($record->status !== 'completed' && $record->status !== 'cancelled')
  <div class="box">
    <span class="lbl">Before the vehicle goes back on the road</span>
    <ul class="checks">
      <li>Road test after the repair, including brakes and steering.</li>
      <li>Odometer entered on the job so the service plan rolls forward.</li>
      <li>Final invoice posted to vehicle running expenses.</li>
      <li>A fresh pre-trip inspection recorded on the vehicle page.</li>
    </ul>
  </div>
@endif

<div class="sig">
  <div>Mechanic / technician<br><span class="muted">Name, signature and date</span></div>
  <div>Vendor / garage<br><span class="muted">Stamp, signature and date</span></div>
  <div>Transport manager<br><span class="muted">Releasing the vehicle</span></div>
</div>

<p class="note" style="margin-top:22px">
  This job card is generated from the fleet records; the amounts above are what has actually been posted, not what was quoted.
  Printed by {{ auth()->user()?->name ?? 'the transport desk' }} on {{ now()->format('d M Y \a\t H:i') }}.
</p>
</body></html>
