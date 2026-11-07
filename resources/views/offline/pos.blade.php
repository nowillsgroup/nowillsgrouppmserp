@extends('layouts.app')

@section('title', 'Offline POS Terminal')
@section('actions')
  <a class="btn" href="{{ route('pos.index') }}"><x-icon name="cup"/> Full terminal</a>
  <button type="button" class="btn btn-primary" data-offline-sync-now><x-icon name="refresh"/> Sync now</button>
@endsection

@section('content')
{{--
  Restaurant ordering that does not wait for the network.

  The page draws itself from the last snapshot the server sent (menu, tables,
  running orders). Opening an order or sending dishes writes an operation into
  the outbox in /js/offline-core.js and returns immediately; the queue is posted
  to /offline/sync when a connection exists. See app/Services/OfflinePosService.php
  for what the server does with each operation.
--}}
<div id="offlinePos" class="offline-pos"
     data-snapshot-url="{{ route('offline.pos.snapshot') }}"
     data-can-order="{{ can_do('pos.order') ? '1' : '0' }}"
     data-business-date="{{ bdate()->toDateString() }}"
     data-business-date-label="{{ bdate()->format('D, d M Y') }}">

  <div class="card offline-pos-strip">
    <div class="card-body flex justify-between gap-1 wrap">
      <div>
        <h3><x-icon name="wifi"/> Restaurant orders without a connection</h3>
        <p class="small muted mb-0">
          The menu, the tables and the running orders are kept on this device. Orders opened and dishes sent are queued here
          and posted to the server the moment the connection returns — in the order they happened, on the business date you were working.
        </p>
      </div>
      <div class="offline-pos-state" data-pos-state role="status" aria-live="polite">Preparing the local terminal…</div>
    </div>
  </div>

  <div class="offline-pos-grid">
    <section class="card flush offline-pos-pane">
      <div class="card-head"><h3>Tables</h3><span class="small muted" data-pos-snapshot-note></span></div>
      <div class="card-body">
        <div class="offline-pos-setup">
          <label class="offline-pos-field">
            <span>Outlet</span>
            <select data-pos-outlet></select>
          </label>
          <label class="offline-pos-field">
            <span>Order type</span>
            <select data-pos-order-type>
              <option value="dine_in">Dine-in</option>
              <option value="takeaway">Takeaway</option>
              <option value="room_service">Room service</option>
            </select>
          </label>
          <label class="offline-pos-field offline-pos-field-narrow">
            <span>Covers</span>
            <input type="number" min="1" max="50" value="2" data-pos-covers>
          </label>
          <label class="offline-pos-field" data-pos-stay-field hidden>
            <span>In-house room</span>
            <select data-pos-stay><option value="">Choose the room…</option></select>
          </label>
          <button type="button" class="btn btn-sm" data-pos-new>Start a new order</button>
        </div>

        <div class="offline-pos-tables" data-pos-tables aria-live="polite"></div>
        <p class="offline-pos-empty" data-pos-tables-empty hidden>No tables in this outlet.</p>

        <h4 class="offline-pos-sub">Running orders <span class="small muted" data-pos-orders-count></span></h4>
        <div class="offline-pos-orders" data-pos-orders></div>
        <p class="offline-pos-empty" data-pos-orders-empty>Nothing is running. Pick a free table to open a bill.</p>
      </div>
    </section>

    <section class="card flush offline-pos-pane">
      <div class="card-head">
        <h3>Menu</h3>
        <input type="search" class="offline-pos-search" data-pos-search placeholder="Search the menu" aria-label="Search the menu">
      </div>
      <div class="card-body">
        <div class="offline-pos-categories" data-pos-categories role="tablist" aria-label="Menu categories"></div>
        <div class="offline-pos-items" data-pos-items aria-live="polite"></div>
        <p class="offline-pos-empty" data-pos-items-empty hidden>No dish carries that name.</p>
      </div>
    </section>

    <section class="card flush offline-pos-pane offline-pos-draft-pane">
      <div class="card-head">
        <h3 data-pos-draft-title>New order</h3>
        <span class="small muted" data-pos-draft-ref></span>
      </div>
      <div class="card-body">
        <div class="offline-pos-draft" data-pos-draft aria-live="polite"></div>
        <p class="offline-pos-empty" data-pos-draft-empty>Nothing on this ticket yet. Tap a dish to start it.</p>

        <div class="offline-pos-draft-foot">
          <div>
            <div class="small muted">Ticket total</div>
            <div class="offline-pos-total" data-pos-draft-total>GH₵ 0.00</div>
          </div>
          <div class="offline-pos-draft-actions">
            <button type="button" class="btn btn-sm" data-pos-clear>Clear</button>
            <button type="button" class="btn btn-primary" data-pos-send disabled>Send to kitchen</button>
          </div>
        </div>
        <p class="tiny muted mb-0">Settlement stays online: cash, card, MoMo and room posting run through the cashier screen so shift totals, the folio and VAT stay in one place.</p>
      </div>
    </section>
  </div>

  <section class="card flush offline-pos-queue">
    <div class="card-head">
      <h3>Waiting to sync <span class="small muted" data-pos-queue-count></span></h3>
      <button type="button" class="btn btn-sm" data-offline-sync-now>Sync now</button>
    </div>
    <div class="card-body">
      <div data-pos-queue></div>
      <p class="offline-pos-empty" data-pos-queue-empty>Everything on this device has reached the server.</p>
    </div>
  </section>
</div>

{{-- The side-dish sheet: a dish that comes with sides asks for them before it joins the ticket. --}}
<div class="offline-pos-modal" data-pos-sides hidden>
  <div class="offline-pos-modal-panel" role="dialog" aria-modal="true" aria-labelledby="offlineSidesTitle">
    <div class="offline-pos-modal-head">
      <h3 id="offlineSidesTitle" data-pos-sides-title>Side dish</h3>
      <button type="button" class="btn btn-sm" data-pos-sides-cancel>Cancel</button>
    </div>
    <div class="offline-pos-modal-body">
      <p class="small muted" data-pos-sides-note>This dish comes with a side. Pick one or more, or continue without if the guest does not want one.</p>
      <p class="small bold" data-pos-sides-warn role="alert" style="color:#b91c1c" hidden>This dish must be served with a side dish — pick at least one.</p>
      <div class="offline-pos-side-cards" data-pos-sides-list></div>
    </div>
    <div class="offline-pos-modal-foot">
      <button type="button" class="btn btn-sm" data-pos-sides-skip>Continue with none</button>
      <button type="button" class="btn btn-primary" data-pos-sides-add>Add to ticket</button>
    </div>
  </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/offline-pos.js') }}?v={{ config('pms.version') }}"></script>
@endpush
