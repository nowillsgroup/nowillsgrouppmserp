@extends('layouts.app')
@section('title', 'Guest Requests')
@section('actions')<a class="btn" href="{{ route('offline.guest_requests') }}"><x-icon name="wifi"/> Offline capture</a> @if(can_do('requests.manage'))<button class="btn btn-primary" data-modal="newReq"><x-icon name="plus"/> Log request</button>@endif @endsection
@section('content')
@php $sla = max(1, (int) setting('portal_sla_minutes', 15)); $cats = \App\Models\GuestRequest::CATEGORIES; $manage = can_do('requests.manage'); @endphp
<div class="stats">
  <x-stat label="New – awaiting response" :value="$stats['new']" icon="bell" tone="amber" href="?status=new"/>
  <x-stat label="Overdue (> {{ $sla }} min)" :value="$stats['overdue']" icon="alert" :tone="$stats['overdue'] ? 'red' : 'green'" href="?status=new"/>
  <x-stat label="In progress" :value="$stats['working']" icon="clock" tone="blue" href="?status=open"/>
  <x-stat label="Completed today" :value="$stats['done']" icon="check" tone="green" href="?status=today" :sub="($stats['avg_response'] !== null ? 'avg response '.round($stats['avg_response']).' min' : 'no responses yet').($stats['rating'] ? ' · ★ '.number_format($stats['rating'], 1) : '')"/>
</div>

<div class="tabs">@foreach(['open' => 'Open', 'new' => 'New', 'in_progress' => 'In progress', 'today' => 'Today', 'done' => 'Done', 'declined' => 'Declined', 'all' => 'All'] as $k => $v)<a href="?{{ http_build_query(array_merge(request()->except('page', 'status'), ['status' => $k])) }}" class="{{ $status === $k ? 'active' : '' }}">{{ $v }}</a>@endforeach</div>
<form class="filters mb-1 flex gap-1 wrap items-center" method="get"><input type="hidden" name="status" value="{{ $status }}">
  <select name="category" class="input input-sm" style="width:auto;min-width:180px" onchange="this.form.submit()"><option value="">All types</option>@foreach($cats as $k => $c)<option value="{{ $k }}" @selected(request('category') === $k)>{{ $c[0] }}</option>@endforeach</select>
  <input name="room" value="{{ request('room') }}" placeholder="Room no." class="input input-sm" style="width:110px">
  <label class="check small"><input type="checkbox" name="mine" value="1" @checked(request('mine')) onchange="this.form.submit()"> <span>Assigned to me</span></label>
  <button class="btn btn-sm">Filter</button>
  <span class="small muted" id="liveNote" style="margin-left:auto">Auto-refreshes every 30 s</span>
</form>

<div class="card flush"><div class="card-body table-wrap"><table class="table">
<thead><tr><th>Request</th><th>Room / guest</th><th>What</th><th>Waiting</th><th>Status</th><th>Assigned</th><th class="right">Actions</th></tr></thead><tbody>
@forelse($requests as $r)
@php $g = $r->stay?->guest ?? $r->stay?->reservation?->guest; $over = $r->isOverdue(); @endphp
<tr class="{{ $over ? 'req-overdue' : '' }}">
  <td class="bold">{{ $r->request_no }}<div class="tiny muted">{{ $r->created_at->format('d M H:i') }} · {{ $r->source === 'portal' ? 'Guest portal' : ($r->source === 'tv' ? 'Room TV' : ($r->source === 'chat' ? 'Guest message' : 'Staff'.($r->handler && $r->source === 'staff' ? ' ('.$r->handler->name.')' : ''))) }}</div></td>
  <td><b>{{ $r->room?->room_no ?? '—' }}</b><div class="small">{{ $g?->full_name }}</div></td>
  <td><span class="badge badge-slate">{{ $r->categoryLabel() }}</span> @if($r->priority === 'high')<span class="badge badge-red">High</span>@endif
    <div class="bold small mt-1">{{ $r->quantity > 1 ? $r->quantity.' × ' : '' }}{{ $r->item }}</div>
    @if($r->preferred_at)<div class="tiny"><x-icon name="clock" size="sm"/> {{ $r->preferred_at->format('D d M H:i') }}</div>@endif
    @if($r->notes)<div class="tiny muted">“{{ \Illuminate\Support\Str::limit($r->notes, 140) }}”</div>@endif
    @if($r->linked_type)<div class="tiny">@if($r->linked_type === 'MaintenanceRequest')<a href="{{ route('maintenance.index') }}">🔧 {{ optional($r->linked())->ticket_no }}</a>@else<a href="{{ route('housekeeping.tasks') }}">🧹 HK task #{{ $r->linked_id }}</a>@endif</div>@endif
    @if($r->staff_note)<div class="tiny text-blue">↳ {{ $r->staff_note }}</div>@endif
    @if($r->rating)<div class="tiny" style="color:#f59e0b">{{ str_repeat('★', $r->rating) }}</div>@endif
  </td>
  <td class="small {{ $over ? 'text-red bold' : '' }}">@if($r->isOpen()){{ $r->waitingMinutes() }} min{{ $r->acknowledged_at ? '' : ' unanswered' }}@else{{ $r->completed_at ? $r->created_at->diffInMinutes($r->completed_at).' min total' : '—' }}@endif</td>
  <td>{!! status_badge($r->status) !!}</td>
  <td>@if($manage && $r->isOpen())<form method="post" action="{{ route('requests.assign', $r) }}">@csrf<select name="assigned_to" class="input input-sm" onchange="this.form.submit()" style="width:150px"><option value="">— nobody —</option>@foreach($staff as $id => $n)<option value="{{ $id }}" @selected($r->assigned_to === $id)>{{ $n }}</option>@endforeach</select></form>@else<span class="small">{{ $r->assignee?->name ?? '—' }}</span>@endif</td>
  <td class="right nowrap">@if($r->category === 'laundry' && $r->isOpen() && can_do('laundry.orders'))@if($lo = \App\Models\LaundryOrder::where('guest_request_id', $r->id)->first())<a class="btn btn-sm" href="{{ route('laundry.show', $lo) }}"><x-icon name="shirt" size="sm"/> {{ $lo->order_no }}</a>@else<a class="btn btn-sm btn-primary" href="{{ route('laundry.create', ['request' => $r->id]) }}"><x-icon name="shirt" size="sm"/> Collect</a>@endif @endif
  @if($manage && $r->isOpen())
    @if($r->status === 'new')<form method="post" action="{{ route('requests.action', [$r, 'acknowledge']) }}" class="inline">@csrf<button class="btn btn-sm">Acknowledge</button></form>@endif
    @if($r->status !== 'in_progress')<form method="post" action="{{ route('requests.action', [$r, 'start']) }}" class="inline">@csrf<button class="btn btn-sm">Start</button></form>@endif
    <button class="btn btn-sm btn-success" data-modal="doneModal" data-action="{{ route('requests.action', [$r, 'done']) }}" data-ftitle="{{ $r->request_no }}"><x-icon name="check" size="sm"/> Done</button>
    <button class="btn btn-sm btn-ghost" style="color:#b91c1c" data-modal="declineModal" data-action="{{ route('requests.action', [$r, 'decline']) }}">Decline</button>
    @endif
    @if($manage && $r->stay)<a class="btn btn-sm btn-ghost" href="{{ route('requests.portal', $r->stay) }}" title="Guest portal"><x-icon name="qr" size="sm"/></a>@endif
  </td>
