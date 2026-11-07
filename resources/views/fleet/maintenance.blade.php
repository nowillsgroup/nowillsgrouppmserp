@extends('layouts.app')
@section('title', 'Maintenance & Repairs')
@section('crumbs') <a href="{{ route('tr.index') }}">Transport</a> / <a href="{{ route('tr.fleet.index') }}">Fleet</a> / Maintenance @endsection
@push('scripts')<script>
  // Complete-work-order panels: keyboard accessible, announced through aria-expanded.
  document.querySelectorAll('[data-wo-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var row = document.getElementById(btn.getAttribute('data-wo-toggle'));
      if (!row) { return; }
      var open = row.hasAttribute('hidden');
      if (open) { row.removeAttribute('hidden'); } else { row.setAttribute('hidden', ''); }
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) { var f = row.querySelector('input,select,textarea'); f && f.focus(); }
    });
  });
</script>@endpush
@section('actions')
  @if(can_do('transport.track'))<a class="btn" href="{{ route('tr.fleet.tracking') }}"><x-icon name="wifi"/> Live tracking</a>@endif
  <a class="btn" href="{{ route('tr.fleet.fuel') }}"><x-icon name="wallet"/> Fuel</a>
  <a class="btn" href="{{ route('tr.fleet.export.work_orders', array_filter(['status' => $status])) }}"><x-icon name="download"/> Export CSV</a>
  <a class="btn" href="{{ route('tr.fleet.export.inspections') }}"><x-icon name="download"/> Inspections CSV</a>
@endsection
@push('scripts')<script>
  // Complete-work-order panels: keyboard accessible, announced through aria-expanded.
  document.querySelectorAll('[data-wo-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var row = document.getElementById(btn.getAttribute('data-wo-toggle'));
      if (!row) { return; }
      var open = row.hasAttribute('hidden');
      if (open) { row.removeAttribute('hidden'); } else { row.setAttribute('hidden', ''); }
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) { var f = row.querySelector('input,select,textarea'); f && f.focus(); }
    });
  });
