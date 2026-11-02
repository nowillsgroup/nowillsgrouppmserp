@extends('layouts.app')
@section('title', 'OTA Inbox')
@section('crumbs') <a href="{{ route('cm.index') }}">Channel Manager</a> / OTA inbox @endsection
@section('content')
<div class="cm-page">
@include('channel-manager._nav')
@php $tabLabel = \App\Http\Controllers\ChannelManagerController::INBOX_TABS[$tab] ?? 'OTA messages'; @endphp
<header class="cm-hero"><div class="cm-hero-copy"><div class="cm-kicker"><x-icon name="mail"/> Incoming channel activity</div><h2>{{ $tabLabel }}</h2><p>Review booking, change and cancellation messages received from your connected channels.</p></div><span class="cm-hero-mark" aria-hidden="true"><x-icon name="mail"/></span></header>
<form class="cm-filter" method="get" id="inboxFilter"><input type="hidden" name="tab" value="{{ $tab }}">
  <label class="field"><span>Connection</span><select name="connection" aria-label="Filter by channel connection"><option value="">All channels</option>@foreach($connections as $id => $n)<option value="{{ $id }}" @selected(request('connection') == $id)>{{ $n }}</option>@endforeach</select></label>
  <label class="field"><span>Booking reference</span><input name="q" value="{{ request('q') }}" placeholder="Search channel booking ref…" aria-label="Search channel booking reference"></label>
  <button class="btn btn-primary"><x-icon name="search"/> Filter messages</button>
  @if(request('connection') || request('q'))<a class="btn btn-ghost" href="{{ route('cm.inbox', ['tab' => $tab]) }}">Clear filters</a>@endif
</form>
<nav class="tabs cm-inbox-tabs" id="inboxTabs" aria-label="Filter OTA messages">@foreach(\App\Http\Controllers\ChannelManagerController::INBOX_TABS as $k => $v)<a href="?{{ http_build_query(array_merge(request()->except('page'), ['tab' => $k])) }}" class="{{ $tab === $k ? 'active' : '' }}" @if($tab === $k) aria-current="page" @endif>{{ $v }} <span class="badge {{ $k === 'attention' && $counts[$k] ? 'badge-amber' : 'badge-slate' }}">{{ $counts[$k] }}</span></a>@endforeach</nav>
<section class="card flush" aria-label="OTA messages"><div class="card-body table-wrap" role="region" aria-label="Scrollable OTA message register" tabindex="0"><table class="table cm-inbox-table" id="inboxTable">
<caption class="cm-sr-only">{{ $tabLabel }} OTA messages. Select a reference to review the full message, reservation details and processing history.</caption>
<thead><tr><th scope="col">Received</th><th scope="col">Channel</th><th scope="col">Booking ref</th><th scope="col">Event</th><th scope="col">Guest</th><th scope="col">Stay</th><th scope="col" class="num">Amount</th><th scope="col">Result</th><th scope="col">Reservation</th></tr></thead><tbody>
@forelse($messages as $m) @php $p = $m->payload; @endphp
<tr>
  <td class="small nowrap"><a href="{{ route('cm.message', $m) }}"><time datetime="{{ $m->created_at->toIso8601String() }}">{{ $m->created_at->format('d M H:i') }}</time></a></td>
  <td class="small">{{ $m->connection?->name ?? 'Unknown channel' }}</td><td><a href="{{ route('cm.message', $m) }}" class="bold">{{ $m->channel_ref }}</a></td>
  <td><span class="badge badge-{{ ['new' => 'blue', 'modify' => 'purple', 'cancel' => 'red'][$m->event] ?? 'slate' }}">{{ $m->event }}</span></td>
  <td>{{ $m->guestName() }}</td>
  <td class="small nowrap">@if(! empty($p['arrival']))<time datetime="{{ \Illuminate\Support\Carbon::parse($p['arrival'])->toDateString() }}">{{ \Illuminate\Support\Carbon::parse($p['arrival'])->format('d M') }}</time> – <time datetime="{{ \Illuminate\Support\Carbon::parse($p['departure'])->toDateString() }}">{{ \Illuminate\Support\Carbon::parse($p['departure'])->format('d M Y') }}</time>@else—@endif</td>
  <td class="num">{{ $m->amount !== null ? money($m->amount) : '—' }}@if(! empty($p['currency'])) <span class="tiny muted">{{ $p['currency'] }}</span>@endif</td>
  <td>{!! $m->statusBadge() !!}@if($m->attention)<div class="tiny" style="color:#9a6307">{{ \App\Models\ChannelMessage::ATTENTION[$m->attention] ?? $m->attention }}</div>@elseif($m->status === 'failed')<div class="tiny cm-error-text" style="max-width:260px">{{ \Illuminate\Support\Str::limit($m->error, 90) }}</div>@elseif(! empty($m->warnings))<div class="tiny muted">{{ count($m->warnings) }} warning(s)</div>@endif</td>
  <td>@if($m->reservation)@if(can_do('reservations.view'))<a href="{{ route('reservations.show', $m->reservation) }}">{{ $m->reservation->reservation_no }}</a>@else{{ $m->reservation->reservation_no }}@endif @else<span class="muted">—</span>@endif</td>
</tr>
@empty<tr><td colspan="9"><x-empty text="No messages in this view." icon="mail"/></td></tr>@endforelse
</tbody></table></div></section>
{{ $messages->links() }}
</div>
@endsection
