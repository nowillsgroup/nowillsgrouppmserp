@extends('layouts.print')
@section('title', 'WHT certificate '.$p->wht_cert_no)
@section('doctitle', 'WITHHOLDING TAX CREDIT CERTIFICATE')
@section('docmeta')<div class="small">Certificate no. <b id="certNo">{{ $p->wht_cert_no }}</b></div><div class="small">Date {{ $p->date->format('d M Y') }}</div>@endsection
@section('content')
@if($p->cancelled_at)<div style="border:2px solid #dc2626;color:#dc2626;padding:.4rem;text-align:center;font-weight:700;margin-bottom:1rem">VOID – the payment was cancelled ({{ $p->cancel_reason }})</div>@endif
<table style="width:100%;margin-bottom:1rem"><tr>
<td style="vertical-align:top;width:50%"><div class="small text-2">Withholding agent</div><b>{{ $currentHotel->legal_name ?: $currentHotel->name }}</b><div class="small">{{ $currentHotel->address }}</div><div class="small">TIN: <b>{{ $tin ?: '—' }}</b></div></td>
<td style="vertical-align:top"><div class="small text-2">Supplier (withholdee)</div><b>{{ $p->supplier->name }}</b><div class="small">{{ $p->supplier->address }}</div><div class="small">TIN: <b id="certTin">{{ $p->supplier->tax_number ?: 'not provided' }}</b></div></td>
</tr></table>
<table class="table table-compact" id="certTable"><tbody>
<tr><td>Nature of payment</td><td class="bold">{{ \App\Services\WhtService::label($p->wht_category) }}</td></tr>
<tr><td>Payment reference</td><td>{{ $p->payment_no }}{{ $p->reference ? ' · '.$p->reference : '' }} ({{ $p->paymentMode?->name }})</td></tr>
<tr><td>Invoices settled</td><td>@forelse($p->allocations as $a){{ $a->invoice->supplier_ref ?: $a->invoice->doc_no }}@if(! $loop->last), @endif @empty on account @endforelse</td></tr>
<tr><td>Gross amount settled</td><td class="num">{{ money($p->amount) }}</td></tr>
<tr><td>Amount subject to tax (excl. VAT)</td><td class="num">{{ money($p->wht_base) }}</td></tr>
@if((float) $p->wht_amount > 0)<tr><td>Withholding tax rate</td><td class="num">{{ rtrim(rtrim(number_format($p->wht_rate, 2), '0'), '.') }}%</td></tr>
<tr><td class="bold">Tax withheld</td><td class="num bold" id="certWht">{{ money($p->wht_amount) }}</td></tr>@endif
@if((float) $p->wvat_amount > 0)<tr><td class="bold">Withholding VAT ({{ money($p->wvat_base) }} taxable value)</td><td class="num bold">{{ money($p->wvat_amount) }}</td></tr>@endif
<tr><td>Net amount paid</td><td class="num">{{ money($p->netPaid()) }}</td></tr>
</tbody></table>
<p class="small" style="margin-top:1rem">We certify that the tax shown above has been withheld from our payment to you and will be paid to the Ghana Revenue Authority with our withholding tax return for {{ $p->date->format('F Y') }} (due {{ \App\Services\WhtService::due($p->date->format('Y-m'))->format('d M Y') }}). Keep this certificate: tax withheld on account can be credited against your income tax (Income Tax Act 2015, Act 896).</p>
<table style="width:100%;margin-top:3rem"><tr><td style="width:50%"></td><td style="text-align:center;border-top:1px solid #333;padding-top:.3rem" class="small">For {{ $currentHotel->legal_name ?: $currentHotel->name }} – authorised signatory</td></tr></table>
@endsection
