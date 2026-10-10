@extends('layouts.print')
@section('title', $c->claim_no)
@section('doctitle', 'STAFF EXPENSE CLAIM')
@section('docmeta')<div class="small">No. <b id="ecNo">{{ $c->claim_no }}</b><br>{{ $c->employee->name }} ({{ $c->employee->emp_code }}){{ $c->employee->department ? ' · '.$c->employee->department->name : '' }}<br>Status: <b>{{ strtoupper($c->status) }}</b></div>@endsection
@section('content')
@php $td = 'padding:5px 6px;border-bottom:1px solid #e2e8f0'; $num = $td.';text-align:right;font-variant-numeric:tabular-nums'; $cats = collect(\App\Services\ExpenseClaimService::CATEGORIES)->map(fn ($x) => $x[0]); @endphp
<p style="margin-top:1rem"><b>{{ $c->title }}</b>@if($c->notes)<br><span class="small">{{ $c->notes }}</span>@endif</p>
<table style="width:100%;border-collapse:collapse;font-size:13px" id="ecLines">
  <tr style="background:#f1f5f9"><th style="{{ $td }};text-align:left">Date</th><th style="{{ $td }};text-align:left">Type</th><th style="{{ $td }};text-align:left">Description</th><th style="{{ $td }};text-align:left">Receipt</th><th style="{{ $num }}">VAT</th><th style="{{ $num }}">Amount</th></tr>
  @foreach($c->lines as $l)
  <tr><td style="{{ $td }}">{{ dmy($l->date) }}</td><td style="{{ $td }}">{{ $cats[$l->category] ?? $l->category }}</td><td style="{{ $td }}">{{ $l->description }}@if($l->km) ({{ rtrim(rtrim(number_format((float) $l->km, 1), '0'), '.') }} km) @endif</td>
    <td style="{{ $td }}">{{ $l->receipt_ref }}{{ $l->receipt_path ? ' ✓' : '' }}</td><td style="{{ $num }}">{{ (float) $l->vat_amount ? money($l->vat_amount, false) : '' }}</td><td style="{{ $num }}">{{ money($l->amount, false) }}</td></tr>
  @endforeach
  <tr><td colspan="4" style="{{ $td }}"><b>Total claimed</b></td><td style="{{ $num }}"><b>{{ money($c->vat_total, false) }}</b></td><td style="{{ $num }}"><b>{{ money($c->total, false) }}</b></td></tr>
</table>
<p class="small" style="margin-top:.6rem"><i>Ghana cedis {{ lcfirst(amount_words((float) $c->total)) }}</i></p>
<h4 style="margin-top:1rem">Charged to</h4>
<table style="width:60%;border-collapse:collapse;font-size:13px">
  @foreach($c->lines->groupBy(fn ($l) => $l->head?->label) as $h => $g)<tr><td style="{{ $td }}">{{ $h }}</td><td style="{{ $num }}">{{ money($g->sum('amount'), false) }}</td></tr>@endforeach
</table>
@if($c->status === 'paid')<p class="small" style="margin-top:.8rem">Paid {{ dmy($c->paid_on) }} {{ $c->pay_method === 'petty' ? 'in cash from '.$c->pettyTxn?->float?->name.' ('.$c->pettyTxn?->txn_no.')' : 'by '.$c->paymentMode?->name.($c->pay_reference ? ' · ref '.$c->pay_reference : '') }}.</p>@endif
<p class="tiny" style="margin-top:1rem;color:#64748b">I certify that these expenses were incurred wholly on hotel business and have not been claimed before.</p>
<table style="width:100%;margin-top:2.5rem;font-size:13px;text-align:center"><tr>
  <td><div style="border-top:1px solid #0f172a;margin:0 1rem;padding-top:4px">Claimant<br><b>{{ $c->employee->name }}</b>@if($c->submitted_at)<br><span class="tiny">{{ $c->submitted_at->format('d M Y') }}</span>@endif</div></td>
  <td><div style="border-top:1px solid #0f172a;margin:0 1rem;padding-top:4px">Approved by<br><b>{{ $c->status !== 'rejected' ? $c->approver?->name : '' }}</b>@if($c->approved_on)<br><span class="tiny">{{ dmy($c->approved_on) }}</span>@endif</div></td>
  <td><div style="border-top:1px solid #0f172a;margin:0 1rem;padding-top:4px">Paid by<br><b>{{ $c->payer?->name }}</b></div></td>
</tr></table>
@endsection
