@extends('layouts.print')
@php $titles = ['expense' => 'PETTY CASH VOUCHER', 'advance' => 'IOU – CASH ADVANCE', 'settle' => 'IOU SETTLEMENT', 'claim_pay' => 'PETTY CASH VOUCHER – EXPENSE CLAIM', 'topup' => 'PETTY CASH TOP-UP', 'variance' => 'CASH COUNT ADJUSTMENT']; @endphp
@section('title', $t->txn_no)
@section('doctitle', $titles[$t->type] ?? 'PETTY CASH')
@section('docmeta')<div class="small">No. <b id="pvNo">{{ $t->txn_no }}</b> · {{ dmy($t->date) }}<br>{{ $t->float->name }}<br>Status: <b>{{ strtoupper($t->status) }}</b></div>@endsection
@section('content')
@php $td = 'padding:7px 8px;border-bottom:1px solid #e2e8f0;vertical-align:top'; @endphp
<table style="width:100%;border-collapse:collapse;font-size:14px;margin-top:1rem" id="pvTable">
  <tr><td style="{{ $td }};width:32%;color:#64748b">{{ in_array($t->type, ['advance', 'settle', 'claim_pay']) ? 'Staff member' : 'Paid to' }}</td><td style="{{ $td }}"><b>{{ $t->party() }}</b>@if($t->employee) <span class="small">({{ $t->employee->emp_code }})</span>@endif</td></tr>
  <tr><td style="{{ $td }};color:#64748b">For</td><td style="{{ $td }}">{{ $t->description }}</td></tr>
  @if($t->head)<tr><td style="{{ $td }};color:#64748b">Account</td><td style="{{ $td }}">{{ $t->head->label }}</td></tr>@endif
  @if($t->type === 'settle')
  <tr><td style="{{ $td }};color:#64748b">IOU</td><td style="{{ $td }}">{{ $t->advance?->txn_no }} of {{ dmy($t->advance?->date) }} · {{ money($t->amount) }}</td></tr>
  <tr><td style="{{ $td }};color:#64748b">Spent per receipts</td><td style="{{ $td }}">{{ money($t->spent) }}</td></tr>
  <tr><td style="{{ $td }};color:#64748b">{{ (float) $t->effect >= 0 ? 'Change returned' : 'Further amount paid out' }}</td><td style="{{ $td }}"><b>{{ money(abs((float) $t->effect)) }}</b></td></tr>
  @else
  <tr><td style="{{ $td }};color:#64748b">Amount</td><td style="{{ $td }};font-size:18px"><b id="pvAmount">{{ money($t->amount) }}</b></td></tr>
  <tr><td style="{{ $td }};color:#64748b">In words</td><td style="{{ $td }}"><i>Ghana cedis {{ lcfirst(amount_words((float) $t->amount)) }}</i></td></tr>
  @endif
  @if((float) $t->vat_amount > 0)<tr><td style="{{ $td }};color:#64748b">VAT, NHIL &amp; GETFund in the receipt</td><td style="{{ $td }}">{{ money($t->vat_amount) }} (GRA VAT invoice)</td></tr>@endif
  @if($t->type === 'advance')<tr><td style="{{ $td }};color:#64748b">To be settled by</td><td style="{{ $td }}">{{ dmy($t->due_date) }} with receipts and any change</td></tr>@endif
  @if($t->receipt_ref || $t->receipt_path || $t->no_receipt_reason)<tr><td style="{{ $td }};color:#64748b">Receipt</td><td style="{{ $td }}">{{ $t->receipt_ref }}{{ $t->receipt_path ? ' (attached)' : '' }}{{ $t->no_receipt_reason ? 'None – '.$t->no_receipt_reason : '' }}</td></tr>@endif
</table>
<table style="width:100%;margin-top:3rem;font-size:13px;text-align:center"><tr>
  <td style="width:33%"><div style="border-top:1px solid #0f172a;margin:0 1rem;padding-top:4px">Prepared by<br><b>{{ $t->creator?->name }}</b></div></td>
  <td style="width:33%"><div style="border-top:1px solid #0f172a;margin:0 1rem;padding-top:4px">Approved by<br><b>{{ $t->approver?->name }}</b></div></td>
  <td style="width:33%"><div style="border-top:1px solid #0f172a;margin:0 1rem;padding-top:4px">{{ $t->type === 'settle' ? 'Settled by' : 'Received by' }}<br><b>{{ $t->party() }}</b></div></td>
</tr></table>
@endsection
