@extends('layouts.app')
@section('title', 'Car Rental')

@section('actions')
<a class="btn" href="{{ route('rentals.fleet') }}"><x-icon name="truck"/> Fleet</a>
@if(auth()->user()->hasPermission('rentals.manage'))<button class="btn btn-primary" data-modal="bookModal" id="bookBtn"><x-icon name="plus"/> New rental</button>@endif
@endsection

@section('content')
@if (session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if ($errors->any())<div class="alert alert-danger">{{ collect($errors->all())->implode(' ') }}</div>@endif

<div class="grid grid-2">
  <div class="card">
    <div class="card-head"><h3>Pick-ups due</h3></div>
    <div class="card-body table-wrap">
      @if ($dueOut->isEmpty())
        <x-empty text="No pick-ups due." icon="truck"/>
      @else
        <table class="table table-compact">
          <thead><tr><th>Ref</th><th>Vehicle</th><th>Customer</th><th>Pick-up</th><th>Return due</th><th>Total</th><th>Desk</th></tr></thead>
          <tbody>
            @foreach ($dueOut as $r)
            <tr>
              <td class="mono small">{{ $r->reference }}</td>
              <td>{{ $r->vehicle->reg_no }} <span class="muted small">{{ $r->vehicle->make }} {{ $r->vehicle->model }}</span></td>
              <td>{{ $r->customer_name }}</td>
              <td class="small">{{ $r->pickup_at->format('D j M H:i') }}</td>
              <td class="small">{{ $r->return_due_at->format('D j M H:i') }}</td>
              <td class="bold">GH₵ {{ number_format($r->total, 2) }}</td>
              <td>
                @if(auth()->user()->hasPermission('rentals.manage'))
                <form method="post" action="{{ route('rentals.pickup', $r) }}" class="inline">@csrf
                  <button class="btn btn-sm btn-primary" data-confirm="Hand over the keys — pick up {{ $r->reference }}?"><x-icon name="check"/> Pick up</button>
                </form>
                <form method="post" action="{{ route('rentals.cancel', $r) }}" class="inline">@csrf
                  <button class="btn btn-sm" data-confirm="Cancel {{ $r->reference }}?"><x-icon name="x"/></button>
                </form>
                @endif
              </td>
            </tr>
            @endforeach
          </tbody>
        </table>
      @endif
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h3>Returns due</h3></div>
    <div class="card-body table-wrap">
      @if ($dueIn->isEmpty())
        <x-empty text="No returns expected." icon="truck"/>
      @else
        <table class="table table-compact">
          <thead><tr><th>Ref</th><th>Vehicle</th><th>Customer</th><th>Return due</th><th>Total</th><th>Desk</th></tr></thead>
          <tbody>
            @foreach ($dueIn as $r)
            <tr>
              <td class="mono small">{{ $r->reference }}</td>
              <td>{{ $r->vehicle->reg_no }}</td>
              <td>{{ $r->customer_name }}</td>
              <td class="small {{ $r->return_due_at->isPast() ? 'bold' : '' }}">{{ $r->return_due_at->format('D j M H:i') }}</td>
              <td class="bold">GH₵ {{ number_format($r->total, 2) }}</td>
              <td>
                @if(auth()->user()->hasPermission('rentals.manage'))
                <form method="post" action="{{ route('rentals.return', $r) }}" class="inline">@csrf
                  <button class="btn btn-sm btn-primary" data-confirm="Take the car back — record {{ $r->reference }} as returned?"><x-icon name="check"/> Return</button>
                </form>
                @if ($r->stay_id)
                <form method="post" action="{{ route('rentals.bill', $r) }}" class="inline">@csrf
                  <button class="btn btn-sm" data-confirm="Bill GH₵ {{ number_format($r->total, 2) }} to the guest folio?">Bill folio</button>
                </form>
                @endif
                @endif
              </td>
            </tr>
            @endforeach
          </tbody>
        </table>
      @endif
    </div>
  </div>
</div>

<div class="card">
  <div class="card-head"><h3>Fleet at a glance</h3></div>
  <div class="card-body table-wrap">
    <table class="table table-compact">
      <thead><tr><th>Reg</th><th>Vehicle</th><th>Class</th><th>Rate / day</th><th>Status</th></tr></thead>
      <tbody>
        @foreach ($fleet as $v)
        <tr>
          <td class="mono">{{ $v->reg_no }}</td>
          <td>{{ $v->make }} {{ $v->model }} <span class="muted small">{{ $v->seats }} seats · {{ $v->transmission }}</span></td>
          <td>{{ $v->classLabel() }}</td>
          <td>GH₵ {{ number_format($v->daily_rate, 2) }}</td>
          <td><span class="badge badge-{{ $v->statusTone() }}">{{ $v->statusLabel() }}</span></td>
        </tr>
        @endforeach
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-head"><h3>Recent bookings</h3></div>
  <div class="card-body table-wrap">
    <table class="table table-compact">
      <thead><tr><th>Ref</th><th>Vehicle</th><th>Customer</th><th>Window</th><th>Days</th><th>Adjust</th><th>Total</th><th>Status</th></tr></thead>
      <tbody>
        @foreach ($bookings as $r)
        <tr>
          <td class="mono small">{{ $r->reference }}</td>
          <td>{{ $r->vehicle->reg_no }}</td>
          <td>{{ $r->customer_name }}</td>
          <td class="small">{{ $r->pickup_at->format('j M') }} – {{ $r->return_due_at->format('j M') }}</td>
          <td class="small">{{ max(1, (int) ceil($r->pickup_at->diffInHours($r->return_due_at) / 24)) }}</td>
          <td class="small">{{ $r->adjustments ? 'GH₵ '.number_format($r->adjustments, 2) : '—' }}</td>
          <td class="bold">GH₵ {{ number_format($r->total, 2) }}</td>
          <td><span class="badge badge-{{ $r->statusTone() }}">{{ $r->statusLabel() }}</span></td>
        </tr>
        @endforeach
      </tbody>
    </table>
  </div>
</div>

@if(auth()->user()->hasPermission('rentals.manage'))
<div class="modal" id="bookModal"><div class="modal-box">
  <form method="post" action="{{ route('rentals.store') }}">@csrf
    <div class="modal-head"><h3>New car rental</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
    <div class="modal-body">
      <div class="x-field"><label>Vehicle</label>
        <select name="rental_vehicle_id" required>
          <option value="">— choose —</option>
          @foreach ($vehicles as $v)
          <option value="{{ $v->id }}" {{ $v->status === 'available' ? '' : 'disabled' }}>{{ $v->reg_no }} — {{ $v->make }} {{ $v->model }} ({{ $v->classLabel() }}, GH₵ {{ number_format($v->daily_rate, 2) }}/day){{ $v->status !== 'available' ? ' — '.$v->statusLabel() : '' }}</option>
          @endforeach
        </select>
      </div>
      <div class="grid grid-2">
        <div class="x-field"><label>Customer name</label><input name="customer_name" maxlength="120" required></div>
        <div class="x-field"><label>Phone</label><input name="customer_phone" maxlength="40"></div>
      </div>
      <div class="grid grid-2">
        <div class="x-field"><label>Driving licence no.</label><input name="license_no" maxlength="60"></div>
        <div class="x-field"><label>Bill to stay (optional)</label>
          <select name="reservation_room_id"><option value="">— direct settlement —</option>
            @foreach ($stays as $s)<option value="{{ $s->id }}">{{ $s->room->number ?? '?' }} — {{ $s->reservation->reference ?? '' }}</option>@endforeach
          </select>
        </div>
      </div>
      <div class="grid grid-2">
        <div class="x-field"><label>Pick-up</label><input type="datetime-local" name="pickup_at" value="{{ now()->format('Y-m-d\TH:i') }}" required></div>
        <div class="x-field"><label>Return due</label><input type="datetime-local" name="return_due_at" value="{{ now()->addDays(3)->format('Y-m-d\TH:i') }}" required></div>
      </div>
      <div class="x-field"><label>Extras (GH₵ — GPS, child seat …)</label><input type="number" step="0.01" min="0" name="extras" value="0"></div>
      <div class="x-field"><label>Notes</label><textarea name="notes" rows="2"></textarea></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary" id="doBook">Book rental</button></div>
  </form>
</div></div>
@endif
@endsection
