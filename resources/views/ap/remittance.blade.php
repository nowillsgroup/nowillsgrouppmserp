@extends('layouts.print')
@section('title', 'Remittance '.$p->payment_no)
@section('doctitle', 'REMITTANCE ADVICE')
@section('docmeta')<div class="small"><b>{{ $p->payment_no }}</b></div><div class="small">{{ $p->date->format('d M Y') }}</div>@endsection
@section('content')
@if($p->cancelled_at)<div style="border:2px solid #dc2626;color:#dc2626;padding:.4rem;text-align:center;font-weight:700;margin-bottom:1rem">CANCELLED – {{ $p->cancel_reason }}</div>@endif
<table style="width:100%;margin-bottom:1rem"><tr>
<td style="vertical-align:top"><div class="small text-2">To</div><b>{{ $p->supplier->name }}</b><div class="small">{{ $p->supplier->contact_person }}</div><div class="small">{{ $p->supplier->address }}</div><div class="small">{{ $p->supplier->phone }} {{ $p->supplier->email }}</div>@if($p->supplier->tax_number)<div class="small">TIN: {{ $p->supplier->tax_number }}</div>@endif</td>
<td style="vertical-align:top;text-align:right"><div class="small text-2">Payment</div><div style="font-size:1.4rem;font-weight:700">@if($p->isForeign()){{ $p->fxLabel() }}@else{{ money($p->withheld() > 0 ? $p->netPaid() : $p->amount) }}@endif</div><div class="small">{{ $p->paymentMode?->name }}{{ $p->reference ? ' · Ref '.$p->reference : '' }}</div>@if($p->isForeign())<div class="small">equivalent to {{ money($p->withheld() > 0 ? $p->netPaid() : $p->amount) }} at {{ number_format((float) $p->fx_rate, 4) }}</div>@endif</td>
</tr></table>
<p class="small">Please find below the details of our payment. Kindly update your records.</p>
<table class="table table-compact"><thead><tr><th>Our ref.</th><th>Your invoice</th><th>Invoice date</th><th class="num">Invoice amount</th><th class="num">Paid</th></tr></thead><tbody>
@forelse($p->allocations as $a)<tr><td>{{ $a->invoice->doc_no }}</td><td>{{ $a->invoice->supplier_ref }}</td><td>{{ $a->invoice->doc_date->format('d M Y') }}</td><td class="num">{{ money($a->invoice->total, false) }}</td><td class="num">{{ money($a->amount, false) }}@if($a->invoice->isForeign())<div class="tiny">{{ $a->invoice->currency }} {{ number_format($a->amount / (float) $a->invoice->fx_rate, 2) }}</div>@endif</td></tr>
@empty<tr><td colspan="5">Payment on account</td></tr>@endforelse
@if($p->unapplied() > 0)<tr><td colspan="4">Advance / on account</td><td class="num">{{ money($p->unapplied(), false) }}</td></tr>@endif
@if($p->fxDifference() != 0.0)<tr><td colspan="4">Internal GHS reconciliation: transfer + GRA tax − invoice carrying value ({{ $p->fxDifference() > 0 ? 'realised loss' : 'realised gain' }}; not an extra amount due to you)</td><td class="num">{{ money(abs($p->fxDifference()), false) }}</td></tr>@endif
</tbody><tfoot><tr><td colspan="4" class="right bold">{{ $p->withheld() > 0 ? 'Total settled' : 'Total paid' }}</td><td class="num bold">{{ money($p->amount, false) }}</td></tr>
@if((float) $p->wht_amount > 0)<tr><td colspan="4" class="right">Less withholding tax {{ rtrim(rtrim(number_format($p->wht_rate, 2), '0'), '.') }}% ({{ \App\Services\WhtService::label($p->wht_category) }}) – certificate {{ $p->wht_cert_no }}</td><td class="num">-{{ money($p->wht_amount, false) }}</td></tr>@endif
@if((float) $p->wvat_amount > 0)<tr><td colspan="4" class="right">Less withholding VAT</td><td class="num">-{{ money($p->wvat_amount, false) }}</td></tr>@endif
@if($p->withheld() > 0)<tr><td colspan="4" class="right bold">Net paid to you</td><td class="num bold">{{ money($p->netPaid(), false) }}</td></tr>@endif
</tfoot></table>
@if($p->withheld() > 0)<p class="small">The tax withheld is paid to the Ghana Revenue Authority on your behalf; the WHT credit certificate is enclosed – you can offset it against your income tax.</p>@endif
<p class="small text-2" style="margin-top:2rem">Prepared by {{ $p->creator?->name }} · {{ now()->format('d M Y H:i') }}</p>
@endsection
