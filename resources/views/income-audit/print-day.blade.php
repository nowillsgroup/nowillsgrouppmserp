@extends('layouts.print')
@section('title', 'Income audit '.dmy($day->business_date))
@section('doctitle', 'INCOME AUDIT REPORT')
@section('docmeta')<div class="small">Business date <b id="auditDate">{{ $day->label() }}</b><br>Status: {{ \App\Models\AuditDay::STATUSES[$day->status] }}<br>Checks run {{ $day->checked_at?->format('d M Y H:i') ?? '—' }}</div>@endsection
@section('content')
@php $td = 'padding:5px 7px;border-bottom:1px solid #e2e8f0;vertical-align:top'; $names = ['room' => 'Room revenue', 'other' => 'Other revenue (incl. POS)', 'tax' => 'Taxes & levies', 'collections' => 'Collections']; @endphp
<h4 style="margin:1rem 0 .3rem">Revenue reconciliation (GH₵)</h4>
<table style="width:100%;border-collapse:collapse;font-size:13px">
  <thead><tr style="background:#f1f5f9;text-align:left"><th style="{{ $td }}"></th><th style="{{ $td }};text-align:right">Night-audit report</th><th style="{{ $td }};text-align:right">Baseline</th><th style="{{ $td }};text-align:right">Now</th></tr></thead>
  <tbody>@foreach($names as $k => $l)<tr><td style="{{ $td }}">{{ $l }}</td><td style="{{ $td }};text-align:right">{{ isset($figures['reported'][$k]) ? money($figures['reported'][$k], false) : '—' }}</td><td style="{{ $td }};text-align:right">{{ isset($figures['baseline'][$k]) ? money($figures['baseline'][$k], false) : '—' }}</td><td style="{{ $td }};text-align:right">{{ money($figures['now'][$k] ?? 0, false) }}</td></tr>@endforeach
    <tr><td style="{{ $td }}">Discounts &amp; allowances</td><td style="{{ $td }}"></td><td style="{{ $td }}"></td><td style="{{ $td }};text-align:right">{{ money($figures['discounts'] ?? 0, false) }}</td></tr></tbody>
</table>
<h4 style="margin:1.2rem 0 .3rem">Exceptions ({{ $findings->count() }})</h4>
<table style="width:100%;border-collapse:collapse;font-size:12px" id="printFindings">
  <thead><tr style="background:#f1f5f9;text-align:left"><th style="{{ $td }}">Check</th><th style="{{ $td }}">Room / ref.</th><th style="{{ $td }}">Found</th><th style="{{ $td }};text-align:right">GH₵</th><th style="{{ $td }}">Outcome</th></tr></thead>
  <tbody>@forelse($findings as $f)<tr><td style="{{ $td }}"><b>{{ $checks[$f->check][0] ?? $f->check }}</b><br><span class="small muted">{{ $f->severity }}</span></td><td style="{{ $td }}">{{ $f->room_no }} {{ $f->ref_label }}</td><td style="{{ $td }}">{{ $f->detail }}</td><td style="{{ $td }};text-align:right">{{ (float) $f->amount ? money($f->amount, false) : '' }}</td>
    <td style="{{ $td }}"><b>{{ \App\Models\AuditFinding::STATUSES[$f->status] }}</b>@if($f->resolution)<br>{{ $f->resolution }}@endif @if($f->resolver)<br><span class="small muted">{{ $f->resolver->name }}</span>@endif</td></tr>
  @empty<tr><td colspan="5" style="{{ $td }}">No exceptions.</td></tr>@endforelse</tbody>
</table>
<table style="width:100%;margin-top:2.5rem;font-size:13px"><tr>
  <td style="width:50%;vertical-align:top">Income auditor: <b>{{ $day->signer?->name ?? '________________' }}</b><br><span class="small muted">{{ $day->signed_at?->format('d M Y H:i') }}</span>@if($day->sign_note)<br><span class="small">{{ $day->sign_note }}</span>@endif</td>
  <td style="width:50%;vertical-align:top">Financial controller: <b>{{ $day->closer?->name ?? '________________' }}</b><br><span class="small muted">{{ $day->closed_at?->format('d M Y H:i') }}</span>@if($day->close_note)<br><span class="small">{{ $day->close_note }}</span>@endif</td>
</tr></table>
@endsection
