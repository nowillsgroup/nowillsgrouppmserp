@extends('layouts.print')
@section('title', 'Comparative '.$rfq->rfq_no)
@section('doctitle', 'COMPARATIVE STATEMENT')
@section('docmeta')<div class="small">{{ $rfq->rfq_no }} · {{ $rfq->title }}<br>Quotes due {{ $rfq->respond_by->format('d M Y') }} · {{ $cmp['quotes']->count() }} received<br>Status: <b>{{ strtoupper($rfq->status) }}</b></div>@endsection
@section('content')
@php $td = 'padding:5px 6px;border:1px solid #cbd5e1'; $quotes = $cmp['quotes']; @endphp
<table style="width:100%;border-collapse:collapse;font-size:12px;margin-top:1rem" id="cmpTable">
  <tr style="background:#f1f5f9"><th style="{{ $td }};text-align:left">Item</th><th style="{{ $td }}">Qty</th><th style="{{ $td }}">Last rate</th>@foreach($quotes as $q)<th style="{{ $td }}">{{ $q->supplier->name }}</th>@endforeach</tr>
  @foreach($cmp['rows'] as $r)<tr><td style="{{ $td }}">{{ $r['line']->item->name }}</td><td style="{{ $td }};text-align:right">{{ (float) $r['line']->qty }} {{ $r['line']->item->unit }}</td><td style="{{ $td }};text-align:right">{{ $r['last'] !== null ? number_format($r['last'], 2) : '—' }}</td>
    @foreach($quotes as $q)@php $c = $r['cells'][$q->id]; $low = $c && $c['ql']->id === $r['lowest']; $won = $c && $r['line']->awarded_quote_line_id === $c['ql']->id; @endphp
    <td style="{{ $td }};text-align:right;{{ $low ? 'background:#dcfce7;' : '' }}{{ $won ? 'font-weight:800;' : '' }}">@if($c){{ number_format($c['ql']->rate, 2) }} <span style="color:#64748b">+{{ (float) $c['ql']->tax_rate }}%</span>@if(! $c['full'])<br><i>qty {{ $c['qty'] }}</i>@endif @if($won) ✔@endif @else — @endif</td>@endforeach</tr>
  @if($r['line']->award_reason)<tr><td colspan="{{ 3 + $quotes->count() }}" style="{{ $td }};font-size:11px"><i>Not L1 – {{ $r['line']->award_reason }}</i></td></tr>@endif
  @endforeach
  <tr style="font-weight:700"><td colspan="3" style="{{ $td }};text-align:right">Total incl. tax</td>@foreach($quotes as $q)<td style="{{ $td }};text-align:right">{{ number_format($q->total, 2) }}</td>@endforeach</tr>
  <tr><td colspan="3" style="{{ $td }};text-align:right">Delivery / payment (days)</td>@foreach($quotes as $q)<td style="{{ $td }};text-align:right">{{ $q->delivery_days ?? '—' }} / {{ $q->payment_terms_days ?? '—' }}</td>@endforeach</tr>
  <tr><td colspan="3" style="{{ $td }};text-align:right">Quote ref · valid until</td>@foreach($quotes as $q)<td style="{{ $td }};text-align:right">{{ $q->quote_ref ?: '—' }} · {{ dmy($q->valid_until) }}</td>@endforeach</tr>
</table>
<p class="small">Green = lowest landed price (L1) for the full quantity. ✔ = awarded. Total at L1: GH₵ {{ number_format($cmp['lowTotal'], 2) }}.@if($rfq->award_note) Award note: {{ $rfq->award_note }}@endif</p>
<table style="width:100%;margin-top:3rem;font-size:13px;text-align:center"><tr>
  <td style="width:33%"><div style="border-top:1px solid #0f172a;margin:0 1rem;padding-top:4px">Prepared by<br><b>{{ $rfq->creator?->name }}</b></div></td>
  <td style="width:33%"><div style="border-top:1px solid #0f172a;margin:0 1rem;padding-top:4px">Recommended / awarded by<br><b>{{ $rfq->awarder?->name }}</b></div></td>
  <td style="width:33%"><div style="border-top:1px solid #0f172a;margin:0 1rem;padding-top:4px">Approved (General Manager)</div></td>
</tr></table>
@endsection
