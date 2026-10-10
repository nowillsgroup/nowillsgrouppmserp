@extends('layouts.print')
@section('title', 'Training matrix')
@section('pageclass', 'landscape')
@section('doctitle', 'TRAINING MATRIX')
@section('docmeta')<div class="small">{{ $dept?->name ?? 'All departments' }}</div><div class="small">As at {{ bdate()->format('d M Y') }}</div><div class="small bold">Compliance {{ $m['totals']['pct'] === null ? '—' : $m['totals']['pct'].'%' }}</div>@endsection
@section('content')
<table class="table table-compact mx-table mt-1" id="matrixPrint">
  <thead><tr><th>Employee</th>@foreach($m['courses'] as $c)<th class="center" title="{{ $c->name }}">{{ $c->code }}</th>@endforeach<th class="num">%</th></tr></thead>
  <tbody>@foreach($m['employees'] as $e)
    <tr><td><b>{{ $e->name }}</b> <span class="tiny muted">{{ $e->designation }}</span></td>
      @foreach($m['courses'] as $c)<td class="center">@if(isset($m['cells'][$e->id][$c->id]))@include('learning._cell', ['cell' => $m['cells'][$e->id][$c->id]])@else<span class="mx-cell mx-na">–</span>@endif</td>@endforeach
      <td class="num">{{ $m['rows'][$e->id]['pct'] === null ? '—' : round($m['rows'][$e->id]['pct']).'%' }}</td></tr>
  @endforeach</tbody>
</table>
<div class="tiny mt-1">@foreach($m['courses'] as $c)<span class="chip-lite"><b>{{ $c->code }}</b> {{ $c->name }} ({{ $c->validityLabel() }})</span>@endforeach</div>
@endsection
