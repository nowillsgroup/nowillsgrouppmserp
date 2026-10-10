@extends('layouts.app')
@section('title', 'Floor Plan & Waitlist')
@section('crumbs') Point of Sale / Floor plan @endsection
@section('actions')
@if($outlet)
  <a class="btn" href="{{ route('tb.index', ['outlet' => $outlet->id]) }}"><x-icon name="calendar"/> Today's bookings</a>
  @if(can_do('tables.manage'))<button type="button" class="btn" id="editLayout"><x-icon name="edit"/> Edit layout</button><button type="button" class="btn btn-primary" id="saveLayout" style="display:none"><x-icon name="check"/> Save layout</button>@endif
  @if(can_do('tables.book'))<a class="btn btn-accent" href="#" data-modal="waitModal" id="addWaitBtn"><x-icon name="clock"/> Add to waitlist</a><a class="btn btn-primary" href="#" data-modal="bookModal" id="newBookingBtn"><x-icon name="plus"/> Booking</a>@endif
@endif
@endsection
@section('content')
@if(!$outlet)
  <div class="alert alert-warn"><x-icon name="alert"/><div>No outlet takes table reservations yet. @if(can_do('tables.manage'))<a href="{{ route('tb.settings') }}">Switch them on in Reservation Settings</a>.@endif</div></div>
@else
@if($outlets->count() > 1)<div class="tabs">@foreach($outlets as $o)<a href="{{ route('tb.floor', ['outlet' => $o->id]) }}" class="{{ $o->id === $outlet->id ? 'active' : '' }}">{{ $o->name }}</a>@endforeach</div>@endif
@php
  $col = collect($live);
  $count = fn ($s) => $col->where('state', $s)->count();
  $secLabels = $col->groupBy(fn ($t) => $t['section'] ?: '')->filter(fn ($g, $k) => $k !== '')->map(fn ($g) => ['x' => $g->min('x'), 'y' => max(0, $g->min('y') - 7)]);
  $canBook = can_do('tables.book');
@endphp
<div class="stats">
  <x-stat label="Tables free" :value="$count('free').' / '.$col->count()" icon="grid" tone="green" :sub="$count('clearing').' to clear · '.$count('blocked').' blocked'"/>
  <x-stat label="Seated" :value="$count('seated') + $count('billed')" icon="cup" tone="red" :sub="$stats['served_covers'].' covers today · '.$count('billed').' billed'"/>
  <x-stat label="Arriving (next 3 h)" :value="$upcoming->count()" icon="calendar" tone="blue" :sub="$upcoming->sum('party_size').' covers · '.$upcoming->filter(fn ($u) => $u->start_at->lt(now()))->count().' late'"/>
  <x-stat label="Waitlist" :value="$waitlist->count()" icon="clock" tone="amber" :sub="'quote now: 2 pax '.$quote[2].' min · 4 pax '.$quote[4].' min'"/>
