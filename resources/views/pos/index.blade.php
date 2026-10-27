@extends('layouts.app')
@section('title', 'POS Terminal')
@section('crumbs') Point of Sale / {{ $outlet?->name }} @endsection
@section('actions')
@if($outlet && can_do('pos.order'))
  <button class="btn" data-modal="takeModal"><x-icon name="cup"/> Takeaway</button>
  <button class="btn btn-accent" data-modal="rsModal"><x-icon name="bed"/> Room Service</button>
@endif
<a class="btn" href="{{ route('pos.orders') }}"><x-icon name="receipt"/> Orders & Bills</a>
@if($outlet)<a class="btn" href="{{ route('pos.qr', ['outlet' => $outlet->id]) }}"><x-icon name="grid"/> Table QR</a>@endif
@if($outlet?->tb_enabled && can_do('tables.view'))<a class="btn" href="{{ route('tb.floor', ['outlet' => $outlet->id]) }}" id="posFloorLink"><x-icon name="calendar"/> Floor plan & bookings</a>@endif
@endsection
@push('head')
<style>
.table-session-modal{max-width:590px;overflow:hidden;border-top:3px solid #1f9d68}.session-head{padding:1.25rem 1.35rem 1rem;align-items:flex-start}.session-head h3{font-family:Georgia,serif;font-size:1.45rem;margin:.2rem 0 .1rem;color:#172033}.session-head p{margin:0;color:#8b98ad;font-size:.9rem}.session-kicker{font-size:.7rem;letter-spacing:.15em;font-weight:800;color:#15945f}.session-body{padding:1.3rem 1.35rem}.guest-lookup-card{background:#f1faf6;border:1px solid #bce7d3;border-radius:14px;padding:1rem 1.05rem}.lookup-title{display:flex;align-items:center;gap:.45rem;color:#117e51;font-weight:750;margin-bottom:.65rem}.lookup-row{display:flex;gap:0}.lookup-row .input{border-radius:10px 0 0 10px;background:#fff}.lookup-btn{border:1px solid #c7e4d6;border-left:0;border-radius:0 10px 10px 0;background:#e2f3eb;color:#15945f;min-width:50px;display:grid;place-items:center;cursor:pointer}.guest-lookup-card p{font-size:.72rem;color:#8997aa;margin:.55rem 0 0}.guest-results{display:none;position:relative;background:#fff;border:1px solid #c7e4d6;border-radius:9px;margin-top:.35rem;max-height:190px;overflow:auto;z-index:3}.guest-results.show{display:block}.guest-results button{display:block;width:100%;border:0;border-bottom:1px solid #edf1f4;background:#fff;text-align:left;padding:.6rem .7rem;cursor:pointer}.guest-results button:last-child{border-bottom:0}.guest-results button:hover{background:#f1faf6}.session-divider{display:flex;align-items:center;gap:.75rem;color:#94a0b2;font-size:.72rem;letter-spacing:.1em;font-weight:700;margin:1.2rem 0 .9rem}.session-divider:before,.session-divider:after{content:"";height:1px;background:#e4e8ee;flex:1}.session-body label{text-transform:uppercase;letter-spacing:.08em;font-size:.72rem;color:#6d7c93;font-weight:750}.phone-row,.session-fields,.steward-input{display:flex;gap:.65rem}.country-code{max-width:110px}.phone-row .input:last-child{flex:1}.session-fields>.field{flex:1}.stepper{display:flex;gap:.55rem}.stepper button{width:54px;border:1px solid #dce2ea;background:#f7f9fb;border-radius:9px;font-size:1.25rem;color:#1d2738;cursor:pointer}.stepper input{text-align:center;min-width:0}.session-foot{padding:1rem 1.35rem 1.3rem;border-top:1px solid #e8ecf1;gap:.7rem}.session-foot .session-cancel{min-width:110px}.session-submit{flex:1;justify-content:center;font-size:.95rem}@media(max-width:560px){.session-fields{display:grid;grid-template-columns:1fr 1.5fr}.country-code{max-width:100px}}
.ptable-main{display:block;color:inherit;text-decoration:none}.ptable-main:hover{text-decoration:none}.ptable-actions{display:flex;justify-content:center;gap:.22rem;border-top:1px solid rgba(148,163,184,.35);margin-top:.55rem;padding-top:.5rem}.ptable-action{display:grid;place-items:center;width:27px;height:27px;padding:0;border:1px solid transparent;border-radius:6px;background:rgba(255,255,255,.7);color:#475569;cursor:pointer;text-decoration:none}.ptable-action:hover{border-color:#94a3b8;background:#fff;color:#166534;text-decoration:none}.ptable-action .i{width:14px;height:14px}
</style>
@endpush
@push('scripts')
<script>
// refresh the floor plan every 30 s so new guest QR orders appear (skipped while a dialog is open)
setInterval(() => { if (!document.querySelector('.modal.show') && document.visibilityState === 'visible') location.reload(); }, 30000);

const sessionForm = document.getElementById('tableSessionForm');
const sessionTable = document.getElementById('tableSessionTable');
const sessionTitle = document.getElementById('tableSessionTitle');
const sessionName = document.getElementById('tableSessionName');
const sessionPhone = document.getElementById('tableSessionPhone');
const sessionPhoneInput = document.getElementById('tableSessionPhoneInput');
const sessionCountry = document.getElementById('tableSessionCountry');
const sessionCovers = document.getElementById('tableSessionCovers');
const guestLookup = document.getElementById('tableGuestLookup');
const guestSearchButton = document.getElementById('tableGuestSearch');
const guestResults = document.getElementById('tableGuestResults');
const guestSearchUrl = '{{ route("pos.guests") }}';
let guestSearchTimer;
let guestSearchRequest = 0;

const phoneForForm = () => {
  const source = sessionPhoneInput.value.trim();
  if (!source) { sessionPhone.value = ''; return; }
  const digits = source.replace(/\D/g, '');
  const countryDigits = sessionCountry.value.replace(/\D/g, '');
  const localDigits = digits.replace(/^0+/, '');
  sessionPhone.value = source.startsWith('+') || digits.startsWith('00') ? '+' + digits.replace(/^00/, '') : (localDigits.startsWith(countryDigits) ? '+' + localDigits : sessionCountry.value + localDigits);
};

const clearGuestResults = () => {
  guestResults.innerHTML = '';
  guestResults.classList.remove('show');
};

const showGuestResults = guests => {
  guestResults.innerHTML = '';
  if (!guests.length) {
    const empty = document.createElement('div');
    empty.className = 'tiny muted';
    empty.textContent = 'No matching guests found.';
    guestResults.appendChild(empty);
  } else {
    guests.forEach(guest => {
      const button = document.createElement('button');
      button.type = 'button';
      const name = document.createElement('strong');
      name.textContent = guest.name || 'Unnamed guest';
      button.appendChild(name);
      if (guest.vip) {
        const badge = document.createElement('span');
        badge.className = 'badge badge-purple';
        badge.textContent = ' VIP';
        button.append(' ', badge);
      }
      const detail = document.createElement('div');
      detail.className = 'tiny muted';
      detail.textContent = [guest.phone, guest.email].filter(Boolean).join(' · ') || 'No phone or email recorded';
      button.appendChild(detail);
      button.addEventListener('click', () => {
        sessionName.value = guest.name || '';
        const phone = String(guest.phone || '').trim();
        const matchedCountry = [...sessionCountry.options].find(option => phone.startsWith(option.value));
        if (matchedCountry) {
          sessionCountry.value = matchedCountry.value;
          sessionPhoneInput.value = phone.slice(matchedCountry.value.length).replace(/^0+/, '');
        } else {
          sessionPhoneInput.value = phone;
        }
        phoneForForm();
        guestLookup.value = guest.name || '';
        clearGuestResults();
      });
      guestResults.appendChild(button);
    });
  }
  guestResults.classList.add('show');
};

const findGuests = async () => {
  const query = guestLookup.value.trim();
  if (query.length < 2) { guestSearchRequest++; guestSearchButton.disabled = false; clearGuestResults(); return; }
  const request = ++guestSearchRequest;
  guestSearchButton.disabled = true;
  try {
    const response = await fetch(guestSearchUrl + '?q=' + encodeURIComponent(query), {headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}});
    if (!response.ok) throw new Error('Guest lookup failed');
    const guests = await response.json();
    if (request === guestSearchRequest) showGuestResults(Array.isArray(guests) ? guests : []);
  } catch (error) {
    if (request === guestSearchRequest) showGuestResults([]);
  } finally {
    if (request === guestSearchRequest) guestSearchButton.disabled = false;
  }
};

guestSearchButton?.addEventListener('click', findGuests);
guestLookup?.addEventListener('input', () => {
  clearTimeout(guestSearchTimer);
  guestSearchTimer = setTimeout(findGuests, 250);
});
guestLookup?.addEventListener('keydown', event => {
  if (event.key === 'Enter') { event.preventDefault(); findGuests(); }
});
sessionPhoneInput?.addEventListener('input', phoneForForm);
sessionCountry?.addEventListener('change', phoneForForm);
sessionForm?.addEventListener('submit', phoneForForm);
document.querySelectorAll('#tableSession [data-pax]').forEach(button => button.addEventListener('click', () => {
  const next = Math.max(1, Math.min(50, Number(sessionCovers.value || 1) + Number(button.dataset.pax)));
  sessionCovers.value = next;
}));

document.addEventListener('click', event => {
  const opener = event.target.closest('#tableSession [data-close]');
  if (opener) clearGuestResults();
  const tableButton = event.target.closest('[data-modal="tableSession"]');
  if (!tableButton || !sessionForm) return;
  sessionTable.value = tableButton.dataset.tableId || '';
  sessionTitle.textContent = 'Table — ' + (tableButton.dataset.tableNo || '');
  guestSearchRequest++;
  clearTimeout(guestSearchTimer);
  guestSearchButton.disabled = false;
  sessionPhone.value = '';
  sessionPhoneInput.value = '';
  guestLookup.value = '';
  clearGuestResults();
  sessionCovers.value = '1';
  phoneForForm();
});
</script>
@endpush
@section('content')
@include('pos._workspace-nav')
@if($outlet)
<div class="pos-mode-tabs" role="tablist" aria-label="Start an order">
  <span class="pos-mode-tab active" role="tab" aria-selected="true"><x-icon name="grid" size="sm"/> Dine In</span>
  @if(can_do('pos.order'))
    <button type="button" class="pos-mode-tab" data-modal="rsModal" role="tab"><x-icon name="bed" size="sm"/> Room Service</button>
    <button type="button" class="pos-mode-tab" data-modal="takeModal" role="tab"><x-icon name="cup" size="sm"/> Take Away</button>
  @endif
</div>
@endif
@if(!$outlet)<div class="alert alert-warn"><x-icon name="alert"/><div>No outlets configured. <a href="{{ route('masters.index','outlets') }}">Create an outlet</a>.</div></div>@else
<div class="tabs">@foreach($outlets as $o)<a href="{{ route('pos.index', ['outlet' => $o->id]) }}" class="{{ $o->id === $outlet->id ? 'active' : '' }}">{{ $o->name }}</a>@endforeach</div>
@if($pending->isNotEmpty())
<div class="alert alert-warn qr-alert"><x-icon name="bell"/><div><b>New guest orders (QR / portal) awaiting confirmation</b>
  @foreach($running->whereIn('id', $pending->keys()) as $o)<a class="btn btn-sm btn-accent ml-1" href="{{ route('pos.terminal', $o) }}">{{ $o->table ? 'Table '.$o->table->table_no : ($o->stay?->room ? 'Room '.$o->stay->room->room_no : $o->order_no) }} · {{ $pending[$o->id] }} item(s)</a>@endforeach
</div></div>
@endif
<div class="grid g-3-1">
  <div class="card"><div class="card-head"><h3>Tables</h3><div class="legend"><span><i style="background:#86efac"></i>Free</span><span><i style="background:#fca5a5"></i>Running</span><span><i style="background:#fcd34d"></i>Billed</span></div></div>
    <div class="card-body">
      @if($tables->isEmpty())<x-empty text="No tables for this outlet. Use Takeaway / Room Service." icon="grid"/>@endif
      <div class="tables-grid">
      @foreach($tables as $t) @php $o = $t->openOrder; @endphp
        @if($o)<div class="ptable {{ $o->status === 'billed' ? 'billed' : 'busy' }}">
          <a href="{{ route('pos.terminal', $o) }}" class="ptable-main" title="Open {{ $o->order_no }}">
        @else<button type="button" class="ptable free" data-modal="tableSession" data-table-id="{{ $t->id }}" data-table-no="{{ $t->table_no }}" @disabled(!can_do('pos.order'))>
        @endif
            <div class="tn">{{ $t->table_no }}@if($o && isset($pending[$o->id]))<span class="badge badge-amber" style="margin-left:.3rem" title="Guest QR order pending">QR {{ $pending[$o->id] }}</span>@endif</div>
            <div class="tiny muted">{{ $t->section }} · {{ $t->capacity }} seats</div>
            @php $rs = $reservedSoon[$t->id] ?? null; @endphp
            @if($o)<div class="small bold mt-1">{{ money($o->total) }}</div><div class="tiny">{{ $o->created_at->diffForHumans(null, true) }}</div>
            @elseif($t->blocked_reason)<div class="small text-red mt-1" title="{{ $t->blocked_reason }}">Out of service</div>
            @elseif($t->needs_clearing)<div class="small mt-1" style="color:#7c3aed">To clear</div>
            @elseif($rs)<div class="small mt-1 {{ $rs->start_at->isPast() ? 'text-red' : 'text-blue' }}">Reserved {{ $rs->start_at->format('H:i') }}</div>
            @else<div class="small text-green mt-1">Available</div>@endif
            @if($o && $rs && $rs->start_at->isFuture())<div class="tiny text-blue" title="{{ $rs->name }}">Next: {{ $rs->start_at->format('H:i') }}</div>@endif
        @if($o)</a><div class="ptable-actions" aria-label="Actions for {{ $o->order_no }}">
          @if(can_do('pos.order') && $freeTables->isNotEmpty())<button type="button" class="ptable-action" data-modal="tableMoveModal" data-action="{{ route('pos.move', $o) }}" title="Shift table" aria-label="Shift table"><x-icon name="swap"/></button>@endif
          @if(can_do('pos.view'))<button type="button" class="ptable-action" data-modal="orderDetail{{ $o->id }}" title="View order detail" aria-label="View order detail"><x-icon name="eye"/></button><a class="ptable-action" target="_blank" href="{{ route('pos.print', $o) }}" title="View invoice" aria-label="View invoice"><x-icon name="print"/></a>@endif
          @if(can_do('pos.settle') && $o->items->where('is_cancelled', false)->where('is_pending', false)->sum('qty') > 1 && $o->items->where('is_pending', true)->where('is_cancelled', false)->isEmpty())<a class="ptable-action" href="{{ route('pos.terminal', $o) }}?modal=split" title="Split bill" aria-label="Split bill"><x-icon name="receipt"/></a>@endif
          @if(can_do('pos.order'))<a class="ptable-action" href="{{ route('pos.terminal', $o) }}" title="Edit order" aria-label="Edit order"><x-icon name="edit"/></a>@endif
        </div></div>@else</button>@endif
        @if($o)
        <div class="modal" id="orderDetail{{ $o->id }}"><div class="modal-box" style="max-width:620px"><div class="modal-head"><h3>Order {{ $o->order_no }} · Table {{ $t->table_no }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close aria-label="Close"><x-icon name="x"/></button></div>
          <div class="modal-body"><div class="grid g-2 small"><div><b>Guest</b><br>{{ $o->customer_name ?: 'Walk-in guest' }}@if($o->customer_phone)<br><span class="muted">{{ $o->customer_phone }}</span>@endif</div><div><b>Waiter</b><br>{{ $o->steward ?: 'Not assigned' }}</div><div><b>Covers</b><br>{{ $o->covers ?: 1 }}</div><div><b>Status</b><br>{!! status_badge($o->status) !!}</div></div><hr><table class="table table-compact"><thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Amount</th></tr></thead><tbody>@forelse($o->items->where('is_cancelled', false)->where('is_pending', false) as $line)<tr><td>{{ $line->item_name }}</td><td class="num">{{ (float) $line->qty }}</td><td class="num">{{ money($line->amount, false) }}</td></tr>@empty<tr><td colspan="3" class="muted">No accepted items yet.</td></tr>@endforelse</tbody><tfoot><tr><th colspan="2" class="num">Total</th><th class="num">{{ money($o->total, false) }}</th></tr></tfoot></table></div>
          <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><a class="btn" target="_blank" href="{{ route('pos.print', $o) }}"><x-icon name="print"/> View invoice</a>@if(can_do('pos.order'))<a class="btn btn-primary" href="{{ route('pos.terminal', $o) }}"><x-icon name="edit"/> Edit order</a>@endif</div>
        </div></div>
        @endif
      @endforeach
      </div>
    </div></div>
  <div class="card"><div class="card-head"><h3>Running orders</h3></div><div class="card-body" style="padding-top:.3rem"><ul class="list">
    @forelse($running as $o)<li><div><a href="{{ route('pos.terminal', $o) }}" class="bold">{{ $o->order_no }}</a><div class="tiny muted">{{ $o->table ? 'Table '.$o->table->table_no : ($o->stay ? 'Room '.$o->stay->room?->room_no : ucwords(str_replace('_',' ',$o->order_type))) }} · {{ $o->customer_name }}</div></div><div class="right">{{ money($o->total) }}<br>{!! status_badge($o->status) !!}@if(isset($pending[$o->id]))<span class="badge badge-amber">QR new</span>@endif</div></li>
    @empty<li class="muted">No running orders.</li>@endforelse
  </ul></div></div>
</div>

<div class="modal" id="takeModal"><div class="modal-box"><form method="post" action="{{ route('pos.open') }}">@csrf<input type="hidden" name="outlet_id" value="{{ $outlet->id }}">
  <div class="modal-head"><h3>New takeaway / delivery</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid"><x-field name="order_type" label="Type" type="select" :options="['takeaway'=>'Takeaway','delivery'=>'Delivery']" :placeholder="false"/><x-field name="customer_name" label="Customer"/><x-field name="customer_phone" label="Phone"/><x-field name="delivery_address" label="Delivery address"/><x-field name="delivery_fee" label="Delivery fee" type="number" step="0.01" min="0" value="0"/><x-field name="delivery_notes" label="Delivery notes"/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Start Order</button></div>
</form></div></div>
<div class="modal" id="rsModal"><div class="modal-box"><form method="post" action="{{ route('pos.open') }}">@csrf<input type="hidden" name="outlet_id" value="{{ $outlet->id }}"><input type="hidden" name="order_type" value="room_service">
  <div class="modal-head"><h3>Room service order</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="field"><label>In-house room</label><select name="reservation_room_id" required><option value="">Select room</option>@foreach($inHouse as $s)<option value="{{ $s->id }}">{{ $s->room?->room_no }} – {{ $s->reservation->guest->full_name }}</option>@endforeach</select></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Start Order</button></div>
</form></div></div>
<div class="modal" id="tableSession"><div class="modal-box table-session-modal"><form method="post" action="{{ route('pos.open') }}" data-reset id="tableSessionForm">@csrf
  <input type="hidden" name="outlet_id" value="{{ $outlet->id }}"><input type="hidden" name="pos_table_id" id="tableSessionTable"><input type="hidden" name="order_type" value="dine_in"><input type="hidden" name="customer_phone" id="tableSessionPhone">
  <div class="modal-head session-head"><div><div class="session-kicker">POINT OF SALE</div><h3 id="tableSessionTitle">Table — 1</h3><p>Opening Table Session</p></div><button type="button" class="btn btn-ghost btn-icon" data-close aria-label="Close"><x-icon name="x"/></button></div>
  <div class="modal-body session-body">
    <div class="guest-lookup-card"><div class="lookup-title"><x-icon name="search"/> Find an existing guest</div><div class="lookup-row"><input class="input" id="tableGuestLookup" type="search" autocomplete="off" placeholder="Search by name, phone or email"><button type="button" class="lookup-btn" id="tableGuestSearch" aria-label="Search guest"><x-icon name="search"/></button></div><div class="guest-results" id="tableGuestResults"></div><p>Pick a guest to auto-fill the details below, or enter a new guest.</p></div>
    <div class="session-divider"><span>GUEST INFO</span></div>
    <div class="field"><label for="tableSessionName">Guest name</label><input class="input" id="tableSessionName" name="customer_name" maxlength="120" placeholder="Enter guest name"></div>
    <div class="field"><label for="tableSessionPhoneInput">Phone number</label><div class="phone-row"><select class="input country-code" id="tableSessionCountry"><option value="+233">GH +233</option><option value="+234">NG +234</option><option value="+254">KE +254</option><option value="+27">ZA +27</option><option value="+44">UK +44</option><option value="+1">US +1</option></select><input class="input" id="tableSessionPhoneInput" type="tel" inputmode="tel" placeholder="Enter phone number"></div></div>
    <div class="session-fields"><div class="field"><label for="tableSessionCovers">Pax</label><div class="stepper"><button type="button" data-pax="-1">−</button><input id="tableSessionCovers" name="covers" type="number" min="1" max="50" value="1"><button type="button" data-pax="1">+</button></div></div><div class="field"><label for="tableSessionSteward">Waiter</label><div class="steward-input"><input class="input" id="tableSessionSteward" name="steward" maxlength="60" value="{{ auth()->user()?->name ?? '' }}" placeholder="Enter waiter"></div></div></div>
  </div>
  <div class="modal-foot session-foot"><button type="button" class="btn session-cancel" data-close>Cancel</button><button class="btn btn-accent session-submit"><x-icon name="check"/> Open POS</button></div>
</form></div></div>
@if($freeTables->isNotEmpty())
<div class="modal" id="tableMoveModal"><div class="modal-box"><form method="post" action="#">@csrf
  <div class="modal-head"><h3>Shift table</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><p class="small muted" style="margin-top:0">Move the open order to another free table in this outlet.</p><div class="field"><label for="tableMoveTarget">New table</label><select name="pos_table_id" id="tableMoveTarget" required>@foreach($freeTables as $free)<option value="{{ $free->id }}">Table {{ $free->table_no }}{{ $free->section ? ' · '.$free->section : '' }} ({{ $free->capacity }} seats)</option>@endforeach</select></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary"><x-icon name="swap"/> Shift table</button></div>
</form></div></div>
@endif
@endif
@endsection
