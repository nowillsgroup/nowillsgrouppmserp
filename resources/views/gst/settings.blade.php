@extends('layouts.app')
@section('title', 'GRA VAT & E-VAT settings')
@section('crumbs') Billing / GRA VAT / Settings @endsection
@section('content')
@include('gst._nav')
<form method="post" action="{{ route('gst.settings.save') }}" id="gstSettings">@csrf
<div class="grid g-2">
  <div>
    <div class="card"><div class="card-head"><h3><x-icon name="building"/> Registration</h3>@if(can_do('settings.manage'))<a class="small" href="{{ route('settings.index') }}">Edit in Hotel settings →</a>@endif</div><div class="card-body">
      <ul class="bq-list">
        <li><span>Legal name</span><b>{{ $hotel->legal_name ?: $hotel->name }}</b></li>
        <li><span>TIN</span><b class="mono">{{ $tin ?? '—' }} {!! $tinOk ? '<span class="badge badge-green">valid</span> <span class="tiny muted">'.e(\App\Services\GstService::tinType($tin)).'</span>' : '<span class="badge badge-red">invalid</span>' !!}</b></li>
        <li><span>Address</span><b>{{ trim($hotel->address.', '.$hotel->city, ', ') ?: '—' }}</b></li>
        <li><span>Base currency</span><b>{{ $hotel->currency }} ({{ $hotel->currency_symbol }})</b></li>
        <li><span>VAT registration</span><b id="vatStatus"><span class="badge {{ ['registered' => 'badge-green', 'must_register' => 'badge-red', 'approaching' => 'badge-amber', 'below_threshold' => 'badge-blue'][$reg['status']] }}">{{ $reg['status_label'] }}</span> <span class="tiny muted">{{ $reg['registered_at'] ? 'since '.\Illuminate\Support\Carbon::parse($reg['registered_at'])->format('d M Y') : 'no date recorded' }}</span></b></li>
        <li><span>Supplies</span><b>{{ $reg['supply_kind_label'] }}</b></li>
        <li><span>Registration threshold</span><b>{{ $reg['threshold_label'] }}</b></li>
        <li><span>Taxable supplies, {{ $reg['window'] }}</span><b class="mono">{{ money($reg['turnover']['completed']) }}</b></li>
        <li><span>The twelve months to today</span><b class="mono">{{ money($reg['turnover']['rolling']) }} <span class="tiny muted">{{ $reg['turnover']['open_period'] }} still open</span></b></li>
        @if($reg['pct'] !== null)<li><span>Of the threshold</span><b>{{ $reg['pct'] }}%</b></li>@endif
      </ul>
      <div class="alert {{ $reg['status'] === 'registered' ? ($blocker ? 'alert-warn' : 'alert-info') : ($reg['status'] === 'must_register' ? 'alert-error' : 'alert-warn') }} mt-1" id="vatTest">
        <x-icon name="alert"/><div><b>{{ $reg['act'] }}.</b> {{ $reg['note'] }}
          @if($blocker)<div class="mt-1"><b>E-VAT is not submitting:</b> {{ $blocker }}</div>@endif
          <div class="tiny mt-1">Turnover is this property's own taxable base, month by month, from the same figures the filed VAT return uses — closed documents with credit notes negative, plus direct-settled outlet bills.</div>
        </div></div>
      <p class="tiny muted mt-1">GRA TIN: P (individual), C (company), G (government), Q (other organisation) or V + 10 digits; individuals may use their Ghana Card PIN (GHA-123456789-0). Buyers with a valid TIN are B2B – their TIN is printed on the invoice and sent to E-VAT.</p>
    </div></div>

    <div class="card mt-1"><div class="card-head"><h3><x-icon name="qr"/> GRA E-VAT</h3></div><div class="card-body form-grid">
      <label class="check span-all"><input type="checkbox" name="gst_einvoice_enabled" value="1" @checked(old('gst_einvoice_enabled', $s['gst_einvoice_enabled']) == '1')> Submit every tax invoice and credit note to the GRA Virtual Sales Data Controller (VSDC)</label>
      @error('gst_einvoice_enabled')<span class="err span-all">{{ $message }}</span>@enderror
      <label class="check span-all"><input type="checkbox" name="gst_einvoice_auto" value="1" @checked(old('gst_einvoice_auto', $s['gst_einvoice_auto']) == '1')> Stamp automatically when an invoice is settled / a credit note is issued</label>
      <label class="check span-all"><input type="checkbox" name="gst_evat_pos" id="f_gst_evat_pos" value="1" @checked(old('gst_evat_pos', $s['gst_evat_pos']) == '1')> Also stamp restaurant / outlet bills paid at the outlet (VAT receipts); bills posted to a room are stamped with the guest invoice</label>
      <x-field name="gst_einvoice_driver" label="Connection" type="select" :options="['sandbox' => 'Sandbox – offline VSDC simulator (test stamps)', 'gra' => 'GRA E-VAT API (certified integration)']" :value="$s['gst_einvoice_driver']" :placeholder="false" class="span-all"/>
    </div></div>

    <div class="card mt-1" id="evatBox"><div class="card-head"><h3><x-icon name="key"/> E-VAT API credentials</h3></div><div class="card-body form-grid">
      <x-field name="gst_evat_url" label="Invoice endpoint URL" :value="$s['gst_evat_url']" placeholder="https://…gra.gov.gh/…/invoice" hint="Issued by GRA after joint user-acceptance testing" class="span-all"/>
      <x-field name="gst_evat_key" label="Security key" type="password" placeholder="{{ $s['gst_evat_key'] ? '•••••••• stored – leave blank to keep' : '' }}"/>
      <x-field name="gst_evat_username" label="E-VAT user name" :value="$s['gst_evat_username']" hint="Blank = the signed-in user"/>
    </div></div>
  </div>
  <div>
    <div class="card mt-1"><div class="card-head"><h3><x-icon name="money"/> Price entry</h3></div><div class="card-body">
      <label class="check span-all"><input type="radio" name="tax_prices_inclusive" value="0" @checked(old('tax_prices_inclusive', $s['tax_prices_inclusive'] ?? '0') !== '1')> <span><b>Tax-exclusive</b> — prices are entered net of tax and the tax is added at the till (current behaviour)</span></label>
      <label class="check span-all"><input type="radio" name="tax_prices_inclusive" value="1" @checked(old('tax_prices_inclusive', $s['tax_prices_inclusive'] ?? '0') === '1')> <span><b>Tax-inclusive</b> — prices include tax; the till extracts VAT, NHIL, GETFund and the Tourism Levy from the price</span></label>
      <p class="tiny muted span-all">Applies to POS outlets, the recreation ticket outlet, club day passes and membership charges. Room rates keep their own per-rate-plan tax-inclusive setting.</p>
    </div></div>
    <div class="card flush"><div class="card-head"><h3><x-icon name="layers"/> Tax components</h3>@if(can_do('masters.manage'))<a class="small" href="{{ route('masters.index', 'taxes') }}">Edit in Masters →</a>@endif</div>
      <div class="card-body table-wrap"><table class="table" id="taxTable"><thead><tr><th>Tax</th><th>Code</th><th>Authority</th><th class="num">Rate</th><th>Applies to</th><th>Status</th></tr></thead><tbody>
      @forelse($taxes as $t)<tr><td>{{ $t->name }}</td><td class="mono small">{{ $t->code ?? '—' }}</td><td class="small">{{ $t->authority ?? '—' }}</td><td class="num">{{ rtrim(rtrim(number_format($t->rate, 2), '0'), '.') }}%</td><td class="small">{{ ucfirst($t->applies_to) }}</td>
        <td>{!! $t->is_active ? '<span class="badge badge-green">active</span>' : '<span class="badge badge-slate">off</span>' !!}</td></tr>
      @empty<tr><td colspan="6"><x-empty text="No taxes configured." icon="layers"/></td></tr>@endforelse
      </tbody></table></div>
      <div class="card-body"><p class="tiny muted">VAT Act 2025 (Act 1151, from 1 Jan 2026): VAT 15%, NHIL 2.5% and GETFund levy 2.5% are all charged on the same tax-exclusive value (no cascading) and are input-deductible. The Tourism Levy (Act 817, L.I. 2185) is 1% of the net value of services at hotels, restaurants and event venues, charged separately and remitted to the Ghana Tourism Authority within 30 days after the month.</p></div>
    </div>
  </div>
</div>
<div class="mt-1"><button class="btn btn-primary" id="saveGst"><x-icon name="check"/> Save settings</button></div>
</form>
@endsection
