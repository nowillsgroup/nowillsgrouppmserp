@php
  $res = $stay->reservation;
  $guest = $res->guest;
  $nights = $stay->arrival_date->diffInDays($stay->departure_date);
  $tax = \App\Services\TaxService::roomTax((float) $stay->rate);
  $expected = round($nights * ((float) $stay->rate + $tax['amount']), 2);
  $folio = $stay->folio;
  $balance = $folio ? $folio->balance() : null;
@endphp
@php
  $photoDoc = \App\Models\GuestDocument::where('guest_id', $guest->id)->where('type', 'photo')->latest('id')->first();
@endphp
<div class="stay-card">
  <div class="flex between gap-1 wrap">
    @if($photoDoc && (can_do('guests.view') || can_do('frontdesk.checkin')))
      <img src="{{ route('guests.documents.show', $photoDoc) }}" alt="Photo of {{ $guest->full_name }}" style="width:56px;height:56px;object-fit:cover;border-radius:10px">
    @else
      <div class="no-photo" style="width:56px;height:56px" title="No guest photo on file">{{ strtoupper(substr($guest->first_name,0,1)) }}{{ strtoupper(substr((string)$guest->last_name,0,1)) }}</div>
    @endif
    <div>
      <div class="tiny muted">{{ $res->reservation_no }} · {{ ucwords(str_replace('_', ' ', $stay->status)) }}</div>
      <h2 style="margin:0">{{ $guest->full_name }}@if($guest->is_vip) <span class="badge badge-purple">VIP</span>@endif</h2>
      <div class="small text-2">{{ $guest->phone ?: 'No phone on file' }}{{ $guest->email ? ' · '.$guest->email : '' }}</div>
      @if($res->company)<div class="small">Company: {{ $res->company->name }}</div>@endif
    </div>
    <div class="right">
      <div class="tiny muted">Room</div>
      <div class="bold">{{ $stay->room->room_no ?? 'Unassigned' }}</div>
      <div class="small">{{ $stay->roomType->name }}</div>
    </div>
  </div>
  <div class="grid g-2" style="margin-top:.8rem">
    <div><div class="tiny muted">Arrival</div><div>{{ $stay->arrival_date->format('D, d M Y') }}</div></div>
    <div><div class="tiny muted">Departure</div><div>{{ $stay->departure_date->format('D, d M Y') }}</div></div>
    <div><div class="tiny muted">Nights · pax</div><div>{{ $nights }} · {{ $stay->adults }}/{{ $stay->children }}</div></div>
    <div><div class="tiny muted">Rate / night</div><div>{{ money($stay->rate) }}</div></div>
    <div><div class="tiny muted">Expected room + tax</div><div>{{ money($expected) }}</div><div class="tiny muted">Not a folio balance</div></div>
    <div><div class="tiny muted">Folio balance</div><div>{{ $balance === null ? 'No folio yet' : money($balance) }}</div></div>
    @php $lastPay = $folio ? $folio->transactions()->where('is_void', false)->where('type', 'payment')->latest('id')->first() : null; @endphp
    <div><div class="tiny muted">Last payment</div><div>{{ $lastPay ? money($lastPay->amount).($lastPay->reference ? ' · '.$lastPay->reference : '') : 'No payment on file' }}@if($lastPay) · <a href="{{ route('folios.receipt', $lastPay) }}" target="_blank">Print receipt</a>@endif</div></div>
  </div>
  @if($res->special_request)<p class="small" style="margin-top:.7rem"><b>Request:</b> {{ $res->special_request }}</p>@endif
  <p class="small muted">Source: {{ $res->source?->name ?? 'Direct' }}{{ $res->ota_reference ? ' · '.$res->ota_reference : '' }}{{ $res->booking_type === 'group' ? ' · Group '.($res->group_name ?: '') : '' }}{{ $stay->ratePlan ? ' · '.$stay->ratePlan->name : '' }}</p>
  <p class="small">Loyalty points on file: {{ (int) $guest->loyalty_points }}@if($res->company) · {{ $res->company->name }} credit {{ $res->company->credit_status }}@endif</p>
  @if($res->company && $res->company->credit_status === 'hold')
    <p class="small">This company is on credit hold. City ledger is not offered from this desk. Cash, card and Mobile Money still post to the folio.</p>
  @endif
  @php
    $idOnFile = filled($guest->id_type) && filled($guest->id_number);
    $openTickets = $stay->room_id ? \App\Models\MaintenanceRequest::where('room_id', $stay->room_id)->whereIn('status', ['open', 'in_progress'])->count() : 0;
  @endphp
  <p class="small">@if($idOnFile)ID on file: {{ str_replace('_', ' ', $guest->id_type) }}@else No ID on file. Check-in requires an ID type and number.@endif
    @if($stay->room) · Room {{ $stay->room->room_no }} housekeeping {{ str_replace('_', ' ', $stay->room->hk_status) }}@if(in_array($stay->room->hk_status, ['dirty', 'touch_up'], true)). Check-in does not clean the room.@endif
    @else · No room assigned.
    @endif
    @if($openTickets) · {{ $openTickets }} open maintenance ticket(s) on this room.@endif
  </p>

  <div class="flex gap-1 wrap" style="margin:.6rem 0">
    <a class="btn btn-sm" href="{{ route('reservations.show', $res) }}">Open reservation</a>
    <a class="btn btn-sm" href="{{ route('reservations.print', $res) }}" target="_blank">Print reservation</a>
    <a class="btn btn-sm" href="{{ route('frontdesk.grc', $stay) }}" target="_blank">Registration card</a>
    @if($folio)
      <a class="btn btn-sm" href="{{ route('folios.show', $folio) }}">Open folio</a>
      <a class="btn btn-sm" href="{{ route('folios.invoice', $folio) }}" target="_blank">Invoice / receipt</a>
    @endif
    @if($stay->status === 'reserved' && $stay->room_id && can_do('frontdesk.checkin'))
      <a class="btn btn-sm btn-primary" href="{{ route('frontdesk.checkin', $stay) }}">Check in</a>
    @endif
    @if($stay->status === 'checked_in' && can_do('frontdesk.checkout'))
      <a class="btn btn-sm btn-primary" href="{{ route('frontdesk.checkout', $stay) }}" title="Checkout marks this room vacant and dirty">Check out</a>
    @endif
    @if($stay->status === 'checked_in' && can_do('frontdesk.roommove'))
      <a class="btn btn-sm" href="{{ route('frontdesk.move', $stay) }}">Room-move screen</a>
    @endif
  </div>
  @php
    $audit = \App\Models\ActivityLog::with('user')->where(function ($q) use ($stay, $res) {
        $q->where(fn ($w) => $w->where('subject_type', 'ReservationRoom')->where('subject_id', $stay->id))
          ->orWhere(fn ($w) => $w->where('subject_type', 'Reservation')->where('subject_id', $res->id));
    })->latest('id')->limit(5)->get();
  @endphp
  <h3>Audit</h3>
  @forelse($audit as $line)
    <p class="small">{{ $line->created_at?->format('d M H:i') }} · {{ $line->action }} · {{ $line->description }}@if($line->user) · {{ $line->user->name }}@endif</p>
  @empty
    <p class="small muted">No audit line is on file for this stay.</p>
  @endforelse
  @if(can_do('logs.view'))<p class="tiny"><a href="{{ route('logs.index', ['q' => $res->reservation_no]) }}">Open the audit log</a></p>@endif

  @if(can_do('reservations.edit') && in_array($stay->status, ['reserved', 'checked_in'], true))
  <form method="post" action="{{ route('reservations.place', $stay) }}" class="desk-form">
    @csrf
    <h3>Assign, move, upgrade</h3>
    <p class="tiny muted">The server checks availability, out-of-order rooms and dated blocks. A drag on the timeline uses the same tape-chart check.</p>
    @if($free->isEmpty())
      <p class="small">No other room is free for these dates.</p>
    @else
      <div class="flex gap-1 wrap">
        <select name="room_id" required aria-label="Room">
          @foreach($free as $r)<option value="{{ $r->id }}">{{ $r->room_no }} · {{ $r->roomType->name }} · {{ $r->hk_status }}</option>@endforeach
        </select>
        <input name="rate" type="number" min="0" step="0.01" placeholder="New rate (optional)" aria-label="New rate" style="width:9rem">
        <button class="btn btn-sm" type="button" data-room-check="{{ route('reservations.place.check', $stay) }}">Check room</button>
        <button class="btn btn-primary btn-sm">Confirm room</button>
      </div>
    @endif
  </form>
  <form method="post" action="{{ route('reservations.dates', $stay) }}" class="desk-form" data-preview="{{ route('reservations.dates.preview', $stay) }}">
    @csrf
    <h3>Extend or shorten</h3>
    <div class="flex gap-1 wrap">
      <label class="tiny">Arrival <input type="date" name="arrival_date" value="{{ $stay->arrival_date->toDateString() }}" @if($stay->status==='checked_in') readonly @endif required></label>
      <label class="tiny">Departure <input type="date" name="departure_date" value="{{ $stay->departure_date->toDateString() }}" required></label>
      <button class="btn btn-sm" type="button" data-quote>Preview impact</button>
      <button class="btn btn-sm btn-primary">Confirm dates</button>
    </div>
    <p class="quote tiny muted">Preview checks availability and shows the room and tax change before anything is saved. Night audit posts the charge. Posted nights are not reversed here.</p>
  </form>
  <form method="post" action="{{ route('reservations.split', $stay) }}" class="desk-form" data-preview="{{ route('reservations.split.preview', $stay) }}">
    @csrf
    <h3>Split stay</h3>
    @if($free->isEmpty())
      <p class="small">A split needs a free room for the second part. None is free on the current dates.</p>
    @else
      <div class="flex gap-1 wrap">
        <label class="tiny">Split on <input type="date" name="split_date" required value="{{ $stay->arrival_date->copy()->addDay()->toDateString() }}"></label>
        <select name="room_id" required aria-label="Room for the second part">
          @foreach($free as $r)<option value="{{ $r->id }}">{{ $r->room_no }} · {{ $r->roomType->name }}</option>@endforeach
        </select>
        <input name="rate" type="number" min="0" step="0.01" placeholder="Rate for 2nd part" aria-label="Rate for second part" style="width:9rem">
        <button class="btn btn-sm" type="button" data-quote>Preview split</button>
        <button class="btn btn-sm">Confirm split</button>
      </div>
      <p class="quote tiny muted">Preview shows both parts and the expected room and tax. Nothing is saved until you confirm.</p>
    @endif
  </form>
  @endif

  @if(can_do('folio.payment'))
  <form method="post" action="{{ $folio && $folio->status === 'open' ? route('folios.payment', $folio) : route('reservations.advance', $res) }}" class="desk-form" id="deskStayPaymentForm" data-preview="{{ $folio && $folio->status === 'open' ? route('folios.payment.preview', $folio) : route('reservations.advance.preview', $res) }}">
    @csrf
    @if($folio && $folio->status === 'open')<input type="hidden" name="type" value="payment">@endif
    <h3>{{ $folio && $folio->status === 'open' ? 'Take payment' : 'Take advance' }}</h3>
    <div id="deskSinglePayment" class="flex gap-1 wrap">
      <input name="amount" type="number" min="0.01" step="0.01" required placeholder="Amount" aria-label="Amount" style="width:7rem">
      <select name="payment_mode_id" required aria-label="Payment mode" data-payment-mode>
        @foreach($modes as $m)<option value="{{ $m->id }}">{{ $m->name }}</option>@endforeach
      </select>
      <input name="momo_network" placeholder="MoMo network" aria-label="Mobile Money network" style="width:7rem">
      <input name="momo_phone" placeholder="Mobile Money phone" aria-label="Mobile Money phone" style="width:8rem">
      <input name="reference" placeholder="Transaction ID" aria-label="Transaction reference" style="width:8rem">
      <x-cheque-fields class="span-all"/>
    </div>
    <div class="mt-1"><button class="btn btn-sm" type="button" id="deskSplitToggle">Split {{ $folio && $folio->status === 'open' ? 'payment' : 'advance' }}</button></div>
    <div id="deskSplitPayment" class="mt-1" hidden>
      <p class="tiny muted">Add two or more tenders. They will be posted together as separate entries{{ $folio && $folio->status === 'open' ? ' to this folio; partial payments are allowed' : ' as an advance on the reservation' }}. Total allocated: <b id="deskAllocationTotal">0.00</b>.</p>
      <div id="deskPaymentAllocations"></div>
      <button class="btn btn-sm mt-1" type="button" id="deskAddPaymentAllocation">Add payment method</button>
    </div>
    <div class="flex gap-1 wrap mt-1">
      <button class="btn btn-sm" type="button" data-quote>Preview {{ $folio && $folio->status === 'open' ? 'payment' : 'advance' }}</button>
      <button class="btn btn-sm btn-primary">Confirm {{ $folio && $folio->status === 'open' ? 'payment' : 'advance' }}</button>
    </div>
    <p class="quote tiny muted">Preview shows where the money would sit. Nothing is posted until you confirm. A receipt prints only after the payment is on the folio.</p>
  </form>
  @endif
  @if($folio && $folio->status === 'open' && can_do('folio.post'))
  <form method="post" action="{{ route('folios.charge', $folio) }}" class="desk-form" data-preview="{{ route('folios.charge.preview', $folio) }}">
    @csrf
    <h3>Add charge</h3>
    <div class="flex gap-1 wrap">
      <select name="category" required aria-label="Charge category">
        @foreach($categories as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
      </select>
      <input name="description" required placeholder="Description" aria-label="Description" style="width:10rem">
      <input name="amount" type="number" min="0.01" step="0.01" required placeholder="Amount" aria-label="Charge amount" style="width:7rem">
      <label class="tiny"><input type="checkbox" name="apply_tax" value="1" checked> Tax</label>
      <button class="btn btn-sm" type="button" data-quote>Preview charge</button>
      <button class="btn btn-sm">Confirm charge</button>
    </div>
    <p class="quote tiny muted">Preview shows the tax from the hotel’s tax setup. Nothing is posted until you confirm.</p>
  </form>
  @endif
  @if(can_do('keys.view') || can_do('keys.issue'))
  <div class="desk-form">
    <h3>Key card</h3>
    @php $cards = \App\Models\KeyCard::where('reservation_room_id', $stay->id)->latest('id')->limit(5)->get(); @endphp
    @forelse($cards as $card)
      <p class="small">{{ $card->card_no }} · {{ \App\Models\KeyCard::STATUSES[$card->status][0] ?? $card->status }} · until {{ $card->valid_until?->format('d M H:i') }}</p>
    @empty
      <p class="small muted">No key card on file.</p>
    @endforelse
  </div>
  @endif
  @if($stay->status === 'checked_in' && $stay->room_id && can_do('keys.issue'))
  <form method="post" action="{{ route('keys.issue') }}" class="desk-form">
    @csrf
    <input type="hidden" name="stay_id" value="{{ $stay->id }}">
    <input type="hidden" name="mode" value="new">
    <div class="flex gap-1 wrap">
      <input name="count" type="number" min="1" max="4" value="1" required aria-label="Number of cards" style="width:5rem">
      <input name="holder" value="{{ $guest->full_name }}" aria-label="Card holder" style="width:10rem">
      <button class="btn btn-sm">Encode card</button>
      <a class="btn btn-sm" href="{{ route('keys.index') }}">Key register</a>
    </div>
  </form>
  @elseif(can_do('keys.view'))
    <p class="small"><a href="{{ route('keys.index') }}">Key register</a> — a card can be encoded after check-in.</p>
  @endif
  @if($stay->status === 'checked_in' && can_do('frontdesk.checkout'))
  <form method="post" action="{{ route('reservations.checkout.preview', $stay) }}" class="desk-form" data-preview="{{ route('reservations.checkout.preview', $stay) }}">
    @csrf
    <h3>Checkout</h3>
    <div class="flex gap-1 wrap">
      <button class="btn btn-sm" type="button" data-quote>Preview checkout</button>
      <a class="btn btn-sm btn-primary" href="{{ route('frontdesk.checkout', $stay) }}">Open checkout</a>
    </div>
    <p class="quote tiny muted">Preview reads the folio, unsettled orders and key cards. It does not post a night or check the guest out. The existing checkout settles the bill and marks the room vacant and dirty.</p>
  </form>
  @endif
  @if(can_do('reservations.view') && $guest->email)
  <form method="post" action="{{ route('reservations.email', $res) }}" class="desk-form">
    @csrf
    <h3>Email confirmation</h3>
    <div class="flex gap-1 wrap">
      <input name="to" type="email" value="{{ $guest->email }}" aria-label="Email" style="width:14rem">
      <button class="btn btn-sm">Send</button>
    </div>
  </form>
  @endif
</div>
@push('scripts')
<script>
(function () {
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
  const keyOf = form => [...new FormData(form).entries()].map(([key, value]) => `${key}=${String(value)}`).join('|');
  const paymentForm = document.getElementById('deskStayPaymentForm');
  if (paymentForm && document.getElementById('deskSplitToggle')) {
    const toggle = document.getElementById('deskSplitToggle');
    const single = document.getElementById('deskSinglePayment');
    const split = document.getElementById('deskSplitPayment');
    const rows = document.getElementById('deskPaymentAllocations');
    const totalEl = document.getElementById('deskAllocationTotal');
    const modes = @json($modes->pluck('name', 'id'));
    const oldLines = @js(old('payments', []));
    const esc = value => String(value).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    let seq = 0, restored = false;
    const updateTotal = () => {
      const sum = [...rows.querySelectorAll('input[name$="[amount]"]')].reduce((n, input) => n + (Number(input.value) || 0), 0);
      if (totalEl) totalEl.textContent = sum.toFixed(2);
    };
    const syncCheque = () => rows.querySelectorAll('[data-desk-payment-row]').forEach(row => {
      const mode = row.querySelector('select[name$="[payment_mode_id]"]');
      const required = (mode?.selectedOptions?.[0]?.textContent || '').toLowerCase().includes('cheque');
      row.querySelectorAll('input[name$="[cheque_bank_name]"],input[name$="[cheque_number]"],input[name$="[cheque_bank_branch]"]').forEach(input => { input.required = required; });
    });
    const addRow = (line = {}) => {
      if (!line || typeof line !== 'object') line = {};
      const i = seq++;
      const options = Object.entries(modes).map(([id, name]) => `<option value="${esc(id)}">${esc(name)}</option>`).join('');
      rows.insertAdjacentHTML('beforeend', `<div class="form-grid mt-1" data-desk-payment-row style="border:1px solid var(--c-border);padding:.55rem;border-radius:var(--radius)">
        <div class="field"><label>Method</label><select name="payments[${i}][payment_mode_id]" required>${options}</select></div>
        <div class="field"><label>Amount</label><input name="payments[${i}][amount]" type="number" min="0.01" step="0.01" required></div>
        <div class="field"><label>MoMo network</label><input name="payments[${i}][momo_network]" maxlength="20"></div>
        <div class="field"><label>MoMo phone</label><input name="payments[${i}][momo_phone]" maxlength="30"></div>
        <div class="field span-all"><label>Reference / transaction ID</label><input name="payments[${i}][reference]" maxlength="80"></div>
        <details class="span-all"><summary class="tiny bold">Cheque details</summary><div class="form-grid mt-1">
          <div class="field"><label>Bank name</label><input name="payments[${i}][cheque_bank_name]" maxlength="100"></div>
          <div class="field"><label>Cheque number</label><input name="payments[${i}][cheque_number]" maxlength="20"></div>
          <div class="field"><label>Bank branch</label><input name="payments[${i}][cheque_bank_branch]" maxlength="100"></div>
        </div></details>
        <button class="btn btn-sm btn-ghost" type="button" data-remove-desk-payment>Remove tender</button>
      </div>`);
      const row = rows.lastElementChild;
      for (const key of ['payment_mode_id','amount','momo_network','momo_phone','reference','cheque_bank_name','cheque_number','cheque_bank_branch']) {
        const field = row.querySelector(`[name="payments[${i}][${key}]"]`);
        if (field && line[key] !== undefined && line[key] !== null) field.value = String(line[key]);
      }
      syncCheque(); updateTotal();
    };
    const setSplit = enabled => {
      single.hidden = enabled; split.hidden = !enabled;
      single.querySelectorAll('input,select').forEach(input => { input.disabled = enabled; });
      toggle.textContent = enabled ? 'Use one payment method' : 'Split payment';
      if (enabled && !rows.children.length) {
        if (!restored && Array.isArray(oldLines) && oldLines.length) { oldLines.slice(0,10).forEach(addRow); restored = true; }
        while (rows.children.length < 2) addRow();
      }
      if (!enabled) rows.replaceChildren();
      updateTotal();
    };
    toggle.addEventListener('click', () => setSplit(split.hidden));
    document.getElementById('deskAddPaymentAllocation')?.addEventListener('click', () => addRow());
    rows.addEventListener('input', updateTotal);
    rows.addEventListener('change', event => { updateTotal(); if (event.target.matches('select[name$="[payment_mode_id]"]')) syncCheque(); });
    rows.addEventListener('click', event => { if (event.target.closest('[data-remove-desk-payment]')) { event.target.closest('[data-desk-payment-row]')?.remove(); updateTotal(); } });
    if (Array.isArray(oldLines) && oldLines.length) setSplit(true);
  }
  document.querySelectorAll('[data-quote]').forEach(btn => btn.addEventListener('click', () => {
    const form = btn.closest('form');
    const split = form.querySelector('#deskSplitPayment');
    if (split && !split.hidden && form.querySelectorAll('[data-desk-payment-row]').length < 2) {
      const p = form.querySelector('.quote'); if (p) p.textContent = 'Add at least two payment methods, or switch back to one method.';
      return;
    }
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
