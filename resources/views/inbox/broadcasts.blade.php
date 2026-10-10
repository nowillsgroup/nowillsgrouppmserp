@extends('layouts.app')
@section('title', 'Broadcasts')
@section('crumbs') Guest Messaging / Broadcasts @endsection
@php $B = \App\Models\InboxBroadcast::class; @endphp
@section('content')
<div class="g-1-2">
  <div>
    @if($canSend)
    <div class="card" id="inbBcNew"><div class="card-head"><h3><x-icon name="bell"/> New broadcast</h3></div><div class="card-body">
      <form method="post" action="{{ route('inbox.broadcasts.store') }}" class="form-grid" id="inbBcForm" data-preview="{{ route('inbox.broadcasts.preview') }}">@csrf
        <div class="field span-all"><label>Title (for your records)</label><input name="title" class="input" maxlength="80" required value="{{ old('title') }}" placeholder="e.g. Pool closed for cleaning"></div>
        <div class="field"><label>Send to</label><select name="audience" class="input" id="inbBcAud">@foreach($B::AUDIENCES as $k => $l)<option value="{{ $k }}" @selected(old('audience') === $k)>{{ $l }}</option>@endforeach</select></div>
        <div class="field" id="inbBcFloorF"><label>Floor</label><select name="floor_id" class="input" id="inbBcFloor"><option value="">—</option>@foreach($floors as $fl)<option value="{{ $fl->id }}" @selected((int) old('floor_id') === $fl->id)>{{ $fl->name }}</option>@endforeach</select></div>
        <div class="field span-all"><label>Channel</label><select name="channel" class="input" id="inbBcCh">@foreach($B::CHANNELS as $k => $l)<option value="{{ $k }}" @selected(old('channel', 'best') === $k)>{{ $l }}</option>@endforeach</select></div>
        <div class="field span-all"><label>Message</label><textarea name="body" rows="5" maxlength="1000" required placeholder="Dear {guest}, …">{{ old('body') }}</textarea>
          <small class="muted">Placeholders: {guest} {first_name} {room} {departure} {checkout_time} {hotel} {wifi_name} {wifi_password}. Guests who opted out of SMS / WhatsApp get the portal chat instead (or are skipped).</small></div>
        <div class="span-all small inb-reach" id="inbBcReach">Choose the audience to see how many guests it reaches.</div>
        <div class="span-all"><button class="btn btn-primary" id="inbBcSend" onclick="return confirm('Send this message to the selected guests now?')"><x-icon name="chev" size="sm"/> Send broadcast</button></div>
      </form>
    </div></div>
    @endif
    <div class="card"><div class="card-body small muted">Broadcasts reach guests with a live stay only. Each guest gets the message in their own conversation, so a reply lands in the inbox like any other message.</div></div>
  </div>
  <div>
    <div class="card"><div class="card-head"><h3>Sent broadcasts</h3></div><div class="card-body table-wrap">
      <table class="table table-compact" id="inbBcList"><thead><tr><th>No.</th><th>Title</th><th>Audience</th><th class="num">Sent</th><th class="num">Not reached</th><th>By</th></tr></thead><tbody>
      @forelse($list as $b)
        <tr><td class="nowrap"><a href="{{ route('inbox.broadcast', $b) }}">{{ $b->number }}</a><div class="tiny muted">{{ $b->created_at->format('d M H:i') }}</div></td><td>{{ $b->title }}<div class="tiny muted">{{ \Illuminate\Support\Str::limit($b->body, 70) }}</div></td>
          <td class="small">{{ $B::AUDIENCES[$b->audience] ?? $b->audience }}{{ $b->floor ? ' · '.$b->floor->name : '' }}</td><td class="num">{{ $b->sent }}</td><td class="num">{{ $b->skipped + $b->failed ?: '—' }}</td><td class="small">{{ $b->creator?->name }}</td></tr>
      @empty<tr><td colspan="6" class="muted">No broadcasts yet.</td></tr>@endforelse
      </tbody></table>
      {{ $list->links() }}
    </div></div>
  </div>
</div>
@endsection
@push('scripts')<script src="{{ asset('js/inbox.js') }}?v=73"></script>@endpush
