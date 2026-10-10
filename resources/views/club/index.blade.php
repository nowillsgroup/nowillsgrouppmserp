@extends('layouts.app')
@section('title', 'Health Club')
@section('crumbs') Health Club / Dashboard @endsection
@section('actions')
@if(can_do('club.desk'))<a class="btn btn-primary" href="{{ route('club.desk') }}" id="goDesk"><x-icon name="qr"/> Check-in desk</a>@endif
@if(can_do('club.members'))<a class="btn" href="{{ route('club.members.create') }}" id="goEnrol"><x-icon name="plus"/> Enrol member</a>@endif
@endsection
@section('content')
<div class="stats" id="clubStats">
  <x-stat label="Active memberships" :value="$s['active']" icon="dumbbell" tone="blue" :sub="$s['cards'].' card holders · '.$s['frozen'].' frozen'" :href="route('club.members')"/>
  <x-stat label="Monthly recurring revenue" :value="money($s['mrr'])" icon="trend" tone="green" :sub="'net of tax · '.money($s['unearned']).' fees received in advance'"/>
  <x-stat label="In the club now" :value="$s['in_now']" icon="users" tone="purple" :sub="$s['visits_today'].' visits today · '.$s['day_passes_today'].' day passes · '.$s['denied_today'].' refused'" :href="route('club.desk')"/>
  <x-stat label="In arrears" :value="$s['arrears_count']" icon="alert" :tone="$s['arrears_count'] ? 'red' : 'green'" :sub="money($s['arrears_amount']).' overdue · '.$s['expiring'].' ending in 30 days without renewal'" :href="route('club.billing')"/>
</div>

<div class="card mt-1" id="occBoard"><div class="card-head"><h3><x-icon name="users"/> Occupancy now</h3><span class="small muted">visits close automatically after {{ \App\Services\ClubService::cfg('club_checkout_hours') }} h</span></div>
  <div class="card-body"><div class="club-occ">
    @foreach($occ as $k => $o)@php $pct = $o['cap'] ? min(100, round($o['in'] * 100 / $o['cap'])) : 0; @endphp
      <div class="fac {{ $o['cap'] && $o['in'] >= $o['cap'] ? 'full' : ($pct >= 75 ? 'busy' : '') }}" data-fac="{{ $k }}"><div class="small muted">{{ $o['label'] }}</div><div class="n">{{ $o['in'] }}<span class="small muted"> / {{ $o['cap'] }}</span></div><div class="progress"><span style="width:{{ $pct }}%"></span></div></div>
    @endforeach
  </div></div></div>

