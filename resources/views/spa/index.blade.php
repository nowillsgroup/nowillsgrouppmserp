@extends('layouts.app')
@section('title', 'Spa & Activities')
@section('actions')
<form class="filters">
  <a class="btn btn-icon" href="{{ route('spa.index', ['date' => \Carbon\Carbon::parse($date)->subDay()->toDateString()]) }}" title="Previous day">‹</a>
  <div class="field"><input type="date" name="date" value="{{ $date }}" data-autosubmit></div>
  <a class="btn btn-icon" href="{{ route('spa.index', ['date' => \Carbon\Carbon::parse($date)->addDay()->toDateString()]) }}" title="Next day">›</a>
</form>
@if(can_do('spa.manage'))<button class="btn btn-primary" data-modal="apptModal" data-action="{{ route('spa.store') }}" data-f_method="POST" data-title="New appointment" data-fdate="{{ $date }}"><x-icon name="plus"/> New Booking</button>@endif
@endsection
@section('content')
<div class="grid g-4 mb-1">
  <div class="stat tone-blue"><div class="ico"><x-icon name="calendar"/></div><div><div class="v">{{ $stats['booked'] }}</div><div class="l">Upcoming / in progress</div></div></div>
  <div class="stat tone-green"><div class="ico"><x-icon name="check"/></div><div><div class="v">{{ $stats['done'] }}</div><div class="l">Completed</div></div></div>
  <div class="stat tone-teal"><div class="ico"><x-icon name="money"/></div><div><div class="v">{{ money($stats['revenue']) }}</div><div class="l">Revenue (before tax)</div></div></div>
  <div class="stat tone-red"><div class="ico"><x-icon name="x"/></div><div><div class="v">{{ $stats['cancelled'] }}</div><div class="l">Cancelled / no-show</div></div></div>
</div>

@if($services->isEmpty())
  <div class="alert alert-info">No services yet. Add treatments and activities under <a href="{{ route('masters.index', 'spa-services') }}">Spa &amp; Activities › Services</a>.</div>
@endif

<div class="spa-cal mb-1">
  <table>
    <thead><tr><th style="width:64px"></th>@foreach($cols as $id => $name)<th>{{ $name }}</th>@endforeach</tr></thead>
    <tbody>
    @php $skip = []; @endphp
    @foreach($slots as $i => $slot)
      <tr><td class="t">{{ $slot }}</td>
      @foreach($cols as $cid => $cname)
        @if(($skip[$cid] ?? -1) >= $i) @continue @endif
        @php $cell = $grid[$cid][$i] ?? null; @endphp
        @if($cell)
          @php $span = max(array_column($cell, 'span')); $skip[$cid] = $i + $span - 1; @endphp
          <td rowspan="{{ $span }}">
            @foreach($cell as $c) @php $a = $c['a']; @endphp
              <a href="#appt-{{ $a->id }}" class="appt st-{{ $a->status }}" title="{{ $a->service->name }} – {{ $a->customer_name }}"><b>{{ substr($a->start_time,0,5) }} {{ $a->service->name }}</b>{{ $a->customer_name }}{{ $a->stay?->room ? ' · Rm '.$a->stay->room->room_no : '' }}{{ $a->spaRoom ? ' · '.$a->spaRoom->name : '' }}</a>
            @endforeach
          </td>
        @elseif(can_do('spa.manage') && $cid)
          <td class="free" data-modal="apptModal" data-action="{{ route('spa.store') }}" data-f_method="POST" data-title="New appointment – {{ $cname }}" data-fdate="{{ $date }}" data-fstart_time="{{ $slot }}" data-femployee_id="{{ $cid }}" title="Book {{ $cname }} at {{ $slot }}"></td>
        @else
          <td></td>
        @endif
      @endforeach
      </tr>
    @endforeach
    </tbody>
  </table>
  @if(count($cols) === 0)<div class="card-body muted">No therapists. Add employees to the “Spa &amp; Wellness” department.</div>@endif
</div>

