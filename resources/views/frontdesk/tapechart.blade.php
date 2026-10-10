@extends('layouts.app')
@section('title', 'Tape Chart')
@section('crumbs') Front Office / Tape Chart @endsection
@if(can_do('frontdesk.roommove'))
@push('scripts')
<script>
(() => {
  let drag = null;
  document.querySelectorAll('[draggable=true][data-stay]').forEach(el => {
    el.addEventListener('dragstart', e => { drag = el.dataset; e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', el.dataset.stay); document.body.classList.add('dragging'); });
    el.addEventListener('dragend', () => { document.body.classList.remove('dragging'); document.querySelectorAll('tr.drop-over').forEach(r => r.classList.remove('drop-over')); });
  });
  document.querySelectorAll('.tape tr[data-room]').forEach(tr => {
    tr.addEventListener('dragover', e => { if (!drag) return; e.preventDefault(); e.dataTransfer.dropEffect = 'move'; tr.classList.add('drop-over'); });
    tr.addEventListener('dragleave', e => { if (!tr.contains(e.relatedTarget)) tr.classList.remove('drop-over'); });
    tr.addEventListener('drop', e => {
      e.preventDefault(); tr.classList.remove('drop-over'); if (!drag) return; const d = drag; drag = null;
      const verb = d.inhouse === '1' ? 'Move in-house guest' : 'Assign';
      if (!confirm(`${verb} ${d.label} to room ${tr.dataset.roomNo}?` + (d.inhouse === '1' ? '\nThe current room will be marked vacant & dirty.' : ''))) return;
      fetch(@json(route('frontdesk.tapechart.drop')), { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
        body: JSON.stringify({ stay_id: +d.stay, room_id: +tr.dataset.room }) })
        .then(r => r.json().then(j => ({ ok: r.ok, j }))).then(({ ok, j }) => { if (ok) location.reload(); else alert(j.message || Object.values(j.errors || {})[0] || 'Move failed'); })
        .catch(() => alert('Network error – please try again.'));
    });
  });
})();
</script>
@endpush
@endif
@section('actions')
<a class="btn" href="{{ route('frontdesk.tapechart', ['from' => $from->copy()->subDays(7)->toDateString(), 'days' => $days]) }}">‹ Week</a>
<a class="btn" href="{{ route('frontdesk.tapechart') }}">Today</a>
<a class="btn" href="{{ route('frontdesk.tapechart', ['from' => $from->copy()->addDays(7)->toDateString(), 'days' => $days]) }}">Week ›</a>
<form class="filters" method="get"><div class="field"><input type="date" name="from" value="{{ $from->toDateString() }}" data-autosubmit></div><div class="field"><select name="days" data-autosubmit>@foreach([7,14,21,30] as $d)<option @selected($days==$d)>{{ $d }}</option>@endforeach</select></div></form>
@endsection
@section('content')
@php $W = 62; $bd = bdate(); $dnd = can_do('frontdesk.roommove'); @endphp
@if($dnd && $unassigned->count())
<div class="card mb-1"><div class="card-body flex wrap gap-1 items-center" style="padding:.6rem .8rem">
  <b class="small">Unassigned – drag onto a room:</b>
  @foreach($unassigned as $s)<span class="chip-drag" draggable="true" data-stay="{{ $s->id }}" data-label="{{ $s->reservation->reservation_no }} · {{ $s->reservation->guest->full_name }}" title="{{ $s->roomType?->name }} · {{ $s->arrival_date->format('d M') }} → {{ $s->departure_date->format('d M') }}">{{ $s->reservation->guest->full_name }} <span class="tiny">{{ $s->roomType?->code ?? $s->roomType?->name }} · {{ $s->arrival_date->format('d/m') }}–{{ $s->departure_date->format('d/m') }}</span></span>@endforeach
</div></div>
@endif
<div class="tape">
<table>
  <thead><tr><th class="rlabel" style="z-index:4">Room</th>
    @for($i=0;$i<$days;$i++) @php $d = $from->copy()->addDays($i); @endphp
      <th class="{{ $d->isSameDay($bd) ? 'today' : ($d->isWeekend() ? 'wknd' : '') }}">{{ $d->format('D') }}<br><b>{{ $d->format('d M') }}</b></th>
    @endfor</tr></thead>
  <tbody>
  @foreach($rooms as $type => $list)
    <tr class="grp"><td colspan="{{ $days + 1 }}">{{ $type }} ({{ $list->count() }})</td></tr>
    @foreach($list as $room)
      <tr data-room="{{ $room->id }}" data-room-no="{{ $room->room_no }}">
        <td class="rlabel">{{ $room->room_no }} <span class="hk hk-{{ $room->hk_status }}" style="display:inline-block;width:8px;height:8px;border-radius:50%"></span>@if(in_array($room->status,['out_of_order','out_of_service']))<small class="text-red">OOO</small>@endif</td>
        @for($i=0;$i<$days;$i++) @php $d = $from->copy()->addDays($i); @endphp
          <td class="cell">
            @foreach(($byRoom[$room->id] ?? collect()) as $s)
              @php $start = $s->arrival_date->max($from); @endphp
              @if($start->isSameDay($d))
                @php $len = $start->diffInDays($s->departure_date->min($from->copy()->addDays($days))); @endphp
                <a class="bar b-{{ $s->reservation->status === 'tentative' ? 'tentative' : $s->status }}" @if($dnd && in_array($s->status, ['reserved','checked_in'])) draggable="true" data-stay="{{ $s->id }}" data-label="{{ $s->reservation->reservation_no }} · {{ $s->reservation->guest->full_name }}" data-inhouse="{{ $s->status === 'checked_in' ? 1 : 0 }}" @endif style="width: {{ max(1,$len) * $W - 4 }}px" href="{{ route('reservations.show', $s->reservation_id) }}" title="{{ $s->reservation->reservation_no }} · {{ $s->reservation->guest->full_name }} · {{ $s->arrival_date->format('d M') }}→{{ $s->departure_date->format('d M') }}">{{ $s->reservation->guest->full_name }}</a>
              @endif
            @endforeach
            @if($d->gte($bd) && $room->status !== 'out_of_order' && can_do('reservations.create'))<a class="new" href="{{ route('reservations.create', ['arrival' => $d->toDateString(), 'departure' => $d->copy()->addDay()->toDateString(), 'room_type_id' => $room->room_type_id, 'room_id' => $room->id]) }}" title="New booking {{ $room->room_no }} {{ $d->format('d M') }}"></a>@endif
          </td>
        @endfor
      </tr>
    @endforeach
  @endforeach
  </tbody>
</table>
</div>
<div class="legend mt-1"><span><i style="background:#3b82f6"></i>Reserved</span><span><i style="background:#ef4444"></i>In-house</span><span><i style="background:#f59e0b"></i>Tentative</span><span><i style="background:#94a3b8"></i>Checked out</span><span>Click an empty cell to create a booking.</span>@if($dnd)<span><b>Drag</b> a booking onto another room row to move / assign it.</span>@endif</div>

@if($unassigned->count())
<div class="card flush mt-2"><div class="card-head"><h3>Unassigned reservations ({{ $unassigned->count() }})</h3></div><div class="card-body table-wrap">
<table class="table"><thead><tr><th>Res No</th><th>Guest</th><th>Type</th><th>Stay</th><th></th></tr></thead><tbody>
@foreach($unassigned as $s)<tr><td>{{ $s->reservation->reservation_no }}</td><td>{{ $s->reservation->guest->full_name }}</td><td>{{ $s->roomType?->name }}</td><td>{{ $s->arrival_date->format('d M') }} → {{ $s->departure_date->format('d M') }}</td><td class="right"><a class="btn btn-sm" href="{{ route('reservations.show', $s->reservation_id) }}">Assign</a></td></tr>@endforeach
</tbody></table></div></div>
@endif
@endsection
