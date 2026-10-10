@extends('layouts.app')
@section('title', $j->name)
@section('crumbs') <a href="{{ route('crm.index') }}">Marketing</a> / <a href="{{ route('crm.journeys.index') }}">Automations</a> / {{ $j->name }} @endsection
@section('actions')
@if(can_do('crm.send'))
  @if($j->is_active)<form method="post" action="{{ route('crm.journeys.run', $j) }}" style="display:inline">@csrf<button class="btn" id="runJourney" title="Run for today's business date now"><x-icon name="refresh"/> Run now</button></form>@endif
  <form method="post" action="{{ route('crm.journeys.toggle', $j) }}" style="display:inline">@csrf<button class="btn {{ $j->is_active ? '' : 'btn-success' }}" id="toggleJourney"><x-icon name="{{ $j->is_active ? 'x' : 'check' }}"/> {{ $j->is_active ? 'Switch off' : 'Switch on' }}</button></form>
@endif
@if(can_do('crm.manage'))<a class="btn" href="{{ route('crm.journeys.edit', $j) }}"><x-icon name="edit"/> Edit</a>@endif
@endsection
@section('content')
@php $ch = \App\Services\CrmService::CHANNELS[$j->channel]; @endphp
<div class="alert {{ $j->is_active ? 'alert-success' : 'alert-warn' }}"><x-icon name="{{ $j->is_active ? 'check' : 'alert' }}"/><div>
  <b>{{ $j->triggerLabel() }}</b> – {{ $j->whenLabel() }} · {{ $ch }} · {{ $j->segment ? 'segment "'.$j->segment->name.'"' : 'everyone who qualifies' }}@if($j->promo) · promo <b>{{ $j->promo->code }}</b>@endif.
  {{ $j->is_active ? 'Active'.($j->last_run_at ? ' – last run '.$j->last_run_at->format('d M H:i') : '') : 'Switched off – nothing is sent.' }}
</div></div>
<div class="stats req5">
  <x-stat label="Delivered" :value="$stats['sent']" icon="check" tone="blue" :sub="$stats['skipped'].' skipped · '.$stats['failed'].' failed'"/>
  <x-stat label="Clicks" :value="$stats['clicks']" icon="link" tone="purple" :sub="$stats['ctr'].'% click-through'"/>
  <x-stat label="Bookings" :value="$stats['bookings']" icon="calendar" tone="green" :sub="$stats['nights'].' room nights'"/>
  <x-stat label="Room revenue" :value="money($stats['revenue'])" icon="money" tone="green" :sub="'discount '.money($stats['discount'])"/>
  <x-stat label="Due today / tomorrow" :value="$next->count()" icon="clock" tone="amber" sub="guests who qualify"/>
</div>
<div class="grid g-2-1">
  <div>
    @if($next->isNotEmpty())
    <div class="card flush" id="nextUp"><div class="card-head"><h3><x-icon name="clock"/> Coming up</h3></div><div class="card-body table-wrap"><table class="table">
      <thead><tr><th>Guest</th><th>Occasion</th><th>{{ $ch }} consent</th></tr></thead><tbody>
      @foreach($next->take(15) as [$g, $occ, $r])<tr><td><a href="{{ route('guests.show', $g) }}">{{ $g->full_name }}</a></td>
        <td class="small">@if($r)<a href="{{ route('reservations.show', $r) }}">{{ $r->reservation_no }}</a> {{ $r->arrival_date->format('d M') }}–{{ $r->departure_date->format('d M') }}@elseif($j->trigger === 'birthday'){{ $g->dob?->format('d M') }}@elseif($j->trigger === 'anniversary'){{ $g->anniversary?->format('d M') }}@else last stay {{ $g->last_stay_on?->format('d M Y') }}@endif</td>
        <td>{!! $g->optedIn($j->channel) && ! $g->is_blacklisted ? '<span class="badge badge-green">yes</span>' : '<span class="badge badge-slate">no – will be skipped</span>' !!}</td></tr>@endforeach
      </tbody></table></div></div>
    @endif
    <div class="tabs">@foreach(['' => 'All', 'sent' => 'Delivered', 'skipped' => 'Skipped', 'failed' => 'Failed'] as $k => $v)<a href="?status={{ $k }}" class="{{ (string) request('status') === $k ? 'active' : '' }}">{{ $v }}</a>@endforeach</div>
    <div class="card flush"><div class="card-body table-wrap"><table class="table" id="journeyLog">
      <thead><tr><th>Date</th><th>Guest</th><th>Occasion</th><th>Status</th><th>Engagement</th></tr></thead><tbody>
      @forelse($messages as $m)<tr><td class="small nowrap">{{ $m->created_at->format('d M Y H:i') }}</td><td><a href="{{ route('guests.show', $m->guest_id) }}">{{ $m->guest?->full_name }}</a></td>
        <td class="small">{{ str_starts_with((string) $m->occurrence, 'R') ? 'Booking' : (str_starts_with((string) $m->occurrence, 'W') ? 'Lapsed since '.substr($m->occurrence, 1) : $m->occurrence) }}</td>
        <td>{!! status_badge($m->status) !!}@if($m->skip_reason)<div class="tiny muted">{{ $m->skip_reason }}</div>@endif</td>
        <td class="small">{{ $m->clicked_at ? 'clicked' : '' }}@if($m->reservation) · booked <a href="{{ route('reservations.show', $m->reservation) }}">{{ $m->reservation->reservation_no }}</a>@endif{{ $m->unsubscribed_at ? ' · unsubscribed' : '' }}</td></tr>
      @empty<tr><td colspan="5"><x-empty text="Nothing sent yet." icon="mail"/></td></tr>@endforelse
      </tbody></table></div></div>
    {{ $messages->links() }}
  </div>
  <div>
    <div class="card"><div class="card-head"><h3><x-icon name="eye"/> {{ $ch }} preview</h3></div><div class="card-body" id="journeyPreview">
      @if($preview)
        @if($j->channel === 'email')<div class="bold mb-1">{{ $preview['subject'] }}</div>@endif
        <div class="small" style="border:1px solid var(--c-border);border-radius:10px;padding:.8rem;background:#fff">
          @if($j->channel === 'email'){!! \App\Mail\MarketingMail::renderBody($preview['body']) !!}@if($j->cta_label)<p><span class="btn btn-primary btn-sm">{{ $j->cta_label }}</span></p>@endif
          @else<div style="white-space:pre-wrap">{{ $preview['body'] }}</div>@endif
        </div>
      @endif
    </div></div>
    @if(can_do('crm.manage'))
    <div class="card"><div class="card-head"><h3>Test send</h3></div><div class="card-body">
      <form method="post" action="{{ route('crm.journeys.test', $j) }}">@csrf
        <x-field name="to" :label="$j->channel === 'email' ? 'E-mail' : 'Mobile'" :type="$j->channel === 'email' ? 'email' : 'text'" :value="$j->channel === 'email' ? auth()->user()->email : ''" required/>
        <button class="btn" id="journeyTest"><x-icon name="mail"/> Send test</button>
      </form>
    </div></div>
    @endif
  </div>
</div>
@endsection
