@extends('layouts.app')
@section('title', 'Apartments & Leases')
@section('crumbs') Apartments &amp; Leases / Dashboard @endsection
@section('actions')
@if(can_do('leases.billing'))<form method="post" action="{{ route('leases.billing.run') }}" style="display:inline">@csrf<button class="btn" id="runBilling"><x-icon name="refresh"/> Billing run</button></form>@endif
@if(can_do('leases.manage'))<a class="btn btn-primary" href="{{ route('leases.create') }}" id="newLease"><x-icon name="plus"/> New lease</a>@endif
@endsection
@section('content')
@php $arrIds = $stats['arrears']->pluck('lease.id')->all(); @endphp
<div class="stats" id="leaseStats">
  <x-stat label="Units let" :value="$stats['leased'].' / '.$stats['units']" icon="building" tone="blue" :sub="$stats['occupancy'].'% occupancy · '.$stats['vacant'].' vacant'" :href="route('leases.units')"/>
  <x-stat label="Monthly rent roll" :value="money($stats['rent_roll'])" icon="trend" tone="green" sub="rent + service charge, excl. tax" :href="route('leases.list')"/>
  <x-stat label="Tenants in arrears" :value="$stats['arrears']->count()" icon="alert" :tone="$stats['arrears']->count() ? 'red' : 'green'" :sub="money($stats['arrears']->sum('amount')).' overdue after '.\App\Services\LeaseService::cfg('lease_grace_days').' days grace'" :href="route('leases.billing').'#arrears'"/>
  <x-stat label="Security deposits held" :value="money($stats['deposits'])" icon="lock" tone="purple" :sub="$stats['unsettled'].' move-out(s) to settle'"/>
</div>

@if($stats['overdue_moveout']->isNotEmpty())
<div class="alert alert-warn mt-1" id="holdingOver"><x-icon name="alert"/> <div><b>Past the end of term / move-out date without a move-out:</b>
  @foreach($stats['overdue_moveout'] as $l)<a href="{{ route('leases.show', $l) }}">{{ $l->lease_no }}</a> {{ $l->tenant_name }} ({{ dmy($l->lastDay()) }})@if(! $loop->last), @endif @endforeach – renew the lease or record the move-out; rent is no longer invoiced.</div></div>
@endif

<div class="card mt-1" id="unitBoard"><div class="card-head"><h3><x-icon name="building"/> Unit board</h3><span class="small muted"><span class="badge badge-green">vacant</span> <span class="badge badge-blue">leased</span> <span class="badge badge-amber">notice</span> <span class="badge badge-purple">reserved</span> <span class="badge badge-red">arrears</span></span></div>
  <div class="card-body"><div class="lease-board">
  @forelse($units as $u)@php $cl = $u->currentLease; @endphp
    <a class="u {{ $u->status }} {{ $cl?->status === 'notice' ? 'notice' : '' }} {{ $cl && in_array($cl->id, $arrIds) ? 'arrears' : '' }}" href="{{ $cl ? route('leases.show', $cl) : (can_do('leases.manage') && $u->status === 'vacant' ? route('leases.create', ['unit' => $u->id]) : route('leases.units')) }}" data-unit="{{ $u->code }}">
      <div class="flex" style="justify-content:space-between"><span class="code">{{ $u->code }}</span><span class="tiny muted">{{ \App\Models\LeaseUnit::TYPES[$u->type] }}</span></div>
      <div class="t">{{ $u->name }}{{ $u->room ? ' · room '.$u->room->room_no : '' }}</div>
      @if($cl)<div class="small mt-1 bold">{{ \Illuminate\Support\Str::limit($cl->tenant_name, 24) }}</div><div class="tiny muted">{{ $cl->status === 'notice' ? 'leaving '.dmy($cl->move_out_on) : 'to '.dmy($cl->end_date) }} · {{ money($cl->rent, false) }}/mo</div>
      @else<div class="small mt-1 muted">{{ \App\Models\LeaseUnit::STATUSES[$u->status] }}</div><div class="tiny muted">list {{ money($u->list_rent, false) }}/mo</div>@endif
    </a>
  @empty <x-empty text="No units yet – add them under Units." icon="building"/> @endforelse
  </div></div></div>

