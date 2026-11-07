@extends('layouts.app')
@section('title', 'Daily executive brief')
@section('crumbs') <a href="{{ route('briefings.index') }}">Daily briefings</a> / Executive brief @endsection
@section('actions')
  @if($date->toDateString() !== $today->toDateString())
    <a class="btn btn-soft" href="{{ route('briefings.exec') }}"><x-icon name="refresh"/> Today</a>
  @endif
  <a class="btn btn-soft" href="{{ route('briefings.book', ['date' => $date->toDateString()]) }}"><x-icon name="layers"/> Briefing book</a>
  <a class="btn" href="{{ route('briefings.exec.csv', ['date' => $date->toDateString()]) }}"><x-icon name="download"/> Excel / CSV</a>
  <a class="btn" href="{{ route('briefings.exec.print', ['date' => $date->toDateString()]) }}"><x-icon name="print"/> Print</a>
@endsection
@section('content')

@include('briefings.partials.masthead', ['pack' => $pack, 'isExec' => true, 'signed' => $pack['signed_count'], 'packCount' => $pack['pack_count']])

<div class="stats brf-headline">
  @foreach($pack['headline'] as $h)
    <x-stat :label="$h['label']" :value="$h['value']" :sub="$h['sub'] ?? null" :tone="$h['tone'] ?? 'slate'" :icon="$h['icon'] ?? 'grid'"/>
  @endforeach
</div>

{{-- The whole-property tables read across the page; the department detail sits in two columns. --}}
@foreach($pack['sections'] as $section)
  @continue(empty($section['full']))
  @continue($section['key'] === 'signoff')
  @include('briefings.partials.band', ['section' => $section])
@endforeach

<div class="brf-columns">
  @foreach($pack['sections'] as $section)
    @continue(!empty($section['full']))
    @include('briefings.partials.band', ['section' => $section])
  @endforeach
</div>

@foreach($pack['sections'] as $section)
  @continue($section['key'] !== 'signoff')
  @include('briefings.partials.band', ['section' => $section])
@endforeach

<div class="card brf-watch">
  <div class="card-head"><h3>What the general manager does with this</h3><span class="small muted">the day's decisions</span></div>
  <div class="card-body">
    <ol class="brf-todo">
      @foreach($pack['todo'] as $t)<li>{{ $t }}</li>@endforeach
    </ol>
    @if(count($pack['watch']))
      <h4 class="brf-subhead">Red today</h4>
      <ul class="brf-list brf-list-flat">
        @foreach($pack['watch'] as $w)
          <li class="brf-li tone-red"><span class="brf-dot"></span><span class="brf-li-text">{{ $w }}</span></li>
        @endforeach
      </ul>
    @endif
  </div>
</div>

<p class="tiny muted brf-sources">Where these numbers come from: {{ $pack['sources'] }}.</p>
@endsection
