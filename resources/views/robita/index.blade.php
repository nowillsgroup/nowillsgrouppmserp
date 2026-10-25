@extends('layouts.app')
@section('title', 'Robita Smart Door Locks')
@section('crumbs') Interfaces / Robita Locks @endsection
@section('actions')
  <a class="btn" href="{{ route('robita.devices') }}"><x-icon name="lock"/> Devices</a>
  <a class="btn" href="{{ route('robita.events') }}"><x-icon name="clock"/> Events</a>
  <a class="btn btn-primary" href="{{ route('robita.settings') }}"><x-icon name="tool"/> Settings</a>
@endsection
@section('content')
@php $c = $counts; @endphp

<div class="stats">
  <x-stat :value="$c['robita_devices_online']" label="Online devices" icon="lock" tone="blue" :sub="$c['robita_devices_total'].' registered'" :href="route('robita.devices')"/>
  <x-stat :value="$c['robita_devices_total']" label="Total devices" icon="building" tone="slate" :sub="$c['robita_devices_offline'].' offline'" :href="route('robita.devices')"/>
  <x-stat :value="$c['robita_credentials_active']" label="Active credentials" icon="key" tone="green" sub="cards issued to devices"/>
  <x-stat :value="$c['robita_events_unlock']" label="Unlocks today" icon="check" tone="teal" :sub="$c['robita_events_alarm'].' alarms'" :href="route('robita.events')"/>
  <x-stat :value="$c['robita_low_battery']" label="Low battery" icon="alert" :tone="$c['robita_low_battery'] ? 'red' : 'green'" sub="needs replacement" :href="route('robita.devices')"/>
  <x-stat :value="$c['robita_events_alarm']" label="Alarms today" icon="bell" :tone="$c['robita_events_alarm'] ? 'red' : 'slate'" sub="tamper / forced entry" :href="route('robita.events', ['alarm' => '1'])"/>
</div>

<div class="grid g-2">
  <div class="card">
    <div class="card-head">
      <h3><x-icon name="lock" size="sm"/> Door Lock Devices</h3>
      <div class="flex" style="gap:.4rem">
        <button class="btn btn-sm btn-primary" data-modal="registerDevice" id="registerDeviceBtn"><x-icon name="plus" size="sm"/> Register Device</button>
        <a class="btn btn-sm" href="{{ route('robita.devices') }}">Manage all</a>
      </div>
    </div>
    <div class="card-body table-wrap">
      <table class="table table-compact">
        <thead><tr><th>Device</th><th>Room</th><th>State</th><th>Battery</th><th>Signal</th><th>Last seen</th><th></th></tr></thead>
        <tbody>
        @forelse($devices->take(8) as $device)
          <tr>
            <td><b>{{ $device->name }}</b><div class="small muted">{{ $device->serial }} · {{ $device->model }}</div></td>
            <td>{{ $device->room?->room_no ?? ($device->location ?? '—') }}</td>
            <td>
              @if($device->status === 'decommissioned')
                <span class="badge badge-slate">Off</span>
              @elseif($device->isOnline())
                <span class="badge badge-{{ $device->isLocked() ? 'green' : 'amber' }}">{{ $device->isLocked() ? 'Locked' : 'Unlocked' }}</span>
              @else
                <span class="badge badge-red">Offline</span>
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
            <td class="small muted">{{ $device->last_seen_at ? $device->last_seen_at->diffForHumans() : '—' }}</td>
            <td class="text-right">
              @if($device->isOnline())<a class="btn btn-sm" href="{{ route('robita.devices') }}">Control</a>@endif
            </td>
          </tr>
        @empty
          <tr><td colspan="7"><x-empty icon="lock" text="No devices registered yet. Click Register Device to add a Robita lock."/></td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-head">
      <h3><x-icon name="clock" size="sm"/> Recent Access Events</h3>
      <a class="btn btn-sm" href="{{ route('robita.events') }}">View all</a>
    </div>
    <div class="card-body table-wrap">
      <table class="table table-compact">
        <thead><tr><th>Time</th><th>Device</th><th>Type</th><th>Method</th><th>User</th></tr></thead>
        <tbody>
        @forelse($lastEvents as $event)
          <tr>
            <td class="small muted nowrap">{{ $event->event_at?->format('H:i') ?? '—' }}</td>
            <td>{{ $event->device?->name ?? '—' }}</td>
            <td>
              @if($event->isAlarm())<span class="badge badge-red">{{ ucfirst($event->event_type) }}</span>
              @elseif($event->event_type === 'unlock')<span class="badge badge-green">Unlock</span>
              @elseif($event->event_type === 'remote_unlock')<span class="badge badge-blue">Remote</span>
              @else<span class="badge badge-slate">{{ ucfirst($event->event_type) }}</span>@endif
            </td>
            <td class="small muted">{{ ucfirst($event->method ?? '—') }}</td>
            <td>{{ $event->user_name ?? '—' }}</td>
          </tr>
        @empty
          <tr><td colspan="5"><x-empty icon="clock" text="No events received yet."/></td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-head"><h3><x-icon name="link" size="sm"/> Cloud Connection</h3><a class="btn btn-sm" href="{{ route('robita.settings') }}">Configure</a></div>
  <div class="card-body">
    @if($cfg['robita_cloud_url'] === '')
      <div class="alert alert-info"><x-icon name="alert"/><div><b>Demo mode.</b> Set a Robita cloud URL in Settings to enable live device communication. Commands are acknowledged locally without cloud calls.</div></div>
    @else
      <div class="alert alert-success"><x-icon name="check"/><div><b>Cloud connected.</b> {{ $cfg['robita_cloud_url'] }}</div></div>
    @endif
    <dl class="kv" style="margin-top:.6rem">
      <dt>Webhook URL</dt><dd class="mono" style="font-size:.75rem">{{ url('/api/robita/'.$cfg['robita_webhook_token']) }}</dd>
      <dt>Poll interval</dt><dd>{{ $cfg['robita_poll_minutes'] ? $cfg['robita_poll_minutes'].' min' : 'Webhook only' }}</dd>
      <dt>Default model</dt><dd>{{ $cfg['robita_default_model'] }}</dd>
    </dl>
  </div>
</div>

<div class="modal" id="registerDevice">
  <div class="modal-box" style="max-width:720px">
    <form method="post" action="{{ route('robita.devices.store') }}">@csrf
      <div class="modal-head"><h3>Register Robita Device</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
      <div class="modal-body">
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
        <button type="submit" class="btn btn-primary"><x-icon name="lock"/> Register Device</button>
      </div>
    </form>
  </div>
</div>
@endsection
