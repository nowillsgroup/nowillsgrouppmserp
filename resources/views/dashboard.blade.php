@extends('layouts.app')
@section('title', 'Dashboard')
@section('crumbs') Operations · {{ $currentHotel->name }} @endsection
@section('actions')
  <div class="dash-page-actions">
    @if(can_do('reservations.create'))
      <a class="btn btn-primary" href="{{ route('reservations.create') }}"><x-icon name="calendar" size="sm"/> New reservation</a>
    @endif
  </div>
@endsection
@push('head')
<link rel="stylesheet" href="{{ asset('css/dashboard.css') }}?v={{ config('pms.version') }}-dashboard-layout-v5">
@endpush
@section('content')
<div class="dashboard-page">
  <section class="dash-welcome" aria-label="Property dashboard summary">
    <div class="dash-welcome-copy">
      <span class="dash-eyebrow">PROPERTY OPERATIONS</span>
      <h2>Welcome back, {{ auth()->user()->name }}</h2>
      <p>Here is today’s operating picture for {{ $currentHotel->name }}.</p>
    </div>
    <div class="dash-business-date"><span>Business date</span><strong>{{ bdate()->format('l, d M Y') }}</strong></div>
  </section>
  <section class="dash-section" aria-labelledby="dashRoomsHeading">
    <div class="dash-section-heading">
      <div><span class="dash-section-kicker">ROOMS &amp; GUEST FLOW</span><h2 id="dashRoomsHeading">Property at a glance</h2><p>Current room position and today’s guest movement.</p></div>
      <span class="dash-section-note">Live property snapshot</span>
    </div>
<div class="stats dash-stats dash-stats-rooms">
  <x-stat label="Occupancy ({{ $occupied }}/{{ $sellable }} sellable rooms)" :value="$occPct.'%'" icon="bed" tone="blue" :href="can_do('frontdesk.view') ? route('frontdesk.rooms') : null"/>
  <x-stat label="Arrivals Today" :value="$arrivals->count().' / '.($arrivals->count() + $checkedInToday)" icon="login" tone="green" :href="can_do('frontdesk.view') ? route('frontdesk.arrivals') : null"/>
  <x-stat label="Departures Pending" :value="$departures->count()" icon="logout" tone="amber" :href="can_do('frontdesk.view') ? route('frontdesk.departures') : null"/>
  <x-stat label="In-House Guests" :value="$inHouseGuests" icon="users" tone="purple" :href="can_do('frontdesk.view') ? route('frontdesk.inhouse') : null"/>
</div>
  </section>
  <section class="dash-section dash-finance-section" aria-labelledby="dashRevenueHeading">
    <div class="dash-section-heading">
      <div><span class="dash-section-kicker">TODAY’S PERFORMANCE</span><h2 id="dashRevenueHeading">Revenue snapshot</h2><p>Daily revenue and collection measures for the current business date.</p></div>
      <span class="dash-section-note">Amounts in property currency</span>
    </div>
<div class="stats dash-stats dash-stats-finance">
  <x-stat label="Room Revenue (today)" :value="money($roomRevenue)" icon="money" tone="blue"/>
  <x-stat label="Other Revenue (today)" :value="money($otherRevenue)" icon="cup" tone="teal"/>
  <x-stat label="Collections (today)" :value="money($collections)" icon="wallet" tone="green"/>
  <x-stat label="ADR (today)" :value="money($adr)" icon="chart" tone="purple"/>
</div>
  </section>

{{-- v5.4 KPI alert events + pinned report views --}}
@php $kpiEv = can_do('reports.alerts') ? \App\Services\KpiService::openEvents() : collect(); $pinned = can_do('reports.view') ? \App\Services\ReportScheduleService::viewsFor(auth()->user())->where('user_id', auth()->id())->where('is_pinned', true) : collect(); @endphp
@if($kpiEv->isNotEmpty() || $pinned->isNotEmpty())
<div class="dash-notices">
@endif
@if($kpiEv->isNotEmpty())
<div class="card dash-notice-card" id="dashKpi"><div class="card-head"><h3><x-icon name="alert"/> KPI alerts <span class="badge badge-red">{{ $kpiEv->count() }}</span></h3><a class="small" href="{{ route('reports.alerts') }}">Acknowledge</a></div>
  <div class="card-body" style="padding-top:.3rem"><ul class="list dash-kpi-list">@foreach($kpiEv->take(5) as $ev)
    <li><span class="{{ $ev->severity === 'critical' ? 'text-red' : ($ev->severity === 'warning' ? 'text-amber' : '') }}"><span class="badge badge-{{ ['critical' => 'red', 'warning' => 'amber', 'info' => 'blue'][$ev->severity] ?? 'slate' }}">{{ $ev->severity }}</span> {{ $ev->message }}</span><span class="tiny muted nowrap">{{ $ev->business_date->format('d M') }}</span></li>
  @endforeach @if($kpiEv->count() > 5)<li><span class="muted small">+ {{ $kpiEv->count() - 5 }} more</span><a href="{{ route('reports.alerts') }}"><b>View all</b></a></li>@endif</ul></div></div>
@endif
@if($pinned->isNotEmpty())
<div class="card dash-notice-card" id="dashPinned">
  <div class="card-head"><h3>Pinned reports</h3><a class="small" href="{{ route('reports.library') }}">Manage</a></div>
  <div class="card-body dash-pinned-reports">@foreach($pinned as $pv)<a class="btn btn-sm btn-soft" href="{{ $pv->url() }}">{{ $pv->name }}</a>@endforeach</div>
</div>
@endif
@if($kpiEv->isNotEmpty() || $pinned->isNotEmpty())
</div>
@endif

