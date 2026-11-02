@extends('layouts.app')
@section('title', 'Robita Devices')
@section('crumbs') Interfaces / Robita Locks / Devices @endsection
@section('actions')
  <a class="btn" href="{{ route('robita.index') }}"><x-icon name="grid"/> Dashboard</a>
  <a class="btn" href="{{ route('robita.events') }}"><x-icon name="clock"/> Events</a>
  <button class="btn btn-primary" data-modal="registerDevice" id="registerDeviceBtn"><x-icon name="plus"/> Add Demo Lock</button>
@endsection
@section('content')
@php $c = $counts; @endphp

<div class="alert alert-warning" style="margin-bottom:.8rem"><x-icon name="alert"/><div><b>Demo mode.</b> Every action on this page changes local PMS state only. Status, battery, signal and event data are not proof of hardware connectivity.</div></div>
<div class="stats">
  <x-stat :value="$c['robita_devices_total']" label="Registered records" icon="lock" tone="blue"/>
  <x-stat :value="$c['robita_devices_demo']" label="Demo records" icon="building" tone="slate"/>
  <x-stat :value="$c['robita_low_battery']" label="Reported low battery" icon="alert" :tone="$c['robita_low_battery'] ? 'amber' : 'green'"/>
  <x-stat :value="$c['robita_credentials_active']" label="Active local credentials" icon="key" tone="green"/>
</div>

