@extends('layouts.app')
@section('title', 'Marketing Dashboard')
@section('crumbs') Guests &amp; CRM / Marketing @endsection
@section('actions')
@if(can_do('crm.manage'))<form method="post" action="{{ route('crm.refresh') }}" style="display:inline">@csrf<button class="btn" title="Recalculate stays, nights, revenue and last stay for every guest"><x-icon name="refresh"/> Refresh guest stats</button></form>@endif
@if(can_do('crm.send'))<a class="btn" href="{{ route('crm.settings') }}"><x-icon name="tool"/> Settings</a>@endif
@if(can_do('crm.manage'))<a class="btn btn-primary" href="{{ route('crm.campaigns.create') }}" id="newCampaign"><x-icon name="plus"/> New campaign</a>@endif
@endsection
@section('content')
@php $pct = fn ($n) => $total ? round($n / $total * 100) : 0; @endphp
<div class="stats req5">
  <x-stat label="Reachable guests" :value="number_format($consent['any'])" icon="users" tone="blue" :sub="$pct($consent['any']).'% of '.number_format($total).' opted in to a channel'" :href="route('crm.segments.index')"/>
  <x-stat label="Messages delivered" :value="number_format($sent)" icon="mail" tone="purple" sub="campaigns + automations, 30 days"/>
  <x-stat label="Click-through" :value="$ctr.'%'" icon="link" tone="slate" :sub="$clicks.' clicked · '.$unsubs.' unsubscribed'"/>
  <x-stat label="Attributed bookings" :value="$value['bookings']" icon="calendar" tone="green" :sub="$value['nights'].' room nights, 30 days'" :href="route('reports.show', 'crm_campaigns')"/>
  <x-stat label="Attributed revenue" :value="money($value['revenue'])" icon="money" tone="green" :sub="'promo discount '.money($value['discount'])"/>
</div>

<div class="grid g-2-1">
  <div class="card flush"><div class="card-head"><h3><x-icon name="mail"/> Recent campaigns</h3><a class="btn btn-sm btn-ghost" href="{{ route('crm.campaigns.index') }}">All campaigns</a></div><div class="card-body table-wrap"><table class="table" id="dashCampaigns">
    <thead><tr><th>Campaign</th><th>Audience</th><th>Status</th><th class="num">Delivered</th><th class="num">Clicks</th><th class="num">Bookings</th><th class="num">Revenue</th></tr></thead><tbody>
    @forelse($campaigns as $c) @php $st = in_array($c->status, ['sent', 'sending']) ? \App\Services\CrmService::stats($c) : null; @endphp
      <tr><td><a class="bold" href="{{ route('crm.campaigns.show', $c) }}">{{ $c->name }}</a><div class="tiny muted">{{ \App\Services\CrmService::CHANNELS[$c->channel] }}{{ $c->sent_at ? ' · '.$c->sent_at->format('d M Y') : ($c->scheduled_at && $c->status === 'scheduled' ? ' · '.$c->scheduled_at->format('d M H:i') : '') }}</div></td>
        <td class="small">{{ $c->segment?->name ?? 'All opted-in guests' }}</td><td>{!! status_badge($c->status) !!}</td>
        <td class="num">{{ $st['sent'] ?? '—' }}</td><td class="num">{{ $st ? $st['clicks'].' ('.$st['ctr'].'%)' : '—' }}</td><td class="num">{{ $st['bookings'] ?? '—' }}</td><td class="num">{{ $st ? money($st['revenue'], false) : '—' }}</td></tr>
    @empty<tr><td colspan="7"><x-empty text="No campaigns yet." icon="mail"/></td></tr>@endforelse
    </tbody></table></div></div>

  <div class="card"><div class="card-head"><h3><x-icon name="shield"/> Consent by channel</h3></div><div class="card-body">
    @foreach(\App\Services\CrmService::CHANNELS as $ch => $label)
      <div class="flex" style="justify-content:space-between"><span class="small">{{ $label }}</span><b class="small">{{ $consent[$ch] }} · {{ $pct($consent[$ch]) }}%</b></div>
      <div class="progress mb-1"><span style="width:{{ $pct($consent[$ch]) }}%"></span></div>
    @endforeach
    <p class="tiny muted m-0">Excludes blacklisted guests. Consent is captured on the guest profile, the booking engine and the unsubscribe page – every change is logged.</p>
    <div class="form-section">Delivered – last 14 days</div>
    @php $max = max(1, $daily->max() ?? 1); @endphp
    <div class="bar-chart" style="height:110px">@for($i = 13; $i >= 0; $i--) @php $d = now()->subDays($i)->toDateString(); $n = (int) ($daily[$d] ?? 0); @endphp
      <div class="col"><div class="b" style="height:{{ $n / $max * 100 }}%" data-v="{{ $n }}"></div><small>{{ \Illuminate\Support\Carbon::parse($d)->format('d') }}</small></div>@endfor</div>
  </div></div>