<div class="grid g-2-1 dash-main-grid">
  <div class="dash-main-column">
    <div class="card dash-chart-card">
      <div class="card-head dash-chart-head">
        <div><h3>Daily revenue &amp; occupancy</h3><p class="dash-card-subtitle">Last 14 audit days · hover, tap or focus a bar to see revenue and occupancy.</p></div>
        @if(can_do('nightaudit.run'))<a class="btn btn-sm btn-ghost" href="{{ route('nightaudit.index') }}">Details</a>@elseif(can_do('reports.view'))<a class="btn btn-sm btn-ghost" href="{{ route('reports.show', 'revenue') }}">Details</a>@endif
      </div>
      <div class="card-body">
        @php $max = max(1, $audits->max(fn($a) => $a->room_revenue + $a->other_revenue)); @endphp
        @if($audits->isEmpty())<x-empty text="Run night audit to build history." icon="chart"/>@else
        <div class="bar-chart">
          @foreach($audits as $a)
            @php $v = $a->room_revenue + $a->other_revenue; @endphp
            <div class="col"><div class="b" role="img" tabindex="0" aria-label="{{ $a->business_date->format('d M Y') }}: {{ money($v) }} revenue, {{ $a->occupancy_pct }}% occupancy" title="{{ money($v) }} revenue · {{ $a->occupancy_pct }}% occupied" style="height: {{ max(2, $v / $max * 100) }}%" data-v="{{ money($v) }} · {{ $a->occupancy_pct }}%"></div><small>{{ $a->business_date->format('d/m') }}</small></div>
          @endforeach
        </div>
        @endif
      </div>
    </div>

    <div class="grid g-2 dash-stay-grid">
      <div class="card">
        <div class="card-head"><h3>Expected Arrivals</h3><a href="{{ route('frontdesk.arrivals') }}" class="small">View all</a></div>
        <div class="card-body" style="padding-top:.3rem">
          <ul class="list">
            @forelse($arrivals->take(6) as $s)
              <li><div><b>{{ $s->reservation->guest->full_name }}</b><div class="tiny muted">{{ $s->reservation->reservation_no }} · {{ $s->room?->room_no ?? 'Unassigned' }} · {{ \App\Services\StayService::checkInReady($s) }}</div></div>
                @if(can_do('frontdesk.checkin'))<a class="btn btn-sm btn-success" href="{{ route('frontdesk.checkin', $s) }}">Check-in</a>@endif</li>
            @empty <li class="muted">No pending arrivals.</li> @endforelse
          </ul>
        </div>
      </div>
      <div class="card">
        <div class="card-head"><h3>Due Departures</h3><a href="{{ route('frontdesk.departures') }}" class="small">View all</a></div>
        <div class="card-body" style="padding-top:.3rem">
          <ul class="list">
            @forelse($departures->take(6) as $s)
              <li><div><b>{{ $s->room?->room_no }} · {{ $s->reservation->guest->full_name }}</b><div class="tiny muted">{{ $s->reservation->reservation_no }} · @php $due = \App\Services\StayService::quoteCheckoutFigures($s)['due']; @endphp {{ $due === null ? 'No folio' : 'To settle '.money($due).' · not posted' }}</div></div>
                @if(can_do('frontdesk.checkout'))<a class="btn btn-sm btn-warning" href="{{ route('frontdesk.checkout', $s) }}">Check-out</a>@endif</li>
            @empty <li class="muted">No pending departures.</li> @endforelse
          </ul>
        </div>
      </div>
    </div>

    <div class="card dash-activity-card">
      <div class="card-head"><h3>Recent Activity</h3>@if(can_do('logs.view'))<a href="{{ route('logs.index') }}" class="small">Activity log</a>@endif</div>
      <div class="card-body" style="padding-top:.3rem"><ul class="list">
        @forelse($activity as $a)<li><div><span class="badge badge-slate">{{ $a->module }}</span> {{ $a->description }}</div><span class="tiny muted nowrap">{{ $a->user?->name }} · {{ $a->created_at->diffForHumans() }}</span></li>
        @empty <li class="muted">No activity yet.</li> @endforelse
      </ul></div>
    </div>
  </div>

  <aside class="dash-sidebar">
    <div class="card dash-room-card">
      <div class="card-head"><h3>Room Status</h3>@if(can_do('frontdesk.view'))<a class="small" href="{{ route('frontdesk.rooms') }}">View rooms</a>@endif</div>
      <div class="card-body flex gap-2 items-center">
        @php $pO = $total ? $occupied/$total*100 : 0; $pV = $total ? $vacant/$total*100 : 0; @endphp
        <div class="donut" role="img" aria-label="{{ $occPct }}% occupancy of sellable rooms: {{ $occupied }} occupied, {{ $vacant }} vacant, {{ $unavailable }} unavailable (outages or leases), {{ $dirty }} dirty" style="background: conic-gradient(#dc2626 0 {{ $pO }}%, #16a34a {{ $pO }}% {{ $pO+$pV }}%, #94a3b8 {{ $pO+$pV }}% 100%)"><div><div><b style="font-size:1.4rem">{{ $occPct }}%</b><div class="tiny muted">occupied</div></div></div></div>
        <ul class="list" style="flex:1">
          <li><span><span class="badge badge-red">Occupied</span></span><b>{{ $occupied }}</b></li>
          <li><span><span class="badge badge-green">Vacant</span></span><b>{{ $vacant }}</b></li>
          <li><span><span class="badge badge-slate">Unavailable</span></span><b>{{ $unavailable }}</b></li>
          <li><span><span class="badge badge-amber">Dirty</span></span><b>{{ $dirty }}</b></li>
        </ul>
      </div>
    </div>
    @if(can_do('frontdesk.view') || can_do('pos.view') || can_do('housekeeping.view') || can_do('nightaudit.run') || can_do('reports.view'))
    <div class="card dash-shortcuts-card">
      <div class="card-head"><h3>Shortcuts</h3></div>
      <div class="card-body quick">
        @if(can_do('frontdesk.view'))
          <a href="{{ route('frontdesk.rooms') }}"><x-icon name="grid"/>Room View</a>
          <a href="{{ route('frontdesk.tapechart') }}"><x-icon name="calendar"/>Tape Chart</a>
        @endif
        @if(can_do('pos.view'))<a href="{{ route('pos.index') }}"><x-icon name="cup"/>POS</a>@endif
        @if(can_do('housekeeping.view'))<a href="{{ route('housekeeping.index') }}"><x-icon name="broom"/>Housekeeping</a>@endif
        @if(can_do('nightaudit.run'))<a href="{{ route('nightaudit.index') }}"><x-icon name="moon"/>Night Audit</a>@endif
        @if(can_do('reports.view'))<a href="{{ route('reports.index') }}"><x-icon name="chart"/>Reports</a>@endif
      </div>
    </div>
    @endif
  </aside>
