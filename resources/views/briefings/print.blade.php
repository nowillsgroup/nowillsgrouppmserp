@extends('layouts.print')
@section('title', $pack['title'])
@section('doctitle', $pack['is_exec'] ? 'Daily executive brief' : $pack['role'].' — daily briefing')
@section('docmeta')
  <div class="small">Business date <b>{{ $pack['date']->format('D j M Y') }}</b></div>
  <div class="small">{{ $pack['is_exec'] ? 'General Manager & CEO' : $pack['role'] }}</div>
  <div class="small">Printed {{ now()->format('d M Y H:i') }}</div>
@endsection
@section('content')

<div class="stats brf-headline brf-print-headline">
  @foreach($pack['headline'] as $h)
    <div class="stat tone-{{ $h['tone'] ?? 'slate' }}">
      <div><div class="v">{{ $h['value'] }}</div><div class="l">{{ $h['label'] }}</div>@if(!empty($h['sub']))<div class="tiny muted">{{ $h['sub'] }}</div>@endif</div>
    </div>
  @endforeach
</div>

@include('briefings.partials.band', ['section' => $pack['house']])

@foreach($pack['sections'] as $section)
  @include('briefings.partials.band', ['section' => $section])
@endforeach

@include('briefings.partials.attention', ['pack' => $pack])

@if($pack['is_exec'])
  <p class="small">Signed off today: {{ $pack['signed_count'] }} of {{ $pack['pack_count'] }} departments.</p>
@elseif($pack['signoff'])
  <p class="small">Signed by {{ $pack['signoff']->user?->name ?? 'a manager' }} at {{ $pack['signoff']->signed_at?->format('H:i') }}{{ $pack['signoff']->note ? ' — '.$pack['signoff']->note : '' }}.</p>
@else
  <p class="small">Not signed off at the time of printing.</p>
@endif

<p class="tiny muted">Where these numbers come from: {{ $pack['sources'] }}.</p>
@endsection