<div class="card flush">
  <div class="card-head"><h3><x-icon name="lock" size="sm"/> Device Registry</h3></div>
  <div class="card-body table-wrap">
    <table class="table table-compact">
      <thead><tr><th>Device</th><th>Room / Location</th><th>Type</th><th>State</th><th>Battery</th><th>Signal</th><th>Events</th><th>Last local event</th><th class="text-right">Actions</th></tr></thead>
      <tbody>
      @forelse($devices as $device)
        <tr>
          <td><b>{{ $device->name }}</b><div class="small muted">{{ $device->serial }} · {{ $device->model }}</div></td>
                            <td>
                                @if($device->room)
                                    <b>{{ $device->room->room_no }}</b><div class="small muted">{{ $device->room->floor?->name ?? '' }}</div>
                                @else
                                    <span class="muted">{{ $device->location ?? '—' }}</span>
                                @endif
                            </td>
          <td><span class="badge badge-slate">{{ ucfirst(str_replace('_', ' ', $device->device_type)) }}</span></td>
          <td>
            @if($device->status === 'decommissioned')
              <span class="badge badge-slate">Demo record off</span>
            @elseif($device->status === 'demo')
              <span class="badge badge-blue">Demo · {{ $device->lock_state ?: 'unknown' }}</span>
            @else
              <span class="badge badge-amber">Legacy status · not verified</span>
            @endif
          </td>
          <td>
            @if($device->battery_pct !== null)
              <span class="badge badge-{{ $device->batteryTone() }}">{{ $device->battery_pct }}%</span>
            @else<span class="muted">—</span>@endif
          </td>
          <td>
            @if($device->signal)<span class="badge badge-{{ $device->signalTone() }}">{{ ucfirst($device->signal) }}</span>
            @else<span class="muted">—</span>@endif
          </td>
          <td class="small muted">{{ $device->event_count ?? 0 }}</td>
          <td class="small muted">{{ $device->last_seen_at ? $device->last_seen_at->diffForHumans() : '—' }}</td>
          <td class="text-right">
            @if($device->status !== 'decommissioned')
              @if($device->isLocked())
                <form method="post" action="{{ route('robita.devices.lock', $device) }}" style="display:inline" data-confirm="Simulate unlocking {{ $device->name }}? No hardware will be actuated.">@csrf<button class="btn btn-sm" title="Simulate unlock"><x-icon name="check" size="sm"/> Unlock demo</button></form>
              @else
                <form method="post" action="{{ route('robita.devices.lock', $device) }}" style="display:inline" data-confirm="Simulate locking {{ $device->name }}? No hardware will be actuated.">@csrf<button class="btn btn-sm" title="Simulate lock"><x-icon name="lock" size="sm"/> Lock demo</button></form>
              @endif
              <form method="post" action="{{ route('robita.devices.force_lock', $device) }}" style="display:inline" data-confirm="Set the local demo state to locked? No hardware will be actuated.">@csrf<button class="btn btn-sm btn-primary"><x-icon name="lock" size="sm"/> Set locked</button></form>
              <form method="post" action="{{ route('robita.devices.force_unlock', $device) }}" style="display:inline" data-confirm="Set the local demo state to unlocked? No hardware will be actuated.">@csrf<button class="btn btn-sm btn-danger"><x-icon name="check" size="sm"/> Set unlocked</button></form>
            @endif
            <form method="post" action="{{ route('robita.devices.status', $device) }}" style="display:inline" data-confirm="{{ $device->status === 'decommissioned' ? 'Reactivate this demo record' : 'Decommission this demo record' }}?">@csrf
              @if($device->status === 'decommissioned')
                <button class="btn btn-sm"><x-icon name="refresh" size="sm"/> Reactivate demo</button>
              @else
                <button class="btn btn-sm"><x-icon name="x" size="sm"/> Decommission</button>
              @endif
            </form>
            <a class="btn btn-sm" href="{{ route('robita.events', ['device_id' => $device->id]) }}"><x-icon name="clock" size="sm"/> Log</a>
          </td>
        </tr>
        @if($device->credentials->where('status', 'active')->isNotEmpty())
          @foreach($device->credentials->where('status', 'active')->take(3) as $cred)
            <tr class="sub-row">
              <td colspan="9">
                <div class="small muted" style="display:flex;align-items:center;gap:.5rem">
                  <x-icon name="key" size="sm"/>
                  <span>{{ $cred->credential_name }}</span> · <span class="mono">{{ $cred->card_serial }}</span> · valid until {{ $cred->valid_until?->format('d M H:i') }}
                  @if($cred->sync_state === 'simulated')<span class="badge badge-blue" title="This credential was not transmitted">DEMO ONLY · NOT SENT TO LOCK</span>
                  @elseif($cred->needsSync())<span class="badge badge-amber" title="{{ $cred->last_error }}">DELIVERY UNVERIFIED · ADAPTER DISABLED</span>
                  @elseif($cred->sync_state === 'ok')<span class="badge badge-green">HARDWARE ACKNOWLEDGED</span>@endif
                  <form method="post" action="{{ route('robita.credentials.cancel', $cred) }}" style="margin-left:auto" data-confirm="Cancel this local demo credential? No hardware command will be sent.">@csrf<button class="btn btn-sm"><x-icon name="x" size="sm"/> Cancel demo</button></form>
                </div>
              </td>
            </tr>
          @endforeach
        @endif
      @empty
        <tr><td colspan="9"><x-empty icon="lock" text="No devices registered."/></td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="modal" id="registerDevice">
  <div class="modal-box" style="max-width:720px">
    <form method="post" action="{{ route('robita.devices.store') }}">@csrf
      <div class="modal-head"><h3>Add Demo Lock Record</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
      <div class="modal-body">
        <div class="alert alert-info"><x-icon name="alert"/><div>Creates a local PMS simulation only. No device is provisioned or contacted.</div></div>
        <div class="form-grid">
          <div><label>Serial Number</label><input class="input" name="serial" required placeholder="e.g. RBT-S1-001234"></div>
          <div><label>Device Name</label><input class="input" name="name" required placeholder="e.g. Room 101 door"></div>
          <div><label>Model</label><input class="input" name="model" value="{{ $cfg['robita_default_model'] }}" placeholder="Robita S1"></div>
          <div>
            <label>Device Type</label>
            <select class="input" name="device_type">
              <option value="door_lock">Room door lock</option>
              <option value="common_area">Common area lock</option>
              <option value="gate">Gate / entrance</option>
            </select>
          </div>
          <div>
            <label>Room</label>
            <select class="input" name="room_id">
              <option value="">— Not mapped —</option>
              @foreach(\App\Models\Room::where('hotel_id', \App\Support\Tenant::id())->orderBy('floor')->orderBy('room_no')->get() as $r)
                <option value="{{ $r->id }}">{{ $r->room_no }} — {{ $r->floor }}</option>
              @endforeach
            </select>
          </div>
          <div><label>Floor</label><input class="input" name="floor" placeholder="e.g. 1st floor"></div>
          <div><label>Location</label><input class="input" name="location" placeholder="e.g. Main entrance, Lobby"></div>
          <div><label>Notes</label><input class="input" name="notes" placeholder="Optional"></div>
        </div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn" data-close>Cancel</button>
        <button type="submit" class="btn btn-primary"><x-icon name="plus"/> Add Demo Record</button>
      </div>
    </form>
  </div>
</div>
@endsection
