@extends('layouts.app')

@section('title', 'Offline Front Desk')
@section('actions')
  <a class="btn" href="{{ route('frontdesk.arrivals') }}"><x-icon name="key"/> Front desk</a>
  <button type="button" class="btn btn-primary" data-offline-sync-now><x-icon name="refresh"/> Sync now</button>
@endsection

@section('content')
{{--
  Front desk work that does not wait for the network.

  Arrivals, in-house rooms and the room list come from the last snapshot. A check-in
  or a folio charge is written into the outbox by /js/offline-core.js and replayed
  through /offline/sync; the server runs the same StayService / FolioService code the
  online desk runs. Payments and check-out stay online — they belong to the cashier
  shift and the folio's payment trail.
--}}
<div id="offlineDesk" class="offline-desk"
     data-snapshot-url="{{ route('offline.frontdesk.snapshot') }}"
     data-can-checkin="{{ can_do('frontdesk.checkin') ? '1' : '0' }}"
     data-can-charge="{{ can_do('folio.post') ? '1' : '0' }}"
     data-business-date="{{ bdate()->toDateString() }}">

  <div class="card offline-pos-strip">
    <div class="card-body flex justify-between gap-1 wrap">
      <div>
        <h3><x-icon name="wifi"/> Check-in and charges without a connection</h3>
        <p class="small muted mb-0">
          Arrivals and in-house rooms are kept on this device. A check-in or a charge is queued here and posted to the server —
          on the business date you were working — the moment the connection returns.
        </p>
      </div>
      <div class="offline-pos-state" data-desk-state role="status" aria-live="polite">Preparing the local desk…</div>
    </div>
  </div>

  <div class="offline-desk-grid">
    <section class="card flush offline-pos-pane">
      <div class="card-head"><h3>Arrivals</h3><span class="small muted" data-desk-arrivals-note></span></div>
      <div class="card-body">
        <div class="offline-desk-arrivals" data-desk-arrivals aria-live="polite"></div>
        <p class="offline-pos-empty" data-desk-arrivals-empty>No arrival is waiting for a room.</p>
      </div>
    </section>

    <section class="card flush offline-pos-pane">
      <div class="card-head"><h3>In-house guests</h3><span class="small muted" data-desk-inhouse-note></span></div>
      <div class="card-body">
        <div class="offline-desk-inhouse" data-desk-inhouse aria-live="polite"></div>
        <p class="offline-pos-empty" data-desk-inhouse-empty>Nobody is in house.</p>
      </div>
    </section>

    <section class="card flush offline-pos-pane">
      <div class="card-head"><h3>Post a charge</h3><span class="small muted">No payment is taken here</span></div>
      <div class="card-body">
        <div class="offline-desk-charge">
          <label class="offline-pos-field">
            <span>Guest</span>
            <select data-charge-stay></select>
          </label>
          <label class="offline-pos-field">
            <span>Category</span>
            <select data-charge-category></select>
          </label>
          <label class="offline-pos-field">
            <span>Description</span>
            <input type="text" maxlength="255" data-charge-description placeholder="Minibar — 2 bottles of water">
          </label>
          <label class="offline-pos-field offline-pos-field-narrow">
            <span>Amount</span>
            <input type="number" min="0.01" step="0.01" data-charge-amount placeholder="0.00">
          </label>
          <label class="offline-desk-check">
            <input type="checkbox" data-charge-tax checked> Add the category's tax
          </label>
          <button type="button" class="btn btn-primary" data-charge-post>Post charge</button>
        </div>
        <p class="tiny muted mb-0" data-desk-charge-note>
          The charge is posted to the guest's open folio when the connection returns. Settlement, refunds and check-out stay on the online cashier screen.
        </p>
      </div>
    </section>
  </div>

  <section class="card flush offline-pos-queue">
    <div class="card-head">
      <h3>Waiting to sync <span class="small muted" data-desk-queue-count></span></h3>
      <button type="button" class="btn btn-sm" data-offline-sync-now>Sync now</button>
    </div>
    <div class="card-body">
      <div data-desk-queue></div>
      <p class="offline-pos-empty" data-desk-queue-empty>Everything on this device has reached the server.</p>
    </div>
  </section>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/offline-frontdesk.js') }}?v={{ config('pms.version') }}"></script>
@endpush
