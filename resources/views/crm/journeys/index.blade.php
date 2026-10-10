@extends('layouts.app')
@section('title', 'Automations')
@section('crumbs') <a href="{{ route('crm.index') }}">Marketing</a> / Automations @endsection
@section('actions')
@if(can_do('crm.manage'))<a class="btn btn-primary" href="{{ route('crm.journeys.create') }}" id="newJourney"><x-icon name="plus"/> New automation</a>@endif
@endsection
@section('content')
<div class="grid g-4 mb-1">
@foreach(\App\Models\MarketingJourney::TRIGGERS as $k => [$label, $meaning])
  @continue($k === 'anniversary')
  <a class="card list-link" style="margin:0" href="{{ can_do('crm.manage') ? route('crm.journeys.create', ['trigger' => $k]) : '#' }}"><div class="card-body">
    <div class="bold"><x-icon :name="['birthday' => 'star', 'pre_arrival' => 'calendar', 'post_stay' => 'message', 'win_back' => 'refresh'][$k]"/> {{ $label }}</div>
    <div class="tiny muted">Sent {{ $meaning }}.</div></div></a>
@endforeach
</div>
<div class="card flush"><div class="card-body table-wrap"><table class="table" id="journeyTable">
<thead><tr><th>Automation</th><th>Trigger</th><th>Channel</th><th>Audience</th><th class="num">Due tomorrow</th><th class="num">Delivered</th><th class="num">Clicks</th><th class="num">Bookings</th><th class="num">Revenue</th><th>Status</th></tr></thead><tbody>
@forelse($journeys as $j) @php $st = $j->stats; @endphp
<tr class="{{ $j->is_active ? '' : 'muted-row' }}">
  <td><a class="bold" href="{{ route('crm.journeys.show', $j) }}">{{ $j->name }}</a>@if($j->promo)<div class="tiny muted">promo {{ $j->promo->code }}</div>@endif</td>
  <td class="small">{{ $j->triggerLabel() }}<div class="tiny muted">{{ $j->whenLabel() }}</div></td>
  <td class="small">{{ \App\Services\CrmService::CHANNELS[$j->channel] }}</td>
  <td class="small">{{ $j->segment?->name ?? 'Everyone who qualifies' }}</td>
  <td class="num">{{ $j->upcoming ?? '—' }}</td>
  <td class="num">{{ $st['sent'] }}@if($st['skipped'])<div class="tiny muted">{{ $st['skipped'] }} skipped</div>@endif</td>
  <td class="num">{{ $st['clicks'] }}</td><td class="num">{{ $st['bookings'] }}</td><td class="num">{{ money($st['revenue'], false) }}</td>
  <td>@if(can_do('crm.send'))<form method="post" action="{{ route('crm.journeys.toggle', $j) }}">@csrf<button class="btn btn-sm {{ $j->is_active ? 'btn-soft' : '' }}" title="{{ $j->is_active ? 'Switch off' : 'Switch on' }}">{!! status_badge($j->is_active ? 'active' : 'inactive') !!}</button></form>@else{!! status_badge($j->is_active ? 'active' : 'inactive') !!}@endif</td>
</tr>
@empty<tr><td colspan="10"><x-empty text="No automations yet – start with a birthday greeting or a post-stay thank-you." icon="refresh"/></td></tr>@endforelse
</tbody></table></div></div>
<p class="tiny muted mt-1">Active automations run every morning at 09:00 and right after the night audit. Each guest is contacted at most once per occasion (per birthday year, per booking, per lapse), and only if they opted in on the channel.</p>
@endsection
