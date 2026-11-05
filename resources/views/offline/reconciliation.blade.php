@extends('layouts.app')

@section('title', 'Offline reconciliation')
@section('crumbs') <a href="{{ route('offline.hub') }}">Offline</a> / Reconciliation @endsection
@section('actions')
  <a class="btn" href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}"><x-icon name="download"/> CSV</a>
  <button type="button" class="btn" onclick="window.print()"><x-icon name="print"/> Print</button>
@endsection

@section('content')
{{--
  The evidence after an outage.

  Every operation a device replays leaves a receipt on the server: which device,
  which member of staff, what was asked, what it produced and whether it applied.
  This page reads those receipts and nothing else — it never replays, edits or
  deletes an operation, so looking at what happened cannot change what happened.
--}}
<div class="print-only print-head">
  <h2>{{ hotel()->name }}</h2>
  <div>Offline reconciliation · {{ $from->format('d M Y') }} – {{ $to->format('d M Y') }}</div>
</div>

<div class="card no-print">
  <div class="card-body">
    <form class="filters" method="get" action="{{ route('offline.reconciliation') }}">
      <div class="field"><label for="rc-from">From</label>
        <input id="rc-from" type="date" name="from" value="{{ $from->toDateString() }}" max="{{ bdate()->toDateString() }}"></div>
      <div class="field"><label for="rc-to">To</label>
        <input id="rc-to" type="date" name="to" value="{{ $to->toDateString() }}" max="{{ bdate()->toDateString() }}"></div>
      <div class="field"><label for="rc-status">Status</label>
        <select id="rc-status" name="status">
          <option value="">Everything</option>
          @foreach (['applied' => 'Applied', 'conflict' => 'Clashed', 'rejected' => 'Refused', 'retry' => 'Waiting to retry', 'stalled' => 'Given up (stalled)'] as $key => $label)
            <option value="{{ $key }}" @selected($filters['status'] === $key)>{{ $label }}</option>
          @endforeach
        </select></div>
      <div class="field"><label for="rc-operation">Work</label>
        <select id="rc-operation" name="operation">
          <option value="">Every kind</option>
          @foreach ($operations as $key => $label)
            <option value="{{ $key }}" @selected($filters['operation'] === $key)>{{ $label }}</option>
          @endforeach
        </select></div>
      @if (count($devices))
        <div class="field"><label for="rc-device">Device</label>
          <select id="rc-device" name="device">
            <option value="">Every device</option>
            @foreach ($devices as $device)
              <option value="{{ $device }}" @selected($filters['device'] === $device)>{{ \Illuminate\Support\Str::limit($device, 18) }}</option>
            @endforeach
          </select></div>
      @endif
      <div class="flex gap-1">
        <a class="btn btn-sm btn-soft" href="{{ route('offline.reconciliation', ['from' => bdate()->toDateString(), 'to' => bdate()->toDateString()]) }}">Today</a>
        <a class="btn btn-sm btn-soft" href="{{ route('offline.reconciliation', ['from' => bdate()->copy()->subDay()->toDateString(), 'to' => bdate()->copy()->subDay()->toDateString()]) }}">Yesterday</a>
        <a class="btn btn-sm btn-soft" href="{{ route('offline.reconciliation', ['from' => bdate()->copy()->subDays(6)->toDateString(), 'to' => bdate()->toDateString()]) }}">Last 7 days</a>
      </div>
      <button class="btn btn-primary">Generate</button>
    </form>
  </div>
</div>

<div class="stats">
  <x-stat label="Work replayed" :value="$summary['total']" icon="layers" tone="blue" sub="{{ $from->format('d M') }} – {{ $to->format('d M') }}"/>
  <x-stat label="Applied" :value="$summary['applied']" icon="check" tone="green" sub="reached the records"/>
  <x-stat label="Needs a person" :value="$summary['needs_person']" icon="alert" tone="{{ $summary['needs_person'] ? 'amber' : 'slate' }}" sub="clashed or refused"/>
  <x-stat label="Waiting to retry" :value="$summary['retrying']" icon="refresh" tone="slate" sub="still on the device"/>
  <x-stat label="Devices" :value="$summary['devices']" icon="wifi" tone="slate" sub="that sent work"/>
</div>

<div class="card flush">
  <div class="card-head">
    <h3>{{ $rows->count() }} receipt(s)@if ($truncated) <span class="small muted">· newest {{ $limit }} of {{ $summary['total'] }}</span>@endif</h3>
    <span class="small muted no-print">Newest first</span>
  </div>
  <div class="card-body table-wrap">
    <table class="table table-compact">
      <thead>
        <tr>
          <th>When</th>
          <th>Device</th>
          <th>Staff</th>
          <th>Work</th>
          <th>Asked for</th>
          <th>Produced</th>
          <th>Status</th>
          <th>Reason</th>
        </tr>
      </thead>
      <tbody>
        @forelse ($rows as $row)
          <tr @class(['offline-recon-row-' . $row['status']])>
            <td class="tiny">{{ $row['when']?->format('d M H:i') }}</td>
            <td class="tiny muted">{{ $row['device'] ? \Illuminate\Support\Str::limit($row['device'], 14) : '—' }}</td>
            <td class="tiny">{{ $row['staff'] ?? '—' }}</td>
            <td>{{ $row['label'] }}</td>
            <td class="small muted">{{ $row['what'] }}</td>
            <td class="small">{{ $row['produced'] ?? '—' }}</td>
            <td>
              @php
                $tone = ['applied' => 'green', 'conflict' => 'amber', 'rejected' => 'red', 'retry' => 'blue', 'stalled' => 'red'][$row['status']] ?? 'slate';
              @endphp
              <span class="badge badge-{{ $tone }}">{{ $row['status'] }}</span>
            </td>
            <td class="tiny {{ $row['needs_person'] ? 'offline-recon-reason' : 'muted' }}">{{ $row['reason'] ?? '—' }}</td>
          </tr>
        @empty
          <tr><td colspan="8" class="muted">No device sent work in this window.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<p class="tiny muted no-print">
  Receipts are what the server stored when the device's queue was replayed. A refused or clashed change is still on the
  device: open the device's <a href="{{ route('offline.queue') }}">outbox</a> to send it again or discard it.
</p>
@endsection
