@php $stay = $room->currentStay; @endphp
<div class="room-card">
  <div class="flex gap-1">
    @if($photo)
      <img src="{{ asset($photo->path) }}" alt="{{ $photo->caption ?: 'Room '.$room->room_no }}" style="width:96px;height:72px;object-fit:cover;border-radius:8px">
    @else
      <div class="no-photo" title="No photo on file">No photo on file</div>
    @endif
    <div>
      <h2 style="margin:0">Room {{ $room->room_no }}</h2>
      <div class="small">{{ $room->roomType->name }} · {{ $room->floor->name ?? 'No floor' }}</div>
      <div>{!! status_badge($room->status) !!} {!! status_badge($room->hk_status) !!}</div>
      @if($room->status === 'vacant' && $room->hk_status === 'dirty')<div class="small">Vacant dirty</div>@endif
    </div>
  </div>
  @if($stay)
    <p class="small" style="margin-top:.7rem">In house: <a href="{{ route('reservations.stay', $stay) }}">{{ $stay->reservation->guest->full_name }}</a> until {{ $stay->departure_date->format('d M') }}@php $stayBal = $stay->folio ? $stay->folio->balance() : null; @endphp · {{ $stayBal === null ? 'No folio' : money($stayBal) }}</p>
  @else
    <p class="small muted" style="margin-top:.7rem">No in-house guest.</p>
  @endif
  <p class="small"><b>Amenities on file:</b> {{ $amenities->isEmpty() ? 'None recorded for this room or its type.' : $amenities->join(', ') }}</p>
  @if($outage)
    <p class="small">Blocked {{ $outage->starts_on->format('d M') }}–{{ $outage->ends_on->format('d M') }} ({{ \App\Models\RoomOutage::REASONS[$outage->reason] ?? $outage->reason }}). {{ $outage->notes }}</p>
    @if(can_do('reservations.edit'))
    <form method="post" action="{{ route('reservations.outages.release', $outage) }}">@csrf<button class="btn btn-sm">Release block</button></form>
    @endif
  @endif

  @if(can_do('reservations.edit'))
  <form method="post" action="{{ route('reservations.outages.store') }}" class="desk-form" data-preview="{{ route('reservations.outages.preview') }}">
    @csrf
    <input type="hidden" name="room_id" value="{{ $room->id }}">
    <h3>Block room</h3>
    <div class="flex gap-1 wrap">
      <input type="date" name="starts_on" required value="{{ bdate()->toDateString() }}" aria-label="Block from">
      <input type="date" name="ends_on" required value="{{ bdate()->copy()->addDay()->toDateString() }}" aria-label="Block until">
      <select name="reason" aria-label="Block reason">
        @foreach(\App\Models\RoomOutage::REASONS as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
      </select>
      <input name="notes" placeholder="Note" aria-label="Block note" style="width:8rem">
      <button class="btn btn-sm" type="button" data-quote>Preview block</button>
      <button class="btn btn-sm">Confirm block</button>
    </div>
    <p class="quote tiny muted">Preview checks overlapping reservations and says whether the room would be marked out of order. Nothing is saved until you confirm.</p>
  </form>
  <form method="post" action="{{ route('reservations.images.store') }}" enctype="multipart/form-data" class="desk-form">
    @csrf
    <input type="hidden" name="room_id" value="{{ $room->id }}">
    <h3>Room photo</h3>
    <div class="flex gap-1 wrap">
      <input type="file" name="photo" accept="image/*" required aria-label="Room photo">
      <input name="caption" placeholder="Caption" aria-label="Caption" style="width:8rem">
      <button class="btn btn-sm">Upload</button>
    </div>
  </form>
  @endif

  @php
    $tasks = \App\Models\HousekeepingTask::with('employee')->where('room_id', $room->id)->latest('task_date')->limit(5)->get();
    $tickets = \App\Models\MaintenanceRequest::where('room_id', $room->id)->latest('id')->limit(5)->get();
    $history = \App\Models\ReservationRoom::with('reservation.guest')->where('room_id', $room->id)->where('status', 'checked_out')->orderByDesc('departure_date')->limit(5)->get();
  @endphp
  <h3>Housekeeping history</h3>
  @forelse($tasks as $task)
    <p class="small">{{ $task->task_date?->format('d M') ?? $task->task_date }} · {{ \App\Models\HousekeepingTask::TYPES[$task->task_type] ?? $task->task_type }} · {{ $task->status }}{{ $task->employee ? ' · '.$task->employee->name : '' }}{{ $task->completed_at ? ' · done '.$task->completed_at->format('d M H:i') : '' }}</p>
  @empty
    <p class="small muted">No housekeeping task is on file for this room.</p>
  @endforelse
  <h3>Maintenance</h3>
  @forelse($tickets as $ticket)
    <p class="small">@if(can_do('maintenance.manage'))<a href="{{ route('maintenance.index') }}">{{ $ticket->ticket_no }}</a>@else{{ $ticket->ticket_no }}@endif · {{ $ticket->title }} · {{ $ticket->priority }} · {{ $ticket->status }}</p>
  @empty
    <p class="small muted">No maintenance ticket is on file for this room.</p>
  @endforelse
  <h3>Previous stays</h3>
  @forelse($history as $old)
    <p class="small">{{ $old->reservation->guest->full_name ?? 'Guest' }} · {{ $old->arrival_date->format('d M') }}–{{ $old->departure_date->format('d M') }} · {{ $old->reservation->reservation_no }}</p>
  @empty
    <p class="small muted">No previous checkout is on file for this room.</p>
  @endforelse

  @if(can_do('housekeeping.manage'))
  <form method="post" action="{{ route('housekeeping.tasks.store') }}" class="desk-form">
    @csrf
    <input type="hidden" name="room_id" value="{{ $room->id }}">
    <input type="hidden" name="task_date" value="{{ bdate()->toDateString() }}">
    <h3>Request cleaning</h3>
    <div class="flex gap-1 wrap">
      <select name="task_type" required aria-label="Task type">
        @foreach(\App\Models\HousekeepingTask::TYPES as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
      </select>
      <select name="priority" required aria-label="Priority"><option value="normal">Normal</option><option value="high">High</option><option value="low">Low</option></select>
      <input name="notes" placeholder="Note" aria-label="Task note" style="width:8rem">
      <button class="btn btn-sm">Create task</button>
    </div>
  </form>
  @endif
  @if(can_do('housekeeping.manage'))
  <form method="post" action="{{ route('housekeeping.status') }}" class="desk-form">
    @csrf
    <input type="hidden" name="rooms[]" value="{{ $room->id }}">
    <h3>Housekeeping</h3>
    <div class="flex gap-1 wrap">
      <select name="hk_status" aria-label="Housekeeping status">
        @foreach(['clean' => 'Clean', 'dirty' => 'Dirty', 'inspected' => 'Inspected', 'touch_up' => 'Touch up'] as $k => $label)
          <option value="{{ $k }}" @selected($room->hk_status === $k)>{{ $label }}</option>
        @endforeach
      </select>
      <input name="notes" placeholder="Note" aria-label="Housekeeping note" style="width:8rem">
      <button class="btn btn-sm">Update room</button>
      <a class="btn btn-sm" href="{{ route('housekeeping.index') }}">Housekeeping board</a>
    </div>
  </form>
  @elseif(can_do('housekeeping.view'))
    <p class="small"><a href="{{ route('housekeeping.index') }}">Housekeeping board</a> — updating a room needs the housekeeping manage permission.</p>
  @endif

  @if(can_do('maintenance.manage'))
  <form method="post" action="{{ route('maintenance.store') }}" class="desk-form">
    @csrf
    <input type="hidden" name="room_id" value="{{ $room->id }}">
    <h3>Maintenance ticket</h3>
    <div class="flex gap-1 wrap">
      <input name="title" required placeholder="What is wrong" aria-label="Ticket title" style="width:10rem">
      <select name="category" required aria-label="Category">
        @foreach(['electrical','plumbing','ac','carpentry','it','civil','general'] as $c)<option value="{{ $c }}">{{ ucfirst($c) }}</option>@endforeach
      </select>
      <select name="priority" required aria-label="Priority">
        @foreach(['low','normal','high','urgent'] as $p)<option value="{{ $p }}" @selected($p==='normal')>{{ ucfirst($p) }}</option>@endforeach
      </select>
      <label class="tiny"><input type="checkbox" name="block_room" value="1"> Block room now</label>
      <button class="btn btn-sm">Open ticket</button>
    </div>
  </form>
  @endif
</div>
@push('scripts')
<script>
(function () {
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
  const keyOf = form => {
    const fd = new FormData(form);
    return ['starts_on', 'ends_on', 'reason', 'room_id'].map(k => fd.get(k) || '').join('|');
  };
  document.querySelectorAll('[data-quote]').forEach(btn => btn.addEventListener('click', () => {
    const form = btn.closest('form');
    fetch(form.dataset.preview, { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf }, body: new FormData(form) })
      .then(r => r.json().then(j => ({ ok: r.ok, j })))
      .then(({ ok, j }) => {
        const p = form.querySelector('.quote');
        if (p) p.textContent = (j.message || 'No preview.') + (ok ? ' Press confirm to save.' : '');
        if (ok) form.dataset.seen = keyOf(form);
      });
  }));
  document.querySelectorAll('form[data-preview]').forEach(form => form.addEventListener('submit', e => {
    if (form.dataset.seen === keyOf(form)) return;
    e.preventDefault();
    form.querySelector('[data-quote]')?.click();
  }));
})();
</script>
@endpush
