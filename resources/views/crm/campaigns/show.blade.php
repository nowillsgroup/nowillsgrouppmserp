@extends('layouts.app')
@section('title', $c->name)
@section('crumbs') <a href="{{ route('crm.index') }}">Marketing</a> / <a href="{{ route('crm.campaigns.index') }}">Campaigns</a> / {{ $c->name }} @endsection
@section('actions')
@if(can_do('crm.manage'))
  <form method="post" action="{{ route('crm.campaigns.duplicate', $c) }}" style="display:inline">@csrf<button class="btn" id="dupCampaign"><x-icon name="layers"/> Duplicate</button></form>
  @if($c->isEditable())<a class="btn" href="{{ route('crm.campaigns.edit', $c) }}" id="editCampaign"><x-icon name="edit"/> Edit</a>@endif
@endif
@endsection
@section('content')
@php $ch = \App\Services\CrmService::CHANNELS[$c->channel]; @endphp
<div class="alert alert-info"><x-icon name="mail"/><div>
  {!! status_badge($c->status) !!} <b>{{ $ch }}</b> to <b>{{ $c->segment?->name ?? 'all opted-in guests' }}</b>
  @if($c->segment)<span class="small muted">({{ $c->segment->describe() }})</span>@endif
  @if($c->promo) · promo <b>{{ $c->promo->code }}</b> ({{ $c->promo->offerLabel() }})@endif
  @if($c->status === 'scheduled') · goes out <b>{{ $c->scheduled_at->format('D d M Y H:i') }}</b>@endif
  @if($c->sent_at) · sent {{ $c->sent_at->format('d M Y H:i') }}{{ $c->sender ? ' by '.$c->sender->name : '' }}@endif
</div></div>

@if($breakdown)
<div class="grid g-2-1">
  <div class="card"><div class="card-head"><h3><x-icon name="users"/> Audience right now</h3></div><div class="card-body">
    <div class="flex" style="gap:2rem;align-items:flex-end">
      <div><div style="font-size:2.2rem;font-weight:800" id="eligibleCount">{{ $breakdown['eligible'] }}</div><div class="small muted">will receive it</div></div>
      <div><div style="font-size:1.4rem;font-weight:700">{{ $breakdown['total'] }}</div><div class="small muted">in the audience</div></div>
    </div>
    @if($breakdown['skipped'])<ul class="bq-list mt-1" id="skipList">@foreach($breakdown['skipped'] as $why => $n)<li><span>Skipped – {{ $why }}</span><b>{{ $n }}</b></li>@endforeach</ul>@endif
    @if(can_do('crm.send'))
    <div class="flex mt-1" style="gap:.5rem;flex-wrap:wrap">
      <form method="post" action="{{ route('crm.campaigns.send', $c) }}" onsubmit="return confirm('Send this {{ $ch }} campaign to {{ $breakdown['eligible'] }} guest(s) now?')">@csrf<button class="btn btn-primary" id="sendNow" @disabled(! $breakdown['eligible'])><x-icon name="mail"/> Send now</button></form>
      <form method="post" action="{{ route('crm.campaigns.schedule', $c) }}" class="flex" style="gap:.4rem">@csrf
        <input type="datetime-local" name="scheduled_at" class="input" style="width:auto" value="{{ ($c->scheduled_at ?? now()->addDay()->setTime(10, 0))->format('Y-m-d\TH:i') }}" min="{{ now()->format('Y-m-d\TH:i') }}" required>
        <button class="btn" id="scheduleBtn"><x-icon name="clock"/> {{ $c->status === 'scheduled' ? 'Reschedule' : 'Schedule' }}</button></form>
      @if($c->status === 'scheduled')<form method="post" action="{{ route('crm.campaigns.unschedule', $c) }}">@csrf<button class="btn btn-ghost" id="unscheduleBtn">Back to draft</button></form>@endif
    </div>
    @else<p class="small muted mt-1">Sending needs the "Send / schedule campaigns" permission.</p>@endif
  </div></div>
  <div class="card"><div class="card-head"><h3><x-icon name="check"/> Test send</h3></div><div class="card-body">
    @if(can_do('crm.manage'))
    <form method="post" action="{{ route('crm.campaigns.test', $c) }}" id="testForm">@csrf
      <x-field name="to" :label="$c->channel === 'email' ? 'Send a test to (e-mail)' : 'Send a test to (mobile)'" :type="$c->channel === 'email' ? 'email' : 'text'" :value="$c->channel === 'email' ? auth()->user()->email : ''" required/>
      <button class="btn" id="sendTest"><x-icon name="mail"/> Send test</button>
    </form>
    <p class="tiny muted">Uses {{ $sample?->first_name ?? 'a sample guest' }}'s details for the tokens. Tests are not counted.</p>
    @endif
    @if(can_do('crm.manage'))<form method="post" action="{{ route('crm.campaigns.cancel', $c) }}" onsubmit="return confirm('Cancel this campaign?')" class="mt-1">@csrf<button class="btn btn-ghost btn-sm text-red" id="cancelCampaign"><x-icon name="x"/> Cancel campaign</button></form>@endif
  </div></div>