<div class="fs-split mt-1">
  <div>
    <div class="card flush" id="dashArrears"><div class="card-head"><h3><x-icon name="alert"/> Arrears – entry blocked after {{ \App\Services\ClubService::cfg('club_grace_days') }} days' grace</h3><a class="small" href="{{ route('club.billing') }}#arrears">All →</a></div>
      <div class="card-body table-wrap"><table class="table">
        <thead><tr><th>Membership</th><th>Plan</th><th>Overdue since</th><th class="num">Overdue</th></tr></thead>
        <tbody>@forelse($arrears as $a)
          <tr><td><a href="{{ route('club.members.show', $a['membership']) }}" class="bold">{{ $a['membership']->membership_no }}</a> {{ $a['membership']->holder_name }}</td><td class="small">{{ $a['membership']->plan->name }}</td><td class="small">{{ dmy($a['since']) }}</td><td class="num bold text-red">{{ money($a['amount'], false) }}</td></tr>
        @empty <tr><td colspan="4"><x-empty text="No member is in arrears." icon="check"/></td></tr> @endforelse</tbody></table></div></div>

    <div class="card flush mt-1" id="dashExpiring"><div class="card-head"><h3><x-icon name="calendar"/> Ending in the next 14 days</h3><a class="small" href="{{ route('club.billing') }}">Renewals →</a></div>
      <div class="card-body table-wrap"><table class="table">
        <thead><tr><th>Membership</th><th>Plan</th><th>Ends</th><th>Renewal</th></tr></thead>
        <tbody>@forelse($expiring as $m)
          <tr><td><a href="{{ route('club.members.show', $m) }}" class="bold">{{ $m->membership_no }}</a> {{ $m->holder_name }}</td><td class="small">{{ $m->plan->name }}</td><td class="small">{{ dmy($m->end_date) }}</td>
            <td>@if($m->cancel_mode)<span class="badge badge-slate">leaving</span>@elseif($m->auto_renew)<span class="badge badge-green">auto-renews</span>@else<span class="badge badge-amber">call member</span>@endif</td></tr>
        @empty <tr><td colspan="4"><x-empty text="Nothing ending soon." icon="calendar"/></td></tr> @endforelse</tbody></table></div></div>

    <div class="card mt-1" id="dashTrend"><div class="card-head"><h3><x-icon name="chart"/> Visits – last 14 days</h3></div><div class="card-body">
      @php $max = max(1, $trend->max('n')); @endphp
      <div class="bar-chart">@foreach($trend as $t)<div class="col"><div class="b" style="height:{{ round($t['n'] * 100 / $max) }}%" data-v="{{ $t['n'] }} visits"></div><small>{{ $t['d']->format('d/m') }}</small></div>@endforeach</div>
    </div></div>
  </div>

  <div>
    <div class="card flush" id="dashInNow"><div class="card-head"><h3>Checked in now</h3><a class="small" href="{{ route('club.desk') }}">Desk →</a></div>
      <div class="card-body table-wrap"><table class="table">
        <tbody>@forelse($inNow as $v)
          <tr><td>{{ $v->name }}<div class="tiny muted">{{ \App\Models\ClubVisit::TYPES[$v->visitor_type] }}{{ $v->pax > 1 ? ' · '.$v->pax.' people' : '' }}</div></td><td class="small">{{ \App\Models\ClubPlan::FACILITIES[$v->facility] ?? $v->facility }}</td><td class="small num">{{ $v->checked_in_at->format('H:i') }}</td></tr>
        @empty <tr><td><x-empty text="Nobody is checked in." icon="users"/></td></tr> @endforelse</tbody></table></div></div>

    <div class="card flush mt-1" id="dashClasses"><div class="card-head"><h3><x-icon name="clock"/> Today's classes</h3><a class="small" href="{{ route('club.classes') }}">Timetable →</a></div>
      <div class="card-body table-wrap"><table class="table">
        <tbody>@forelse($sessions as $x)
          <tr><td><a href="{{ route('club.classes.session', [$x['class'], bdate()->toDateString()]) }}" class="bold">{{ $x['class']->name }}</a><div class="tiny muted">{{ $x['class']->timeLabel() }} · {{ $x['class']->instructorName() }}</div></td>
            <td class="num small">{{ $x['booked'] }}/{{ $x['class']->capacity }}@if($x['waitlist'])<div class="tiny text-amber">+{{ $x['waitlist'] }} waiting</div>@endif</td></tr>
        @empty <tr><td><x-empty text="No classes today." icon="clock"/></td></tr> @endforelse</tbody></table></div></div>

    <div class="card mt-1" id="dashPlans"><div class="card-head"><h3>Members by plan</h3><a class="small" href="{{ route('club.plans') }}">Plans →</a></div><div class="card-body"><ul class="list">
      @foreach($plans as $p)<li><span class="small">{{ $p->name }} <span class="muted">· {{ money($p->price, false) }}/{{ strtolower($p->termLabel()) }}</span></span><b>{{ $p->live }}</b></li>@endforeach
    </ul></div></div>

    @if($denied->isNotEmpty())
    <div class="card flush mt-1" id="dashDenied"><div class="card-head"><h3>Refused entries (7 days)</h3><a class="small" href="{{ route('club.visits', ['denied' => 1]) }}">All →</a></div><div class="card-body table-wrap"><table class="table">
      <tbody>@foreach($denied as $v)<tr><td class="small">{{ $v->checked_in_at->format('d/m H:i') }}</td><td>{{ $v->name }}<div class="tiny text-red">{{ $v->deny_reason }}</div></td></tr>@endforeach</tbody></table></div></div>
    @endif
  </div>
</div>
@endsection