</script>@endpush
@section('content')
<div class="grid g-2">
  <div class="card" id="workshopStats">
    <div class="card-head"><h3>Workshop performance</h3><span class="small muted">Last 90 days · closed jobs</span></div>
    <div class="card-body">
      @if($stats['closed'] === 0)
        <p class="small muted m-0">No work order has been completed in the last 90 days.</p>
      @else
        <div class="stats" style="grid-template-columns:repeat(auto-fit,minmax(130px,1fr));margin-bottom:.75rem">
          <x-stat label="Jobs closed" :value="$stats['closed']" icon="tool" tone="blue"/>
          <x-stat label="Days off road" :value="$stats['off_road_days']" icon="clock" tone="amber" :sub="$stats['avg_days'] !== null ? $stats['avg_days'].' day(s) per job' : 'No start times recorded'"/>
          <x-stat label="Repair spend" :value="money($stats['cost'])" icon="wallet" tone="purple" :sub="$stats['avg_cost'] !== null ? money($stats['avg_cost']).' per job' : ''"/>
        </div>
        @php $catTotal = max(1, array_sum($stats['categories'])); @endphp
        @foreach($stats['categories'] as $cat => $n)
          <div class="small" style="padding:.2rem 0">
            <div class="flex items-center justify-between gap-1">
              <span>{{ \App\Models\VehicleMaintenanceRecord::CATEGORIES[$cat] ?? $cat }}</span>
              <span class="tiny muted">{{ $n }}</span>
            </div>
            <div style="height:.3rem;background:var(--c-slate-50,#f1f5f9);border-radius:999px;overflow:hidden;margin-top:.15rem">
              <div style="height:100%;width:{{ (int) round($n / $catTotal * 100) }}%;background:#6366f1"></div>
            </div>
          </div>
        @endforeach
        <p class="tiny muted mt-1">Days off road are counted from start to completion, so a job the desk never started adds no downtime – the number is a floor, not an estimate.</p>
      @endif
    </div>
  </div>

  <div class="card" id="openJobsAgeing">
    <div class="card-head"><h3>Open jobs</h3><span class="small muted">{{ $stats['open'] }} waiting</span></div>
    <div class="card-body">
      @if($stats['open'] === 0)
        <p class="small muted m-0">Nothing is waiting in the workshop.</p>
      @else
        <p class="small m-0">
          @if($stats['oldest'])
            Oldest: <a href="{{ route('tr.fleet.vehicle', $stats['oldest']->vehicle) }}"><b>{{ $stats['oldest']->vehicle?->code }}</b></a>
            – {{ $stats['oldest']->title }}
            @if($stats['oldest']->due_date)
              <span class="{{ $stats['oldest']->due_date->isPast() ? 'text-red' : 'muted' }}">({{ $stats['oldest']->due_date->isPast() ? abs($stats['oldest']->due_date->diffInDays(now()->startOfDay())).' day(s) overdue' : 'due '.$stats['oldest']->due_date->format('d M') }})</span>
            @endif
          @endif
        </p>
        <div class="table-wrap mt-1">
          <table class="table">
            <caption class="sr-only">Open work orders with due date and priority</caption>
            <thead><tr><th scope="col">Vehicle</th><th scope="col">Job</th><th scope="col">Due</th><th scope="col">Priority</th><th scope="col"><span class="sr-only">Job card</span></th></tr></thead>
            <tbody>
            @foreach($openJobs as $job)
              <tr>
                <td class="small"><b>{{ $job->vehicle?->code ?? '—' }}</b></td>
                <td class="small">{{ $job->title }}</td>
                <td class="small nowrap">@if($job->due_date)<span class="{{ $job->due_date->isPast() ? 'text-red' : '' }}">{{ $job->due_date->format('d M') }}</span>@else — @endif</td>
                <td><span class="badge badge-{{ $job->priority === 'urgent' ? 'red' : ($job->priority === 'high' ? 'amber' : 'slate') }}">{{ \App\Models\VehicleMaintenanceRecord::PRIORITIES[$job->priority] ?? $job->priority }}</span></td>
                <td class="right nowrap"><a class="btn btn-sm btn-ghost" href="{{ route('tr.fleet.work-order.print', $job) }}" title="Printable job card for the garage"><x-icon name="print" size="sm"/> Card</a></td>
              </tr>
            @endforeach
            </tbody>
          </table>
        </div>
        @if($stats['vendors']->isNotEmpty())
          <p class="tiny muted mt-1">Busiest suppliers by value (90 days):
            @foreach($stats['vendors'] as $v){{ $v['vendor'] }} {{ money($v['cost']) }} ({{ $v['jobs'] }}){{ $loop->last ? '' : ' · ' }}@endforeach
          </p>
        @endif
      @endif
    </div>
  </div>
</div>

<div class="grid g-2" id="inspections">
  <div class="card">
    <div class="card-head"><h3>Pre-trip inspections</h3><span class="small muted">Expected every {{ \App\Services\FleetService::cfg()['inspection_days'] }} day(s)</span></div>
    <div class="card-body">
      @php $due = $inspectionDue->where('due', true); @endphp
      @if($due->isEmpty())
        <p class="small muted m-0">Every active vehicle has been inspected inside the window.</p>
      @else
        <p class="small m-0"><b>{{ $due->count() }}</b> of {{ $inspectionDue->count() }} active vehicle(s) are due an inspection.</p>
        <ul class="small" style="margin:.5rem 0 0;padding-left:1.1rem;display:grid;gap:.2rem">
          @foreach($due->take(8) as $row)
            <li><a href="{{ route('tr.fleet.vehicle', $row['vehicle']) }}"><b>{{ $row['vehicle']->code }}</b></a>
              @if($row['days'] === null)<span class="text-red">never inspected</span>
              @else<span class="muted">{{ $row['days'] }} day(s) since the last inspection</span>@endif
            </li>
          @endforeach
        </ul>
      @endif
      <p class="tiny muted mt-1">A never-inspected car counts as overdue: a blank history is a gap, not a clean record. Record inspections from the vehicle page.</p>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h3>What a car was checked for</h3><span class="small muted">Eight items per inspection</span></div>
    <div class="card-body">
      <ul class="small" style="margin:0;padding-left:1.1rem;display:grid;gap:.2rem">
        @foreach(\App\Models\VehicleInspection::CHECKS as $label)
          <li>{{ $label }}</li>
        @endforeach
      </ul>
      <p class="tiny muted mt-1">A check that is not ticked is recorded as not confirmed and printed on the job card, so a defect cannot arrive without its context.</p>
    </div>
  </div>
</div>

