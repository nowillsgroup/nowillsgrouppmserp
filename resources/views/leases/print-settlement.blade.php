@extends('layouts.print')
@section('title', 'Deposit settlement '.$l->lease_no)
@section('doctitle', 'DEPOSIT SETTLEMENT')
@section('docmeta')<div class="small">Lease <b>{{ $l->lease_no }}</b> · {{ $l->unit->code }}<br>Moved out {{ dmy($l->move_out_on ?? $l->end_date) }}@if($l->settled_at)<br>Settled {{ $l->settled_at->format('d/m/Y') }}@else<br><b>DRAFT – not yet settled</b>@endif</div>@endsection
@section('content')
@php $td = 'padding:6px 8px;border-bottom:1px solid #e2e8f0'; $out = $l->inspections->where('kind', 'move_out')->first(); @endphp
<div style="margin:1rem 0"><b>{{ $l->company?->name ?? $l->tenant_name }}</b>@if($l->company)<br>Occupant: {{ $l->tenant_name }}@endif<br>{{ $l->unit->code }} – {{ $l->unit->name }} · {{ dmy($l->start_date) }} to {{ dmy($l->move_out_on ?? $l->end_date) }}</div>
<h4>Deposit movements</h4>
<table style="width:100%;border-collapse:collapse;font-size:13px">
  @foreach($deps as $d)<tr><td style="{{ $td }}">{{ dmy($d->date) }}</td><td style="{{ $td }}">{{ $d->receipt_no }}</td><td style="{{ $td }}">{{ \App\Models\LeasePayment::KINDS[$d->kind] }}{{ $d->paymentMode ? ' – '.$d->paymentMode->name : '' }}{{ $d->reference ? ' ('.$d->reference.')' : '' }}</td><td style="{{ $td }};text-align:right;white-space:nowrap">{{ $d->kind === 'deposit' ? '' : '−' }}{{ money($d->amount, false) }}</td></tr>@endforeach
</table>
<h4 style="margin-top:1rem">Final account</h4>
<table style="width:100%;border-collapse:collapse;font-size:13px" id="finalTable">
  @foreach($finals as $f)<tr><td style="{{ $td }}">{{ $f->charge_no }}</td><td style="{{ $td }}">{{ $f->description }}</td><td style="{{ $td }};text-align:right;white-space:nowrap">{{ money($f->total, false) }}</td></tr>@endforeach
</table>
@if($out)<p class="small">Move-out inspection {{ dmy($out->date) }}: {{ collect($out->items)->whereIn('condition', ['damaged', 'missing'])->map(fn ($i) => $i['item'].' – '.$i['note'].($i['charge'] > 0 ? ' ('.money($i['charge'], false).')' : ''))->implode('; ') ?: 'no damage recorded' }}.</p>@endif
<table style="width:60%;margin-left:40%;border-collapse:collapse;font-size:14px;margin-top:1rem" id="settleSummary">
  @if($l->settled_at)
    <tr><td style="{{ $td }}">Deposit applied to the account</td><td style="{{ $td }};text-align:right;white-space:nowrap">{{ money($deps->where('kind', 'deposit_applied')->sum('amount')) }}</td></tr>
    <tr><td style="{{ $td }}"><b>Deposit refunded</b></td><td style="{{ $td }};text-align:right;white-space:nowrap"><b>{{ money($deps->where('kind', 'deposit_refund')->sum('amount')) }}</b></td></tr>
    @php $credRef = (float) \App\Models\LeasePayment::where('lease_id', $l->id)->where('kind', 'refund')->whereNull('voided_at')->where('date', '>=', ($l->move_out_on ?? $l->end_date)->toDateString())->sum('amount'); @endphp
    @if($credRef > 0)<tr><td style="{{ $td }}">Account credit refunded (unused rent, overpayments)</td><td style="{{ $td }};text-align:right;white-space:nowrap">{{ money($credRef) }}</td></tr>@endif
    <tr><td style="{{ $td }}">Balance still owed by the tenant</td><td style="{{ $td }};text-align:right;white-space:nowrap">{{ money(max(0, $s['balance'])) }}</td></tr>
  @else
    <tr><td style="{{ $td }}">Deposit held</td><td style="{{ $td }};text-align:right;white-space:nowrap">{{ money($s['held']) }}</td></tr>
    <tr><td style="{{ $td }}">Balance on the account</td><td style="{{ $td }};text-align:right;white-space:nowrap">{{ money($s['balance']) }}</td></tr>
    <tr><td style="{{ $td }}">To be applied</td><td style="{{ $td }};text-align:right;white-space:nowrap">{{ money($s['apply']) }}</td></tr>
    <tr><td style="{{ $td }}">Deposit to be refunded</td><td style="{{ $td }};text-align:right;white-space:nowrap">{{ money($s['refund']) }}</td></tr>
    @if($s['credit'] > 0)<tr><td style="{{ $td }}">Account credit to be refunded</td><td style="{{ $td }};text-align:right;white-space:nowrap">{{ money($s['credit']) }}</td></tr>@endif
    <tr><td style="{{ $td }}"><b>Total to refund</b></td><td style="{{ $td }};text-align:right;white-space:nowrap"><b>{{ money($s['total_refund']) }}</b></td></tr>
    @if($s['due'] > 0)<tr><td style="{{ $td }}">Still owed by the tenant</td><td style="{{ $td }};text-align:right;white-space:nowrap">{{ money($s['due']) }}</td></tr>@endif
  @endif
</table>
<table style="width:100%;margin-top:3rem;font-size:13px;text-align:center"><tr>
  <td style="width:50%"><div style="border-top:1px solid #0f172a;margin:0 2rem;padding-top:4px">For the Landlord</div></td>
  <td style="width:50%"><div style="border-top:1px solid #0f172a;margin:0 2rem;padding-top:4px">Tenant – accepted<br><b>{{ $l->tenant_name }}</b></div></td>
</tr></table>
@endsection
