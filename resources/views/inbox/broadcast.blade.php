@extends('layouts.app')
@section('title', $b->number.' · '.$b->title)
@section('crumbs') Guest Messaging / <a href="{{ route('inbox.broadcasts') }}">Broadcasts</a> / {{ $b->number }} @endsection
@php $B = \App\Models\InboxBroadcast::class; @endphp
@section('content')
<div class="stats" id="inbBcStats">
  <x-stat label="Recipients" :value="$b->recipients" icon="users" tone="blue" :sub="$B::AUDIENCES[$b->audience] ?? $b->audience"/>
  <x-stat label="Sent" :value="$b->sent" icon="check" tone="green" :sub="$B::CHANNELS[$b->channel] ?? $b->channel"/>
  <x-stat label="Not reached" :value="$b->skipped + $b->failed" icon="alert" :tone="$b->skipped + $b->failed ? 'amber' : 'slate'" :sub="$b->failed.' failed · '.$b->skipped.' skipped'"/>
</div>
<div class="g-2-1">
  <div class="card"><div class="card-head"><h3>Delivery</h3></div><div class="card-body table-wrap">
    <table class="table table-compact" id="inbBcMsgs"><thead><tr><th>Room</th><th>Guest</th><th>Channel</th><th>Status</th><th></th></tr></thead><tbody>
    @forelse($msgs as $m)
      <tr><td>{{ $m->conversation?->stay?->room?->room_no ?? '—' }}</td><td>{{ $m->conversation?->displayName() }}</td><td class="small">{{ \App\Models\Conversation::CHANNELS[$m->channel] ?? $m->channel }}</td>
        <td><span class="badge badge-{{ $m->status === 'failed' ? 'red' : 'green' }}">{{ $m->status }}</span>@if($m->error)<div class="tiny muted">{{ $m->error }}</div>@endif</td>
        <td>@if($m->conversation)<a class="small" href="{{ route('inbox.index', ['c' => $m->conversation_id, 'f' => 'all']) }}">conversation</a>@endif</td></tr>
    @empty<tr><td colspan="5" class="muted">No messages were sent.</td></tr>@endforelse
    </tbody></table>
  </div></div>
  <div>
    <div class="card"><div class="card-head"><h3>Message</h3></div><div class="card-body small"><p class="pre-wrap m-0">{{ $b->body }}</p><p class="tiny muted mt-1 m-0">Sent {{ $b->created_at->format('d M Y H:i') }} by {{ $b->creator?->name }}</p></div></div>
    @if($b->skipped_reasons)
    <div class="card"><div class="card-head"><h3>Not reached</h3></div><div class="card-body small">
      @foreach($b->skipped_reasons as $why => $n)<div class="inb-kv"><span>{{ ucfirst($why) }}</span><b>{{ $n }}</b></div>@endforeach
    </div></div>
    @endif
  </div>
</div>
@endsection