<div class="card" id="inspectionLog">
  <div class="card-head"><h3>Inspection log</h3>
      <span class="small muted">{{ $inspections->total() }} recorded · <a href="{{ route('tr.fleet.export.inspections', array_filter(['result' => $inspectionResult])) }}">download CSV</a></span></div>
    <div class="card-body">
      <form class="filters" method="get" action="{{ route('tr.fleet.maintenance') }}#inspectionLog" style="margin-bottom:.6rem">
        @if($status !== '')<input type="hidden" name="status" value="{{ $status }}">@endif
        @if(request('vehicle'))<input type="hidden" name="vehicle" value="{{ request('vehicle') }}">@endif
        <label class="sr-only" for="inspVehicle">Inspection vehicle</label>
        <select name="inspection_vehicle" id="inspVehicle" data-autosubmit>
          <option value="">All vehicles</option>
          @foreach($vehicles as $v)<option value="{{ $v->id }}" @selected(request('inspection_vehicle') == $v->id)>{{ $v->label() }}</option>@endforeach
        </select>
        <label class="sr-only" for="inspResult">Inspection result</label>
        <select name="inspection_result" id="inspResult" data-autosubmit>
          <option value="">Any result</option>
          @foreach(\App\Models\VehicleInspection::RESULTS as $k => $lbl)<option value="{{ $k }}" @selected($inspectionResult === $k)>{{ $lbl }}</option>@endforeach
        </select>
        <noscript><button class="btn btn-sm">Filter</button></noscript>
      </form>
      @if($inspections->isEmpty())
        <p class="small muted m-0">No inspection matches this filter. Inspections are recorded from a vehicle's own page.</p>
      @else
        <div class="table-wrap">
          <table class="table" id="inspectionLogTable">
            <caption class="sr-only">Inspection log with result, checked items and any work order raised</caption>
            <thead><tr><th scope="col">When</th><th scope="col">Vehicle</th><th scope="col">Inspector</th><th scope="col">Odometer</th>
              <th scope="col">Result</th><th scope="col" class="num">Checks</th><th scope="col">Defects / work order</th></tr></thead>
            <tbody>
            @foreach($inspections as $i)
              <tr @class(['row-alert' => $i->result === 'major'])>
                <td class="small nowrap">{{ $i->inspected_at->format('d M Y H:i') }}</td>
                <td><a href="{{ route('tr.fleet.vehicle', $i->vehicle) }}"><b>{{ $i->vehicle?->code }}</b></a></td>
                <td class="small">{{ $i->inspector ?: '—' }}</td>
                <td class="num">{{ $i->odometer ? number_format($i->odometer) : '—' }}</td>
                <td><span class="badge badge-{{ \App\Models\VehicleInspection::TONES[$i->result] ?? 'slate' }}">{{ \App\Models\VehicleInspection::RESULTS[$i->result] ?? $i->result }}</span></td>
                <td class="num">{{ count($i->passedChecks()) }}/{{ count(\App\Models\VehicleInspection::CHECKS) }}</td>
                <td class="small">
                  @if($i->defects){{ \Illuminate\Support\Str::limit($i->defects, 90) }}@else<span class="muted">—</span>@endif
                  @if($i->workOrder)<div class="tiny text-amber"><a href="{{ route('tr.fleet.work-order.print', $i->workOrder) }}">{{ $i->workOrder->title }}</a> ({{ \App\Models\VehicleMaintenanceRecord::STATUSES[$i->workOrder->status] ?? $i->workOrder->status }})</div>@endif
                </td>
              </tr>
            @endforeach
            </tbody>
          </table>
        </div>
      @endif
  </div>
</div>

@if($inspections->hasPages())
  <div class="card"><div class="card-body">{{ $inspections->links() }}</div></div>
@endif

<div class="card">
  <div class="card-head"><h3>Workshop attention</h3><span class="small muted">Due dates and odometer thresholds from each vehicle’s service plan</span></div>
  <div class="card-body">
    @php $flag = $states->filter(fn ($s) => $s['overdue']->isNotEmpty() || $s['open']->isNotEmpty()); @endphp
    @if($flag->isEmpty())
      <p class="small muted m-0">No vehicle is due or in the workshop.</p>
    @else
      <ul class="small" style="margin:0;padding-left:1.1rem;display:grid;gap:.3rem">
        @foreach($vehicles as $v)
          @php $s = $states[$v->id]; @endphp
          @continue($s['overdue']->isEmpty() && $s['open']->isEmpty())
          <li>
            <b>{{ $v->code }}</b> ({{ number_format($v->odometer) }} km) –
            @if($s['overdue']->isNotEmpty())
              <span class="text-red">{{ $s['overdue']->pluck('name')->implode(', ') }} overdue</span>
            @endif
            @if($s['open']->isNotEmpty())
              {{ $s['open']->count() }} open job(s): {{ $s['open']->pluck('title')->take(3)->implode(', ') }}
            @endif
          </li>
        @endforeach
      </ul>
    @endif
  </div>