</div>
<div class="grid g-3-1">
  <div class="card"><div class="card-head"><h3>{{ $outlet->name }} <span class="muted small" id="floorClock">{{ now()->format('H:i') }}</span></h3>
    <div class="legend fl-legend"><span><i class="free"></i>Free</span><span><i class="reserved"></i>Reserved &lt; 1 h</span><span><i class="late"></i>Guest late</span><span><i class="seated"></i>Seated</span><span><i class="billed"></i>Billed</span><span><i class="clearing"></i>To clear</span><span><i class="blocked"></i>Blocked</span></div></div>
    <div class="card-body">
      <div class="floor" id="floor">
        @foreach($secLabels as $sec => $pos)<div class="floor-sec" style="left:{{ $pos['x'] }}%;top:calc({{ $pos['y'] }}% + .3rem)">{{ $sec }}</div>@endforeach
        @foreach($live as $t)
          <button type="button" class="ft {{ $t['shape'] }} {{ $t['state'] }} {{ $t['overdue'] ? 'overdue' : '' }}" data-id="{{ $t['id'] }}" id="ft_{{ $t['no'] }}" style="left:{{ $t['x'] ?? 5 }}%;top:{{ $t['y'] ?? 5 }}%">
            <span class="cap">{{ $t['capacity'] }}</span>
            <span class="no">{{ $t['no'] }}</span>
            @switch($t['state'])
              @case('seated') @case('billed')<span class="meta">{{ $t['party'] }} pax · {{ $t['since'] }} min</span><span class="meta"><b>{{ $t['state'] === 'billed' ? 'billed' : ($t['overdue'] ? 'due '.$t['expected'] : 'till '.$t['expected']) }}</b>@if($t['joined']) · +{{ implode(',', $t['joined']) }}@endif</span>@break
              @case('reserved') @case('late')<span class="meta">{{ $t['next']['time'] }} · {{ $t['next']['party'] }} pax</span><span class="meta">{{ \Illuminate\Support\Str::limit($t['next']['name'], 13) }}</span>@break
              @case('clearing')<span class="meta">clear &amp; reset</span>@break
              @case('blocked')<span class="meta">{{ \Illuminate\Support\Str::limit($t['blocked'], 14) }}</span>@break
              @default<span class="meta">{{ $t['next'] ? 'next '.$t['next']['time'] : 'free' }}</span>
            @endswitch
          </button>
        @endforeach
      </div>
      <p class="tiny muted mt-1" id="floorHint">Click a table for details. Ctrl / ⌘ / Shift-click to select several tables and join them for a bigger party.</p>
    </div></div>

  <div>
    <div class="card tb-panel" id="tbPanel"><div class="card-head"><h3 id="panelTitle">Select a table</h3></div><div class="card-body" id="panelBody"><p class="muted small">The table's party, bill, next booking and actions appear here.</p></div></div>

    <div class="card"><div class="card-head"><h3>Waitlist <span class="muted">{{ $waitlist->count() }}</span></h3></div><div class="card-body" id="waitList" style="padding-top:.2rem">
      @forelse($waitlist as $i => $w)
        @php $waited = (int) $w->created_at->diffInMinutes(now()); @endphp
        <div class="wl-item" data-ref="{{ $w->booking_no }}"><div class="wl-pos">{{ $i + 1 }}</div><div class="grow">
          <div><a href="{{ route('tb.show', $w) }}" class="bold">{{ $w->name }}</a> · {{ $w->party_size }} pax @if($w->status === 'notified')<span class="badge badge-purple">called {{ $w->notified_at->format('H:i') }}</span>@endif</div>
          <div class="tiny muted">waiting <span class="{{ $w->quoted_minutes !== null && $waited > $w->quoted_minutes ? 'wl-over' : '' }}">{{ $waited }} min</span> · quoted {{ $w->quoted_minutes ?? '—' }} min @if($w->phone)· {{ $w->phone }}@endif @if($w->section_pref)· {{ $w->section_pref }}@endif</div>
          @if($w->notes)<div class="tiny">{{ $w->notes }}</div>@endif
          @if($canBook)<div class="flex mt-1" style="gap:.3rem">
            <form method="post" action="{{ route('tb.seat', $w) }}" class="seat-with-sel">@csrf<button class="btn btn-sm btn-success">Seat</button></form>
            @if($w->status === 'waiting')<form method="post" action="{{ route('tb.notify', $w) }}">@csrf<button class="btn btn-sm" title="Text the guest that the table is ready">Ready</button></form>@endif
            <form method="post" action="{{ route('tb.cancel', $w) }}">@csrf<input type="hidden" name="reason" value="Left without being seated"><button class="btn btn-sm btn-ghost">Left</button></form>
          </div>@endif
        </div></div>
      @empty<p class="muted small">Nobody waiting.</p>@endforelse
    </div></div>

    <div class="card"><div class="card-head"><h3>Arriving next</h3></div><div class="card-body" id="upcomingList" style="padding-top:.2rem">
      @forelse($upcoming as $u)
        <div class="wl-item" data-ref="{{ $u->booking_no }}"><div class="grow">
          <div><b class="{{ $u->start_at->lt(now()) ? 'text-red' : '' }}">{{ $u->start_at->format('H:i') }}</b> <a href="{{ route('tb.show', $u) }}" class="bold">{{ $u->name }}</a> · {{ $u->party_size }} pax {!! $u->status === 'booked' ? tb_badge('booked') : '' !!}</div>
          <div class="tiny muted">{{ $u->tableLabel() }}@if($u->start_at->lt(now())) · <span class="text-red">late {{ (int) $u->start_at->diffInMinutes(now()) }} min</span>@endif @if($u->occasion)· {{ \App\Models\TableReservation::OCCASIONS[$u->occasion] ?? '' }}@endif</div>
          @if($canBook)<div class="flex mt-1" style="gap:.3rem"><form method="post" action="{{ route('tb.seat', $u) }}" class="seat-with-sel">@csrf<button class="btn btn-sm btn-success">Seat</button></form>
            @if($u->start_at->lte(now()))<form method="post" action="{{ route('tb.noshow', $u) }}">@csrf<button class="btn btn-sm btn-ghost">No-show</button></form>@endif</div>@endif
        </div></div>
      @empty<p class="muted small">No bookings in the next three hours.</p>@endforelse
    </div></div>
  </div>
