@extends('layouts.app')
@section('title', 'TV Messages')
@section('crumbs') IPTV &amp; Guest Welcome / TV Messages @endsection
@php $u = auth()->user(); $IS = \App\Services\IptvService::class; $canMsg = $IS::canMessage($u); $canEm = $IS::canEmergency($u); $M = \App\Models\TvMessage::class; @endphp
@section('actions')
  <a class="btn" href="{{ route('reports.show', ['report' => 'iptv_messages']) }}"><x-icon name="download"/> Message report</a>
@endsection
@section('content')
@include('iptv._emergency', ['emergency' => $emergency, 'canEm' => $canEm])
<div class="g-2-1">
  <div>
    <div class="card"><div class="card-body"><form method="get" class="filters" style="align-items:flex-end">
      <div class="field"><label>Kind</label><select name="kind"><option value="">All</option>@foreach($M::KINDS as $k => $l)<option value="{{ $k }}" @selected(request('kind') === $k)>{{ $l }}</option>@endforeach</select></div>
      <button class="btn btn-sm">Filter</button></form></div></div>
    <div class="card flush"><div class="card-body table-wrap"><table class="table table-compact" id="tvMsgList"><thead><tr><th>Ref</th><th>Message</th><th>To</th><th>When</th><th class="num">Read</th><th>State</th><th></th></tr></thead><tbody>
    @forelse($list as $m)<tr class="{{ $m->kind === 'emergency' ? 'row-red' : '' }}"><td class="nowrap"><b>{{ $m->number }}</b></td>
      <td class="small"><b>{{ $m->title }}</b>{!! $m->priority === 'high' ? ' <span class="badge badge-red">high</span>' : '' !!}{!! $m->requires_ack ? ' <span class="badge badge-blue">ack</span>' : '' !!}<div class="tiny muted">{{ \Illuminate\Support\Str::limit($m->body, 110) }}</div>
        @if($m->close_note)<div class="tiny muted">{{ $m->status === 'cleared' ? 'All clear' : 'Withdrawn' }} by {{ $m->closer?->name }} {{ $m->closed_at?->format('d M H:i') }}: {{ $m->close_note }}</div>@endif</td>
      <td class="small">{{ $m->targetLabel() }}{!! $reach[$m->id] !== null ? '<div class="tiny muted">'.$reach[$m->id].' TV(s) now</div>' : '' !!}</td>
      <td class="small nowrap">{{ $m->starts_at->format('d M H:i') }}<div class="tiny muted">{{ $m->closed_at ? ($m->status === 'cleared' ? 'all clear ' : 'withdrawn ').$m->closed_at->format('d M H:i') : ($m->ends_at ? 'until '.$m->ends_at->format('d M H:i') : 'until withdrawn') }} · {{ $m->sender?->name }}</div></td>
      <td class="num small">{{ $m->reads_count }}</td><td><span class="badge badge-{{ $m->stateTone() }}">{{ $m->state() }}</span></td>
      <td class="nowrap">@if($canMsg && $m->kind !== 'emergency' && in_array($m->state(), ['live', 'scheduled'], true))<details class="esg-pop"><summary class="btn btn-sm">Withdraw</summary><form method="post" action="{{ route('iptv.messages.cancel', $m) }}">@csrf<input name="reason" class="input" maxlength="190" placeholder="Reason" required><button class="btn btn-sm btn-danger">Withdraw</button></form></details>@endif</td></tr>
    @empty<tr><td colspan="7" class="muted">No messages yet.</td></tr>@endforelse
    </tbody></table></div></div>
    {{ $list->links() }}
  </div>
  <div>
    @if($canMsg)
    <div class="card" id="new"><div class="card-head"><h3>Send a message to TVs</h3></div><div class="card-body">
      <form method="post" action="{{ route('iptv.messages.store') }}" class="form-grid" id="tvMsgForm">@csrf
        <div class="field span-all"><label>To</label><select name="target" id="tvMsgTarget" required>@foreach($M::TARGETS as $k => $l)<option value="{{ $k }}" @selected(old('target', request('target', 'room')) === $k)>{{ $l }}</option>@endforeach</select></div>
        <div class="field span-all" data-for="room"><label>Room</label><select name="room_id"><option value="">—</option>@foreach($inhouse as $s)@php $g = $s->guest ?? $s->reservation?->guest; @endphp<option value="{{ $s->room_id }}" @selected(old('room_id', request('room')) == $s->room_id)>{{ $s->room?->room_no }} · {{ $g ? trim($g->first_name.' '.$g->last_name) : '' }}</option>@endforeach</select></div>
        <div class="field span-all" data-for="floor"><label>Floor</label><select name="floor_id"><option value="">—</option>@foreach($floors as $f)<option value="{{ $f->id }}" @selected(old('floor_id') == $f->id)>{{ $f->name }}</option>@endforeach</select></div>
        <div class="field span-all" data-for="group"><label>Booking</label><select name="reservation_id"><option value="">—</option>@foreach($groups as $r)<option value="{{ $r->id }}" @selected(old('reservation_id') == $r->id)>{{ $r->reservation_no }}{{ $r->group_name ? ' · '.$r->group_name : '' }}</option>@endforeach</select></div>
        <div class="field span-all"><label>Title</label><input name="title" maxlength="80" required value="{{ old('title') }}"></div>
        <div class="field span-all"><label>Message</label><textarea name="body" rows="3" maxlength="500" required>{{ old('body') }}</textarea></div>
        <div class="field"><label>Show from</label><input type="datetime-local" name="starts_at" value="{{ old('starts_at') }}"></div>
        <div class="field"><label>Until</label><input type="datetime-local" name="ends_at" value="{{ old('ends_at') }}"></div>
        <div class="field"><label>Priority</label><select name="priority"><option value="normal">Normal</option><option value="high" @selected(old('priority') === 'high')>High – pops up</option></select></div>
        <div class="field"><label>&nbsp;</label><label class="small"><input type="checkbox" name="requires_ack" value="1" @checked(old('requires_ack'))> Guest must press OK</label></div>
        <div class="span-all"><button class="btn btn-primary">Send</button> <span class="tiny muted">Leave “from” empty to send now. Broadcasts end after 24 h unless you set an end.</span></div>
      </form>
      <script>(function(){var s=document.getElementById('tvMsgTarget');function f(){document.querySelectorAll('#tvMsgForm [data-for]').forEach(function(e){e.style.display=e.getAttribute('data-for')===s.value?'':'none';});}s.addEventListener('change',f);f();})();</script>
    </div></div>
    @endif
    <div class="card"><div class="card-head"><h3>Tips</h3></div><div class="card-body small">
      <ul class="plain">
        <li>Guest messages taken by the desk (phone messages, parcels) show on the TV automatically as “You have a message at the front desk” – no need to send them twice.</li>
        <li>High-priority messages pop up over what the guest is watching; normal messages wait in the TV’s inbox with a badge.</li>
        <li>For fire or evacuation use the <b>Emergency alert</b> on the dashboard – it covers every screen until the all clear.</li>
      </ul>
    </div></div>
  </div>
</div>
@endsection
