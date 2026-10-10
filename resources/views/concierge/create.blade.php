@extends('layouts.app')
@section('title', 'New Activity Booking')
@section('crumbs') Concierge / New booking @endsection
@php $A = \App\Models\Activity::class; @endphp
@section('content')
<form method="post" action="{{ route('concierge.store') }}" id="bookingForm" class="g-2-1">@csrf
<div class="card"><div class="card-head"><h3><x-icon name="globe"/> Activity</h3></div><div class="card-body"><div class="form-grid">
  <div class="field span-all"><label for="bActivity">Activity</label>
    <select name="activity_id" id="bActivity" required><option value="">Choose…</option>
    @foreach($activities->groupBy('category') as $cat => $list)<optgroup label="{{ $A::CATEGORIES[$cat] ?? $cat }}">
      @foreach($list as $a)<option value="{{ $a->id }}" @selected(old('activity_id', $pre['activity_id']) == $a->id) data-scheduled="{{ $a->isScheduled() ? 1 : 0 }}" data-times="{{ implode(',', $a->timesList()) }}" data-days="{{ $a->daysLabel() }}"
        data-collection="{{ $a->collectionMode() }}" data-price="{{ $a->priceLabel() }}" data-cutoff="{{ $a->cutoff_hours }}" data-cancel="{{ $a->free_cancel_hours }}" data-fee="{{ (float) $a->cancel_fee_pct }}" data-instant="{{ $a->instant ? 1 : 0 }}"
        data-meet="{{ $a->pickup_included ? 'Hotel pick-up included' : ($a->meeting_point ?: '') }}">{{ $a->code }} · {{ $a->name }} ({{ $a->vendor->name }})</option>@endforeach
    </optgroup>@endforeach</select>
    <div class="tiny muted" id="actInfo"></div></div>
  <div class="field"><label for="bDate">Date</label><input type="date" name="date" id="bDate" value="{{ old('date', $pre['date']) }}" min="{{ bdate()->toDateString() }}" required></div>
  <div class="field"><label for="bTime">Time</label><select name="time" id="bTime"><option value="{{ old('time', $pre['time']) }}">{{ old('time', $pre['time']) ?: '—' }}</option></select><input type="time" id="bTimeFree" value="{{ old('time', '09:00') }}" style="display:none" aria-label="Start time"></div>
  <div class="field"><label for="bAdults">Adults</label><input type="number" name="adults" id="bAdults" min="1" max="30" value="{{ old('adults', 2) }}" required></div>
  <div class="field"><label for="bChildren">Children</label><input type="number" name="children" id="bChildren" min="0" max="30" value="{{ old('children', 0) }}"></div>
  <div class="field"><label for="bPickup">Pick-up point</label><input name="pickup_point" id="bPickup" maxlength="150" value="{{ old('pickup_point') }}" placeholder="blank = operator's meeting point"></div>
  <div class="field"><label for="bPickupTime">Pick-up time</label><input type="time" name="pickup_time" id="bPickupTime" value="{{ old('pickup_time') }}"></div>
  <div class="field span-all"><label for="bNotes">Notes for the operator</label><input name="notes" id="bNotes" maxlength="500" value="{{ old('notes') }}" placeholder="Dietary needs, mobility, language, child seats…"></div>
  <div class="field"><label for="bRef">Vendor reference (if already confirmed)</label><input name="vendor_ref" id="bRef" maxlength="60" value="{{ old('vendor_ref') }}"></div>
  <div class="field"><label for="bSource">Taken by</label><select name="source" id="bSource"><option value="desk">Concierge desk</option><option value="phone" @selected(old('source') === 'phone')>Phone / e-mail</option></select></div>
  <label class="flex gap-1 span-all" style="align-items:center"><input type="checkbox" name="waitlist" value="1" id="bWaitlist" @checked(old('waitlist'))> If the departure is full, put the guest on the waitlist</label>
  @if(can_do('concierge.manage'))<label class="flex gap-1 span-all small" style="align-items:center"><input type="checkbox" name="override_cutoff" value="1" id="bOverride"> Book inside the operator's cut-off (the vendor has agreed)</label>@endif
</div></div></div>

