@extends('layouts.app')
@section('title', $pack['title'])
@section('crumbs') <a href="{{ route('briefings.index') }}">Daily briefings</a> / {{ $pack['label'] }} @endsection
@section('actions')
  @if($date->toDateString() !== $today->toDateString())
    <a class="btn btn-soft" href="{{ route('briefings.show', ['pack' => $pack['key']]) }}"><x-icon name="refresh"/> Today</a>
  @endif
  <a class="btn" href="{{ route('briefings.show', ['pack' => $pack['key'], 'date' => $date->copy()->subDay()->toDateString()]) }}" title="Previous business date"><x-icon name="chev"/> Prev</a>
  <a class="btn btn-soft" href="{{ route('briefings.csv', ['pack' => $pack['key'], 'date' => $date->toDateString()]) }}"><x-icon name="download"/> Excel / CSV</a>
  <a class="btn" href="{{ route('briefings.print', ['pack' => $pack['key'], 'date' => $date->toDateString()]) }}"><x-icon name="print"/> Print</a>
  @if(can_do('reports.exec'))
    <a class="btn btn-soft" href="{{ route('briefings.exec') }}"><x-icon name="hotel"/> Executive brief</a>
  @endif
@endsection
@section('content')

@if(count($packs) > 1)
  <div class="brf-switch" role="navigation" aria-label="Other briefings you can read">
    @foreach($packs as $p)
      <a class="brf-switch-link {{ $p['key'] === $pack['key'] ? 'is-current' : '' }}" href="{{ route('briefings.show', ['pack' => $p['key']]) }}">
        <x-icon :name="$p['icon']"/><span>{{ $p['label'] }}</span>
        @if($p['signed'])<span class="brf-switch-dot" title="Signed"></span>@endif
      </a>
    @endforeach
  </div>
@endif

@include('briefings.partials.masthead', ['pack' => $pack])

@if($pack['signoff'] && $pack['signoff']->note)
  <div class="brf-note"><x-icon name="message"/><div><b>{{ $pack['signoff']->user?->name ?? 'The head' }} noted:</b> {{ $pack['signoff']->note }}</div></div>
@endif

<div class="stats brf-headline">
  @foreach($pack['headline'] as $h)
    <x-stat :label="$h['label']" :value="$h['value']" :sub="$h['sub'] ?? null" :tone="$h['tone'] ?? 'slate'" :icon="$h['icon'] ?? 'grid'"/>
  @endforeach
</div>

@include('briefings.partials.band', ['section' => $pack['house']])

<div class="grid grid-2 brf-lower">
  <div>
    @foreach($pack['sections'] as $section)
      @if(in_array($section['kind'], ['stats', 'table'], true))
        @include('briefings.partials.band', ['section' => $section])
      @endif
    @endforeach
  </div>
  <div>
    @foreach($pack['sections'] as $section)
      @if($section['kind'] === 'list')
        @include('briefings.partials.band', ['section' => $section])
      @endif
    @endforeach

    <div class="card brf-watch">
      <div class="card-head"><h3>On the head's desk</h3><span class="small muted">what the briefing is telling them to do</span></div>
      <div class="card-body">
        @if(count($pack['watch']))
          <ul class="brf-list">
            @foreach($pack['watch'] as $w)
              <li class="brf-li tone-red"><span class="brf-dot"></span><span class="brf-li-text">{{ $w }}</span></li>
            @endforeach
          </ul>
        @else
          <p class="small muted" style="margin:0">Nothing is flagged against this department today.</p>
        @endif
        @if(count($pack['todo']))
          <h4 class="brf-subhead">Actions for today</h4>
          <ol class="brf-todo">
            @foreach($pack['todo'] as $t)<li>{{ $t }}</li>@endforeach
          </ol>
        @endif
      </div>
    </div>

    <div class="card brf-sign">
      <div class="card-head"><h3>Sign-off</h3></div>
      <div class="card-body">
        @if($pack['signoff'])
          <p class="small" style="margin-top:0">
            Signed by <b>{{ $pack['signoff']->user?->name ?? 'a manager' }}</b> at <b>{{ $pack['signoff']->signed_at?->format('H:i') }}</b> on {{ $pack['signoff']->signed_at?->format('d M Y') }}.
          </p>
          <p class="small muted">The general manager sees this department as filed for {{ $pack['date']->format('j M') }}.</p>
          @if($date->toDateString() === $today->toDateString())
            <details class="brf-resign">
              <summary class="small">Sign again after a correction</summary>
              <form method="POST" action="{{ route('briefings.sign', $pack['key']) }}" class="brf-sign-form">
                @csrf
                <input class="input" type="text" name="note" maxlength="500" placeholder="What changed or what you want the GM to know">
                <button class="btn btn-sm btn-primary" type="submit">Re-sign</button>
              </form>
            </details>
          @endif
        @elseif($date->toDateString() === $today->toDateString())
          <p class="small" style="margin-top:0">Signing tells the general manager you have read these figures and that they are yours for {{ $pack['date']->format('j M') }}. The headline figures are stored with your signature.</p>
          <form method="POST" action="{{ route('briefings.sign', $pack['key']) }}" class="brf-sign-form">
            @csrf
            <input class="input" type="text" name="note" maxlength="500" placeholder="Optional note for the GM — a cause, an ask, a correction">
            <button class="btn btn-primary" type="submit"><x-icon name="check"/> Sign this briefing</button>
          </form>
        @else
          <p class="small muted" style="margin:0">Only today's briefing can be signed off.</p>
        @endif
      </div>
    </div>
  </div>
</div>

<p class="tiny muted brf-sources">Where these numbers come from: {{ $pack['sources'] }}.</p>
@endsection