</div>

<div class="tabs" role="tablist" aria-label="Work order status">
  @php $tabs = ['' => 'Open', 'scheduled' => 'Scheduled', 'in_progress' => 'In progress', 'completed' => 'Completed', 'cancelled' => 'Cancelled']; @endphp
  @foreach($tabs as $k => $lbl)
    <a href="{{ route('tr.fleet.maintenance', array_filter(['status' => $k, 'vehicle' => request('vehicle')])) }}" @class(['active' => $status === $k])>{{ $lbl }}</a>
  @endforeach
</div>

<form class="filters" method="get" action="{{ route('tr.fleet.maintenance') }}">
  @if($status !== '')<input type="hidden" name="status" value="{{ $status }}">@endif
  <label class="sr-only" for="maintVehicle">Vehicle</label>
  <select name="vehicle" id="maintVehicle" data-autosubmit>
    <option value="">All vehicles</option>
    @foreach($vehicles as $v)<option value="{{ $v->id }}" @selected(request('vehicle') == $v->id)>{{ $v->label() }}</option>@endforeach
  </select>
</form>

<div class="card flush">
  <div class="card-body table-wrap">
    <table class="table" id="workOrders">
      <caption class="sr-only">Vehicle work orders with priority, due date, supplier and cost</caption>
      <thead><tr><th scope="col">Vehicle</th><th scope="col">Job</th><th scope="col">Priority</th><th scope="col">Status</th><th scope="col">Due</th><th scope="col" class="num">Estimate</th><th scope="col" class="num">Cost</th><th scope="col">Supplier</th><th scope="col"></th></tr></thead>
      <tbody>
      @forelse($records as $r)
        <tr>
          <td><b>{{ $r->vehicle?->code }}</b><div class="tiny muted">{{ $r->vehicle?->reg_no }}</div></td>
          <td>{{ $r->title }}<div class="tiny muted">{{ \App\Models\VehicleMaintenanceRecord::CATEGORIES[$r->category] ?? $r->category }}
            @if($r->plan) · plan {{ $r->plan->name }}@endif
            @if($r->due_odometer) · at {{ number_format($r->due_odometer) }} km @endif</div></td>
          <td>@php $p = ['low' => 'slate', 'normal' => 'blue', 'high' => 'amber', 'urgent' => 'red'][$r->priority] ?? 'slate'; @endphp<span class="badge badge-{{ $p }}">{{ \App\Models\VehicleMaintenanceRecord::PRIORITIES[$r->priority] ?? $r->priority }}</span></td>
          <td>{{ status_badge($r->status) }}</td>
          <td class="small">{{ $r->due_date?->format('d M Y') ?? '—' }}
            @if($r->due_date && $r->due_date->isPast() && in_array($r->status, ['scheduled', 'in_progress'], true))<div class="tiny text-red">Overdue</div>@endif</td>
          <td class="num">{{ (float) $r->estimated_cost > 0 ? money($r->estimated_cost) : '—' }}</td>
          <td class="num">{{ (float) $r->actual_cost > 0 ? money($r->actual_cost) : '—' }}</td>
          <td class="small">{{ $r->vendor ?: '—' }}@if($r->invoice_ref)<div class="tiny muted">{{ $r->invoice_ref }}</div>@endif</td>
          <td class="right nowrap">
            <a class="btn btn-sm btn-ghost" href="{{ route('tr.fleet.work-order.print', $r) }}" title="Printable job card for the garage"><x-icon name="print" size="sm"/><span class="sr-only">Print job card</span></a>
            @if(in_array($r->status, ['scheduled', 'in_progress'], true))
              <div class="fleet-actions">
                @if($r->status === 'scheduled')
                  <form method="post" action="{{ route('tr.fleet.records.start', $r) }}">@csrf<button class="btn btn-sm">Start</button></form>
                @endif
                <button class="btn btn-sm btn-primary" type="button" data-wo-toggle="complete-{{ $r->id }}" aria-expanded="false" aria-controls="complete-{{ $r->id }}">Complete</button>
                <form method="post" action="{{ route('tr.fleet.records.cancel', $r) }}" data-confirm="Cancel this work order?">@csrf
                  <input type="hidden" name="reason" value="Cancelled from the work order list"><button class="btn btn-sm btn-ghost" title="Cancel"><x-icon name="x"/></button></form>
              </div>
            @endif
          </td>
        </tr>
        @if(in_array($r->status, ['scheduled', 'in_progress'], true))
        <tr class="wo-panel" id="complete-{{ $r->id }}" hidden><td colspan="9">
          <form method="post" action="{{ route('tr.fleet.records.complete', $r) }}" class="form-grid">@csrf
            <x-field name="odometer" label="Odometer at completion" type="number" :value="$r->vehicle?->odometer"/>
            <x-field name="actual_cost" label="Invoice amount" type="number" step="0.01" :value="$r->estimated_cost"/>
            <x-field name="vendor" label="Supplier" :value="$r->vendor"/>
            <x-field name="invoice_ref" label="Invoice / job card no."/>
            <x-field name="notes" label="Work done" type="textarea" :value="$r->notes" class="span-all"/>
            <label class="check span-all"><input type="checkbox" name="log_expense" value="1" checked> Add the amount to this vehicle’s running expenses (fuel, service &amp; repair report)</label>
            <div class="span-all"><button class="btn btn-primary" id="completeWo{{ $r->id }}">Mark complete</button></div>
          </form>
        </td></tr>
        @endif
      @empty
        <tr><td colspan="9"><x-empty text="No work orders in this view." icon="tool"/></td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
  <div class="card-body">{{ $records->links() }}</div>
