@extends('layouts.app')
@section('title', 'Supplier Scorecard')
@section('content')
<form class="card" method="get"><div class="card-body flex gap-1 wrap items-end">
  <div class="field"><label>From</label><input type="date" name="from" value="{{ $from }}" class="input"></div><div class="field"><label>To</label><input type="date" name="to" value="{{ $to }}" class="input"></div>
  <button class="btn">Show</button><a class="btn btn-ghost" href="{{ route('reports.show', 'supplier_scorecard') }}?from={{ $from }}&to={{ $to }}">Report / export</a>
  <span class="small muted" style="margin-left:auto;max-width:460px">Score = on-time 35% · fill rate 25% · quality (100 − rejection %) 25% · price compliance 15%, over the measures available. A ≥ 90 · B ≥ 75 · C ≥ 60 · D below.</span>
</div></form>
<div class="card flush"><div class="card-body table-wrap"><table class="table" id="scoreTable">
<thead><tr><th>Supplier</th><th class="num">POs</th><th class="num">Value</th><th class="num">Deliveries</th><th class="num">On time</th><th class="num">Fill rate</th><th class="num">Quality</th><th class="num">Price compliance</th><th class="num">RFQ response</th><th class="num">Lead (d)</th><th class="num">Score</th></tr></thead><tbody>
@php $pc = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format($v, 1), '0'), '.').'%'; $tone = fn ($g) => ['A' => 'green', 'B' => 'blue', 'C' => 'amber', 'D' => 'red'][$g] ?? 'slate'; @endphp
@forelse($rows as $r)<tr data-supplier="{{ $r['supplier_id'] }}"><td class="bold">{{ $r['supplier'] }}</td><td class="num">{{ $r['orders'] }}</td><td class="num">{{ money($r['value'], false) }}</td><td class="num">{{ $r['deliveries'] }}</td>
  <td class="num">{{ $pc($r['on_time']) }}</td><td class="num">{{ $pc($r['fill']) }}</td><td class="num">{{ $pc($r['quality']) }}@if($r['rejected_qty'] > 0)<div class="tiny text-red">{{ $r['rejected_qty'] }} rejected</div>@endif</td>
  <td class="num">{{ $pc($r['price']) }}@if($r['above_list'])<div class="tiny text-red">{{ $r['above_list'] }} above agreed</div>@endif</td>
  <td class="num">{{ $pc($r['response']) }}@if($r['invited'])<div class="tiny muted">{{ $r['quoted'] }}/{{ $r['invited'] }}</div>@endif</td><td class="num">{{ $r['lead_days'] ?? '—' }}</td>
  <td class="num"><span class="badge badge-{{ $tone($r['grade']) }}" data-grade>{{ $r['grade'] }}</span> {{ $r['score'] ?? '' }}</td></tr>
@empty<tr><td colspan="11"><x-empty text="No purchasing activity in this period." icon="chart"/></td></tr>@endforelse
</tbody></table></div></div>
@endsection