</div>

<section class="dash-section dash-attention-section" aria-labelledby="dashAttentionHeading">
  <div class="card dash-attention-card" id="dashAttention">
    <div class="card-head">
      <div><h3 id="dashAttentionHeading">Action centre</h3><p class="dash-card-subtitle">Operational alerts and follow-ups, grouped in one place.</p></div>
      <span class="dash-snapshot-label">Across departments</span>
    </div>
      <div class="card-body" style="padding-top:.3rem"><ul class="list dash-alert-list">
        <li><span><x-icon name="tool" size="sm"/> Open maintenance tickets</span><a href="{{ route('maintenance.index') }}"><b>{{ $openTickets }}</b></a></li>
        <li><span><x-icon name="cup" size="sm"/> Running POS orders</span><a href="{{ route('pos.orders') }}"><b>{{ $openPos }}</b></a></li>
        <li><span><x-icon name="calendar" size="sm"/> New bookings today</span><b>{{ $newBookings }}</b></li>
        @if(can_do('contracts.view')) @php $ka = \App\Services\ContractService::alerts(); @endphp
          @if($ka['expiring'])<li id="dashContractsExpiring"><span class="text-amber"><x-icon name="book" size="sm"/> Rate contracts expiring soon</span><a href="{{ route('contracts.index', ['tab' => 'expiring']) }}"><b>{{ $ka['expiring'] }}</b></a></li>@endif
          @if($ka['drafts'] && can_do('contracts.approve'))<li><span><x-icon name="book" size="sm"/> Contracts awaiting approval</span><a href="{{ route('contracts.index', ['tab' => 'draft']) }}"><b>{{ $ka['drafts'] }}</b></a></li>@endif
        @endif
        @if(can_do('credit.view') && ($holds = \App\Models\Company::where('credit_status', 'hold')->count()))<li id="dashCreditHold"><span class="text-red"><x-icon name="lock" size="sm"/> Companies on credit hold</span><a href="{{ route('credit.index', ['show' => 'hold']) }}"><b>{{ $holds }}</b></a></li>@endif
        @if(can_do('channels.view'))
          @if($obk = \App\Models\Reservation::where('channel_attention', 'overbooked')->where('status', '!=', 'cancelled')->count())<li id="dashOverbooked"><span class="text-red"><x-icon name="globe" size="sm"/> OTA overbookings to resolve</span><a href="{{ route('cm.index') }}"><b>{{ $obk }}</b></a></li>@endif
          @if($chAtt = \App\Services\ChannelService::attentionCount())<li id="dashChannelInbox"><span><x-icon name="globe" size="sm"/> OTA messages needing attention</span><a href="{{ route('cm.inbox') }}"><b>{{ $chAtt }}</b></a></li>@endif
          @if($chFail = \App\Models\ChannelConnection::where('is_active', true)->where('last_push_ok', false)->count())<li id="dashChannelFail"><span class="text-red"><x-icon name="refresh" size="sm"/> Channel sync failing</span><a href="{{ route('cm.index') }}"><b>{{ $chFail }}</b></a></li>@endif
        @endif
        @if(can_do('privacy.view'))
          @php $pvA = \App\Services\PrivacyService::alerts(); @endphp
          @if($pvA['overdue'])<li id="dashPrivacyOverdue"><span class="text-red"><x-icon name="shield" size="sm"/> Privacy requests past the deadline</span><a href="{{ route('privacy.index', ['tab' => 'overdue']) }}"><b>{{ $pvA['overdue'] }}</b></a></li>@endif
          @if($pvA['due_soon'])<li id="dashPrivacyDue"><span><x-icon name="shield" size="sm"/> Privacy requests due within 7 days</span><a href="{{ route('privacy.index') }}"><b>{{ $pvA['due_soon'] }}</b></a></li>@endif
          @if($pvA['breach_next'])<li id="dashBreach"><span class="text-red"><x-icon name="alert" size="sm"/> Breach {{ $pvA['breach_next']->ref }}: notify the Board by {{ $pvA['breach_next']->deadline()->format('d M H:i') }}</span><a href="{{ route('privacy.breaches.show', $pvA['breach_next']) }}"><b>{{ $pvA['breach'] }}</b></a></li>@endif
        @endif
        @if(can_do('gst.view'))
          @php $gsA = \App\Services\GstService::alerts(); @endphp
          @if($gsA['failed'])<li id="dashEinvFailed"><span class="text-red"><x-icon name="qr" size="sm"/> E-VAT submissions rejected / failed</span><a href="{{ route('gst.einvoices') }}"><b>{{ $gsA['failed'] }}</b></a></li>@endif
          @if(can_do('bankrec.view') && ($brO = \App\Services\BankRecService::overview())->contains(fn ($o) => in_array($o['state'], ['overdue'], true) || $o['review'] || $o['unmatched']))
            @php $brBad = $brO->filter(fn ($o) => $o['state'] === 'overdue' || $o['review']); $brOpen = $brO->sum('unmatched'); @endphp
            <li id="dashBankRec"><span class="{{ $brBad->isNotEmpty() ? 'text-red' : '' }}"><x-icon name="book" size="sm"/> @if($brBad->isNotEmpty()){{ $brBad->count() }} account(s) to reconcile ({{ $brBad->map(fn ($o) => $o['account']->name)->join(', ') }})@if($brOpen) · @endif @endif @if($brOpen){{ $brOpen }} bank statement line(s) to match @endif</span><a href="{{ route('bankrec.index') }}"><b>Reconcile</b></a></li>
          @endif
          @if(can_do('roster.view'))@php $wfT = \App\Services\TimeClockService::today(); $wfS = \App\Services\RosterService::pendingSwaps(); $wfA = can_do('timeclock.approve') ? \App\Services\TimeClockService::attentionCount() : 0; @endphp
            @if($wfT['on_duty'] || $wfT['no_show'] || $wfS || $wfA)<li id="dashWorkforce"><span class="{{ $wfT['no_show'] || $wfA ? 'text-amber' : '' }}"><x-icon name="clock" size="sm"/> Workforce: {{ $wfT['on_duty'] }} on duty @if($wfT['late']) · {{ $wfT['late'] }} late @endif @if($wfT['no_show']) · {{ $wfT['no_show'] }} no-show @endif @if($wfS) · {{ $wfS }} swap(s) to approve @endif @if($wfA) · {{ $wfA }} time item(s) to fix / approve @endif</span><a href="{{ $wfS && ! $wfT['no_show'] && ! $wfA ? route('roster.index').'#swaps' : route('clock.index') }}"><b>Time clock</b></a></li>@endif
          @endif
          @if(can_do('club.view'))@php $clA = \App\Services\ClubBillingService::arrears(); $clE = \App\Models\ClubMembership::where('status', 'active')->whereBetween('end_date', [bdate()->toDateString(), bdate()->copy()->addDays(7)->toDateString()])->where(fn ($q) => $q->where('auto_renew', false)->orWhereNotNull('cancel_mode'))->count(); @endphp
            @if($clA->count() || $clE)<li id="dashClub"><span class="{{ $clA->count() ? 'text-red' : 'text-amber' }}"><x-icon name="dumbbell" size="sm"/> Health club: @if($clA->count()){{ $clA->count() }} membership(s) in arrears ({{ money($clA->sum('amount')) }}) @endif @if($clE) · {{ $clE }} ending within 7 days without renewal @endif</span><a href="{{ route('club.billing') }}"><b>Review</b></a></li>@endif @endif
          @if(can_do('leases.view'))@php $lsA = \App\Services\LeaseBillingService::arrears(); $lsE = \App\Models\Lease::where('status', 'active')->where('auto_renew', false)->whereBetween('end_date', [bdate()->toDateString(), bdate()->copy()->addDays(30)->toDateString()])->count(); @endphp
            @if($lsA->count() || $lsE)<li id="dashLeases"><span class="{{ $lsA->count() ? 'text-red' : 'text-amber' }}"><x-icon name="building" size="sm"/> Apartments: @if($lsA->count()){{ $lsA->count() }} tenant(s) in arrears ({{ money($lsA->sum('amount')) }}) @endif @if($lsE) · {{ $lsE }} lease(s) ending within 30 days with no notice or renewal @endif</span><a href="{{ route('leases.billing') }}"><b>Review</b></a></li>@endif @endif
          @if(can_do('income_audit.view') || can_do('income_audit.authorise'))@php $iaS = \App\Services\IncomeAuditService::stats(); @endphp
            @if($iaS['open_findings'] || $iaS['signed'] || $iaS['pending_auth'] || $iaS['count_issues'])<li id="dashIncomeAudit"><span class="{{ $iaS['open_findings'] ? 'text-red' : 'text-amber' }}"><x-icon name="shield" size="sm"/> Income audit: @if($iaS['open_findings']){{ $iaS['open_findings'] }} open exception(s) @endif @if($iaS['signed']) · {{ $iaS['signed'] }} day(s) awaiting the controller @endif @if($iaS['pending_auth']) · {{ $iaS['pending_auth'] }} authorisation(s) to decide @endif @if($iaS['count_issues']) · {{ $iaS['count_issues'] }} room-count discrepancy(ies) @endif</span><a href="{{ route(can_do('income_audit.view') ? 'audit.index' : 'audit.auth') }}"><b>Review</b></a></li>@endif @endif
          @if(can_do('foodsafety.view'))@php $fsA = \App\Services\FoodSafetyService::attention(); $fsC = \App\Services\FoodSafetyService::certProblems(); $fsCx = $fsC['expired'] + $fsC['missing']; @endphp
            @if($fsA['total'] || $fsCx)<li id="dashFoodSafety"><span class="{{ $fsA['breaches'] || $fsA['cooling'] ? 'text-red' : 'text-amber' }}"><x-icon name="thermo" size="sm"/> Food safety: @if($fsA['breaches']){{ $fsA['breaches'] }} out-of-range reading(s) to review @endif @if($fsA['cooling']) · {{ $fsA['cooling'] }} cooling overdue @endif @if($fsA['overdue']) · {{ $fsA['overdue'] }} checklist(s) overdue @endif @if($fsA['failed']) · {{ $fsA['failed'] }} failed checklist(s) to sign off @endif @if($fsCx) · {{ $fsCx }} food handler(s) without a valid medical certificate @endif</span><a href="{{ $fsA['breaches'] && can_do('foodsafety.manage') ? route('fs.temps').'#review' : route('fs.index') }}"><b>Review</b></a></li>@endif
          @endif
          @if(can_do('inventory.view'))@php $prA = \App\Services\ProcurementService::awaitingMyApproval()->count(); $prL = \App\Services\ProcurementService::overdueDeliveries()->count(); $prR = can_do('purchasing.rfq') ? \App\Services\ProcurementService::readyToAward()->count() : 0; @endphp
            @if($prA || $prL || $prR)<li id="dashProcurement"><span class="{{ $prL ? 'text-red' : 'text-amber' }}"><x-icon name="box" size="sm"/> Purchasing: @if($prA){{ $prA }} PO(s) to approve @endif @if($prL){{ $prA ? ' · ' : '' }}{{ $prL }} delivery(ies) overdue @endif @if($prR){{ ($prA || $prL) ? ' · ' : '' }}{{ $prR }} RFQ(s) to award @endif</span><a href="{{ $prR && ! $prA && ! $prL ? route('rfq.index') : route('purchases.index') }}"><b>Review</b></a></li>@endif
          @endif
          @if(can_do('petty.view') && ($pcN = \App\Services\PettyCashService::attentionCount()))<li id="dashPetty"><span class="text-amber"><x-icon name="wallet" size="sm"/> Petty cash: {{ $pcN }} item(s) to approve / top up / IOUs overdue</span><a href="{{ route('petty.index') }}"><b>Review</b></a></li>@endif
          @if((can_do('claims.approve') || can_do('claims.pay')) && ($ecN = (can_do('claims.approve') ? \App\Models\ExpenseClaim::where('status', 'submitted')->count() : 0) + (can_do('claims.pay') ? \App\Models\ExpenseClaim::where('status', 'approved')->count() : 0)))<li id="dashClaims"><span><x-icon name="receipt" size="sm"/> {{ $ecN }} staff expense claim(s) to approve / pay</span><a href="{{ route('claims.index', ['status' => can_do('claims.approve') && \App\Models\ExpenseClaim::where('status', 'submitted')->exists() ? 'submitted' : 'approved']) }}"><b>Open</b></a></li>@endif
          @if(can_do('payroll.view') && ($stA = \App\Services\StatutoryService::alerts())->isNotEmpty())<li id="dashStatDue"><span class="{{ $stA->contains('overdue', true) ? 'text-red' : '' }}"><x-icon name="id" size="sm"/> {{ $stA->count() }} payroll return(s) {{ $stA->contains('overdue', true) ? 'overdue' : 'due' }} · {{ money($stA->sum('total')) }}</span><a href="{{ route('payroll.statutory', ['period' => $stA->first()['period'], 'tab' => $stA->first()['kind']]) }}"><b>Pay</b></a></li>@endif
          @if(can_do('ap.view') && ($whA = \App\Services\WhtService::alert()))<li id="dashWhtReturn"><span class="{{ $whA['overdue'] ? 'text-red' : '' }}"><x-icon name="receipt" size="sm"/> WHT return {{ \Illuminate\Support\Carbon::parse($whA['period'].'-01')->format('M Y') }} {{ $whA['overdue'] ? 'overdue' : 'due' }} {{ $whA['due']->format('d M') }}</span><a href="{{ route('ap.wht', ['period' => $whA['period']]) }}"><b>File</b></a></li>@endif
          @if($gsA['pos_failed'] ?? 0)<li id="dashPosEvatFailed"><span class="text-red"><x-icon name="coffee" size="sm"/> Restaurant bills not stamped by E-VAT</span><a href="{{ route('gst.einvoices', ['tab' => 'pos', 'status' => 'failed']) }}"><b>{{ $gsA['pos_failed'] }}</b></a></li>@endif
          @if($gsA['return_period'])<li id="dashVatReturn"><span class="{{ $gsA['return_overdue'] ? 'text-red' : 'text-amber' }}"><x-icon name="book" size="sm"/> VAT return {{ \Illuminate\Support\Carbon::parse($gsA['return_period'].'-01')->format('M Y') }} {{ $gsA['return_overdue'] ? 'overdue' : 'due' }} {{ $gsA['return_due']->format('d M') }}</span><a href="{{ route('gst.return', ['period' => $gsA['return_period']]) }}"><b>file</b></a></li>@endif
        @endif
        @if(can_do('feedback.cases') || can_do('feedback.view'))
          @php $fbA = \App\Services\FeedbackService::alerts(); @endphp
          @if($fbA['overdue'] && can_do('feedback.cases'))<li id="dashCasesOverdue"><span class="text-red"><x-icon name="shield" size="sm"/> Guest cases past their SLA</span><a href="{{ route('rep.cases.index', ['tab' => 'overdue']) }}"><b>{{ $fbA['overdue'] }}</b></a></li>@endif
          @if($fbA['high'] && can_do('feedback.cases'))<li id="dashCasesHigh"><span class="text-amber"><x-icon name="shield" size="sm"/> Open high-severity guest cases</span><a href="{{ route('rep.cases.index', ['severity' => 'high']) }}"><b>{{ $fbA['high'] }}</b></a></li>@endif
          @if($fbA['reviews_waiting'] + $fbA['reviews_pending'] && can_do('feedback.view'))<li id="dashReviews"><span><x-icon name="star" size="sm"/> Online reviews waiting &gt; 48 h / for approval</span><a href="{{ route('rep.reviews.index', ['tab' => $fbA['reviews_pending'] ? 'pending' : 'respond']) }}"><b>{{ $fbA['reviews_waiting'] }} / {{ $fbA['reviews_pending'] }}</b></a></li>@endif
        @endif
        @if(can_do('tables.view') && \App\Models\Outlet::where('tb_enabled', true)->exists())
          @php $tbQ = \App\Models\TableReservation::whereHas('outlet', fn ($q) => $q->where('tb_enabled', true))->whereDate('date', now()->toDateString());
               $tbLate = (clone $tbQ)->whereIn('status', \App\Models\TableReservation::UPCOMING)->where('start_at', '<', now())->count();
               $tbToCome = (clone $tbQ)->whereIn('status', \App\Models\TableReservation::UPCOMING)->where('start_at', '>=', now())->count();
               $tbWait = (clone $tbQ)->whereIn('status', \App\Models\TableReservation::WAITING)->count(); @endphp
          @if($tbToCome)<li id="dashTablesToday"><span><x-icon name="coffee" size="sm"/> Restaurant bookings still to arrive today</span><a href="{{ route('tb.index', ['tab' => 'upcoming']) }}"><b>{{ $tbToCome }}</b></a></li>@endif
          @if($tbLate)<li id="dashTablesLate"><span class="text-red"><x-icon name="clock" size="sm"/> Restaurant guests running late</span><a href="{{ route('tb.floor') }}"><b>{{ $tbLate }}</b></a></li>@endif
          @if($tbWait)<li id="dashWaitlist"><span class="text-amber"><x-icon name="users" size="sm"/> Parties on the restaurant waitlist</span><a href="{{ route('tb.floor') }}"><b>{{ $tbWait }}</b></a></li>@endif
        @endif
        @if(can_do('transport.view') && \App\Models\Vehicle::exists())
          @php $trA = \App\Services\TransportService::alerts();
               $trToday = \App\Models\TransportTrip::whereDate('scheduled_at', now()->toDateString())->whereIn('status', \App\Models\TransportTrip::OPEN)->count(); @endphp
          @if($trToday)<li id="dashTransportToday"><span><x-icon name="car" size="sm"/> Guest transfers still to run today</span><a href="{{ route('tr.index') }}"><b>{{ $trToday }}</b></a></li>@endif
          @if($trA['unassigned'] + $trA['requested'])<li id="dashTransportAttention"><span class="text-red"><x-icon name="car" size="sm"/> Transfers to confirm / without a car soon</span><a href="{{ route('tr.index') }}"><b>{{ $trA['requested'] }} / {{ $trA['unassigned'] }}</b></a></li>@endif
          @if($trA['papers_expired'] + $trA['licences'])<li id="dashTransportPapers"><span class="text-amber"><x-icon name="alert" size="sm"/> Vehicle papers expired / driving licences expiring</span><a href="{{ route('tr.fleet') }}"><b>{{ $trA['papers_expired'] }} / {{ $trA['licences'] }}</b></a></li>@endif
        @endif
        @if(can_do('formc.view') && \App\Services\FormCService::enabled())
          @php $fcA = \App\Services\FormCService::alerts(); @endphp
          @if($fcA['overdue'] + $fcA['due_soon'])<li id="dashFormC"><span class="{{ $fcA['overdue'] ? 'text-red' : 'text-amber' }}"><x-icon name="id" size="sm"/> Form C overdue / due soon (FRRO)</span><a href="{{ route('fg.formc') }}"><b>{{ $fcA['overdue'] }} / {{ $fcA['due_soon'] }}</b></a></li>@endif
          @if($fcA['departures'])<li id="dashFormCDep"><span><x-icon name="plane" size="sm"/> Foreign guest departures to report</span><a href="{{ route('fg.formc', ['tab' => 'departures']) }}"><b>{{ $fcA['departures'] }}</b></a></li>@endif
        @endif
        @if(can_do('forex.view') && \App\Services\ForexService::enabled())
          @php $fxD = \App\Services\ForexService::alerts(); @endphp
          @if($fxD['rates_missing'])<li id="dashFxRates"><span class="text-amber"><x-icon name="trend" size="sm"/> Currencies without today's exchange rate</span><a href="{{ route('fg.rates') }}"><b>{{ $fxD['rates_missing'] }}</b></a></li>@endif
          @if($fxD['surrender_overdue'])<li id="dashFxSurrender"><span class="text-red"><x-icon name="wallet" size="sm"/> Foreign currency to surrender to the bank</span><a href="{{ route('fg.position') }}"><b>{{ $fxD['surrender_overdue'] }}</b></a></li>@endif
        @endif
        @if(can_do('logbook.view') || can_do('desk.wakeup') || can_do('desk.messages') || can_do('lostfound.manage'))
          @php $dk = \App\Services\FrontDeskOpsService::counts(); @endphp
          @if(can_do('desk.wakeup') && ($dk['wakeups_due'] || $dk['wakeups_late']))<li id="dashWakeups"><span class="{{ $dk['wakeups_late'] ? 'text-red' : 'text-amber' }}"><x-icon name="bell" size="sm"/> Wake-up calls due now / late</span><a href="{{ route('desk.wakeups') }}"><b>{{ $dk['wakeups_due'] }} / {{ $dk['wakeups_late'] }}</b></a></li>@endif
          @if(can_do('desk.messages') && ($dk['messages'] + $dk['parcels']))<li id="dashMessages"><span class="{{ $dk['departed_parcels'] ? 'text-red' : '' }}"><x-icon name="mail" size="sm"/> Guest messages / parcels waiting at the desk</span><a href="{{ route('desk.messages') }}"><b>{{ $dk['messages'] }} / {{ $dk['parcels'] }}</b></a></li>@endif
          @if(can_do('desk.traces') && $dk['traces_today'])<li id="dashTraces"><span class="text-amber"><x-icon name="check" size="sm"/> Open traces for today</span><a href="{{ route('desk.traces') }}"><b>{{ $dk['traces_today'] }}</b></a></li>@endif
          @if(can_do('logbook.view') && ($dk['log_unread'] || $dk['log_urgent']))<li id="dashLogbook"><span class="{{ $dk['log_urgent'] ? 'text-red' : '' }}"><x-icon name="book" size="sm"/> Log book: unread by you / urgent open</span><a href="{{ route('desk.handover') }}"><b>{{ $dk['log_unread'] }} / {{ $dk['log_urgent'] }}</b></a></li>@endif
          @if(can_do('lostfound.manage') && ($dk['lf_due'] || $dk['lf_reports']))<li id="dashLostFound"><span><x-icon name="box" size="sm"/> Lost &amp; found: loss reports open / items past retention</span><a href="{{ route('lostfound.index', ['tab' => $dk['lf_reports'] ? 'reports' : 'due']) }}"><b>{{ $dk['lf_reports'] }} / {{ $dk['lf_due'] }}</b></a></li>@endif
        @endif
        @foreach($lowStock as $i)<li><span class="text-red"><x-icon name="alert" size="sm"/> Low stock: {{ $i->name }}</span><b>{{ rtrim(rtrim(number_format($i->stock(), 2), '0'), '.') }} {{ $i->unit }}</b></li>@endforeach
      </ul></div>
  </div>