</div>

<div class="card" id="incidentLog">
  <div class="card-head"><h3>Incident log</h3>
    <span class="small muted">{{ $incidents['open'] }} live · {{ $incidents['serious'] }} major or worse · estimate {{ money($incidents['estimate']) }}</span></div>
  <div class="card-body table-wrap">
    @if($incidents['rows']->isEmpty())
      <p class="small muted m-0">Nothing has been reported against a vehicle. Record an accident, damage, breakdown or theft below — it is what an insurance claim or a police report has to stand on.</p>
    @else
      <table class="table" id="incidentTable">
        <caption class="sr-only">Vehicle incidents, live ones first</caption>
        <thead><tr><th scope="col">When</th><th scope="col">Vehicle</th><th scope="col">What happened</th><th scope="col">Severity</th>
          <th scope="col" class="num">Estimate</th><th scope="col">Status</th><th scope="col"><span class="sr-only">Update</span></th></tr></thead>
        <tbody>
        @foreach($incidents['rows'] as $i)
          <tr class="{{ $i->isOpen() && in_array($i->severity, ['major', 'critical'], true) ? 'row-alert' : '' }}">
            <td class="nowrap">{{ $i->occurred_at->format('d M') }}<div class="tiny muted">{{ $i->occurred_at->format('H:i') }}</div></td>
            <td><a href="{{ route('tr.fleet.vehicle', $i->vehicle) }}"><b>{{ $i->vehicle?->code }}</b></a>
              @if($i->driver)<div class="tiny muted">{{ $i->driver->name }}</div>@endif</td>
            <td class="small">{{ \App\Models\VehicleIncident::KINDS[$i->kind] ?? $i->kind }}
              <div class="tiny muted">{{ \Illuminate\Support\Str::limit($i->description, 90) }}</div>
              @if($i->location || $i->police_ref)
                <div class="tiny muted">{{ $i->location }}{{ $i->location && $i->police_ref ? ' · ' : '' }}{{ $i->police_ref ? 'Police '.$i->police_ref : '' }}</div>
              @endif</td>
            <td><span class="badge badge-{{ \App\Models\VehicleIncident::TONES[$i->severity] ?? 'slate' }}">{{ \App\Models\VehicleIncident::SEVERITIES[$i->severity] ?? $i->severity }}</span></td>
            <td class="num">{{ $i->estimated_cost !== null ? money($i->estimated_cost) : '—' }}</td>
            <td class="small">{{ \App\Models\VehicleIncident::STATUSES[$i->status] ?? $i->status }}
              @if($i->closure_note)<div class="tiny muted">{{ $i->closure_note }}</div>@endif</td>
            <td class="small">
              <details>
                <summary class="tiny">{{ $i->isOpen() ? 'Update' : 'Reopen' }}</summary>
                <form method="post" action="{{ route('tr.fleet.incidents.status', $i) }}" class="form-grid" style="margin-top:.4rem;min-width:230px">@csrf
                  <label class="tiny" for="incStatus{{ $i->id }}">New status</label>
                  <select name="status" id="incStatus{{ $i->id }}">
                    @foreach(\App\Models\VehicleIncident::STATUSES as $k => $label)
                      @if($i->isOpen() || $k === 'open')
                        <option value="{{ $k }}" @selected($i->status === $k)>{{ $label }}</option>
                      @endif
                    @endforeach
                  </select>
                  <label class="tiny" for="incNote{{ $i->id }}">Note (logged)</label>
                  <input type="text" id="incNote{{ $i->id }}" name="note" minlength="4" maxlength="255" required placeholder="Claim filed with the insurer">
                  <button class="btn btn-sm btn-primary">Save status</button>
                </form>
              </details>
            </td>
          </tr>
        @endforeach
        </tbody>
      </table>
      <p class="tiny muted" style="padding:.5rem .9rem .6rem">The estimate is what the property expects the incident to cost, for the insurer — it is <b>not</b> money posted.
        Actual repair spend goes on the work order and into running costs, and closing an incident never changes either.</p>
    @endif
  </div>
