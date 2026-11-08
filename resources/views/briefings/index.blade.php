@extends('layouts.app')
@section('title', 'Daily Flash')
@section('crumbs') Reports / Daily Flash @endsection
@section('actions')
  @if($canExec)
    <a class="btn btn-soft" href="{{ route('briefings.book') }}"><x-icon name="layers"/> Flash book</a>
    <a class="btn btn-primary" href="{{ route('briefings.exec') }}"><x-icon name="hotel"/> Executive flash</a>
  @endif
@endsection
@section('content')

<div class="flash-hub">
  <section class="flash-welcome" aria-labelledby="flash-welcome-title">
    <div class="flash-welcome-copy">
      <div class="flash-eyebrow"><x-icon name="calendar"/> Business date · {{ $date->format('l j F Y') }}</div>
      <h2 id="flash-welcome-title">A clear view of the day.</h2>
      <p>Department figures from source transactions for the business date, with the decisions that need attention. Heads sign to confirm they have read and own their numbers; the general manager and CEO can review the consolidated property view.</p>
    </div>
    <div class="flash-summary" aria-label="Visible department sign-off summary">
      <div><strong>{{ $packs->count() }}</strong><span>Visible departments</span></div>
      <div><strong>{{ $visibleSigned }}</strong><span>Signed today</span></div>
      <div><strong>{{ $packs->count() - $visibleSigned }}</strong><span>Awaiting sign-off</span></div>
    </div>
  </section>

  @if($canExec)
    <a class="brf-exec-card flash-executive" href="{{ route('briefings.exec') }}">
      <div class="brf-exec-icon"><x-icon name="hotel"/></div>
      <div class="brf-exec-body">
        <span class="flash-kicker">GENERAL MANAGER &amp; CEO</span>
        <h3>Executive flash</h3>
        <p>The whole property on one page: flash report, department scorecard and sign-offs, revenue, cash and banking, receivables, people, operations, experience and risks to money, safety or reputation.</p>
      </div>
      <div class="brf-exec-side">
        <span class="badge badge-{{ $visibleSigned >= $packs->count() && $packs->count() > 0 ? 'green' : 'amber' }}">{{ $visibleSigned }}/{{ $packs->count() }} visible signed</span>
        <span class="flash-exec-link">Open executive flash <span aria-hidden="true">→</span></span>
      </div>
    </a>
  @endif

  <div class="flash-section-heading">
    <div><span class="flash-kicker">YOUR DEPARTMENTS</span><h2>Department flashes</h2></div>
    <p>Open a flash to review its figures and sign off.</p>
  </div>
  <div class="brf-grid flash-grid">
    @forelse($packs as $p)
      <article class="brf-card flash-card">
        <div class="brf-card-top">
          <div class="brf-card-icon"><x-icon :name="$p['icon']"/></div>
          <span class="badge badge-{{ $p['signed'] ? 'green' : 'amber' }}">{{ $p['signed'] ? 'Signed '.$p['signed_at'] : 'Not signed' }}</span>
        </div>
        <div class="flash-card-name"><h3 title="{{ $p['label'] }}">{{ $p['label'] }}</h3><div class="flash-card-role" title="{{ $p['role'] }}">{{ $p['role'] }}</div></div>
        <div class="brf-card-week {{ $p['week_signed'] * 2 < $p['week_days'] ? 'is-thin' : '' }}">{{ $p['week_signed'] }} of the last {{ $p['week_days'] }} days signed</div>
        <div class="flash-card-actions">
          <button type="button" class="flash-detail-button" data-flash-open="flash-detail-{{ $p['key'] }}" aria-haspopup="dialog" aria-controls="flash-detail-{{ $p['key'] }}">Details<span class="sr-only"> for {{ $p['label'] }}</span></button>
          <a href="{{ route('briefings.show', $p['key']) }}" aria-label="Open {{ $p['label'] }} flash">Open <span aria-hidden="true">→</span></a>
        </div>
      </article>
    @empty
      <x-empty text="No department flash is available to you. Ask an administrator for your department's area permission." icon="lock"/>
    @endforelse
  </div>

  @if($hidden)
    <p class="flash-visibility-note">{{ $hidden }} further department flash{{ $hidden === 1 ? '' : 'es' }} exist for other departments and are not shown to you.</p>
  @endif
</div>

@foreach($packs as $p)
  <dialog class="flash-dialog" id="flash-detail-{{ $p['key'] }}" aria-labelledby="flash-title-{{ $p['key'] }}">
    <div class="flash-dialog-head">
      <div><span class="flash-kicker">DEPARTMENT FLASH</span><h2 id="flash-title-{{ $p['key'] }}">{{ $p['label'] }}</h2><p>{{ $p['role'] }}</p></div>
      <form method="dialog"><button class="btn btn-ghost btn-icon" aria-label="Close details" type="submit"><x-icon name="x"/></button></form>
    </div>
    <div class="flash-dialog-body">
      <p>{{ $p['brief'] }}</p>
      <dl class="flash-detail-list">
        <div><dt>Today's sign-off</dt><dd>{{ $p['signed'] ? 'Signed '.$p['signed_at'] : 'Not signed' }}</dd></div>
        <div><dt>Last {{ $p['week_days'] }} days</dt><dd>{{ $p['week_signed'] }} signed</dd></div>
        @if($p['signed'])
          <div><dt>Signed by</dt><dd>{{ $p['signed_by'] ?: 'A manager' }}</dd></div>
          @if($p['note'])<div><dt>Sign-off note</dt><dd>{{ $p['note'] }}</dd></div>@endif
        @endif
      </dl>
    </div>
    <div class="flash-dialog-foot"><a class="btn btn-primary" href="{{ route('briefings.show', $p['key']) }}">Open {{ $p['label'] }} flash <span aria-hidden="true">→</span></a></div>
  </dialog>
@endforeach
@endsection

@push('scripts')
<script>
  document.querySelectorAll('[data-flash-open]').forEach(button => {
    button.addEventListener('click', () => {
      const dialog = document.getElementById(button.dataset.flashOpen);
      if (dialog && typeof dialog.showModal === 'function') dialog.showModal();
    });
  });
</script>
@endpush
