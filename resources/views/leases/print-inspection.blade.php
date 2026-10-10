@extends('layouts.print')
@section('title', 'Inspection '.$l->lease_no)
@section('doctitle', strtoupper(\App\Models\LeaseInspection::KINDS[$i->kind]).' INSPECTION')
@section('docmeta')<div class="small">Lease <b>{{ $l->lease_no }}</b> · {{ $l->unit->code }}<br>{{ dmy($i->date) }}</div>@endsection
@section('content')
@php $td = 'padding:5px 8px;border-bottom:1px solid #e2e8f0'; @endphp
<div style="margin:1rem 0"><b>{{ $l->tenant_name }}</b> – {{ $l->unit->code }} {{ $l->unit->name }}<br><span class="small">Inspected by {{ $i->user?->name ?? '—' }}{{ $i->tenant_ack ? ' · acknowledged by '.$i->tenant_ack : '' }}</span></div>
<table style="width:100%;border-collapse:collapse;font-size:13px" id="inspTable">
  <thead><tr style="background:#f1f5f9;text-align:left"><th style="{{ $td }}">Area</th><th style="{{ $td }}">Item</th><th style="{{ $td }}">Condition</th><th style="{{ $td }}">Note</th><th style="{{ $td }};text-align:right">Charge</th></tr></thead>
  <tbody>@foreach($i->items as $it)<tr><td style="{{ $td }}">{{ $it['area'] }}</td><td style="{{ $td }}">{{ $it['item'] }}</td><td style="{{ $td }};{{ in_array($it['condition'], ['damaged', 'missing']) ? 'color:#b91c1c;font-weight:600' : '' }}">{{ \App\Models\LeaseInspection::CONDITIONS[$it['condition']] ?? $it['condition'] }}</td><td style="{{ $td }}">{{ $it['note'] }}</td><td style="{{ $td }};text-align:right">{{ ($it['charge'] ?? 0) > 0 ? money($it['charge'], false) : '' }}</td></tr>@endforeach</tbody>
  @if((float) $i->damages > 0)<tfoot><tr><th style="{{ $td }};text-align:right" colspan="4">Damages charged (before tax)</th><th style="{{ $td }};text-align:right">{{ money($i->damages) }}</th></tr></tfoot>@endif
</table>
@if($i->notes)<p class="small">{{ $i->notes }}</p>@endif
<table style="width:100%;margin-top:3rem;font-size:13px;text-align:center"><tr>
  <td style="width:50%"><div style="border-top:1px solid #0f172a;margin:0 2rem;padding-top:4px">Inspected by</div></td>
  <td style="width:50%"><div style="border-top:1px solid #0f172a;margin:0 2rem;padding-top:4px">Tenant</div></td>
</tr></table>
@endsection