<div class="card"><div class="card-head"><h3>Appointments · {{ \Carbon\Carbon::parse($date)->format('D, d M Y') }}</h3></div>
<div class="table-wrap"><table class="table">
  <thead><tr><th>No.</th><th>Time</th><th>Service</th><th>Guest</th><th>Therapist / room</th><th class="num">Pax</th><th class="num">Net</th><th>Status</th><th></th></tr></thead>
  <tbody>
  @forelse($appts as $a)
    <tr id="appt-{{ $a->id }}">
      <td class="nowrap">{{ $a->appt_no }}</td><td class="nowrap">{{ substr($a->start_time,0,5) }}–{{ substr($a->end_time,0,5) }}</td>
      <td>{{ $a->service->name }}<div class="tiny muted">{{ \App\Models\SpaService::CATEGORIES[$a->service->category] ?? $a->service->category }}</div></td>
      <td>{{ $a->customer_name }}<div class="tiny muted">{{ $a->stay?->room ? 'In-house · Room '.$a->stay->room->room_no : 'Walk-in' }}{{ $a->customer_phone ? ' · '.$a->customer_phone : '' }}</div></td>
      <td>{{ $a->therapist?->name ?? '—' }}<div class="tiny muted">{{ $a->spaRoom?->name }}</div></td>
      <td class="num">{{ $a->pax }}</td><td class="num">{{ money($a->net()) }}@if($a->discount > 0)<div class="tiny muted">−{{ money($a->discount) }}</div>@endif</td>
      <td>@php $b = ['booked'=>'blue','in_progress'=>'amber','completed'=>'green','cancelled'=>'slate','no_show'=>'red'][$a->status] ?? 'slate'; @endphp
        <span class="badge badge-{{ $b }}">{{ ucwords(str_replace('_',' ',$a->status)) }}</span>
        @if($a->status === 'completed')<div class="tiny muted">{{ ['room_post'=>'Posted to room','payment'=>'Paid','complimentary'=>'Complimentary'][$a->settlement] ?? '' }}@if($a->folio) · <a href="{{ route('folios.show', $a->folio) }}">{{ $a->folio->folio_no }}</a>@endif</div>@endif
        @if($a->cancel_reason)<div class="tiny muted">{{ $a->cancel_reason }}</div>@endif</td>
      <td class="nowrap">
      @if(can_do('spa.manage') && $a->isOpen())
        @if($a->status === 'booked')
          <form method="post" action="{{ route('spa.status', $a) }}" style="display:inline">@csrf<input type="hidden" name="status" value="in_progress"><button class="btn btn-sm btn-soft">Start</button></form>
        @endif
        <button class="btn btn-sm btn-success" data-modal="settleModal" data-action="{{ route('spa.settle', $a) }}" data-title="Settle {{ $a->appt_no }} · {{ money($a->net()) }} + tax" data-fsettlement="{{ $a->stay && $a->stay->status === 'checked_in' ? 'room_post' : 'payment' }}">Complete &amp; settle</button>
        @if($a->status === 'booked')
          <button class="btn btn-sm" data-modal="apptModal" data-action="{{ route('spa.update', $a) }}" data-title="Edit {{ $a->appt_no }}" data-f_method="PUT"
            data-fspa_service_id="{{ $a->spa_service_id }}" data-femployee_id="{{ $a->employee_id }}" data-fspa_room_id="{{ $a->spa_room_id }}" data-fdate="{{ $a->date->toDateString() }}"
            data-fstart_time="{{ substr($a->start_time,0,5) }}" data-fpax="{{ $a->pax }}" data-freservation_room_id="{{ $a->reservation_room_id }}" data-fcustomer_name="{{ $a->reservation_room_id ? '' : $a->customer_name }}"
            data-fcustomer_phone="{{ $a->reservation_room_id ? '' : $a->customer_phone }}" data-fprice="{{ $a->price }}" data-fdiscount="{{ $a->discount }}" data-fnotes="{{ $a->notes }}"><x-icon name="edit" size="sm"/></button>
          <button class="btn btn-sm btn-ghost" data-modal="cancelModal" data-action="{{ route('spa.status', $a) }}" data-title="Cancel {{ $a->appt_no }}">Cancel</button>
          <form method="post" action="{{ route('spa.status', $a) }}" style="display:inline" data-confirm="Mark {{ $a->appt_no }} as no-show?">@csrf<input type="hidden" name="status" value="no_show"><button class="btn btn-sm btn-ghost">No-show</button></form>
        @endif
      @endif
      </td>
    </tr>
  @empty <tr><td colspan="9" class="muted">No appointments on this day.</td></tr> @endforelse
  </tbody>
