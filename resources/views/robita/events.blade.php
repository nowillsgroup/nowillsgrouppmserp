@extends('layouts.app')
@section('title', 'Robita Access Events')
@section('crumbs') Interfaces / Robita Locks / Events @endsection
@section('actions')
  <a class="btn" href="{{ route('robita.index') }}"><x-icon name="grid"/> Dashboard</a>
  <a class="btn" href="{{ route('robita.devices') }}"><x-icon name="lock"/> Devices</a>
@endsection
@section('content')
@php $c = $counts; @endphp

<div class="stats">
  <x-stat :value="$c['robita_events_unlock']" label="Unlocks today" icon="check" tone="green"/>
  <x-stat :value="$c['robita_events_alarm']" label="Alarms today" icon="bell" :tone="$c['robita_events_alarm'] ? 'red' : 'slate'"/>
  <x-stat :value="$c['robita_low_battery']" label="Low battery" icon="alert" :tone="$c['robita_low_battery'] ? 'amber' : 'green'"/>
  <x-stat :value="$c['robita_devices_online']" label="Online devices" icon="lock" tone="blue"/>
</div>

<div class="card flush">
  <div class="card-head">
    <h3><x-icon name="clock" size="sm"/> Access Event Log</h3>
    <form method="get" class="flex" style="gap:.4rem">
      <select class="input" name="type" style="max-width:8rem">
        <option value="">All types</option>
        @foreach(\App\Models\RobitaEvent::TYPES as $k => $v)
          <option value="{{ $k }}" @selected(request('type') === $k)>{{ $v }}</option>
        @endforeach
      </select>
      <select class="input" name="alarm" style="max-width:8rem">
        <option value="">All events</option>
        <option value="1" @selected(request('alarm') === '1')>Alarms only</option>
        <option value="0" @selected(request('alarm') === '0')>Normal events</option>
      </select>
      <input class="input" type="text" name="q" style="max-width:12rem" placeholder="Search user / card / room" value="{{ request('q') }}">
      <button class="btn btn-sm btn-primary"><x-icon name="search" size="sm"/></button>
      @if(request()->query())<a class="btn btn-sm" href="{{ route('robita.events') }}"><x-icon name="x" size="sm"/></a>@endif
    </form>
  </div>
  <div class="card-body table-wrap">
    <table class="table table-compact">
      <thead><tr><th>Timestamp</th><th>Device</th><th>Room</th><th>Event</th><th>Method</th><th>User</th><th>Card</th><th>Source</th></tr></thead>
      <tbody>
      @forelse($events as $event)
        <tr>
          <td class="small muted nowrap">{{ $event->event_at?->format('d M H:i') ?? '—' }}</td>
          <td>{{ $event->device?->name ?? '—' }}</td>
          <td>{{ $event->room?->room_no ?? '—' }}</td>
          <td>
            @if($event->isAlarm())
              <span class="badge badge-red">{{ ucfirst($event->event_type) }}</span>
              @if($event->alarm_type)<div class="small muted">{{ ucfirst(str_replace('_', ' ', $event->alarm_type)) }}</div>@endif
            @elseif($event->event_type === 'unlock')
              <span class="badge badge-green">Unlock</span>
            @elseif($event->event_type === 'remote_unlock')
              <span class="badge badge-blue">Remote unlock</span>
            @elseif($event->event_type === 'key_issued')
              <span class="badge badge-teal">Key issued</span>
            @elseif($event->event_type === 'key_cancelled')
              <span class="badge badge-amber">Key cancelled</span>
            @else
              <span class="badge badge-slate">{{ ucfirst($event->event_type) }}</span>
            @endif
          </td>
          <td class="small muted">{{ ucfirst($event->method ?? '—') }}</td>
          <td>{{ $event->user_name ?? '—' }}</td>
          <td class="small muted mono">{{ $event->card_no ?? $event->card_serial ?? '—' }}</td>
          <td class="small muted">{{ ucfirst($event->source) }}</td>
        </tr>
      @empty
        <tr><td colspan="8"><x-empty icon="clock" text="No events logged yet. Events are received via webhook or polling from Robita devices."/></td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
  @if($events->hasPages())<div class="card-foot">{{ $events->links() }}</div>@endif
</div>
@endsection