</div>

<div class="card">
  <div class="card-head"><h3>Report an incident</h3></div>
  <div class="card-body">
    <form method="post" action="{{ route('tr.fleet.incidents.store') }}" class="form-grid" id="incidentForm">@csrf
      <x-field name="vehicle_id" label="Vehicle" type="select" :options="$vehicles->mapWithKeys(fn ($v) => [$v->id => $v->label()])" required class="span-all"/>
      <x-field name="occurred_at" label="When it happened" type="datetime-local" :value="now()->format('Y-m-d\TH:i')" required/>
      <x-field name="kind" label="What happened" type="select" :options="\App\Models\VehicleIncident::KINDS" :placeholder="false" required/>
      <x-field name="severity" label="Severity" type="select" :options="\App\Models\VehicleIncident::SEVERITIES" :placeholder="false" required/>
      <x-field name="location" label="Where"/>
      <x-field name="third_party" label="Other vehicle / person"/>
      <x-field name="police_ref" label="Police report no."/>
      <x-field name="estimated_cost" label="Estimated cost" type="number" step="0.01"/>
      <x-field name="description" label="What happened" type="textarea" required class="span-all" hint="Facts only: what, where, who was driving, what was damaged."/>
      <div class="span-all"><button class="btn btn-primary" id="saveIncidentBtn"><x-icon name="alert"/> Log incident</button></div>
    </form>
    <p class="tiny muted mt-1">Logging an incident raises nothing automatically: open a work order for the repair and post the actual cost there,
      so the insurer's estimate here and the money spent stay separate.</p>
  </div>
</div>

