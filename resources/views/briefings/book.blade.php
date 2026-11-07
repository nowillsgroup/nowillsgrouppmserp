@extends('layouts.print')
@section('title', 'Daily briefing book')
@section('doctitle', 'Daily briefing book')
@section('docmeta')
  <div class="small">Business date <b>{{ $date->format('D j M Y') }}</b></div>
  <div class="small">General Manager &amp; CEO</div>
  <div class="small">{{ $exec['signed_count'] }} of {{ $exec['pack_count'] }} departments signed</div>
  <div class="small">Printed {{ now()->format('d M Y H:i') }}</div>
@endsection
@section('content')

{{-- What is in the book, and where each part stands on sign-off. --}}
<div class="card brf-band brf-book-toc">
  <div class="card-head">
    <h3>Contents</h3>
    <span class="small muted">{{ count($packs) + 1 }} parts · business date {{ $date->format('D j M Y') }}</span>
  </div>
  <div class="card-body">
    <ol class="brf-book-list">
      <li>
        <a href="#book-exec"><b>Executive brief</b></a>
        <span class="small muted">General Manager &amp; CEO — every department on one scorecard</span>
        <span class="brf-book-state">{{ $exec['signed_count'] }} of {{ $exec['pack_count'] }} signed</span>
      </li>
      @foreach($packs as $p)
        <li>
          <a href="#book-{{ $p['key'] }}"><b>{{ $p['label'] }}</b></a>
          <span class="small muted">{{ $p['role'] }}</span>
          <span class="brf-book-state {{ $p['signoff'] ? 'is-signed' : 'is-open' }}">{{ $p['signoff'] ? 'Signed '.$p['signoff']->signed_at?->format('H:i') : 'Not signed' }}</span>
        </li>
      @endforeach
    </ol>
  </div>
</div>

{{-- Part one: the consolidated brief the GM and the CEO read first. --}}
<div class="brf-book-part" id="book-exec">
  <div class="brf-book-head">
    <div>
      <h2>Executive brief</h2>
      <div class="small muted">General Manager &amp; CEO · business date {{ $exec['date']->format('D j M Y') }}</div>
    </div>
    <div class="brf-book-state {{ $exec['signed_count'] === $exec['pack_count'] ? 'is-signed' : 'is-open' }}">{{ $exec['signed_count'] }} of {{ $exec['pack_count'] }} departments signed</div>
  </div>

  <div class="stats brf-headline brf-print-headline">
    @foreach($exec['headline'] as $h)
      <div class="stat tone-{{ $h['tone'] ?? 'slate' }}">
        <div><div class="v">{{ $h['value'] }}</div><div class="l">{{ $h['label'] }}</div>@if(!empty($h['sub']))<div class="tiny muted">{{ $h['sub'] }}</div>@endif</div>
      </div>
    @endforeach
  </div>

  @foreach($exec['sections'] as $section)
    @include('briefings.partials.band', ['section' => $section])
  @endforeach

  @include('briefings.partials.attention', ['pack' => $exec])

  <p class="small brf-book-sign">Where these numbers come from: <span class="muted">{{ $exec['sources'] }}</span>.</p>
</div>

{{-- Then every department, in registry order, each on its own page. --}}
@foreach($packs as $p)
  <div class="brf-book-part" id="book-{{ $p['key'] }}">
    <div class="brf-book-head">
      <div>
        <h2>{{ $p['label'] }}</h2>
        <div class="small muted">{{ $p['role'] }} · business date {{ $p['date']->format('D j M Y') }}</div>
      </div>
      <div class="brf-book-state {{ $p['signoff'] ? 'is-signed' : 'is-open' }}">{{ $p['signoff'] ? 'Signed '.$p['signoff']->signed_at?->format('H:i') : 'Not signed' }}</div>
    </div>

    <div class="stats brf-headline brf-print-headline">
      @foreach($p['headline'] as $h)
        <div class="stat tone-{{ $h['tone'] ?? 'slate' }}">
          <div><div class="v">{{ $h['value'] }}</div><div class="l">{{ $h['label'] }}</div>@if(!empty($h['sub']))<div class="tiny muted">{{ $h['sub'] }}</div>@endif</div>
        </div>
      @endforeach
    </div>

    @include('briefings.partials.band', ['section' => $p['house']])

    @foreach($p['sections'] as $section)
      @include('briefings.partials.band', ['section' => $section])
    @endforeach

    @include('briefings.partials.attention', ['pack' => $p])

    <p class="small brf-book-sign">
      @if($p['signoff'])
        Signed by <b>{{ $p['signoff']->user?->name ?? 'the head of department' }}</b> at {{ $p['signoff']->signed_at?->format('H:i') }}{{ $p['signoff']->note ? ' — '.$p['signoff']->note : '' }}.
      @else
        Not signed at the time of printing — the general manager sees this as outstanding.
      @endif
      <span class="muted">Where these numbers come from: {{ $p['sources'] }}.</span>
    </p>
  </div>
@endforeach

<p class="tiny muted brf-book-end">End of the book for {{ $date->format('l j F Y') }}. Each part is also available on its own screen, and each department can export its figures to a spreadsheet.</p>
@endsection