</div>

<script type="application/json" id="floorData">@json(collect($live)->keyBy('id'))</script>
<template id="tplActions">
  @if($canBook)
  <form method="post" action="{{ route('tb.walkin') }}" id="walkInForm" class="mt-1">@csrf<input type="hidden" name="outlet_id" value="{{ $outlet->id }}"><div class="sel-ids"></div>
    <div class="form-grid" style="grid-template-columns:1fr 2fr"><div class="field"><label>Guests</label><input type="number" name="party_size" min="1" max="50" value="2" required id="wiParty"></div><div class="field"><label>Name (optional)</label><input name="name" placeholder="Walk-in" id="wiName"></div></div>
    @if(can_do('pos.order'))<label class="check small"><input type="checkbox" name="go" value="pos"> open the POS order</label>@endif
    <button class="btn btn-success btn-block mt-1" id="seatWalkIn"><x-icon name="cup"/> Seat walk-in here</button></form>
  @endif
</template>
<form method="post" id="clearForm" data-url="{{ route('tb.clear', ['ptable' => '__ID__']) }}">@csrf</form>
@if(can_do('tables.manage'))<form method="post" id="blockForm" data-url="{{ route('tb.block', ['ptable' => '__ID__']) }}">@csrf<input type="hidden" name="reason"></form>@endif

@if($canBook)
<div class="modal" id="waitModal"><div class="modal-box"><form method="post" action="{{ route('tb.waitlist.store') }}">@csrf<input type="hidden" name="outlet_id" value="{{ $outlet->id }}">
  <div class="modal-head"><h3>Add to the waitlist</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    <x-field name="name" label="Name" required/>
    <x-field name="party_size" label="Guests" type="number" value="2" required/>
    <x-field name="phone" label="Mobile" hint="to text them when the table is ready"/>
    <x-field name="quoted_minutes" label="Quoted wait (min)" type="number" :value="$quote[2]" hint="estimate from the floor – adjust if needed"/>
    <x-field name="section_pref" label="Seating preference" type="select" :options="$sections->mapWithKeys(fn ($s) => [$s => $s])->all()" placeholder="No preference"/>
    <x-field name="notes" label="Notes" placeholder="High chair, bar seats OK…"/>
    <label class="check span-all"><input type="checkbox" name="send_sms" value="1" checked> Text the guest their place and estimated wait</label>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary" id="saveWait">Add</button></div>
