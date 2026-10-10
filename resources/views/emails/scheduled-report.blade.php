@extends('emails.layout')
@section('body')
@php $cols = $data['cols']; $rows = $data['rows']; $money = $data['money']; $sum = $data['sum']; @endphp
<p style="font-size:16px;margin:0 0 4px"><b>{{ $schedule->name }}</b></p>
<p style="margin:0 0 12px;color:#64748b">{{ $title }} · {{ $period }} · {{ $rows->count() }} row(s) · amounts in {{ $hotel->currency_symbol }}</p>
@if($schedule->message)<p style="margin:0 0 12px;padding:8px 10px;background:#f1f5f9;border-left:3px solid #1e293b">{{ $schedule->message }}</p>@endif
@if(!empty($data['summary']))
<table cellpadding="6" cellspacing="0" style="border-collapse:collapse;font-size:13px;margin-bottom:12px"><tr>
@foreach($data['summary'] as $k => $v)<td style="border:1px solid #e2e8f0"><span style="color:#64748b;font-size:11px">{{ $k }}</span><br><b>{{ is_string($v) ? $v : money($v) }}</b></td>@endforeach
</tr></table>
@endif
@if($inline)
@if($rows->count())
<table width="100%" cellpadding="5" cellspacing="0" style="border-collapse:collapse;font-size:12px">
  <tr style="background:#1e293b;color:#fff">@foreach($cols as $k => $l)<th align="{{ in_array($k, $money) || in_array($k, $sum) ? 'right' : 'left' }}">{{ $l }}</th>@endforeach</tr>
  @foreach($rows->take($limit) as $r)
  <tr style="border-bottom:1px solid #e2e8f0">@foreach($cols as $k => $l)<td align="{{ in_array($k, $money) || in_array($k, $sum) ? 'right' : 'left' }}">{{ in_array($k, $money) ? (isset($r[$k]) ? money($r[$k], false) : '') : (is_scalar($r[$k] ?? '') ? ($r[$k] ?? '') : '') }}</td>@endforeach</tr>
  @endforeach
  @if(count($sum))
  <tr style="font-weight:700;background:#f8fafc">@foreach($cols as $k => $l)<td align="right">@if(in_array($k, $sum)){{ in_array($k, $money) ? money($rows->sum($k), false) : $rows->sum($k) }}@elseif($loop->first)Total @endif</td>@endforeach</tr>
  @endif
</table>
@if($rows->count() > $limit)<p style="font-size:12px;color:#b45309">Showing the first {{ $limit }} of {{ $rows->count() }} rows{{ $schedule->format === 'both' ? ' – the attached CSV has them all' : '' }}.</p>@endif
@else
<p style="color:#64748b">No data for this period.</p>
@endif
@else
<p>The report is attached as a CSV file ({{ $rows->count() }} rows).</p>
@endif
@if(!empty($data['note']))<p style="font-size:12px;color:#64748b">{{ $data['note'] }}</p>@endif
<p style="font-size:12px;color:#64748b;margin-top:14px">Scheduled by {{ $schedule->user?->name }} · {{ $schedule->when() }}. <a href="{{ route('reports.show', $schedule->report_key) }}">Open in Nowills PMS</a>. To stop receiving it, ask the owner or an administrator (Reports › Scheduled reports).</p>
@endsection