</div>
@else
<div class="stats req5">
  <x-stat label="Delivered" :value="$stats['sent']" icon="check" tone="blue" :sub="$stats['recipients'].' recipients · '.$stats['skipped'].' skipped · '.$stats['failed'].' failed'"/>
  <x-stat label="Clicks" :value="$stats['clicks']" icon="link" tone="purple" :sub="$stats['ctr'].'% click-through'" href="?status=clicked"/>
  <x-stat label="Bookings" :value="$stats['bookings']" icon="calendar" tone="green" :sub="$stats['conversion'].'% of delivered · '.$stats['nights'].' nights'" href="?status=booked"/>
  <x-stat label="Room revenue" :value="money($stats['revenue'])" icon="money" tone="green" :sub="'discount given '.money($stats['discount'])"/>
  <x-stat label="Unsubscribes" :value="$stats['unsubscribes']" icon="logout" :tone="$stats['unsubscribes'] ? 'amber' : 'slate'" :sub="$stats['sent'] ? round($stats['unsubscribes'] / $stats['sent'] * 100, 1).'% of delivered' : ''"/>
</div>
@endif

<div class="grid g-2-1">
  <div>
    @if(! $breakdown)
    <div class="tabs">@foreach(['' => 'All', 'sent' => 'Delivered', 'clicked' => 'Clicked', 'booked' => 'Booked', 'skipped' => 'Skipped', 'failed' => 'Failed'] as $k => $v)<a href="?status={{ $k }}" class="{{ (string) request('status') === $k ? 'active' : '' }}">{{ $v }}</a>@endforeach</div>
    <div class="card flush"><div class="card-body table-wrap"><table class="table" id="rcptTable">
      <thead><tr><th>Guest</th><th>To</th><th>Status</th><th>Clicked</th><th>Booking</th></tr></thead><tbody>
      @forelse($recipients as $m)<tr><td><a href="{{ route('guests.show', $m->guest_id) }}">{{ $m->guest?->full_name }}</a></td><td class="small">{{ $m->to ?? '—' }}</td>
        <td>{!! status_badge($m->status) !!}@if($m->skip_reason)<div class="tiny muted">{{ $m->skip_reason }}</div>@endif @if($m->unsubscribed_at)<div class="tiny text-red">unsubscribed</div>@endif</td>
        <td class="small">{{ $m->clicked_at ? $m->clicked_at->format('d M H:i').($m->click_count > 1 ? ' ('.$m->click_count.'×)' : '') : '' }}</td>
        <td class="small">@if($m->reservation)<a href="{{ route('reservations.show', $m->reservation) }}">{{ $m->reservation->reservation_no }}</a>@endif</td></tr>
      @empty<tr><td colspan="5"><x-empty text="Nobody here." icon="users"/></td></tr>@endforelse
      </tbody></table></div></div>
    {{ $recipients->links() }}
    @if($skipReasons->isNotEmpty())<p class="tiny muted">Skipped: @foreach($skipReasons as $why => $n){{ $why }} {{ $n }}{{ $loop->last ? '' : ' · ' }}@endforeach</p>@endif
    @endif
    @if($bookings->isNotEmpty())
    <div class="card flush" id="campBookings"><div class="card-head"><h3><x-icon name="calendar"/> Bookings credited</h3></div><div class="card-body table-wrap"><table class="table">
      <thead><tr><th>Booking</th><th>Guest</th><th>Stay</th><th>Room</th><th>How</th><th class="num">Discount</th><th>Status</th></tr></thead><tbody>
      @foreach($bookings as $r)<tr><td><a href="{{ route('reservations.show', $r) }}">{{ $r->reservation_no }}</a><div class="tiny muted">booked {{ $r->created_at->format('d M') }}</div></td><td class="small">{{ $r->guest?->full_name }}</td>
        <td class="small nowrap">{{ $r->arrival_date->format('d M') }} – {{ $r->departure_date->format('d M') }}</td><td class="small">{{ $r->rooms->pluck('roomType.name')->unique()->join(', ') }}</td>
        <td class="small">{{ ucfirst($r->attribution) }}</td><td class="num">{{ $r->promo_discount > 0 ? money($r->promo_discount, false) : '—' }}</td><td>{!! status_badge($r->status) !!}</td></tr>@endforeach
      </tbody></table></div></div>
    @endif
  </div>
  <div class="card sticky-side"><div class="card-head"><h3><x-icon name="eye"/> {{ $ch }} preview</h3><span class="tiny muted">{{ $sample ? 'as '.$sample->first_name : '' }}</span></div><div class="card-body" id="campPreview">
    @if($preview)
      @if($c->channel === 'email')<div class="bold mb-1">{{ $preview['subject'] }}</div>@endif
      <div class="small" style="border:1px solid var(--c-border);border-radius:10px;padding:.8rem;background:#fff">
        @if($c->channel === 'email'){!! \App\Mail\MarketingMail::renderBody($preview['body']) !!}@if($c->cta_label)<p><span class="btn btn-primary btn-sm">{{ $c->cta_label }}</span></p>@endif<p class="tiny muted m-0">Unsubscribe or change your preferences</p>
        @else<div style="white-space:pre-wrap">{{ $preview['body'] }}</div>@endif
      </div>
      <div class="tiny muted mt-1">{{ mb_strlen($preview['body']) }} characters{{ $c->channel === 'sms' ? ' · '.(mb_strlen($preview['body']) <= 160 ? 1 : (int) ceil(mb_strlen($preview['body']) / 153)).' SMS part(s)' : '' }}</div>
    @else<p class="muted">No guests to preview with.</p>@endif
  </div></div>
</div>
@endsection
