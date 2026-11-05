@extends('layouts.app')

@section('title', 'Outbox')
@section('actions')
  <a class="btn" href="{{ route('offline.hub') }}"><x-icon name="wifi"/> Offline hub</a>
  @if (can_do('offline.desktop.view') || can_do('frontdesk.view') || can_do('housekeeping.manage'))
    <a class="btn" href="{{ route('offline.reconciliation') }}"><x-icon name="list"/> What this property sent</a>
  @endif
  <button type="button" class="btn btn-primary" data-offline-sync-now><x-icon name="refresh"/> Sync now</button>
@endsection

@section('content')
{{--
  The outbox, for a person.

  Everything a screen queues lives on this device until the server has it. Work the
  server refused, or work that clashed with something another device did first, stays
  here with the reason — this is where somebody reads that reason, fixes what caused
  it and sends the work again, or decides it is no longer true and discards it.

  The screen holds no data of its own: it renders IndexedDB through
  /js/offline-core.js, so it works with no connection and on every offline slice.
--}}
<div id="offlineQueue" class="offline-queue">

  <div class="card offline-pos-strip">
    <div class="card-body flex justify-between gap-1 wrap">
      <div>
        <h3><x-icon name="layers"/> Work this device is still holding</h3>
        <p class="small muted mb-0">
          Every offline screen writes here: restaurant orders and kitchen tickets, front-desk check-ins and folio
          charges, room statuses and housekeeping tasks, maintenance issues and guest requests. Nothing leaves this
          list without an answer from the server, or without a person discarding it.
        </p>
      </div>
      <div class="offline-pos-state" data-queue-state role="status" aria-live="polite">Reading this device…</div>
    </div>
  </div>

  <div class="offline-queue-summary" data-queue-summary aria-live="polite"></div>

  <section class="card flush">
    <div class="card-head">
      <h3>Waiting to send <span class="small muted" data-queue-waiting-count></span></h3>
      <span class="small muted">Retries itself; nothing to press</span>
    </div>
    <div class="card-body">
      <div data-queue-waiting class="offline-queue-list"></div>
      <p class="offline-pos-empty" data-queue-waiting-empty>Nothing is waiting. Everything this device did has reached the server.</p>
    </div>
  </section>

  <section class="card flush">
    <div class="card-head">
      <h3>Needs a person <span class="small muted" data-queue-problem-count></span></h3>
      <span class="small muted">Read the reason, then try again or discard</span>
    </div>
    <div class="card-body">
      <div data-queue-problems class="offline-queue-list"></div>
      <p class="offline-pos-empty" data-queue-problems-empty>Nothing here needs attention.</p>
    </div>
  </section>

  <p class="tiny muted">
    Discarding throws a change away for good — the server never received it. Check the guest, the room or the bill first.
  </p>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/offline-queue.js') }}?v={{ config('pms.version') }}"></script>
@endpush
