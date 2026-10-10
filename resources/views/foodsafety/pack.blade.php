@extends('layouts.print')
@section('title', 'HACCP records '.$from.' – '.$to)
@section('doctitle', 'HACCP Records')
@section('docmeta')<div class="small">{{ dmy($from) }} – {{ dmy($to) }}<br>Printed {{ now()->format('d/m/Y H:i') }} by {{ auth()->user()->name }}</div>@endsection
@section('content')
@php $FS = \App\Services\FoodSafetyService::class; $t = fn ($v) => $v === null ? '' : rtrim(rtrim(number_format((float) $v, 1), '0'), '.'); @endphp
<form method="get" class="no-print flex gap-1 mb-1"><input type="date" name="from" value="{{ $from }}"><input type="date" name="to" value="{{ $to }}"><button class="btn btn-sm">Show</button></form>
<p class="small">Compliance over completed days since {{ dmy($from) }}: <b>{{ $compliance['score'] !== null ? $compliance['score'].'%' : '—' }}</b> ({{ $compliance['done'] }}/{{ $compliance['expected'] }} equipment checks, {{ $compliance['lists_done'] }}/{{ $compliance['lists_due'] }} checklists; {{ $compliance['in_range_pct'] ?? '—' }}% of readings within limits).</p>
<h3>1. Temperature records ({{ $logs->count() }})</h3>
<table class="table table-compact" id="packTemps"><thead><tr><th>Date / time</th><th>Type</th><th>What</th><th class="num">°C</th><th>Limit</th><th>OK</th><th>Corrective action</th><th>By</th><th>Reviewed</th></tr></thead>
<tbody>@forelse($logs as $l)<tr><td>{{ $l->recorded_at->format('d/m H:i') }}</td><td>{{ \App\Models\FsTempLog::KINDS[$l->kind] }}</td><td>{{ $l->subject() }}</td><td class="num">{{ $t($l->reading_c) }}{{ $l->reading2_c !== null ? ' → '.$t($l->reading2_c) : '' }}</td><td>{{ $FS::limitText($l->min_c, $l->max_c) }}</td><td>{{ $l->in_range ? '✓' : '✗' }}</td><td>{{ $l->corrective_action }}</td><td>{{ $l->user?->name }}</td><td>{{ $l->reviewer?->name }}</td></tr>
@empty<tr><td colspan="9">No readings.</td></tr>@endforelse</tbody></table>
<h3>2. Checklists ({{ $runs->count() }})</h3>
<table class="table table-compact" id="packLists"><thead><tr><th>Period</th><th>Checklist</th><th class="num">Failed</th><th>Failures / notes</th><th>Completed by</th><th>Signed off</th></tr></thead>
<tbody>@forelse($runs as $r)<tr><td>{{ $FS::periodLabel($r->checklist, $r->period) }}</td><td>{{ $r->checklist->name }}</td><td class="num">{{ $r->failed }}</td><td>{{ collect($r->answers)->where('ok', false)->map(fn ($a) => $a['item'].': '.$a['note'])->implode('; ') }}{{ $r->notes ? ' '.$r->notes : '' }}</td><td>{{ $r->completer?->name }} {{ $r->completed_at->format('d/m H:i') }}</td><td>{{ $r->signer?->name }}</td></tr>
@empty<tr><td colspan="6">No checklists.</td></tr>@endforelse</tbody></table>
<h3>3. Food waste ({{ $waste->count() }})</h3>
<table class="table table-compact"><thead><tr><th>Date</th><th>No.</th><th>Item</th><th class="num">Qty</th><th>Reason</th><th class="num">Cost</th></tr></thead>
<tbody>@forelse($waste as $w)<tr><td>{{ dmy($w->date) }}</td><td>{{ $w->waste_no }}</td><td>{{ $w->name() }}</td><td class="num">{{ rtrim(rtrim(number_format((float) $w->qty, 3), '0'), '.') }} {{ $w->unit }}</td><td>{{ \App\Models\FsWaste::REASONS[$w->reason] }}</td><td class="num">{{ money($w->cost, false) }}</td></tr>
@empty<tr><td colspan="6">No waste recorded.</td></tr>@endforelse</tbody></table>
<p class="small" style="margin-top:12mm">Reviewed by (Head Chef / F&amp;B Manager): ______________________ &nbsp; Date: ____________</p>
@endsection
