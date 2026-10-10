{{-- v3.7: phone class of service, calls and key cards for an in-house stay (folio sidebar) --}}
@php
  $ifCards = \App\Models\KeyCard::where('reservation_room_id', $stay->id)->where('status', 'active')->orderBy('id')->get();
  $ifCalls = \App\Models\CallRecord::where('reservation_room_id', $stay->id)->selectRaw("count(*) n, sum(case when status='posted' then charge else 0 end) charged")->first();
  $ifCfg = \App\Services\InterfaceService::cfg();
@endphp
<div class="card" id="stayInterfaces"><div class="card-head"><h3><x-icon name="phone" size="sm"/> Phone & key cards</h3></div><div class="card-body">
  <dl class="kv">
    <dt>Room phone</dt><dd>ext {{ \App\Services\CallAccountingService::roomExtension($stay->room_id) ?? '—' }} · {{ \App\Services\InterfaceService::COS[$stay->phone_cos ?? 'full'] ?? $stay->phone_cos }}</dd>
    <dt>Calls this stay</dt><dd>@if(can_do('calls.view'))<a href="{{ route('calls.index', ['q' => $stay->room?->room_no, 'from' => $stay->arrival_date->toDateString()]) }}">{{ $ifCalls->n }}</a>@else{{ $ifCalls->n }}@endif · {{ money($ifCalls->charged ?? 0) }} billed</dd>
    <dt>Key cards</dt><dd id="stayCardCount">{{ $ifCards->count() }} working{{ $ifCards->isNotEmpty() ? ' · until '.$ifCards->first()->valid_until->format('d M H:i') : '' }}</dd>
  </dl>
  @if(can_do('calls.manage') || can_do('keys.issue'))
  <form method="post" action="{{ route('calls.cos', $stay) }}" class="flex" style="gap:.4rem;margin-top:.5rem" id="cosForm">@csrf
    <select name="cos" class="input" style="flex:1">@foreach(\App\Services\InterfaceService::COS as $k => $l)<option value="{{ $k }}" @selected(($stay->phone_cos ?? 'full') === $k)>{{ $l }}</option>@endforeach</select><button class="btn btn-sm" id="cosBtn">Set</button></form>
  @endif
  @if(can_do('keys.issue') && $ifCfg['lock_enabled'] === '1')
  <form method="post" action="{{ route('keys.issue') }}" class="flex" style="gap:.4rem;margin-top:.5rem" id="stayEncodeForm">@csrf<input type="hidden" name="stay_id" value="{{ $stay->id }}">
    @foreach($ifCfg['default_areas'] as $a)<input type="hidden" name="areas[]" value="{{ $a }}">@endforeach
    <select name="mode" class="input" style="flex:1"><option value="{{ $ifCards->isEmpty() ? 'new' : 'duplicate' }}">{{ $ifCards->isEmpty() ? 'New key' : 'Duplicate' }}</option>@if($ifCards->isNotEmpty())<option value="new">New key (cancel others)</option>@endif</select>
    <input type="number" name="count" class="input" value="{{ $ifCards->isEmpty() ? 2 : 1 }}" min="1" max="{{ $ifCfg['lock_max_cards'] }}" style="width:4rem"><button class="btn btn-sm btn-primary" id="stayEncodeBtn"><x-icon name="key" size="sm"/> Encode</button></form>
  @endif
</div></div>