</div>

<div class="grid g-2">
  <div class="card flush"><div class="card-head"><h3><x-icon name="refresh"/> Automations</h3><a class="btn btn-sm btn-ghost" href="{{ route('crm.journeys.index') }}">Manage</a></div><div class="card-body table-wrap"><table class="table" id="dashJourneys">
    <thead><tr><th>Automation</th><th>When</th><th>Channel</th><th class="num">Sent (30 d)</th><th>Status</th></tr></thead><tbody>
    @forelse($journeys as $j)<tr class="{{ $j->is_active ? '' : 'muted-row' }}"><td><a href="{{ route('crm.journeys.show', $j) }}">{{ $j->name }}</a><div class="tiny muted">{{ $j->triggerLabel() }}</div></td><td class="small">{{ $j->whenLabel() }}</td>
      <td class="small">{{ \App\Services\CrmService::CHANNELS[$j->channel] }}</td><td class="num">{{ $j->sent_30 }}</td><td>{!! status_badge($j->is_active ? 'active' : 'inactive') !!}</td></tr>
    @empty<tr><td colspan="5"><x-empty text="No automations yet." icon="refresh"/></td></tr>@endforelse
    </tbody></table></div></div>

  <div class="card flush"><div class="card-head"><h3><x-icon name="calendar"/> Bookings credited to marketing</h3></div><div class="card-body table-wrap"><table class="table" id="dashBookings">
    <thead><tr><th>Booking</th><th>Guest</th><th>Source</th><th>How</th><th>Status</th></tr></thead><tbody>
    @forelse($bookings as $r)<tr><td class="nowrap"><a href="{{ route('reservations.show', $r) }}">{{ $r->reservation_no }}</a><div class="tiny muted">{{ $r->arrival_date->format('d M') }} – {{ $r->departure_date->format('d M') }}</div></td>
      <td class="small">{{ $r->guest?->full_name }}</td><td class="small">{{ $r->campaign?->name ?? ($r->journey?->name ?? '—') }}</td>
      <td class="small">{{ ucfirst($r->attribution) }}{{ $r->promo ? ' · '.$r->promo->code : '' }}</td><td>{!! status_badge($r->status) !!}</td></tr>
    @empty<tr><td colspan="5"><x-empty text="No attributed bookings yet." icon="calendar"/></td></tr>@endforelse
    </tbody></table></div></div>
</div>

<div class="grid g-2">
  <div class="card"><div class="card-head"><h3><x-icon name="star"/> Birthdays – next 14 days</h3></div><div class="card-body">
    <ul class="bq-list" id="dashBirthdays">
    @forelse($birthdays as $g)<li><span><a href="{{ route('guests.show', $g->id) }}">{{ trim($g->title.' '.$g->first_name.' '.$g->last_name) }}</a>@if($g->is_vip) <span class="badge badge-purple">VIP</span>@endif
      @unless($g->email_opt_in || $g->sms_opt_in || $g->whatsapp_opt_in)<span class="tiny muted"> · no consent</span>@endunless</span><b>{{ $g->next_birthday->isSameDay(bdate()) ? 'Today' : $g->next_birthday->format('D d M') }}</b></li>
    @empty<li class="muted">No birthdays coming up.</li>@endforelse
    </ul>
  </div></div>
  <div class="card"><div class="card-head"><h3><x-icon name="trend"/> Guest value mix (RFM)</h3><a class="btn btn-sm btn-ghost" href="{{ route('reports.show', 'crm_guest_value') }}">Report</a></div><div class="card-body">
    @php $mixTotal = max(1, $mix->sum()); @endphp
    @forelse($mix as $label => $n)
      <div class="flex" style="justify-content:space-between"><span class="small">{{ $label }}</span><b class="small">{{ $n }}</b></div>
      <div class="progress mb-1"><span style="width:{{ round($n / $mixTotal * 100) }}%"></span></div>
    @empty<p class="muted">No completed stays yet.</p>@endforelse
    <p class="tiny muted m-0">Champion = recent &amp; frequent · At risk = used to come, not lately · Lost = over two years ago. Build a segment on "days since last stay" to target them.</p>
  </div></div>
</div>
@endsection
