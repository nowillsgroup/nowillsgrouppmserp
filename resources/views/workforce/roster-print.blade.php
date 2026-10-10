@extends('layouts.print')
@section('title', 'Duty roster')
@section('pageclass', 'landscape')
@section('doctitle', 'DUTY ROSTER')
@section('docmeta')<div class="small"><b>{{ $dept->name }}</b><br>Week {{ dmy($week) }} – {{ dmy($week->copy()->addDays(6)) }}<br>{{ ['draft' => 'DRAFT', 'published' => 'Published', 'changed' => 'Published (amended)'][$g['week']?->state() ?? 'draft'] }}</div>@endsection
@section('content')
@php $td = 'padding:5px 6px;border:1px solid #cbd5e1;font-size:12px;text-align:center'; @endphp
<table style="width:100%;border-collapse:collapse;margin-top:1rem" id="rosterPrintTable">
  <tr><th style="{{ $td }};text-align:left;background:#f1f5f9">Employee</th>@foreach($g['days'] as $d)<th style="{{ $td }};background:#f1f5f9">{{ $d->format('D d M') }}</th>@endforeach<th style="{{ $td }};background:#f1f5f9">Hours</th></tr>
  @foreach($g['employees'] as $e)
  <tr><td style="{{ $td }};text-align:left"><b>{{ $e->name }}</b><br><span style="color:#64748b">{{ $e->designation }}</span></td>
    @foreach($g['days'] as $d)
      @php $ds = $d->toDateString(); $s = $g['shifts'][$e->id][$ds] ?? null; $lv = $g['leave'][$e->id][$ds] ?? null; @endphp
      <td style="{{ $td }};{{ $s?->isShift() ? 'background:'.($s->template?->color ?? '#64748b').'22' : '' }}">@if($lv && ! $lv->half_day)<i>Leave</i>@elseif($s)<b>{{ $s->code() }}</b>@if($s->isShift())<br>{{ substr($s->start_time, 0, 5) }}–{{ substr($s->end_time, 0, 5) }}@endif @else – @endif</td>
    @endforeach
    <td style="{{ $td }}">{{ number_format($g['hours'][$e->id] ?? 0, 1) }}</td></tr>
  @endforeach
</table>
<p class="small" style="margin-top:.8rem">@foreach($g['templates'] as $t)<b>{{ $t->code }}</b> {{ $t->name }} {{ $t->label() }} &nbsp; @endforeach <b>OFF</b> day off</p>
<table style="width:100%;margin-top:2.5rem;font-size:13px"><tr><td>Prepared by: ______________________</td><td>Approved (HOD): ______________________</td><td>Date: ____________</td></tr></table>
@endsection
