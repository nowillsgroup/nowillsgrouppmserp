@extends('layouts.print')
@section('title', 'Agreement '.$l->lease_no)
@section('doctitle', $l->tax_class === 'commercial' ? 'COMMERCIAL LEASE AGREEMENT' : ($l->tax_class === 'serviced' ? 'SERVICED APARTMENT AGREEMENT' : 'TENANCY AGREEMENT'))
@section('docmeta')<div class="small">No. <b id="agrNo">{{ $l->lease_no }}</b><br>Prepared {{ now()->format('d/m/Y') }}@if($l->signed_at)<br>Signed {{ $l->signed_at->format('d/m/Y') }}@endif</div>@endsection
@section('content')
@php
  $u = $l->unit; $n = 0; $c = function () use (&$n) { return ++$n; };
  $pct = fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
  $perInvoice = ((float) $l->rent + (float) $l->service_charge) * $l->cycle_months;
@endphp
<style>.agr p{margin:.45rem 0;text-align:justify;font-size:13px;line-height:1.5}.agr h4{margin:1rem 0 .2rem;font-size:13.5px}.agr table td{padding:4px 8px;border-bottom:1px solid #e2e8f0;font-size:13px;vertical-align:top}</style>
<div class="agr">
<p><b>THIS AGREEMENT</b> is made on {{ ($l->signed_at ?? now())->format('jS F Y') }} <b>BETWEEN</b> {{ $hotel->name }}{{ $hotel->address ? ', of '.$hotel->address : '' }}{{ $hotel->city ? ', '.$hotel->city : '' }} (the "<b>Landlord</b>") <b>AND</b> {{ $l->company ? $l->company->name.' (the "Tenant"), for the occupation of '.$l->tenant_name : $l->tenant_name.' (the "Tenant")' }}{{ $l->id_no ? ', '.$l->id_type.' '.$l->id_no : '' }}.</p>

<table style="width:100%;border-collapse:collapse;margin:.6rem 0" id="agrSchedule">
  <tr><td style="width:34%;color:#64748b">Premises</td><td><b>{{ $u->code }} – {{ $u->name }}</b>{{ $u->block ? ', '.$u->block : '' }}{{ $u->floor !== null ? ', floor '.$u->floor : '' }} ({{ \App\Models\LeaseUnit::TYPES[$u->type] }}{{ $u->furnished ? ', furnished' : ', unfurnished' }}{{ $u->size_sqm ? ', approx. '.$pct($u->size_sqm).' m²' : '' }})</td></tr>
  <tr><td style="color:#64748b">Use</td><td>{{ $l->tax_class === 'commercial' ? 'Commercial / office use only' : 'Private residential use only' }}{{ $l->occupants ? ' – occupants: '.$l->tenant_name.', '.$l->occupants : '' }}</td></tr>
  <tr><td style="color:#64748b">Term</td><td>{{ $l->term_months }} month(s) from <b>{{ dmy($l->start_date) }}</b> to <b>{{ dmy($l->end_date) }}</b></td></tr>
  <tr><td style="color:#64748b">Rent</td><td><b>{{ money($l->rent) }}</b> per month{{ (float) $l->service_charge > 0 ? ' plus a service charge of '.money($l->service_charge).' per month' : '' }}, payable {{ strtolower(\App\Models\Lease::CYCLES[$l->cycle_months]) }} in advance ({{ money($perInvoice) }} per period before tax)</td></tr>
  <tr><td style="color:#64748b">Security deposit</td><td>{{ money($l->deposit_amount) }}</td></tr>
  <tr><td style="color:#64748b">Included services</td><td>{{ collect([$l->inclusions, $u->amenities])->filter()->implode(' · ') ?: '—' }}</td></tr>
</table>

<h4>{{ $c() }}. Rent and payment</h4>
<p>The Tenant shall pay the rent{{ (float) $l->service_charge > 0 ? ' and service charge' : '' }} {{ strtolower(\App\Models\Lease::CYCLES[$l->cycle_months]) }} in advance on the first day of each period, the first period beginning on {{ dmy($l->start_date) }}. Payment may be made by bank transfer, mobile money, card or cash against an official receipt quoting {{ $l->lease_no }}.
@if($l->tax_class === 'residential') In accordance with section 25(5) of the Rent Act, 1963 (Act 220), the Landlord shall not demand or accept rent in advance for more than {{ $cap === 6 ? 'six (6) months' : 'one (1) month' }}.@endif</p>

<h4>{{ $c() }}. Taxes</h4>
@if($l->tax_class === 'serviced')
<p>The Premises are provided as a serviced apartment with hotel services. Rent and charges are subject to VAT (15%), the National Health Insurance Levy (2.5%) and the GETFund Levy (2.5%) under the Value Added Tax Act, 2025 (Act 1151), and to the Tourism Levy (1%) under the Ghana Tourism Act, 2011 (Act 817) – {{ $pct($rate) }}% in total, shown on each tax invoice.</p>
@elseif($l->tax_class === 'commercial')
<p>Rent and charges for commercial premises are subject to VAT (15%), the NHIL (2.5%) and the GETFund Levy (2.5%) under the Value Added Tax Act, 2025 (Act 1151) – {{ $pct($rate) }}% in total, shown on each tax invoice.</p>
@else
<p>The letting of residential premises is an exempt supply for VAT purposes; no VAT, NHIL or GETFund Levy is charged on the rent.</p>
@endif
@if((float) $l->wht_pct > 0)<p>Where the Tenant is required by the Income Tax Act, 2015 (Act 896) to withhold tax from rent, the Tenant shall withhold {{ $pct($l->wht_pct) }}%, remit it to the Ghana Revenue Authority when due and give the Landlord the withholding tax credit certificate within 30 days. Tax so withheld and evidenced is treated as rent paid.</p>@endif

<h4>{{ $c() }}. Security deposit</h4>
<p>The deposit is held as security for the Tenant's obligations and is not rent paid in advance. It carries no interest. Within thirty (30) days after the Tenant gives up possession and the move-out inspection is done, the Landlord shall return the deposit less any rent, charges, utilities or cost of making good damage (fair wear and tear excepted) then owing, with a written settlement statement.</p>

<h4>{{ $c() }}. Utilities</h4>
<p>Electricity: {{ strtolower(\App\Models\Lease::UTILITY_MODES[$l->elec_mode]) }}@if($l->elec_mode === 'metered') at GH₵ {{ $tariffs['electricity'] }} per kWh on the sub-meter{{ $u->elec_meter ? ' no. '.$u->elec_meter : '' }}@endif. Water: {{ strtolower(\App\Models\Lease::UTILITY_MODES[$l->water_mode]) }}@if($l->water_mode === 'metered') at GH₵ {{ $tariffs['water'] }} per m³ on the sub-meter{{ $u->water_meter ? ' no. '.$u->water_meter : '' }}@endif. Metered consumption is read monthly and invoiced with tax at the rate applicable to this Agreement; opening and closing readings are recorded at move-in and move-out.</p>

@if((float) $l->escalation_pct > 0)
<h4>{{ $c() }}. Rent review</h4>
<p>The rent{{ (float) $l->service_charge > 0 ? ' (excluding the service charge)' : '' }} shall increase by {{ $pct($l->escalation_pct) }}% every {{ $l->escalation_months }} months, the first increase applying from {{ dmy($l->start_date->copy()->addMonthsNoOverflow($l->escalation_months)) }}.</p>
@endif

<h4>{{ $c() }}. Late payment</h4>
<p>Amounts not paid within {{ $grace }} days of the due date are in arrears.@if($lateFee > 0) A late payment fee of {{ $pct($lateFee) }}% of the overdue amount is charged once on each overdue rent invoice.@endif Persistent arrears are a breach of this Agreement.</p>

<h4>{{ $c() }}. Tenant's obligations</h4>
<p>The Tenant shall: use the Premises only for the stated use; keep the interior, fixtures and furnishings clean and in good condition (fair wear and tear excepted) and report defects promptly; not sublet, assign or part with possession without the Landlord's written consent; not make alterations; comply with the house rules, fire and security procedures of the property; not keep hazardous substances; and allow the Landlord access for inspection, repairs and showing to prospective tenants on 24 hours' notice (at any time in an emergency).</p>

<h4>{{ $c() }}. Landlord's obligations</h4>
<p>The Landlord shall give the Tenant quiet enjoyment of the Premises, keep the structure, common areas and building services in repair, insure the building, and provide the included services listed above.</p>

<h4>{{ $c() }}. Notice, renewal and termination</h4>
<p>Either party may end this Agreement by giving not less than {{ $l->notice_days }} days' written notice{{ $l->notice_days ? '' : '' }}; notice to move out before the end of the term does not release the Tenant from rent up to the move-out date. {{ $l->auto_renew ? 'Unless notice is given, this Agreement renews automatically on the same terms for a further term of up to 12 months.' : 'Any renewal shall be agreed in writing before the end of the term.' }} Recovery of possession of residential premises shall only be by agreement or by order of a Rent Magistrate or court under the Rent Act, 1963 (Act 220).</p>

<h4>{{ $c() }}. Hand-back</h4>
<p>At the end of the tenancy the Tenant shall return all keys and access cards and give up the Premises clean and in the condition recorded in the move-in inspection report (fair wear and tear excepted). A joint move-out inspection shall be carried out and closing meter readings taken.</p>

<h4>{{ $c() }}. Data protection</h4>
<p>The Landlord processes the Tenant's personal data for managing this Agreement in accordance with the Data Protection Act, 2012 (Act 843).</p>

<h4>{{ $c() }}. Law, disputes and stamp duty</h4>
<p>This Agreement is governed by the laws of the Republic of Ghana. Disputes shall first be referred to the Rent Control Department{{ $l->tax_class === 'commercial' ? ' or to mediation' : '' }} and thereafter to the courts of Ghana. This Agreement shall be stamped in accordance with the Stamp Duty Act, 2005 (Act 689); stamp duty is borne by the Tenant.</p>
@if($l->notes)<h4>{{ $c() }}. Special conditions</h4><p>{{ $l->notes }}</p>@endif
</div>

<table style="width:100%;margin-top:2.5rem;font-size:13px;text-align:center"><tr>
  <td style="width:50%"><div style="border-top:1px solid #0f172a;margin:0 1.5rem;padding-top:4px">For the Landlord<br><b>{{ $hotel->name }}</b></div></td>
  <td style="width:50%"><div style="border-top:1px solid #0f172a;margin:0 1.5rem;padding-top:4px">The Tenant<br><b>{{ $l->signed_by ?? ($l->company?->name ?? $l->tenant_name) }}</b></div></td>
</tr><tr><td colspan="2" style="padding-top:2rem"><div style="border-top:1px solid #94a3b8;margin:0 30%;padding-top:4px" class="small">Witness – name, signature &amp; date</div></td></tr></table>
@endsection
