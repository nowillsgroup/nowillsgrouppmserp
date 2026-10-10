@extends('layouts.app')

@section('title', 'Offline Housekeeping Workspace')
@section('actions')
  <a class="btn" href="{{ route('housekeeping.index') }}"><x-icon name="broom"/> Full Housekeeping</a>
  <button type="button" class="btn btn-primary" data-offline-sync-now><x-icon name="refresh"/> Sync now</button>
@endsection

@section('content')
<div id="offlineHousekeepingApp" class="offline-workspace" data-snapshot-url="{{ route('offline.housekeeping.snapshot') }}" data-sync-url="{{ route('offline.sync') }}" data-can-edit="{{ can_do('housekeeping.manage') || can_do('housekeeping.attendant') ? '1' : '0' }}">
  <div class="card mb-1 offline-workspace-banner">
    <div class="card-body flex justify-between gap-1 wrap">
      <div>
        <h3><x-icon name="wifi"/> Offline-ready housekeeping</h3>
        <p class="small muted mb-0">Room status updates are saved on this device when the connection is unavailable and replayed when it returns. Conflicting changes are never overwritten.</p>
      </div>
      <div class="offline-workspace-state" data-offline-workspace-state role="status" aria-live="polite">Preparing local workspace…</div>
    </div>
  </div>
  <div class="card flush mb-1">
    <div class="card-head"><h3>Rooms</h3><span class="small muted" data-offline-room-count>Waiting for the first online snapshot…</span></div>
    <div class="card-body">
      <div class="offline-room-grid" data-offline-room-list aria-live="polite"></div>
      <div class="empty muted" data-offline-empty>Connect once to download the room list for this property.</div>
    </div>
  </div>
  <div class="card flush">
    <div class="card-head"><h3>My housekeeping tasks</h3><span class="small muted" data-offline-task-count>Waiting for the first online snapshot…</span></div>
    <div class="card-body">
      <div class="offline-task-list" data-offline-task-list aria-live="polite"></div>
      <div class="empty muted" data-offline-task-empty>Connect once to download today's assigned tasks.</div>
    </div>
  </div>
</div>
@endsection