</section>

<section class="dash-section dash-module-section" aria-labelledby="dashModulesHeading">
  <div class="dash-section-heading"><div><span class="dash-section-kicker">MORE PROPERTY SIGNALS</span><h2 id="dashModulesHeading">Department snapshots</h2><p>Finance, guest services, facilities and upcoming events.</p></div></div>
  <div class="dash-module-grid">
    @if(can_do('budget.view') && ($bs = \App\Services\BudgetService::snapshot()))
    <div class="card" id="dashBudget">
      <div class="card-head"><h3>Budget @if($bs['budget'])· {{ \App\Services\BudgetService::monthLabels($bs['budget']->fiscal_year)[$bs['month']] }}@endif</h3><a class="small" href="{{ route('budgets.bva') }}">Budget vs Actual</a></div>
      <div class="card-body" style="padding-top:.3rem">
        @if($bs['budget'])
          @foreach(['revenue' => 'Revenue MTD', 'gop' => 'GOP MTD'] as $k => $l)
            @php $r = $bs[$k]; $pctB = $r['budget'] > 0 ? min(150, round($r['actual'] * 100 / $r['budget'])) : 0; @endphp
            <div class="mb-1" data-budget-kpi="{{ $k }}"><div class="flex justify-between small"><span>{{ $l }}</span><span><b>{{ money($r['actual']) }}</b> <span class="muted">/ {{ money($r['budget']) }}</span></span></div>
              <div class="progress"><span style="width: {{ min(100, $pctB) }}%;background:{{ $r['fav'] ? '#16a34a' : '#dc2626' }}"></span></div>
              <div class="tiny {{ $r['fav'] ? 'text-green' : 'text-red' }}">{{ $r['var'] >= 0 ? '+' : '' }}{{ money($r['var']) }}@if($r['var_pct'] !== null) ({{ $r['var_pct'] > 0 ? '+' : '' }}{{ number_format($r['var_pct'], 1) }} %)@endif vs budget to {{ $bs['cutoff']->format('d M') }}</div></div>
          @endforeach
          @if($bs['flagged'])<div class="small"><a href="{{ route('budgets.bva') }}" class="text-amber">{{ $bs['flagged'] }} variance(s) need an explanation</a></div>@endif
        @else
          <p class="small muted">No active budget for {{ \App\Services\BudgetService::fyLabel(\App\Services\BudgetService::currentFy()) }}.</p>
        @endif
        @if($bs['pending'] && can_do('budget.approve'))<div class="small mt-1" id="dashBudgetPending"><a href="{{ route('budgets.index', ['status' => 'submitted']) }}"><b>{{ $bs['pending'] }}</b> budget version(s) awaiting your approval</a></div>@endif
      </div>
    </div>
    @endif
    @if(can_do('calls.view') || can_do('keys.view'))
    @php $ifs = \App\Services\InterfaceService::snapshot(); @endphp
    <div class="card" id="dashInterfaces">
      <div class="card-head"><h3>Phones & keys · today</h3>@if(can_do('interfaces.manage'))<a class="small" href="{{ route('interfaces.index') }}">Interface hub</a>@endif</div>
      <div class="card-body" style="padding-top:.3rem"><ul class="list">
        @if(can_do('calls.view'))
        <li><span><x-icon name="phone" size="sm"/> Calls / billed to guests</span><a href="{{ route('calls.index') }}"><b>{{ $ifs['calls_today'] }}</b> · {{ money($ifs['charge_today']) }}</a></li>
        @if($ifs['calls_pending'])<li id="dashCallsPending"><span class="text-amber"><x-icon name="alert" size="sm"/> Calls to review</span><a href="{{ route('calls.index', ['tab' => 'review']) }}"><b>{{ $ifs['calls_pending'] }}</b></a></li>@endif
        @endif
        @if(can_do('keys.view'))
        <li><span><x-icon name="key" size="sm"/> Working guest key cards</span><a href="{{ route('keys.index') }}"><b>{{ $ifs['cards_active'] }}</b></a></li>
        <li @if($ifs['keys_overdue']) id="dashKeysOverdue" @endif><span class="{{ $ifs['keys_overdue'] ? 'text-red' : '' }}"><x-icon name="lock" size="sm"/> Master keys out / overdue</span><a href="{{ route('keys.index', ['tab' => 'register', 'status' => 'out']) }}"><b>{{ $ifs['keys_out'] }} / {{ $ifs['keys_overdue'] }}</b></a></li>
        @endif
        @if($ifs['commands_failed'] && can_do('interfaces.manage'))<li><span class="text-red"><x-icon name="alert" size="sm"/> Interface commands failed (24 h)</span><a href="{{ route('interfaces.index', ['status' => 'failed']) }}"><b>{{ $ifs['commands_failed'] }}</b></a></li>@endif
      </ul></div>
    </div>
    @endif
    @if(can_do('vouchers.view'))
    @php $gvs = \App\Services\GiftVoucherService::snapshot(); @endphp
    <div class="card" id="dashVouchers">
      <div class="card-head"><h3>Gift vouchers</h3><a class="small" href="{{ route('vouchers.index') }}">All vouchers</a></div>
      <div class="card-body" style="padding-top:.3rem"><ul class="list">
        <li><span><x-icon name="wallet" size="sm"/> Outstanding liability</span><a href="{{ route('reports.show', 'voucher_liability') }}"><b>{{ money($gvs['outstanding']) }}</b> · {{ $gvs['live'] }}</a></li>
        <li><span><x-icon name="money" size="sm"/> Sold / redeemed today</span><span><b>{{ money($gvs['sold_today']) }}</b> / {{ money($gvs['redeemed_today']) }}</span></li>
        @if($gvs['expiring'])<li id="dashGvExpiring"><span class="text-amber"><x-icon name="clock" size="sm"/> Expiring in {{ $gvs['expiring_days'] }} days</span><a href="{{ route('vouchers.index', ['status' => 'expiring']) }}"><b>{{ $gvs['expiring'] }}</b> · {{ money($gvs['expiring_value']) }}</a></li>@endif
        @if($gvs['suspended'])<li><span class="text-red"><x-icon name="lock" size="sm"/> Suspended</span><a href="{{ route('vouchers.index', ['status' => 'suspended']) }}"><b>{{ $gvs['suspended'] }}</b></a></li>@endif
      </ul></div>
    </div>
    @endif
    @if(can_do('security.view'))
    @php $sec = \App\Services\SecurityService::snapshot(); @endphp
    <div class="card" id="dashSecurity">
      <div class="card-head"><h3>Security</h3><a class="small" href="{{ route('security.visitors') }}">Open</a></div>
      <div class="card-body" style="padding-top:.3rem"><ul class="list">
        <li><span><x-icon name="users" size="sm"/> Visitors on site</span><a href="{{ route('security.visitors') }}"><b>{{ $sec['on_site'] }}</b> · {{ $sec['visitors_today'] }} today</a></li>
        @if($sec['awaiting'])<li><span class="text-amber"><x-icon name="phone" size="sm"/> Waiting for the guest's OK</span><a href="{{ route('security.visitors') }}"><b>{{ $sec['awaiting'] }}</b></a></li>@endif
        @if($sec['overstays'])<li id="dashOverstay"><span class="text-red"><x-icon name="clock" size="sm"/> Visitor passes expired</span><a href="{{ route('security.visitors') }}"><b>{{ $sec['overstays'] }}</b></a></li>@endif
        <li><span class="{{ $sec['gp_overdue'] ? 'text-red' : '' }}"><x-icon name="box" size="sm"/> Gate passes out / overdue</span><a href="{{ route('security.gp.index', ['f' => $sec['gp_overdue'] ? 'overdue' : 'away']) }}"><b>{{ $sec['gp_away'] }}</b> · {{ $sec['gp_overdue'] }} overdue</a></li>
        @if($sec['gp_pending'])<li><span class="text-amber"><x-icon name="check" size="sm"/> Gate passes to approve</span><a href="{{ route('security.gp.index', ['f' => 'pending']) }}"><b>{{ $sec['gp_pending'] }}</b></a></li>@endif
        <li><span class="{{ $sec['inc_serious'] ? 'text-red' : '' }}"><x-icon name="shield" size="sm"/> Open incidents</span><a href="{{ route('security.incidents') }}"><b>{{ $sec['inc_open'] }}</b>@if($sec['inc_serious']) · {{ $sec['inc_serious'] }} high @endif</a></li>
      </ul></div>
    </div>
    @endif
    @if(can_do('utilities.view') || can_do('compliance.view'))
    <div class="card" id="dashEngineering">
      <div class="card-head"><h3>Engineering</h3><a class="small" href="{{ route(can_do('utilities.view') ? 'utilities.index' : 'compliance.index') }}">Open</a></div>
      <div class="card-body" style="padding-top:.3rem"><ul class="list">
        @if(can_do('utilities.view'))
        @php $ut = \App\Services\UtilityService::snapshot(); @endphp
        <li><span><x-icon name="edit" size="sm"/> Meter readings today</span><a href="{{ route('utilities.index', ['tab' => 'readings']) }}"><b>{{ $ut['read_today'] }}</b> / {{ $ut['meters'] }}</a></li>
        @if($ut['yesterday'])<li><span><x-icon name="alert" size="sm"/> Energy yesterday</span><span><b>{{ number_format($ut['yesterday']['kwh']) }} kWh</b>@if($ut['yesterday']['kwh_por'] !== null) · {{ $ut['yesterday']['kwh_por'] }} / room @endif</span></li>
        <li><span><x-icon name="money" size="sm"/> Utility cost yesterday</span><span><b>{{ money($ut['yesterday']['cost']) }}</b></span></li>@endif
        @if($ut['anomalies'])<li id="dashUtilAnomalies"><span class="text-red"><x-icon name="alert" size="sm"/> Usage anomalies</span><a href="{{ route('utilities.index') }}"><b>{{ $ut['anomalies'] }}</b></a></li>@endif
        @endif
        @if(can_do('compliance.view'))
        @php $cp = \App\Services\ComplianceService::snapshot(); @endphp
        <li @if($cp['counts']['expired']) id="dashLicExpired" @endif><span class="{{ $cp['counts']['expired'] ? 'text-red' : '' }}"><x-icon name="shield" size="sm"/> Licences expired</span><a href="{{ route('compliance.index', ['state' => 'expired']) }}"><b>{{ $cp['counts']['expired'] }}</b>@if($cp['critical_expired']) · {{ $cp['critical_expired'] }} critical @endif</a></li>
        <li><span class="{{ $cp['counts']['due'] ? 'text-amber' : '' }}"><x-icon name="clock" size="sm"/> Due for renewal</span><a href="{{ route('compliance.index', ['state' => 'due']) }}"><b>{{ $cp['counts']['due'] }}</b> · {{ $cp['counts']['renewing'] }} applied</a></li>
        @endif
      </ul></div>
    </div>
    @endif
    <div class="card">
      <div class="card-head"><h3>Business Source Mix (30 days)</h3></div>
      <div class="card-body">
        @php $smax = max(1, $sourceMix->sum('c')); @endphp
        @forelse($sourceMix as $s)
          <div class="mb-1"><div class="flex justify-between small"><span>{{ $s->name }}</span><b>{{ $s->c }}</b></div><div class="progress"><span style="width: {{ $s->c / $smax * 100 }}%"></span></div></div>
        @empty <div class="muted">No data</div> @endforelse
      </div>
    </div>
    <div class="card">
      <div class="card-head"><h3>Upcoming Banquets</h3>@if(can_do('banquet.view'))<a class="small" href="{{ route('banquet.diary') }}">Diary</a>@endif</div>
      <div class="card-body" style="padding-top:.3rem"><ul class="list">
        @forelse($events as $e)<li><div><a href="{{ route('banquet.show', $e) }}"><b>{{ $e->title }}</b></a><div class="tiny muted">{{ $e->event_date->format('d M') }} · {{ $e->functions->pluck('hall.name')->unique()->join(', ') }} · {{ $e->pax() }} pax</div></div>{!! $e->statusBadge() !!}</li>
        @empty <li class="muted">No events.</li> @endforelse
      </ul></div>
    </div>
  </div>
</section>
</div>
@endsection
