@extends('layouts.app')
@section('title', 'Rental Fleet')

@section('actions')
<a class="btn" href="{{ route('rentals.index') }}"><x-icon name="truck"/> Rental desk</a>
@if(auth()->user()->hasPermission('rentals.manage'))<button class="btn btn-primary" data-modal="vehicleModal" id="addVehicleBtn"><x-icon name="plus"/> Add vehicle</button>@endif
@endsection

@section('content')
@if (session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if ($errors->any())<div class="alert alert-danger">{{ collect($errors->all())->implode(' ') }}</div>@endif

<div class="card">
  <div class="card-head"><h3>Fleet</h3></div>
  <div class="card-body table-wrap">
    <table class="table table-compact">
      <thead><tr><th>Reg</th><th>Vehicle</th><th>Class</th><th>Seats</th><th>Odometer</th><th>Rate / day</th><th>Week</th><th>Deposit</th><th>Status</th>@if(auth()->user()->hasPermission('rentals.manage'))<th></th>@endif</tr></thead>
      <tbody>
        @foreach ($fleet as $v)
        <tr>
          <td class="mono">{{ $v->reg_no }}</td>
          <td>{{ $v->make }} {{ $v->model }} <span class="muted small">{{ $v->transmission }} · {{ $v->fuel }}</span></td>
          <td>{{ $v->classLabel() }}</td>
          <td>{{ $v->seats }}</td>
          <td class="small">{{ number_format($v->odometer) }} km</td>
          <td>GH₵ {{ number_format($v->daily_rate, 2) }}</td>
          <td class="small">{{ $v->weekly_rate ? 'GH₵ '.number_format($v->weekly_rate, 2) : '7 × daily' }}</td>
          <td class="small">GH₵ {{ number_format($v->deposit, 2) }}</td>
          <td><span class="badge badge-{{ $v->statusTone() }}">{{ $v->statusLabel() }}</span></td>
          @if(auth()->user()->hasPermission('rentals.manage'))
          <td>
            <form method="post" action="{{ route('rentals.vehicles.update', $v) }}" class="inline">@csrf
              <input type="hidden" name="daily_rate" value="{{ $v->daily_rate }}">
              <select name="status" onchange="this.form.submit()">
                @foreach (\App\Models\RentalVehicle::STATUSES as $k => $meta)<option value="{{ $k }}" @selected($v->status === $k)>{{ $meta[0] }}</option>@endforeach
              </select>
            </form>
          </td>
          @endif
        </tr>
        @endforeach
      </tbody>
    </table>
  </div>
</div>

@if(auth()->user()->hasPermission('rentals.manage'))
<div class="modal" id="vehicleModal"><div class="modal-box">
  <form method="post" action="{{ route('rentals.vehicles.store') }}">@csrf
    <div class="modal-head"><h3>Add fleet vehicle</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
    <div class="modal-body">
      <div class="grid grid-2">
        <div class="x-field"><label>Registration</label><input name="reg_no" maxlength="40" required></div>
        <div class="x-field"><label>Class</label>
          <select name="class">@foreach (\App\Models\RentalVehicle::CLASSES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
        </div>
      </div>
      <div class="grid grid-2">
        <div class="x-field"><label>Make</label><input name="make" maxlength="60" required></div>
        <div class="x-field"><label>Model</label><input name="model" maxlength="60" required></div>
      </div>
      <div class="grid grid-2">
        <div class="x-field"><label>Seats</label><input type="number" name="seats" value="5" min="1" max="20" required></div>
        <div class="x-field"><label>Transmission</label><select name="transmission"><option>automatic</option><option>manual</option></select></div>
      </div>
      <div class="grid grid-2">
        <div class="x-field"><label>Fuel</label><select name="fuel"><option>petrol</option><option>diesel</option><option>hybrid</option><option>electric</option></select></div>
        <div class="x-field"><label>Daily rate (GH₵)</label><input type="number" step="0.01" min="0" name="daily_rate" required></div>
      </div>
      <div class="grid grid-2">
        <div class="x-field"><label>Weekly rate (GH₵, blank = 7 × daily)</label><input type="number" step="0.01" min="0" name="weekly_rate"></div>
        <div class="x-field"><label>Deposit (GH₵)</label><input type="number" step="0.01" min="0" name="deposit" value="0"></div>
      </div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary" id="doAddVehicle">Add vehicle</button></div>
  </form>
</div></div>
@endif
@endsection
