@extends('layouts.app')
@section('title', 'Food & Beverage Service Shifts')
@section('crumbs') Operations / My Shift / Food & Beverage @endsection
@section('actions')<a class="btn" href="{{ route('my-shift') }}"><x-icon name="clock"/> My Shift</a>@endsection
@section('content')
<div class="alert alert-info"><x-icon name="users"/><div>This is a service shift, not a cashier drawer. Each waiter opens and closes under their own login; POS orders they create while it is open are linked to their individual report. Cashiers reconcile payments in Cashiering. @if(\App\Services\DepartmentShiftService::requiresPersonalShift(auth()->user())) Opening your shift unlocks POS order entry; closing it locks the terminal until you open another shift.@endif</div></div>
<div class="grid g-2">
  <section class="card" aria-labelledby="serviceShiftHeading">
    @if($currentServiceShift)
      @php $liveSummary = \App\Services\DepartmentShiftService::summary($currentServiceShift); @endphp
      <div class="card-head"><div><h2 id="serviceShiftHeading">Your service shift is open</h2><div class="small muted">{{ ucfirst($currentServiceShift->shift) }} · {{ $currentServiceShift->business_date->format('d M Y') }} · opened {{ $currentServiceShift->opened_at->format('H:i') }} ({{ $currentServiceShift->opened_at->diffForHumans() }})</div></div><div class="flex gap-1 items-center"><a class="btn btn-sm btn-primary" href="{{ route('pos.index') }}">Open POS Terminal</a><span class="badge badge-green">In progress</span></div></div>
      <div class="card-body">
        <div class="grid g-4">
          <div><span class="small muted">Orders handled</span><div class="h2">{{ $liveSummary['orders'] }}</div></div>
          <div><span class="small muted">Covers</span><div class="h2">{{ $liveSummary['covers'] }}</div></div>
          <div><span class="small muted">Order value</span><div class="h2">{{ money($liveSummary['order_value']) }}</div></div>
          <div><span class="small muted">Still open</span><div class="h2">{{ $liveSummary['open_orders'] }}</div></div>
        </div>
        @if($currentServiceShift->opening_note)<p class="small mt-1"><b>Opening note:</b> {{ $currentServiceShift->opening_note }}</p>@endif
        @php $livePayments = $liveSummary['payments'] ?? []; @endphp
        <div class="flex justify-between items-center mt-1" style="gap:.5rem;flex-wrap:wrap">
          <div class="flex gap-1 wrap items-center">
            @foreach($livePayments as $row)
              <span class="badge badge-{{ ! empty($row['is_cash']) ? 'green' : 'blue' }}" title="{{ $row['count'] }} tender{{ $row['count'] === 1 ? '' : 's' }}">{{ $row['name'] }} {{ money($row['net']) }}</span>
            @endforeach
            <span class="small"><b>Total, all methods: {{ money($liveSummary['payments_total'] ?? 0) }}</b>@if(($liveSummary['room_posts'] ?? 0) > 0)<span class="tiny muted"> · {{ money($liveSummary['room_posts']) }} posted to rooms</span>@endif</span>
          </div>
          <a class="btn btn-sm" href="{{ route('department-shifts.print', $currentServiceShift) }}" target="_blank" rel="noopener"><x-icon name="print" size="sm"/> Print totals sheet</a>
        </div>
        @if(! $livePayments)<p class="tiny muted mt-1">No payment has been collected on this shift yet — room-posted orders are billed to the guest folio.</p>@endif
      </div>
      @if(can_do('pos.order'))
        <div class="card-foot"><form method="post" action="{{ route('department-shifts.close', $currentServiceShift) }}" data-confirm="Close your service shift and save this close-out? POS order entry will lock until you open another shift. Any open orders remain open for handover.">@csrf
          <div class="flex gap-1 wrap items-end"><div class="field" style="flex:1;min-width:220px"><label for="closing_note">Closing note <span class="muted">(optional)</span></label><input id="closing_note" name="closing_note" maxlength="1000" placeholder="Guest follow-ups, open tables, service notes…"></div><button class="btn btn-primary"><x-icon name="check"/> Close shift &amp; save report</button></div>
        </form></div>
      @endif
    @elseif(can_do('pos.order'))
      <div class="card-head"><div><h2 id="serviceShiftHeading">Open your service shift</h2><div class="small muted">Other team members can open the same shift independently.</div></div><span class="badge badge-blue">{{ ucfirst(\App\Services\FrontDeskOpsService::currentShift()) }} shift</span></div>
      <form method="post" action="{{ route('department-shifts.start') }}" class="card-body form-grid">@csrf
        <x-field name="opening_note" label="Opening note" placeholder="Station, section or opening checks (optional)" hint="Keep notes short and operational."/>
        <div class="span-all"><button class="btn btn-primary"><x-icon name="clock"/> Start my service shift</button></div>
      </form>
    @else
      <div class="card-head"><h2 id="serviceShiftHeading">Food &amp; Beverage shift activity</h2><span class="badge badge-slate">Read only</span></div>
      <div class="card-body small muted">Your role can view team POS activity but cannot start or close a waiter shift.</div>
    @endif
  </section>

  <section class="card">
    <div class="card-head"><div><h2>Department day report</h2><div class="small muted">Individual server close-outs grouped by shift.</div></div><a class="btn btn-sm" href="{{ route('department-shifts.day-print', ['date' => $serviceShiftDate]) }}" target="_blank" rel="noopener"><x-icon name="print" size="sm"/> Print day report</a></div>
    <form method="get" class="card-body flex gap-1 wrap items-end"><div class="field"><label for="serviceShiftDate">Business date</label><input id="serviceShiftDate" type="date" name="date" value="{{ $serviceShiftDate }}"></div><button class="btn">View day</button>@if($serviceShiftDate !== bdate()->toDateString())<a class="btn btn-ghost" href="{{ route('department-shifts.index') }}">Today</a>@endif</form>
    @if($serviceDaySummary->isNotEmpty())
      <div class="card-body table-wrap"><table class="table table-compact"><thead><tr><th>Shift</th><th class="num">Staff sessions</th><th class="num">Active</th><th class="num">Orders</th><th class="num">Covers</th><th class="num">Order value</th><th class="num">Settled POS sales</th><th class="num">Collected by method</th><th class="num">Open orders</th></tr></thead><tbody>
        @foreach($serviceDaySummary as $shift => $summary)<tr><td><b>{{ ucfirst($shift) }}</b></td><td class="num">{{ $summary['people'] }}</td><td class="num">{{ $summary['active'] }}</td><td class="num">{{ $summary['orders'] }}</td><td class="num">{{ $summary['covers'] }}</td><td class="num">{{ money($summary['order_value']) }}</td><td class="num">{{ money($summary['settled_sales']) }}</td><td class="num">{{ money($summary['payments_total'] ?? 0) }}</td><td class="num">{{ $summary['open_orders'] }}</td></tr>@endforeach
      </tbody><tfoot><tr><td><b>All shifts</b></td><td class="num">{{ $serviceDaySummary->sum('people') }}</td><td class="num">{{ $serviceDaySummary->sum('active') }}</td><td class="num">{{ $serviceDaySummary->sum('orders') }}</td><td class="num">{{ $serviceDaySummary->sum('covers') }}</td><td class="num">{{ money($serviceDaySummary->sum('order_value')) }}</td><td class="num">{{ money($serviceDaySummary->sum('settled_sales')) }}</td><td class="num">{{ money($serviceDaySummary->sum('payments_total')) }}</td><td class="num">{{ $serviceDaySummary->sum('open_orders') }}</td></tr></tfoot></table></div>
    @else
      <div class="card-body small muted">No Food &amp; Beverage service shifts were recorded for this business date{{ $canViewServiceTeam ? '.' : ' for your account.' }}</div>
    @endif
  </section>
