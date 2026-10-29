@extends('layouts.app')
@section('title', 'Ticket '.$s->number)
@section('crumbs') Parking &amp; Valet / <a href="{{ route('parking.sessions') }}">Tickets</a> / {{ $s->number }} @endsection
@php $canOp = \App\Services\ParkingService::canOperate(auth()->user()); $canMg = \App\Services\ParkingService::canManage(auth()->user()); $canVal = auth()->user()->hasPermission('parking.validate') || $canOp; @endphp
@section('actions')
  <a class="btn" href="{{ route('parking.ticket', $s) }}" target="_blank"><x-icon name="print"/> Ticket</a>
  @if($s->isActive() && $canOp)
    @if($s->is_valet && $s->status === 'parked')<form method="post" action="{{ route('parking.request', $s) }}" class="inline">@csrf<button class="btn"><x-icon name="bell"/> Request car</button></form>@endif
    @if($s->is_valet && in_array($s->status, ['parked', 'requested']))<form method="post" action="{{ route('parking.ready', $s) }}" class="inline">@csrf<button class="btn">At the door</button></form>@endif
    @if(in_array($s->status, ['requested', 'ready']))<form method="post" action="{{ route('parking.repark', $s) }}" class="inline">@csrf<button class="btn">Re-park</button></form>@endif
    <button class="btn" data-modal="pkMoveModal"><x-icon name="swap"/> Move</button>
  @endif
  @if($s->isActive() && $canMg)<button class="btn btn-danger" data-modal="pkCancelModal">Cancel ticket</button>@endif
