@extends('layouts.app')
@section('title', 'Daily briefings')
@section('crumbs') Reports / Daily briefings @endsection
@section('actions')
  @if($canExec)
    <a class="btn btn-soft" href="{{ route('briefings.book') }}"><x-icon name="layers"/> Briefing book</a>
    <a class="btn" href="{{ route('briefings.exec') }}"><x-icon name="hotel"/> Executive brief</a>
  @endif
@endsection
@section('content')

<div class="card brf-intro">
  <div class="card-body brf-intro-body">
    <div>
      <h2>Daily briefings — {{ $date->format('l j F Y') }}</h2>
      <p class="small muted" style="margin:.35rem 0 0">
        Every head of department reads one page a day: their own numbers, built from the transactions
        posted on the business date, with the handful of things that need a decision. Signing tells the
        general manager the figures have been read and owned. The General Manager and the CEO read the
        consolidated brief, which collects every department into one scorecard.
      </p>
    </div>
    <div class="brf-intro-side">
      <div class="brf-intro-count"><b>{{ $packs->count() }}</b> <span>department packs</span></div>
      <div class="brf-intro-count"><b>{{ $signoffs->count() }}</b> <span>signed today</span></div>
    </div>
  </div>
</div>

@if($canExec)
  <a class="brf-exec-card" href="{{ route('briefings.exec') }}">
    <div class="brf-exec-icon"><x-icon name="hotel"/></div>
    <div class="brf-exec-body">
      <h3>Daily executive brief — General Manager &amp; CEO</h3>
      <p>The whole property on one page: the flash report, the department scorecard with sign-offs, revenue by
        department, cash and banking, receivables, people, operations, experience and every risk that carries money,
        safety or reputation.</p>
    </div>
    <div class="brf-exec-side">
      <span class="badge badge-{{ $signoffs->count() >= $packs->count() ? 'green' : 'amber' }}">{{ $signoffs->count() }}/{{ $packs->count() }} signed</span>
      <span class="btn btn-sm">Open brief</span>
    </div>
  </a>
@endif

<h3 class="brf-section-head">Department briefings</h3>
<div class="brf-grid">
  @forelse($packs as $p)
    <a class="brf-card" href="{{ route('briefings.show', $p['key']) }}">
      <div class="brf-card-top">
        <div class="brf-card-icon"><x-icon :name="$p['icon']"/></div>
        <span class="badge badge-{{ $p['signed'] ? 'green' : 'amber' }}">{{ $p['signed'] ? 'Signed '.$p['signed_at'] : 'Not signed' }}</span>
      </div>
      <h4>{{ $p['label'] }}</h4>
      <div class="small muted">{{ $p['role'] }}</div>
      <p>{{ $p['brief'] }}</p>
      <div class="brf-card-week {{ $p['week_signed'] * 2 < $p['week_days'] ? 'is-thin' : '' }}">
        {{ $p['week_signed'] }} of the last {{ $p['week_days'] }} days signed
      </div>
      @if($p['signed'])
        <div class="tiny muted">{{ $p['signed_by'] }}{{ $p['note'] ? ' — '.$p['note'] : '' }}</div>
      @endif
    </a>
  @empty
    <x-empty text="No department briefing is available to you. Ask an administrator for the area permission of your department." icon="lock"/>
  @endforelse
</div>

@if($hidden)
  <p class="tiny muted">{{ $hidden }} further briefing{{ $hidden === 1 ? '' : 's' }} exist for other departments and are not shown to you.</p>
@endif
@endsection