</div>

<section class="card flush mt-2">
  <div class="card-head"><div><h2>{{ $canViewServiceTeam ? 'Team shift sessions' : 'My shift sessions' }}</h2><div class="small muted">{{ $canViewServiceTeam ? 'Staff on the selected business date.' : 'Your individual session history.' }}</div></div></div>
  <div class="card-body table-wrap"><table class="table"><thead><tr><th>Staff member</th><th>Shift / date</th><th>Opened / closed</th><th class="num">Orders</th><th class="num">Covers</th><th class="num">Order value</th><th class="num">Settled sales</th><th>Status</th><th></th></tr></thead><tbody>
    @forelse($teamServiceSessions as $session)
      <tr><td>{{ $session->user?->name ?? 'Former staff member' }}@if((int) $session->user_id === (int) auth()->id()) <span class="badge badge-blue">You</span>@endif</td><td>{{ ucfirst($session->shift) }}<div class="small muted">{{ $session->business_date->format('d M Y') }}</div></td><td class="small">{{ $session->opened_at->format('H:i') }} – {{ $session->closed_at?->format('H:i') ?? '—' }}</td><td class="num">{{ $session->report_summary['orders'] }}</td><td class="num">{{ $session->report_summary['covers'] }}</td><td class="num">{{ money($session->report_summary['order_value']) }}</td><td class="num">{{ money($session->report_summary['settled_sales']) }}</td><td><span class="badge badge-{{ $session->status === 'open' ? 'green' : 'slate' }}">{{ $session->statusLabel() }}</span></td><td><a class="btn btn-sm" href="{{ route('department-shifts.show', $session) }}">Report</a> <a class="btn btn-sm" href="{{ route('department-shifts.print', $session) }}" target="_blank" rel="noopener" title="Print shift totals"><x-icon name="print" size="sm"/> Print</a></td></tr>
    @empty<tr><td colspan="9" class="muted">No individual shift sessions found for this date.</td></tr>@endforelse
  </tbody></table></div>
</section>

@if($ownServiceSessions->isNotEmpty())
<section class="card flush mt-2"><div class="card-head"><h2>My recent service shift reports</h2><span class="small muted">Latest 30</span></div><div class="card-body table-wrap"><table class="table table-compact"><thead><tr><th>Business date</th><th>Shift</th><th>Opened</th><th>Closed</th><th>Status</th><th></th></tr></thead><tbody>
  @foreach($ownServiceSessions as $session)<tr><td>{{ $session->business_date->format('d M Y') }}</td><td>{{ ucfirst($session->shift) }}</td><td>{{ $session->opened_at->format('H:i') }}</td><td>{{ $session->closed_at?->format('H:i') ?? '—' }}</td><td>{{ $session->statusLabel() }}</td><td><a class="btn btn-sm" href="{{ route('department-shifts.show', $session) }}">Report</a></td></tr>@endforeach
</tbody></table></div></section>
@endif
@endsection