<div>
<div class="card"><div class="card-head"><h3><x-icon name="user"/> Guest</h3></div><div class="card-body"><div class="form-grid">
  <div class="field span-all"><label for="bStay">Staying guest</label><select name="reservation_room_id" id="bStay"><option value="">— Not staying (non-resident / walk-in) —</option>
    @foreach($stays as $s) @php $g = $s->guest ?? $s->reservation?->guest; @endphp<option value="{{ $s->id }}" @selected(old('reservation_room_id', $pre['reservation_room_id']) == $s->id) data-name="{{ $g?->full_name }}" data-phone="{{ $g?->phone }}" data-from="{{ $s->arrival_date->toDateString() }}" data-to="{{ $s->departure_date->toDateString() }}">
      {{ $s->status === 'checked_in' ? 'Room '.($s->room?->room_no ?? '—') : 'Arriving '.$s->arrival_date->format('d M') }} · {{ $g?->full_name }} ({{ $s->arrival_date->format('d M') }}–{{ $s->departure_date->format('d M') }})</option>@endforeach</select></div>
  <div class="field span-all"><label for="bName">Guest name</label><input name="guest_name" id="bName" maxlength="150" value="{{ old('guest_name') }}" placeholder="filled from the stay"></div>
  <div class="field"><label for="bPhone">Mobile</label><input name="phone" id="bPhone" maxlength="30" value="{{ old('phone') }}"></div>
  <div class="field"><label for="bEmail">E-mail</label><input type="email" name="email" id="bEmail" maxlength="150" value="{{ old('email') }}"></div>
  <div class="field span-all"><label for="bCollection">Payment</label><select name="collection" id="bCollection">@foreach(\App\Models\ActivityVendor::COLLECTION as $k => $l)<option value="{{ $k }}" @selected(old('collection') === $k)>{{ $l }}</option>@endforeach</select>
    <div class="tiny muted">Room bill: the hotel collects and pays the operator less commission. Pay the vendor: the operator collects and owes the hotel its commission.</div></div>
</div></div></div>
<div class="card mt-1" id="quoteCard"><div class="card-head"><h3><x-icon name="receipt"/> Price</h3></div><div class="card-body">
  <div class="kv"><span>Guest pays</span><b id="qGross">—</b></div>
  <div class="kv"><span>Hotel commission</span><span id="qComm">—</span></div>
  <div class="kv"><span>Owed to the operator</span><span id="qNet">—</span></div>
  <div class="tiny muted mt-1" id="qTerms"></div>
  <button class="btn btn-primary btn-block mt-1" id="bSubmit"><x-icon name="check"/> Book</button>
</div></div>
</div>
</form>
<script>
(() => {
  const $ = id => document.getElementById(id), act = $('bActivity'), date = $('bDate'), time = $('bTime'), free = $('bTimeFree'), ad = $('bAdults'), ch = $('bChildren'), stay = $('bStay'), col = $('bCollection');
  const fmt = v => 'GH₵ ' + Number(v).toLocaleString('en-GH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  let want = time.value;
  function refresh() {
    const o = act.selectedOptions[0];
    if (!o || !o.value) { $('actInfo').textContent = ''; return; }
    $('actInfo').textContent = o.dataset.price + ' · ' + o.dataset.days + (o.dataset.meet ? ' · ' + o.dataset.meet : '') + (o.dataset.instant === '1' ? ' · instant confirmation' : ' · the vendor confirms');
    $('qTerms').textContent = 'Books until ' + o.dataset.cutoff + ' h before. Free cancellation until ' + o.dataset.cancel + ' h before; then ' + o.dataset.fee + '% of the price.';
    const sch = o.dataset.scheduled === '1';
    time.style.display = sch ? '' : 'none'; free.style.display = sch ? 'none' : '';
    time.name = sch ? 'time' : ''; free.name = sch ? '' : 'time';
    fetch('{{ route('concierge.slots') }}?activity=' + o.value + '&date=' + date.value + '&adults=' + (ad.value || 1) + '&children=' + (ch.value || 0), {headers: {'Accept': 'application/json'}})
      .then(r => r.json()).then(j => {
        if (sch) {
          time.innerHTML = '';
          if (!j.runs) { time.innerHTML = '<option value="">Not running – ' + (j.days || '') + '</option>'; }
          (j.slots || []).forEach(s => { const op = document.createElement('option'); op.value = s.time; op.textContent = s.time + (s.status !== 'open' ? ' – ' + s.status : (s.left === null ? '' : ' – ' + s.left + ' seat(s) left')); if (s.time === want) op.selected = true; time.appendChild(op); });
        }
        if (j.quote) { $('qGross').textContent = fmt(j.quote.gross); $('qComm').textContent = fmt(j.quote.commission) + ' (' + j.quote.commission_pct + '%)'; $('qNet').textContent = fmt(j.quote.vendor_net); }
      }).catch(() => {});
  }
  function stayChanged() {
    const o = stay.selectedOptions[0];
    date.max = o && o.value ? o.dataset.to : '';
    if (o && o.value && date.value > o.dataset.to) { date.value = o.dataset.to; refresh(); }
    if (o && o.value) { if (!$('bName').value) $('bName').value = o.dataset.name || ''; if (!$('bPhone').value) $('bPhone').value = o.dataset.phone || ''; col.value = act.selectedOptions[0]?.dataset.collection || 'folio'; }
    else { col.value = 'vendor'; }
  }
  act.addEventListener('change', () => { want = ''; const o = act.selectedOptions[0]; if (o && o.value) col.value = stay.value ? o.dataset.collection : 'vendor'; refresh(); });
  [date, ad, ch].forEach(e => e.addEventListener('change', refresh));
  time.addEventListener('change', () => { want = time.value; });
  stay.addEventListener('change', stayChanged);
  if (!col.dataset.touched && !{{ old('collection') ? 'true' : 'false' }}) { stay.value ? stayChanged() : (col.value = 'vendor'); }
  refresh();
})();
</script>
@endsection