<div class="grid g-2">
  <div class="card"><div class="card-head"><h3>Log a work order</h3></div><div class="card-body">
    <form method="post" action="{{ route('tr.fleet.records.store') }}" class="form-grid" id="woForm">@csrf
      <x-field name="vehicle_id" label="Vehicle" type="select" :options="$vehicles->mapWithKeys(fn ($v) => [$v->id => $v->label()])" required/>
      <x-field name="plan_id" label="Against a service plan" type="select" :options="$plans->filter(fn ($p) => $p->vehicle)->mapWithKeys(fn ($p) => [$p->id => $p->vehicle->code.' · '.$p->name])" placeholder="— None —"/>
      <x-field name="category" label="Category" type="select" :options="\App\Models\VehicleMaintenanceRecord::CATEGORIES" :placeholder="false"/>
      <x-field name="priority" label="Priority" type="select" :options="\App\Models\VehicleMaintenanceRecord::PRIORITIES" :value="'normal'" :placeholder="false"/>
      <x-field name="title" label="Job" required hint="e.g. 60,000 km service, brake pads"/>
      <x-field name="due_date" label="Due date" type="date"/>
      <x-field name="due_odometer" label="Due at (km)" type="number"/>
      <x-field name="vendor" label="Garage / supplier"/>
      <x-field name="estimated_cost" label="Estimated cost" type="number" step="0.01"/>
      <x-field name="description" label="Description" type="textarea" class="span-all"/>
      <label class="check span-all"><input type="checkbox" name="block_vehicle" value="1"> Take the vehicle off the road now (block new trip assignments)</label>
      <div class="span-all"><button class="btn btn-primary" id="saveWoBtn"><x-icon name="plus"/> Log work order</button></div>
    </form>
  </div></div>

  <div class="card"><div class="card-head"><h3>Service plans</h3><span class="small muted">Recurring by days, kilometres or both</span></div><div class="card-body">
    <form method="post" action="{{ route('tr.fleet.plans.store') }}" class="form-grid" id="planForm">@csrf
      <x-field name="vehicle_id" label="Vehicle" type="select" :options="$vehicles->mapWithKeys(fn ($v) => [$v->id => $v->label()])" required/>
      <x-field name="name" label="Plan name" required hint="e.g. Engine oil &amp; filters"/>
      <x-field name="category" label="Category" type="select" :options="\App\Models\VehicleMaintenancePlan::CATEGORIES" :placeholder="false"/>
      <x-field name="interval_days" label="Every (days)" type="number"/>
      <x-field name="interval_km" label="Every (km)" type="number"/>
      <x-field name="last_completed_date" label="Last done (date)" type="date"/>
      <x-field name="last_completed_odometer" label="Last done at (km)" type="number"/>
      <x-field name="notes" label="Notes" type="textarea" class="span-all"/>
      <div class="span-all"><button class="btn btn-primary" id="savePlanBtn"><x-icon name="plus"/> Add plan</button></div>
    </form>
    <p class="tiny muted mt-1">Completing a work order that is linked to a plan rolls the plan forward automatically using its interval.</p>
  </div></div>
</div>

<div class="card flush">
  <div class="card-head"><h3>Active plans</h3></div>
  <div class="card-body table-wrap">
    <table class="table" id="planTable">
      <caption class="sr-only">Service plans with their next due date, odometer and completion history</caption>
      <thead><tr><th scope="col">Vehicle</th><th scope="col">Plan</th><th scope="col">Interval</th><th scope="col">Next due</th><th scope="col">Last done</th><th scope="col"></th></tr></thead>
      <tbody>
      @forelse($plans as $p)
        <tr>
          <td><b>{{ $p->vehicle?->code }}</b><div class="tiny muted">{{ number_format((int) $p->vehicle?->odometer) }} km</div></td>
          <td>{{ $p->name }}<div class="tiny muted">{{ \App\Models\VehicleMaintenancePlan::CATEGORIES[$p->category] ?? $p->category }}</div></td>
          <td class="small">{{ $p->interval_days ? $p->interval_days.' days' : '' }}{{ $p->interval_days && $p->interval_km ? ' / ' : '' }}{{ $p->interval_km ? number_format($p->interval_km).' km' : '' }}</td>
          <td class="small">
            @if($p->due_date){{ $p->due_date->format('d M Y') }}@if($p->due_date->isPast()) <span class="badge badge-red">Overdue</span>@endif @endif
            @if($p->due_odometer)<div class="tiny muted">at {{ number_format($p->due_odometer) }} km</div>@endif
          </td>
          <td class="small">{{ $p->last_completed_date?->format('d M Y') ?? '—' }}@if($p->last_completed_odometer)<div class="tiny muted">{{ number_format($p->last_completed_odometer) }} km</div>@endif</td>
          <td class="right"><form method="post" action="{{ route('tr.fleet.plans.retire', $p) }}" data-confirm="Retire this plan? History is kept.">@csrf<button class="btn btn-sm btn-ghost" title="Retire plan"><x-icon name="trash"/></button></form></td>
        </tr>
      @empty
        <tr><td colspan="6"><x-empty text="No service plans yet – add intervals so due dates are calculated for you." icon="tool"/></td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
</div>
@endsection
@push('scripts')<script>
  // Complete-work-order panels: keyboard accessible, announced through aria-expanded.
  document.querySelectorAll('[data-wo-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var row = document.getElementById(btn.getAttribute('data-wo-toggle'));
      if (!row) { return; }
      var open = row.hasAttribute('hidden');
      if (open) { row.removeAttribute('hidden'); } else { row.setAttribute('hidden', ''); }
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) { var f = row.querySelector('input,select,textarea'); f && f.focus(); }
    });
  });
</script>@endpush