@endsection
@section('content')
<div class="g-2-1">
  <div>
    <div class="card"><div class="card-head"><h3><span class="mono">{{ $s->plate }}</span> · {{ $s->vehicle?->description() }}</h3><span class="badge badge-{{ $s->statusTone() }}" id="pkStatus">{{ $s->statusLabel() }}</span></div><div class="card-body">
      <div class="kv-grid">
        <div><span>Ticket</span><b>{{ $s->number }}</b></div>
        <div><span>Type</span>{{ $s->kindLabel() }}</div>
        <div><span>Zone / bay</span>{{ $s->zone->name }}{{ $s->bay ? ' · '.$s->bay : '' }}</div>
        <div><span>Driver</span>{{ $s->guest_name ?? '—' }}{{ $s->phone ? ' · '.$s->phone : '' }}</div>
        @if($s->stay)<div><span>Stay</span><a href="{{ route('reservations.show', $s->stay->reservation_id) }}">Room {{ $s->stay->room?->room_no ?? '—' }}</a> · {{ ucfirst(str_replace('_', ' ', $s->stay->status)) }} · departs {{ $s->stay->departure_date->format('d M') }}</div>@endif
        @if($s->permit)<div><span>Permit</span>{{ $s->permit->number }} · {{ $s->permit->holder_name }} to {{ $s->permit->valid_to->format('d M Y') }}</div>@endif
        <div><span>In</span>{{ $s->entered_at->format('d M Y H:i') }} · {{ $s->parker?->name }}</div>
        <div><span>{{ $s->exited_at ? 'Out' : 'Parked for' }}</span>{{ $s->exited_at ? $s->exited_at->format('d M Y H:i').' · ' : '' }}{{ $s->durationLabel() }}</div>
        @if($s->is_valet)<div><span>Key tag</span><b>{{ $s->key_tag }}</b>{{ $s->fuel ? ' · fuel '.$s->fuel : '' }}</div>@endif
        @if($s->validation_hours)<div><span>Validated</span>{{ $s->validation_hours }} h · {{ $s->validation_ref }} · {{ $s->validator?->name }}</div>@endif
        @if($s->lost_ticket)<div><span>Lost ticket</span><span class="badge badge-red">yes</span></div>@endif
        @if($s->cancel_reason)<div><span>Cancelled</span>{{ $s->cancel_reason }}</div>@endif
      </div>
      @if($s->is_valet)
        <h4 class="mt-1">Intake condition</h4>
        <div class="flex gap-1 wrap" id="pkDamage">@foreach(\App\Models\ParkingSession::PANELS as $k => $l)<span class="badge badge-{{ in_array($k, $s->damage ?? []) ? 'red' : 'slate' }}">{{ $l }}{{ in_array($k, $s->damage ?? []) ? ' – damage' : '' }}</span>@endforeach</div>
        <p class="small mt-1">{{ $s->condition_notes ?: 'No damage notes.' }} @if($s->valuables)<br><b>Valuables:</b> {{ $s->valuables }}@endif</p>
      @endif
      @if($s->notes)<p class="small muted">{{ $s->notes }}</p>@endif
    </div></div>

    @if($s->is_valet)
    <div class="card mt-1"><div class="card-head"><h3>Valet timeline</h3></div><div class="card-body"><ul class="timeline small">
      <li><b>{{ $s->entered_at->format('d M H:i') }}</b> parked by {{ $s->parker?->name ?? '—' }}</li>
      @if($s->requested_at)<li><b>{{ $s->requested_at->format('d M H:i') }}</b> requested via {{ $s->requested_via === 'qr' ? 'the QR on the ticket' : $s->requested_via }}</li>@endif
      @if($s->ready_at && $s->requested_at)<li><b>{{ $s->ready_at->format('d M H:i') }}</b> at the door – {{ $s->runner?->name ?? '—' }}, {{ $s->waitMinutes() }} min {{ $s->waitMinutes() > $cfg['sla_minutes'] ? '(over the '.$cfg['sla_minutes'].' min target)' : '' }}</li>@endif
      @if($s->exited_at)<li><b>{{ $s->exited_at->format('d M H:i') }}</b> {{ $s->status === 'cancelled' ? 'cancelled' : 'handed over by '.($s->exiter?->name ?? '—') }}</li>@endif
    </ul></div></div>
    @endif

    <div class="card flush mt-1"><div class="card-head"><h3>Charges</h3></div><div class="card-body table-wrap"><table class="table table-compact" id="pkCharges"><thead><tr><th>Business date</th><th>What</th><th class="num">Amount</th><th>Folio</th></tr></thead><tbody>
      @forelse($s->charges->sortBy('id') as $c)<tr><td>{{ $c->business_date->format('d M Y') }}</td><td>{{ ['night' => 'Night of '.$c->night?->format('d M'), 'exit' => 'Exit fee', 'lost' => 'Lost ticket'][$c->kind] ?? $c->kind }}</td><td class="num">{{ money($c->amount) }}</td>
        <td>@if($c->folio)<a href="{{ route('folios.show', $c->folio) }}">{{ $c->folio->folio_no }}</a>@endif</td></tr>
      @empty<tr><td colspan="4" class="muted">No charges yet.</td></tr>@endforelse
      @if((float) $s->fee_waived > 0)<tr class="row-amber"><td colspan="2">Waived: {{ $s->waive_reason }}</td><td class="num">{{ money($s->fee_waived) }}</td><td></td></tr>@endif
    </tbody></table></div></div>
  </div>

  <div>
    @if($s->isActive())
    <div class="card" id="exit"><div class="card-head"><h3>Release the vehicle</h3></div><div class="card-body">
      <table class="table table-compact" id="pkQuote"><tbody>
        @forelse($q['lines'] as $l)<tr><td class="small">{{ $l['label'] }}</td><td class="num">{{ money($l['amount']) }}</td></tr>@empty<tr><td class="muted small" colspan="2">Nothing to pay{{ $s->kind === 'staff' ? ' – permit holder' : ($s->kind === 'guest' ? ' – nights already on the room folio' : ' – within the free period') }}.</td></tr>@endforelse
        @if($q['net'] > 0)<tr><td class="small">Taxes</td><td class="num">{{ money($q['tax']) }}</td></tr><tr class="bold-row"><td>Due</td><td class="num">{{ money($q['total']) }}</td></tr>@endif
      </tbody></table>
      @if($qlost['net'] > $q['net'])<p class="tiny muted">Lost ticket: + {{ money($cfg['lost_fee']) }} (plus tax) – check the driver's ID and the vehicle papers first.</p>@endif
      @if($canOp)
      <form method="post" action="{{ route('parking.exit', $s) }}" id="pkExitForm" data-confirm="Release {{ $s->plate }}?">@csrf
        <div class="form-grid">
          @if($s->kind === 'guest' && $s->stay?->status === 'checked_in')<div class="field span-all"><label>Settle</label><select name="settle" data-parking-settle><option value="room">Post to room {{ $s->stay->room?->room_no }}</option><option value="pay">Pay now</option></select></div>@endif
          <div id="pkDirectTender-{{ $s->id }}" data-parking-direct-tender @if($s->kind === 'guest' && $s->stay?->status === 'checked_in') hidden @endif>
            <div id="pkSingleTender-{{ $s->id }}" class="form-grid">
              <div class="field"><label>Payment mode</label><select name="payment_mode_id" data-payment-mode><option value="">—</option>@foreach($modes as $m)<option value="{{ $m->id }}">{{ $m->name }}</option>@endforeach</select></div>
              <x-field name="momo_network" label="MoMo network"/><x-field name="momo_phone" label="MoMo phone"/>
              <div class="field span-all"><label>Reference</label><input name="reference" maxlength="80" placeholder="MoMo / card ref"></div>
              <x-cheque-fields class="span-all"/>
            </div>
            <x-payment-split-tender widget-id="pkSplitTender-{{ $s->id }}" single-id="pkSingleTender-{{ $s->id }}" :modes="$splitModes" label="payment"/>
          </div>
          @if($s->kind !== 'staff')<div class="field span-all"><label class="check"><input type="checkbox" name="lost_ticket" value="1"> Lost ticket</label></div>@endif
          @if($canMg)<div class="field span-all"><label class="check"><input type="checkbox" name="waive" value="1"> Waive the charge (manager)</label><input name="waive_reason" maxlength="190" placeholder="reason for waiving" class="mt-1"></div>@endif
        </div>
        <button class="btn btn-primary mt-1">{{ $s->is_valet ? 'Hand over the car' : 'Release' }}</button>
      </form>
      @push('scripts')
      <script>
      (() => {
        const form = document.getElementById('pkExitForm');
        const direct = form?.querySelector('[data-parking-direct-tender]');
        const settle = form?.querySelector('[data-parking-settle]');
        if (!form || !direct) return;
        const sync = () => {
          const toRoom = settle && settle.value === 'room';
          if (toRoom) {
            const widget = direct.querySelector('[data-split-tender-widget]');
            if (widget && !widget.querySelector('[data-split-panel]')?.hidden) widget.querySelector('[data-split-toggle]')?.click();
          }
          direct.hidden = Boolean(toRoom);
          direct.querySelectorAll('input,select,textarea').forEach(input => { input.disabled = Boolean(toRoom); });
        };
        settle?.addEventListener('change', sync);
        sync();
      })();
      </script>
      @endpush
      @endif
    </div></div>
    @if($s->kind === 'visitor' && $canVal && $cfg['validation_max'] > 0)
    <div class="card mt-1"><div class="card-head"><h3>Validate</h3></div><div class="card-body">
      <form method="post" action="{{ route('parking.validate', $s) }}" class="form-grid" id="pkValidateForm">@csrf
        <div class="field"><label>Free hours</label><select name="hours">@for($h = 1; $h <= $cfg['validation_max']; $h++)<option value="{{ $h }}" @selected($h === (int) ($s->validation_hours ?: $cfg['validation_max']))>{{ $h }} h</option>@endfor</select></div>
        <div class="field"><label>Bill / outlet ref</label><input name="reference" maxlength="60" value="{{ $s->validation_ref }}" required placeholder="BIL-00123 restaurant"></div>
        <div class="field span-all"><button class="btn">Validate ticket</button></div>
      </form>
    </div></div>
    @endif
    @else
    <div class="card"><div class="card-body">
      <div class="kv-grid">
        <div><span>Charged</span>{{ money($s->fee) }}{{ (float) $s->fee_waived > 0 ? ' · waived '.money($s->fee_waived) : '' }}</div>
        @if($s->folio)<div><span>Folio</span><a href="{{ route('folios.show', $s->folio) }}">{{ $s->folio->folio_no }}</a>{{ $s->folio->invoice_no ? ' · '.$s->folio->invoice_no : '' }}</div>@endif
        @if($s->paymentMode)<div><span>Paid by</span>{{ $s->paymentMode->name }}</div>@endif
      </div>
    </div></div>
    @endif
    <div class="card flush mt-1"><div class="card-head"><h3>Earlier visits</h3></div><div class="card-body table-wrap"><table class="table table-compact"><tbody>
      @forelse($history as $h)<tr><td><a href="{{ route('parking.session', $h) }}">{{ $h->number }}</a></td><td class="small">{{ $h->entered_at->format('d M Y') }}</td><td class="small">{{ $h->kindLabel() }}</td><td class="num small">{{ (float) $h->fee > 0 ? money($h->fee) : '' }}</td></tr>
      @empty<tr><td class="muted small">First visit.</td></tr>@endforelse
    </tbody></table></div></div>
  </div>
</div>
@if($s->isActive() && $canOp)
<div class="modal" id="pkMoveModal"><div class="modal-box"><form method="post" action="{{ route('parking.move', $s) }}">@csrf
  <div class="modal-head"><h3>Move {{ $s->plate }}</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field"><label>Zone</label><select name="parking_zone_id">@foreach($zones as $z)<option value="{{ $z->id }}" @selected($z->id === $s->parking_zone_id)>{{ $z->code }} · {{ $z->name }}</option>@endforeach</select></div>
    <div class="field"><label>Bay</label><input name="bay" value="{{ $s->bay }}" maxlength="20"></div>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Move</button></div>
</form></div></div>
@endif
@if($s->isActive() && $canMg)
<div class="modal" id="pkCancelModal"><div class="modal-box"><form method="post" action="{{ route('parking.cancel', $s) }}">@csrf
  <div class="modal-head"><h3>Cancel ticket {{ $s->number }}</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body"><div class="field"><label>Reason</label><input name="reason" maxlength="190" required placeholder="issued in error, duplicate…"></div><p class="tiny muted">Only tickets without charges can be cancelled – otherwise release the vehicle.</p></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Back</button><button class="btn btn-danger">Cancel ticket</button></div>
</form></div></div>
@endif
@endsection
