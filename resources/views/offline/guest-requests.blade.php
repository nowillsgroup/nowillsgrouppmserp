@extends('layouts.app')

@section('title', 'Offline Guest Requests')
@section('actions')
  <a class="btn" href="{{ route('requests.index') }}"><x-icon name="bell"/> Guest requests</a>
  <button type="button" class="btn btn-primary" data-guest-request-sync><x-icon name="refresh"/> Sync now</button>
@endsection

@section('content')
<div id="offlineGuestRequestsApp" class="offline-workspace" data-snapshot-url="{{ route('offline.guest_requests.snapshot') }}" data-sync-url="{{ route('offline.sync') }}" data-can-create="{{ can_do('requests.manage') ? '1' : '0' }}">
  <div class="card mb-1 offline-workspace-banner">
    <div class="card-body flex justify-between gap-1 wrap">
      <div>
        <h3><x-icon name="wifi"/> Offline guest-request capture</h3>
        <p class="small muted mb-0">Capture a request from a checked-in room without a connection. It is saved on this device and replayed when the connection returns.</p>
      </div>
      <div class="offline-workspace-state" data-guest-request-state role="status" aria-live="polite">Preparing local workspace…</div>
    </div>
  </div>

  <div class="offline-request-layout">
    <section class="card offline-request-form-card">
      <div class="card-head"><h3>New guest request</h3><span class="badge badge-blue">No guest names stored</span></div>
      <form class="card-body" data-guest-request-form>
        <div class="form-grid">
          <div class="field span-all">
            <label for="offlineRequestStay">Checked-in room *</label>
            <select id="offlineRequestStay" class="input" data-guest-stay required></select>
            <span class="hint" data-guest-stay-hint>Connect once to download checked-in rooms.</span>
          </div>
          <div class="field">
            <label for="offlineRequestCategory">Request type *</label>
            <select id="offlineRequestCategory" class="input" data-guest-category required></select>
          </div>
          <div class="field">
            <label for="offlineRequestItem">What is needed *</label>
            <select id="offlineRequestItem" class="input" data-guest-item required></select>
          </div>
          <div class="field">
            <label for="offlineRequestQuantity">Quantity</label>
            <input id="offlineRequestQuantity" class="input" type="number" min="1" max="10" value="1" data-guest-quantity>
            <span class="hint">Used for amenities; other request types are recorded as one.</span>
          </div>
          <div class="field" data-guest-preferred-field>
            <label for="offlineRequestPreferred" data-guest-preferred-label>For date &amp; time</label>
            <input id="offlineRequestPreferred" class="input" type="datetime-local" data-guest-preferred>
            <span class="hint" data-guest-preferred-hint>Required for wake-up calls and transport.</span>
          </div>
          <div class="field">
            <label for="offlineRequestTime">Late check-out time</label>
            <input id="offlineRequestTime" class="input" type="time" value="14:00" data-guest-time>
            <span class="hint">Used for late check-out requests.</span>
          </div>
          <div class="field span-all">
            <label for="offlineRequestNotes">Notes</label>
            <textarea id="offlineRequestNotes" class="input" rows="3" maxlength="500" data-guest-notes placeholder="Add instructions for the team…"></textarea>
            <span class="hint"><span data-guest-notes-count>0</span> / 500 characters</span>
          </div>
        </div>
        <div class="offline-request-form-foot">
          <span class="small muted" data-guest-form-hint>Select a checked-in room to begin.</span>
          <button type="submit" class="btn btn-primary" data-guest-request-submit disabled><x-icon name="plus"/> Save request</button>
        </div>
      </form>
    </section>

    <section class="card flush">
      <div class="card-head"><h3>Recent requests</h3><span class="small muted" data-guest-request-count>No local requests yet</span></div>
      <div class="card-body">
        <div class="offline-request-list" data-guest-request-list aria-live="polite"></div>
        <div class="empty muted" data-guest-request-empty>Requests captured here will appear on this device.</div>
      </div>
    </section>
  </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/offline-guest-requests.js') }}?v={{ config('pms.version') }}"></script>
@endpush