</tr>
@empty<tr><td colspan="7"><x-empty text="No guest requests here." icon="bell"/></td></tr>@endforelse
</tbody></table>{{ $requests->links() }}</div></div>

@if($manage)
<div class="modal" id="newReq"><div class="modal-box"><form method="post" action="{{ route('requests.store') }}">@csrf
  <div class="modal-head"><h3>Log a guest request (phone / desk)</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    <div class="field span-all"><label>In-house guest *</label><select name="reservation_room_id" required class="input"><option value="">— room / guest —</option>@foreach($stays as $s)<option value="{{ $s->id }}" @selected(old('reservation_room_id') == $s->id)>Room {{ $s->room?->room_no }} · {{ ($s->guest ?? $s->reservation?->guest)?->full_name }}</option>@endforeach</select></div>
    <div class="field"><label>Type *</label><select name="category" id="nrCat" required class="input">@foreach($cats as $k => $c)<option value="{{ $k }}" @selected(old('category') === $k)>{{ $c[0] }}</option>@endforeach</select></div>
    <div class="field"><label>Item</label><select name="item" id="nrItem" class="input"></select></div>
    <div class="field"><label>Quantity</label><input type="number" name="quantity" min="1" max="10" value="1" class="input"></div>
    <div class="field"><label>For (date &amp; time)</label><input type="datetime-local" name="preferred_at" class="input"><span class="hint">Required for wake-up calls &amp; transport</span></div>
    <div class="field"><label>Late check-out time</label><input type="time" name="time" value="14:00" class="input"></div>
    <div class="field span-all"><label>Notes</label><textarea name="notes" rows="2" maxlength="500" class="input">{{ old('notes') }}</textarea></div>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Log request</button></div>
</form></div></div>
<div class="modal" id="doneModal"><div class="modal-box"><form method="post" action="">@csrf
  <div class="modal-head"><h3>Mark request done</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="field"><label>Note for the guest (optional)</label><input name="note" maxlength="255" class="input" placeholder="e.g. Towels delivered by Ama"></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-success">Done</button></div>
</form></div></div>
<div class="modal" id="declineModal"><div class="modal-box"><form method="post" action="">@csrf
  <div class="modal-head"><h3>Decline request</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="field"><label>Reason (shown to the guest) *</label><input name="note" maxlength="255" required class="input" placeholder="e.g. Late check-out not possible – room is booked"></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-danger">Decline</button></div>
</form></div></div>
@endif
@endsection
@push('scripts')
<script>
(() => {
  const items = @json(collect($cats)->map(fn ($c, $k) => \App\Services\GuestPortalService::items($k)));
  const cat = document.getElementById('nrCat'), item = document.getElementById('nrItem');
  const fill = () => { if (!cat) return; item.innerHTML = (items[cat.value] || []).map(i => `<option>${i.replace(/</g, '&lt;')}</option>`).join(''); };
  cat?.addEventListener('change', fill); fill();
  let latest = {{ (int) \App\Models\GuestRequest::max('id') }};
  setInterval(() => {
    if (document.querySelector('.modal.open, .modal.show')) return;
    fetch(@json(route('requests.count')), { headers: { Accept: 'application/json' } }).then(r => r.json()).then(d => {
      if (d.latest > latest) { document.getElementById('liveNote').innerHTML = '<b class="text-red">New request received – refreshing…</b>'; setTimeout(() => location.reload(), 800); }
    }).catch(() => {});
  }, 30000);
})();
</script>
@endpush
