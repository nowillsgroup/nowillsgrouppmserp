@extends('layouts.app')
@section('title', 'My Shift')
@section('crumbs') Operations / My Shift @endsection
@section('content')
<style>
  .my-shift{--ms-green:#14532d;--ms-line:var(--c-border);--ms-muted:var(--c-text-2)}
  .my-shift-hero{display:flex;align-items:flex-end;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-bottom:1rem;padding:1.1rem 1.25rem;border-radius:15px;color:#fff;background:linear-gradient(120deg,#123c24,#176239 68%,#257849);box-shadow:0 12px 28px rgba(20,83,45,.15)}
  .my-shift-hero h2{margin:0;font-size:1.35rem;letter-spacing:-.035em}.my-shift-hero p{margin:.3rem 0 0;color:rgba(255,255,255,.78);font-size:.8rem}
  .my-shift-date{padding:.45rem .7rem;border:1px solid rgba(255,255,255,.25);border-radius:9px;background:rgba(255,255,255,.08);font-size:.75rem;font-weight:750}
  .my-shift-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:.8rem}
  .my-shift-card{display:flex;flex-direction:column;min-height:245px;padding:1rem 1.05rem;border:1px solid var(--ms-line);border-radius:13px;background:var(--c-surface);box-shadow:var(--shadow-sm)}
  .my-shift-card-head{display:flex;align-items:center;gap:.65rem}.my-shift-icon{display:grid;place-items:center;width:37px;height:37px;border-radius:10px;background:#eaf5eb;color:#14532d}
  .my-shift-card h3{margin:0;font-size:.95rem}.my-shift-card .eyebrow{margin:.08rem 0 0;color:var(--ms-muted);font-size:.67rem}
  .my-shift-state{margin:.85rem 0 .35rem;font-size:1.05rem;font-weight:820;letter-spacing:-.025em}.my-shift-detail{color:var(--ms-muted);font-size:.75rem;line-height:1.55}
  .my-shift-metrics{display:flex;flex-wrap:wrap;gap:.4rem;margin:.7rem 0}.my-shift-metric{padding:.38rem .55rem;border:1px solid var(--ms-line);border-radius:8px;background:var(--c-bg);font-size:.68rem;color:var(--ms-muted)}.my-shift-metric b{color:var(--c-text)}
  .my-shift-card .my-shift-cta{margin-top:auto;padding-top:.75rem}.my-shift-foot{margin-top:1rem;padding:.75rem .85rem;border:1px solid var(--ms-line);border-radius:10px;background:var(--c-bg);color:var(--ms-muted);font-size:.73rem;line-height:1.55}
  body.dark .my-shift-icon{background:#21402a;color:#b9e8c2}
  @media(max-width:580px){.my-shift-hero{padding:.9rem}.my-shift-card{min-height:0}}
</style>
<div class="my-shift">
  <header class="my-shift-hero">
    <div><h2>My Shift</h2><p>Your department shift, close-out report and hand-over tools—all under your own login.</p></div>
    <div class="my-shift-date">Business date · {{ \Carbon\Carbon::parse($date)->format('D, d M Y') }}</div>
  </header>

  <div class="my-shift-cards">
    @if($canFrontDesk)
      <section class="my-shift-card" aria-labelledby="frontDeskShiftTitle">
        <div class="my-shift-card-head"><span class="my-shift-icon"><x-icon name="bell"/></span><div><h3 id="frontDeskShiftTitle">Front Office</h3><div class="eyebrow">Desk responsibility &amp; incoming hand-over</div></div></div>
        @if($frontDeskPending)
          <div class="my-shift-state">{{ $frontDeskPending }} hand-over{{ $frontDeskPending === 1 ? '' : 's' }} need your review</div>
          <div class="my-shift-detail">Review the outgoing summary and desk snapshot, then acknowledge it before opening your next front-office session.</div>
        @elseif($frontDeskSession)
          <div class="my-shift-state">Your desk session is open</div>
          <div class="my-shift-detail">{{ ucfirst($frontDeskSession->shift) }} shift · opened {{ $frontDeskSession->started_at->format('H:i') }} · {{ $frontDeskSession->started_at->diffForHumans() }}</div>
        @else
          <div class="my-shift-state">{{ $canManageFrontDesk ? 'No open desk session' : 'Front Office shift access is read only' }}</div>
          <div class="my-shift-detail">{{ $canManageFrontDesk ? 'Start a personal front-office session, link log-book work to it, and hand over to a named colleague when you finish.' : 'Your role can view the Front Office shift workspace but cannot start or close a personal desk session.' }}</div>
        @endif
        <div class="my-shift-cta"><a class="btn btn-primary" href="{{ route('desk.shift') }}">{{ $frontDeskPending ? 'Review hand-over' : ($frontDeskSession ? ($canManageFrontDesk ? 'Manage my shift' : 'View my shift') : ($canManageFrontDesk ? 'Open front-office shift' : 'View Front Office shifts')) }} <x-icon name="chev"/></a></div>
      </section>
    @endif

    @if($canCashier)
      <section class="my-shift-card" aria-labelledby="cashierShiftTitle">
        <div class="my-shift-card-head"><span class="my-shift-icon"><x-icon name="money"/></span><div><h3 id="cashierShiftTitle">Cashiering</h3><div class="eyebrow">Personal till, payment totals &amp; cash reconciliation</div></div></div>
        @if($cashierShift)
          <div class="my-shift-state">Your cashier shift is open</div>
          <div class="my-shift-detail">Opened {{ $cashierShift->opened_at->format('H:i') }} · {{ $cashierShift->opened_at->diffForHumans() }}</div>
          <div class="my-shift-metrics"><span class="my-shift-metric">Expected drawer <b>{{ money($cashierSummary['expected_cash']) }}</b></span><span class="my-shift-metric">Transactions <b>{{ $cashierSummary['count'] }}</b></span></div>
        @else
          <div class="my-shift-state">No open cashier shift</div>
          <div class="my-shift-detail">Open your own till shift with a float. Payments and refunds you receive are reconciled separately from other cashiers.</div>
        @endif
        <div class="my-shift-cta"><a class="btn btn-primary" href="{{ route('shifts.index') }}">{{ $cashierShift ? 'Manage & close cashier shift' : 'Open cashier shift' }} <x-icon name="chev"/></a></div>
      </section>
    @endif

    @if($canOutlet)
      <section class="my-shift-card" aria-labelledby="outletShiftTitle">
        <div class="my-shift-card-head"><span class="my-shift-icon"><x-icon name="receipt"/></span><div><h3 id="outletShiftTitle">Recreation outlet</h3><div class="eyebrow">Ticket till, takings &amp; drawer count</div></div></div>
        @if($outletShift)
          <div class="my-shift-state">Your outlet shift is open</div>
          <div class="my-shift-detail">{{ ucfirst($outletShift->shift) }} shift · opened {{ $outletShift->opened_at->format('H:i') }} · float {{ money($outletSummary['opening_float']) }}</div>
          <div class="my-shift-metrics">
            <span class="my-shift-metric">Expected drawer <b>{{ money($outletSummary['expected_cash']) }}</b></span>
            <span class="my-shift-metric">Takings <b>{{ money($outletSummary['total']) }}</b></span>
            <span class="my-shift-metric">Sales <b>{{ $outletSummary['sales'] }}</b></span>
          </div>
        @else
          <div class="my-shift-state">No open outlet shift</div>
          <div class="my-shift-detail">Open a shift with the float you are starting with. Every ticket, rental and service you sell is tied to it, and the close-out reconciles the drawer.</div>
        @endif
        <div class="my-shift-cta"><a class="btn btn-primary" href="{{ route('recreation.shift') }}">{{ $outletShift ? 'Manage & close outlet shift' : 'Open outlet shift' }} <x-icon name="chev"/></a>@if($outletShift)<a class="btn" href="{{ route('recreation.outlet') }}">Open the till</a>@endif</div>
      </section>
    @endif

    @if($canService || $canViewServiceTeam)
      <section class="my-shift-card" aria-labelledby="serviceShiftTitle">
        <div class="my-shift-card-head"><span class="my-shift-icon"><x-icon name="cup"/></span><div><h3 id="serviceShiftTitle">Food &amp; Beverage Service</h3><div class="eyebrow">Waiter / service staff shift &amp; POS order report</div></div></div>
        @if($serviceShift)
          <div class="my-shift-state">Your service shift is open</div>
          <div class="my-shift-detail">{{ ucfirst($serviceShift->shift) }} shift · opened {{ $serviceShift->opened_at->format('H:i') }} · orders you start will be attached to your close-out.</div>
        @else
          <div class="my-shift-state">{{ $canService ? 'No open service shift' : 'Team shift reports' }}</div>
          <div class="my-shift-detail">Each server opens under their own login. Their own POS orders and covers roll into an individual close-out and the department’s day summary.</div>
        @endif
        <div class="my-shift-cta"><a class="btn btn-primary" href="{{ route('department-shifts.index') }}">{{ $serviceShift ? 'Manage & close service shift' : ($canService ? 'Open service shift' : 'View team shift reports') }} <x-icon name="chev"/></a>@if($serviceShift && can_do('pos.view'))<a class="btn btn-accent" href="{{ route('pos.index') }}">Open POS Terminal</a>@endif</div>
      </section>
    @endif
  </div>

  <div class="my-shift-foot"><b>How the shared shift system works:</b> everyone signs in with their own PMS account and opens their own department session. Several people can be on the same named shift at once; their personal reports stay separate, while managers can see the department/day roll-up. Front-office hand-over, cashier cash balancing and waiter sales reporting remain department-specific. This is an operational record, not the HR attendance clock.</div>
</div>
@endsection