</form></div></div>
@include('tables._form', ['outlet' => $outlet, 'tables' => $tables, 'inHouse' => $inHouse, 'back' => 'floor'])
@endif
@endif
@endsection
@if($outlet)
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
  const data = JSON.parse(document.getElementById('floorData').textContent);
  const floor = document.getElementById('floor'), title = document.getElementById('panelTitle'), body = document.getElementById('panelBody');
  const csrf = document.querySelector('meta[name=csrf-token]').content;
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
  const urls = {order: @json(can_do('pos.view') ? route('pos.terminal', ['order' => '__ID__']) : null), booking: @json(route('tb.show', ['tres' => 999999999]))};
  const canManage = @json(can_do('tables.manage'));
  const label = {free: 'Free', reserved: 'Reserved soon', late: 'Guest late', seated: 'Seated', billed: 'Bill printed', clearing: 'Needs clearing', blocked: 'Blocked'};
  let sel = [], editing = false, moved = false;
  const wlQuote = @json($quote);

  function syncSel() {
    floor.querySelectorAll('.ft').forEach(b => { b.classList.toggle('sel', sel[0] == b.dataset.id); b.classList.toggle('pick', sel.includes(b.dataset.id) && sel[0] != b.dataset.id); });
    document.querySelectorAll('.seat-with-sel').forEach(f => { f.querySelectorAll('input.tid').forEach(i => i.remove()); sel.forEach(id => { const i = document.createElement('input'); i.type = 'hidden'; i.name = 'table_ids[]'; i.value = id; i.className = 'tid'; f.appendChild(i); }); });
    render();
  }
  function render() {
    if (!sel.length) { title.textContent = 'Select a table'; body.innerHTML = '<p class="muted small">The table\'s party, bill, next booking and actions appear here.</p>'; return; }
    const t = data[sel[0]], all = sel.map(id => data[id]);
    const cap = all.reduce((a, x) => a + x.capacity, 0);
    title.innerHTML = 'Table ' + all.map(x => esc(x.no)).join(' + ') + ' <span class="muted small">' + cap + ' seats</span>';
    let h = '<div class="kv"><span>Status</span><b>' + label[t.state] + '</b></div><div class="kv"><span>Section</span><b>' + esc(t.section || '—') + '</b></div>';
    if (t.state === 'seated' || t.state === 'billed') {
      h += '<div class="kv"><span>Party</span><b>' + esc(t.name || '—') + ' · ' + (t.party || '?') + ' pax</b></div><div class="kv"><span>Seated</span><b>' + t.since + ' min ago</b></div>';
      h += '<div class="kv"><span>Expected to leave</span><b class="' + (t.overdue ? 'text-red' : '') + '">' + (t.expected || '—') + '</b></div>';
      if (t.joined.length) h += '<div class="kv"><span>Joined with</span><b>' + t.joined.map(esc).join(', ') + '</b></div>';
      if (t.order_id) h += '<div class="kv"><span>Bill so far</span><b>' + Number(t.total).toLocaleString(undefined, {minimumFractionDigits: 2}) + '</b></div>' + (urls.order ? '<a class="btn btn-primary btn-block mt-1" id="openOrder" href="' + urls.order.replace('__ID__', t.order_id) + '">Open order ' + esc(t.order_no) + '</a>' : '');
      if (t.res_id) h += '<a class="btn btn-block mt-1" href="' + urls.booking.replace('999999999', t.res_id) + '">Booking details</a>';
    }
    if (t.state === 'blocked') h += '<div class="kv"><span>Reason</span><b>' + esc(t.blocked) + '</b></div>';
    if (t.next) h += '<div class="kv"><span>Next booking</span><b><a href="' + urls.booking.replace('999999999', t.next.id) + '">' + t.next.time + ' ' + esc(t.next.name) + ' · ' + t.next.party + ' pax</a>' + (t.next.late ? ' <span class="text-red">late</span>' : '') + '</b></div>';
    if (sel.length > 1) h += '<p class="tiny muted">' + sel.length + ' tables selected – seating a party here joins them.</p>';
    body.innerHTML = h;
    const freeish = all.every(x => ['free', 'reserved', 'late', 'clearing'].includes(x.state));
    if (t.state === 'clearing') { const b = document.createElement('button'); b.className = 'btn btn-block mt-1'; b.id = 'clearBtn'; b.innerHTML = 'Table cleared &amp; reset'; b.onclick = () => { const f = document.getElementById('clearForm'); f.action = f.dataset.url.replace('__ID__', t.id); f.submit(); }; body.appendChild(b); }
    if (freeish) {
      const tpl = document.getElementById('tplActions'); if (tpl && tpl.content.children.length) {
        body.appendChild(tpl.content.cloneNode(true));
        const ids = body.querySelector('.sel-ids'); sel.forEach(id => ids.insertAdjacentHTML('beforeend', '<input type="hidden" name="table_ids[]" value="' + id + '">'));
        const p = body.querySelector('#wiParty'); if (p) p.value = Math.min(cap, 2);
        if (t.next && (t.state === 'reserved' || t.state === 'late')) body.insertAdjacentHTML('beforeend', '<p class="tiny text-amber mt-1">Reserved at ' + t.next.time + ' – a walk-in here must finish before then.</p>');
        if (document.querySelector('.seat-with-sel')) body.insertAdjacentHTML('beforeend', '<p class="tiny muted mt-1">To seat a booking or waitlist party at the selected table(s), press <b>Seat</b> next to their name.</p>');
      }
    }
    if (canManage && sel.length === 1 && !['seated', 'billed'].includes(t.state)) {
      const b = document.createElement('button'); b.className = 'btn btn-ghost btn-block mt-1'; b.id = 'blockBtn'; b.textContent = t.state === 'blocked' ? 'Back in service' : 'Block table…';
      b.onclick = () => { const f = document.getElementById('blockForm'); let r = ''; if (t.state !== 'blocked') { r = prompt('Why is table ' + t.no + ' out of service?', 'Reserved for private use'); if (!r) return; } f.reason.value = r; f.action = f.dataset.url.replace('__ID__', t.id); f.submit(); };
      body.appendChild(b);
    }
  }
  floor.addEventListener('click', e => {
    const b = e.target.closest('.ft'); if (!b || editing) return;
    const id = b.dataset.id;
    if (e.ctrlKey || e.metaKey || e.shiftKey) { sel = sel.includes(id) ? sel.filter(x => x !== id) : [...sel, id]; }
    else { sel = (sel.length === 1 && sel[0] === id) ? [] : [id]; }
    syncSel();
  });

  // layout editor – drag tables, save positions as % of the canvas
  const edit = document.getElementById('editLayout'), save = document.getElementById('saveLayout');
  if (edit) {
    edit.addEventListener('click', () => { editing = !editing; floor.classList.toggle('editing', editing); save.style.display = editing ? '' : 'none'; edit.innerHTML = editing ? 'Cancel editing' : 'Edit layout'; if (!editing && moved) location.reload(); sel = []; syncSel(); });
    let drag = null;
    floor.addEventListener('pointerdown', e => { const b = e.target.closest('.ft'); if (!editing || !b) return; e.preventDefault(); const r = floor.getBoundingClientRect(), br = b.getBoundingClientRect(); drag = {b, dx: e.clientX - br.left, dy: e.clientY - br.top, r}; b.setPointerCapture(e.pointerId); });
    floor.addEventListener('pointermove', e => { if (!drag) return; const {b, dx, dy, r} = drag; const x = Math.max(0, Math.min(96, (e.clientX - r.left - dx) / r.width * 100)), y = Math.max(0, Math.min(94, (e.clientY - r.top - dy) / r.height * 100)); b.style.left = x.toFixed(1) + '%'; b.style.top = y.toFixed(1) + '%'; moved = true; });
    floor.addEventListener('pointerup', () => { drag = null; });
    floor.addEventListener('dblclick', e => { const b = e.target.closest('.ft'); if (!editing || !b) return; const order = ['square', 'round', 'rect', 'booth']; const cur = order.find(s => b.classList.contains(s)) || 'square'; b.classList.remove(cur); b.classList.add(order[(order.indexOf(cur) + 1) % 4]); moved = true; });
    save.addEventListener('click', async () => {
      const tables = [...floor.querySelectorAll('.ft')].map(b => ({id: +b.dataset.id, x: Math.round(parseFloat(b.style.left)), y: Math.round(parseFloat(b.style.top)), shape: ['square', 'round', 'rect', 'booth'].find(s => b.classList.contains(s))}));
      const r = await fetch(@json(route('tb.layout')), {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json'}, body: JSON.stringify({outlet_id: @json($outlet->id), tables})});
      if (r.ok) { moved = false; location.reload(); } else alert('Could not save the layout.');
    });
    floor.insertAdjacentHTML('afterend', '');
  }
  // waitlist quote follows the party size
  const wp = document.querySelector('#waitModal [name=party_size]'), wq = document.querySelector('#waitModal [name=quoted_minutes]');
  if (wp && wq) wp.addEventListener('input', () => { const n = +wp.value; wq.value = n <= 2 ? wlQuote[2] : (n <= 4 ? wlQuote[4] : wlQuote[6]); });
  // keep the floor live (not while editing, choosing tables or in a dialog)
  setInterval(() => { if (!editing && !sel.length && !document.querySelector('.modal.show') && document.visibilityState === 'visible') location.reload(); }, 30000);
});
</script>
@endpush
@endif
