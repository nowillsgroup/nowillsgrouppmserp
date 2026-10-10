@extends('layouts.app')
@section('title', 'OTA Inbox')
@section('crumbs') <a href="{{ route('cm.index') }}">Channel Manager</a> / OTA inbox @endsection
@section('content')
@include('channel-manager._nav')
<form class="filters" method="get" id="inboxFilter"><input type="hidden" name="tab" value="{{ $tab }}">
  <select name="connection"><option value="">All channels</option>@foreach($connections as $id => $n)<option value="{{ $id }}" @selected(request('connection') == $id)>{{ $n }}</option>@endforeach</select>
  <input name="q" value="{{ request('q') }}" placeholder="Channel booking ref…"><button class="btn">Filter</button></form>
<div class="tabs" id="inboxTabs">@foreach(\App\Http\Controllers\ChannelManagerController::INBOX_TABS as $k => $v)<a href="?{{ http_build_query(array_merge(request()->except('page'), ['tab' => $k])) }}" class="{{ $tab === $k ? 'active' : '' }}">{{ $v }} <span class="muted">{{ $counts[$k] }}</span></a>@endforeach</div>
<div class="card flush"><div class="card-body table-wrap"><table class="table" id="inboxTable">
<thead><tr><th>Received</th><th>Channel</th><th>Booking ref</th><th>Event</th><th>Guest</th><th>Stay</th><th class="num">Amount</th><th>Result</th><th>Reservation</th></tr></thead><tbody>
@forelse($messages as $m) @php $p = $m->payload; @endphp
<tr>
  <td class="small nowrap"><a href="{{ route('cm.message', $m) }}">{{ $m->created_at->format('d M H:i') }}</a></td>
  <td class="small">{{ $m->connection?->name }}</td><td><a href="{{ route('cm.message', $m) }}" class="bold">{{ $m->channel_ref }}</a></td>
  <td><span class="badge badge-{{ ['new' => 'blue', 'modify' => 'purple', 'cancel' => 'red'][$m->event] ?? 'slate' }}">{{ $m->event }}</span></td>
  <td>{{ $m->guestName() }}</td>
  <td class="small nowrap">@if(! empty($p['arrival'])){{ \Illuminate\Support\Carbon::parse($p['arrival'])->format('d M') }} – {{ \Illuminate\Support\Carbon::parse($p['departure'])->format('d M') }}@else—@endif</td>
  <td class="num">{{ $m->amount !== null ? money($m->amount) : '—' }}</td>
  <td>{!! $m->statusBadge() !!}@if($m->attention)<div class="tiny" style="color:#b45309">{{ \App\Models\ChannelMessage::ATTENTION[$m->attention] ?? $m->attention }}</div>@elseif($m->status === 'failed')<div class="tiny" style="color:#b91c1c;max-width:260px">{{ \Illuminate\Support\Str::limit($m->error, 90) }}</div>@elseif(! empty($m->warnings))<div class="tiny muted">{{ count($m->warnings) }} warning(s)</div>@endif</td>
  <td>@if($m->reservation)@if(can_do('reservations.view'))<a href="{{ route('reservations.show', $m->reservation) }}">{{ $m->reservation->reservation_no }}</a>@else{{ $m->reservation->reservation_no }}@endif @else<span class="muted">—</span>@endif</td>
</tr>
@empty<tr><td colspan="9"><x-empty text="No messages in this view." icon="mail"/></td></tr>@endforelse
</tbody></table></div></div>
{{ $messages->links() }}
@endsection