</table></div></div>

<div class="modal" id="apptModal"><div class="modal-box"><form method="post" action="{{ route('spa.store') }}" data-reset>@csrf<input type="hidden" name="_method" value="POST">
  <div class="modal-head"><h3 data-title>New appointment</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    <div class="field span-all"><label for="spa_service_id">Service / activity</label><select name="spa_service_id" id="spa_service_id" required>
      <option value="">— Select —</option>
      @foreach($services->groupBy('category') as $cat => $list)<optgroup label="{{ \App\Models\SpaService::CATEGORIES[$cat] ?? $cat }}">@foreach($list as $s)<option value="{{ $s->id }}">{{ $s->name }} · {{ $s->duration_min }} min · {{ money($s->price) }}</option>@endforeach</optgroup>@endforeach
    </select></div>
    <x-field name="date" label="Date" type="date" :value="$date" required/>
    <x-field name="start_time" label="Start" type="time" required step="300"/>
    <x-field name="employee_id" label="Therapist / instructor" type="select" :options="$therapists" placeholder="— none —"/>
    <x-field name="spa_room_id" label="Treatment room" type="select" :options="$rooms" placeholder="— none —"/>
    <x-field name="reservation_room_id" label="In-house guest" type="select" :options="$stays" placeholder="— walk-in / outside guest —" class="span-all"/>
    <x-field name="customer_name" label="Walk-in name" placeholder="Required if not in-house"/>
    <x-field name="customer_phone" label="Walk-in phone"/>
    <x-field name="pax" label="Persons" type="number" min="1" max="50" value="1"/>
    <x-field name="price" label="Price (blank = list price × persons)" type="number" step="0.01" min="0"/>
    <x-field name="discount" label="Discount" type="number" step="0.01" min="0" value="0"/>
    <x-field name="notes" label="Notes" placeholder="Preferences, allergies…"/>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Save booking</button></div>
</form></div></div>

<div class="modal" id="settleModal"><div class="modal-box"><form method="post" data-reset>@csrf
  <div class="modal-head"><h3 data-title>Settle</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    <x-field name="settlement" label="Settle by" type="select" :options="['room_post'=>'Post to room (in-house guest)','payment'=>'Direct payment','complimentary'=>'Complimentary']" :placeholder="false" class="span-all"/>
    <div id="spaSingleTender" class="form-grid span-all">
      <x-field name="payment_mode_id" label="Payment mode (direct payment)" type="select" :options="$paymentModes" data-payment-mode/>
      <x-field name="momo_network" label="MoMo network"/><x-field name="momo_phone" label="MoMo phone"/>
      <x-field name="reference" label="Reference" class="span-all"/>
      <x-cheque-fields/>
    </div>
    <x-payment-split-tender widget-id="spaSplitTender" single-id="spaSingleTender" :modes="$paymentModes" label="payment"/>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-success">Complete</button></div>
</form></div></div>

<div class="modal" id="cancelModal"><div class="modal-box"><form method="post" data-reset>@csrf<input type="hidden" name="status" value="cancelled">
  <div class="modal-head"><h3 data-title>Cancel</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="reason" label="Reason" required/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-danger">Cancel appointment</button></div>
</form></div></div>
@endsection
@push('scripts')
<script>
(() => {
  const settlement = document.querySelector('#settleModal [name="settlement"]');
  const widget = document.getElementById('spaSplitTender');
  const toggle = widget?.querySelector('[data-split-toggle]');
  const panel = widget?.querySelector('[data-split-panel]');
  if (!settlement || !toggle || !panel) return;
  const sync = () => {
    const directPayment = settlement.value === 'payment';
    if (!directPayment && !panel.hidden) toggle.click();
    toggle.hidden = !directPayment;
  };
  settlement.addEventListener('change', sync);
  document.addEventListener('click', event => {
    if (event.target.closest('[data-modal="settleModal"]')) setTimeout(sync, 0);
  });
  sync();
})();
</script>
@endpush