<div class="fs-split mt-1">
  <div>
    <div class="card flush" id="dashArrears"><div class="card-head"><h3><x-icon name="alert"/> Arrears</h3><a class="small" href="{{ route('leases.billing') }}#arrears">All →</a></div>
      <div class="card-body table-wrap"><table class="table">
        <thead><tr><th>Lease</th><th>Unit</th><th>Overdue since</th><th class="num">Overdue</th></tr></thead>
        <tbody>@forelse($stats['arrears']->take(8) as $a)
          <tr><td><a href="{{ route('leases.show', $a['lease']) }}" class="bold">{{ $a['lease']->lease_no }}</a> {{ $a['lease']->tenant_name }}</td><td class="small">{{ $a['lease']->unit->code }}</td><td class="small">{{ dmy($a['since']) }}</td><td class="num bold text-red">{{ money($a['amount'], false) }}</td></tr>
        @empty <tr><td colspan="4"><x-empty text="No tenant is in arrears." icon="check"/></td></tr> @endforelse</tbody></table></div></div>

    <div class="card flush mt-1" id="dashExpiring"><div class="card-head"><h3><x-icon name="calendar"/> Ending within {{ \App\Services\LeaseService::cfg('lease_expiry_alert_days') }} days – no notice or renewal yet</h3></div>
      <div class="card-body table-wrap"><table class="table">
        <thead><tr><th>Lease</th><th>Unit</th><th>Ends</th><th class="num">Rent / mo</th></tr></thead>
        <tbody>@forelse($stats['expiring']->sortBy('end_date') as $l)
          <tr><td><a href="{{ route('leases.show', $l) }}" class="bold">{{ $l->lease_no }}</a> {{ $l->tenant_name }}</td><td class="small">{{ $l->unit->code ?? '' }}</td><td class="small">{{ dmy($l->end_date) }} <span class="tiny muted">({{ (int) \Carbon\Carbon::parse($today)->diffInDays($l->end_date) }} d)</span></td><td class="num">{{ money($l->rent, false) }}</td></tr>
        @empty <tr><td colspan="4"><x-empty text="Nothing ending soon without a decision." icon="calendar"/></td></tr> @endforelse</tbody></table></div></div>
  </div>
  <div>
    <div class="card flush" id="dashMoving"><div class="card-head"><h3>Moving out</h3></div>
      <div class="card-body table-wrap"><table class="table"><tbody>@forelse($moving as $l)
        <tr><td><a href="{{ route('leases.show', $l) }}" class="bold">{{ $l->unit->code }}</a> {{ $l->tenant_name }}<div class="tiny muted">notice by {{ $l->notice_by }} on {{ dmy($l->notice_on) }}</div></td><td class="small num">{{ dmy($l->move_out_on) }}</td></tr>
      @empty <tr><td><x-empty text="No notices." icon="logout"/></td></tr> @endforelse</tbody></table></div></div>

    <div class="card flush mt-1" id="dashStarting"><div class="card-head"><h3>Draft &amp; upcoming move-ins</h3></div>
      <div class="card-body table-wrap"><table class="table"><tbody>@forelse($starting as $l)
        <tr><td><a href="{{ route('leases.show', $l) }}" class="bold">{{ $l->unit->code }}</a> {{ $l->tenant_name }}<div class="tiny muted">{{ $l->signed_at ? 'signed' : 'not signed yet' }}</div></td><td class="small num">{{ dmy($l->start_date) }}</td></tr>
      @empty <tr><td><x-empty text="No drafts." icon="login"/></td></tr> @endforelse</tbody></table></div></div>

    <div class="card flush mt-1" id="dashReviews"><div class="card-head"><h3><x-icon name="trend"/> Rent reviews – next 60 days</h3></div>
      <div class="card-body table-wrap"><table class="table"><tbody>@forelse($reviews as $l)
        <tr><td><a href="{{ route('leases.show', $l) }}" class="bold">{{ $l->unit->code }}</a> {{ $l->tenant_name }}<div class="tiny muted">+{{ rtrim(rtrim(number_format($l->escalation_pct, 2), '0'), '.') }}% → {{ money(round($l->rent * (1 + $l->escalation_pct / 100), 2), false) }}/mo</div></td><td class="small num">{{ dmy($l->next_review_on) }}</td></tr>
      @empty <tr><td><x-empty text="No reviews due." icon="trend"/></td></tr> @endforelse</tbody></table></div></div>
  </div>
</div>
@endsection
