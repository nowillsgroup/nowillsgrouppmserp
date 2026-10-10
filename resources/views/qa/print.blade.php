@extends('layouts.print')
@section('title', 'Audit '.$a->number)
@section('doctitle', strtoupper($a->typeLabel()).' REPORT')
@php $fmt = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 1), '0'), '.').'%'; $done = $a->status === 'completed'; $td = 'padding:4px 6px;border-bottom:1px solid #e2e8f0;vertical-align:top'; @endphp
@section('docmeta')<div class="small">{{ $a->number }} · {{ $a->title }}<br>{{ $a->template?->name }} · {{ $a->department?->name ?? 'Whole hotel' }} · {{ $a->scheduled_for->format('d M Y') }} · auditor {{ $a->auditorName() }}<br>
@if($done)Score <b>{{ $fmt($a->score_pct) }}</b> ({{ rtrim(rtrim(number_format($a->points_earned, 1), '0'), '.') }} / {{ rtrim(rtrim(number_format($a->points_possible, 1), '0'), '.') }} pts) · pass mark {{ $fmt($a->pass_mark) }} · <b>{{ $a->passed ? 'PASSED' : 'FAILED' }}</b> · {{ $a->fails }} finding(s), {{ $a->critical_fails }} critical, {{ $a->repeat_fails }} repeat @else Status: <b>{{ strtoupper($a->statusLabel()) }}</b> @endif</div>@endsection
@section('content')
<table style="width:100%;border-collapse:collapse;font-size:12px;margin-top:1rem" id="printAudit">
  @foreach($areas as $area => $items)
  <tr><td colspan="4" style="{{ $td }};background:#f1f5f9"><b>{{ $area }}</b></td></tr>
  @foreach($items as $it)<tr><td style="{{ $td }};width:9%"><b>{{ $it->code }}</b></td><td style="{{ $td }}">{{ $it->title }}{{ $it->is_critical ? ' [CRITICAL]' : '' }}{{ $it->is_repeat ? ' [REPEAT]' : '' }}@if($it->note)<br><i style="color:#475569">{{ $it->note }}</i>@endif</td>
    <td style="{{ $td }};width:6%;text-align:center">{{ $it->weight }}</td><td style="{{ $td }};width:10%;text-align:center;font-weight:bold;color:{{ $it->result === 'fail' ? '#dc2626' : ($it->result === 'pass' ? '#16a34a' : '#64748b') }}">{{ $it->result ? strtoupper($it->result === 'na' ? 'n/a' : $it->result) : '☐ P ☐ F ☐ NA' }}</td></tr>@endforeach
  @endforeach
</table>
@if($a->summary)<h4 style="margin-top:1rem">Summary</h4><p style="font-size:12px;white-space:pre-line">{{ $a->summary }}</p>@endif
@if($a->actions->isNotEmpty())<h4 style="margin-top:1rem">Corrective actions</h4><table style="width:100%;border-collapse:collapse;font-size:12px">
  @foreach($a->actions as $x)<tr><td style="{{ $td }}"><b>{{ $x->number }}</b></td><td style="{{ $td }}">{{ $x->title }}</td><td style="{{ $td }}">{{ $x->priorityLabel() }}</td><td style="{{ $td }}">{{ $x->owner?->name ?? '________________' }}</td><td style="{{ $td }}">due {{ $x->due_date->format('d M Y') }}</td><td style="{{ $td }}">{{ $x->statusLabel() }}</td></tr>@endforeach
</table>@endif
<table style="width:100%;margin-top:2.5rem;font-size:12px"><tr><td style="width:50%">Auditor: ______________________<br>{{ $a->auditorName() }}</td><td>Department head: ______________________<br>{{ $a->acknowledger?->name }}{{ $a->acknowledged_at ? ' · '.$a->acknowledged_at->format('d M Y') : '' }}</td></tr></table>
@endsection
