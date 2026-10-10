@extends('layouts.app')
@section('title', 'Withholding Tax (GRA)')
@section('crumbs') <a href="{{ route('ap.payments') }}">Accounts Payable</a> / Withholding tax @endsection
@section('actions')
<form class="filters" style="margin:0;display:inline-flex"><select name="period" class="input" onchange="this.form.submit()" id="whtPeriod">@foreach($periods as $p)<option value="{{ $p }}" @selected($p === $period)>{{ \Illuminate\Support\Carbon::parse($p.'-01')->format('F Y') }}</option>@endforeach</select></form>
<a class="btn" id="whtExport" href="{{ route('ap.wht.export', ['period' => $period]) }}"><x-icon name="download"/> Schedule (CSV)</a>
@if(can_do('reports.view'))<a class="btn" href="{{ route('reports.show', ['wht_register', 'from' => $period.'-01', 'to' => \Illuminate\Support\Carbon::parse($period.'-01')->endOfMonth()->toDateString()]) }}"><x-icon name="chart"/> WHT register</a>@endif
@endsection
@section('content')
@php $pl = \Illuminate\Support\Carbon::parse($period.'-01')->format('F Y'); @endphp
@if(! $enabled)
<div class="alert alert-warn"><x-icon name="alert"/><div>Withholding tax is switched off – supplier payments are made in full. Resident businesses must withhold tax on payments for goods, works and services above the threshold, and on rent and payments to non-residents.</div></div>
@endif
@if($alert && $alert['period'] !== $period)
<div class="alert {{ $alert['overdue'] ? 'alert-error' : 'alert-warn' }}" id="whtAlert"><x-icon name="alert"/><div>The WHT return for <b>{{ \Illuminate\Support\Carbon::parse($alert['period'].'-01')->format('F Y') }}</b> is {{ $alert['overdue'] ? 'overdue' : 'due' }} ({{ $alert['due']->format('d M Y') }}). <a href="{{ route('ap.wht', ['period' => $alert['period']]) }}">Open it →</a></div></div>
@endif

<div class="stats">
  <x-stat label="Tax withheld · {{ $pl }}" :value="money($r['wht'])" icon="receipt" tone="blue" :sub="$r['rows']->where('wht_amount', '>', 0)->count().' payment(s) · base '.money($r['base'])"/>
  <x-stat label="Withholding VAT" :value="money($r['wvat'])" icon="money" tone="purple" :sub="$cfg['wvat_agent'] === '1' ? 'appointed agent · '.$cfg['wvat_rate'].'%' : 'not an appointed agent'"/>
  <x-stat label="Payable to GRA" :value="money($r['wht'] + $r['wvat'])" icon="building" :tone="$r['filing'] ? 'green' : 'amber'" :sub="'due '.$r['due']->format('d M Y')"/>
  <x-stat label="Status" :value="$r['filing'] ? 'Filed' : ($closed ? 'Not filed' : 'Month open')" :icon="$r['filing'] ? 'check' : 'clock'" :tone="$r['filing'] ? 'green' : ($closed && bdate()->gt($r['due']) ? 'red' : 'slate')" :sub="$r['filing'] ? $r['filing']->reference : null"/>
</div>

@if($r['missing_tin']->isNotEmpty())
<div class="alert alert-warn" id="whtMissingTin"><x-icon name="alert"/><div>No valid TIN for: <b>{{ $r['missing_tin']->join(', ') }}</b>. GRA needs the supplier's TIN on the return – add it under Masters › Suppliers.</div></div>
@endif
@if($r['gl'] !== null && abs($r['gl'] - $r['wht']) > 0.01)
<div class="alert alert-info" id="whtGlDiff"><x-icon name="alert"/><div>The ledger shows {{ money($r['gl']) }} credited to <i>WHT Payable</i> this month against {{ money($r['wht']) }} on the schedule – payments post to the ledger when the night audit closes their day, and manual journals on the account are included.</div></div>
@endif

<div class="grid g-2">
<div class="card flush"><div class="card-head"><h3>By nature of payment</h3></div><div class="card-body table-wrap"><table class="table" id="whtCats">
  <thead><tr><th>Nature of payment</th><th class="num">Rate</th><th class="num">Payments</th><th class="num">Taxable</th><th class="num">Tax withheld</th></tr></thead><tbody>
  @forelse($r['cats'] as $c)<tr><td>{{ $c['label'] }}</td><td class="num">{{ rtrim(rtrim(number_format($c['rate'], 2), '0'), '.') }}%</td><td class="num">{{ $c['n'] }}</td><td class="num">{{ money($c['base'], false) }}</td><td class="num bold">{{ money($c['wht'], false) }}</td></tr>
  @empty<tr><td colspan="5"><x-empty text="No tax withheld in {{ $pl }}." icon="receipt"/></td></tr>@endforelse
  </tbody>@if($r['cats']->isNotEmpty())<tfoot><tr><td colspan="3" class="bold">Total</td><td class="num bold">{{ money($r['cats']->sum('base'), false) }}</td><td class="num bold" id="whtTotal">{{ money($r['wht'], false) }}</td></tr></tfoot>@endif</table></div></div>

<div class="card">
  @if($r['filing'])
  <div class="card-head"><h3><x-icon name="lock"/> Filed</h3></div>
  <div class="card-body" id="whtFiled"><p>Filed on <b>{{ $r['filing']->filed_on->format('d M Y') }}</b> with GRA reference <b class="mono">{{ $r['filing']->reference }}</b> by {{ $r['filing']->filer?->name }}.</p>
    <p class="small muted">Withheld payments dated in {{ $pl }} can no longer be cancelled, and new payments with tax withheld cannot be dated in this month.</p></div>
  @else
  <div class="card-head"><h3><x-icon name="lock"/> Mark as filed</h3><span class="small muted">GRA Taxpayers' Portal › WHT return · due {{ $r['due']->format('d M Y') }}</span></div>
  <div class="card-body">
    @if(! $closed)<p class="small muted">{{ $pl }} is still open – file it after the month end (by the 15th of the following month).</p>
    @elseif(can_do('ap.approve'))
    <form method="post" action="{{ route('ap.wht.file') }}" id="whtFileForm" data-confirm="Mark the WHT return for {{ $pl }} as filed? Withheld payments in the month will be locked." class="form-grid">@csrf
      <input type="hidden" name="period" value="{{ $period }}">
      <div class="field"><label for="f_reference">GRA reference / acknowledgement no. <span class="req">*</span></label><input name="reference" id="f_reference" maxlength="40" required class="mono"></div>
      <div class="field"><label for="f_filed_on">Filed on <span class="req">*</span></label><input type="date" name="filed_on" id="f_filed_on" value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}" required></div>
      <div class="span-2"><button class="btn btn-primary" id="whtFileBtn"><x-icon name="check"/> Mark as filed</button> <span class="small muted">Pay {{ money($r['wht'] + $r['wvat']) }} to GRA by {{ $r['due']->format('d M Y') }}.</span></div>
    </form>
    @else<p class="small muted">Accounts managers mark the return as filed.</p>@endif
  </div>
  @endif
</div>
</div>

<div class="card flush"><div class="card-head"><h3>Payments with tax withheld · {{ $pl }}</h3></div><div class="card-body table-wrap"><table class="table" id="whtTable">
  <thead><tr><th>Date</th><th>Payment</th><th>Supplier</th><th>TIN</th><th>Nature</th><th class="num">Gross</th><th class="num">Taxable</th><th class="num">Rate</th><th class="num">WHT</th><th class="num">W/VAT</th><th class="num">Net paid</th><th>Certificate</th></tr></thead><tbody>
  @forelse($r['rows'] as $p)
  <tr><td class="small nowrap">{{ $p->date->format('d M') }}</td><td><a href="{{ route('ap.payments.show', $p) }}" class="bold">{{ $p->payment_no }}</a></td><td class="small">{{ $p->supplier?->name }}</td>
    <td class="small mono">{{ $p->supplier?->tax_number ?: '—' }}</td><td class="small">{{ \App\Services\WhtService::label($p->wht_category) }}</td>
    <td class="num">{{ money($p->amount, false) }}</td><td class="num">{{ money($p->wht_base, false) }}</td><td class="num">{{ (float) $p->wht_amount > 0 ? rtrim(rtrim(number_format($p->wht_rate, 2), '0'), '.').'%' : '—' }}</td>
    <td class="num bold">{{ money($p->wht_amount, false) }}</td><td class="num">{{ money($p->wvat_amount, false) }}</td><td class="num">{{ money($p->netPaid(), false) }}</td>
    <td class="nowrap"><a href="{{ route('ap.payments.certificate', $p) }}" target="_blank" class="mono small">{{ $p->wht_cert_no }}</a></td></tr>
  @empty<tr><td colspan="12"><x-empty text="No supplier payments with tax withheld in {{ $pl }}." icon="receipt"/></td></tr>@endforelse
  </tbody></table></div></div>

<div class="grid g-2">
<div class="card"><div class="card-head"><h3><x-icon name="tool"/> Settings</h3></div><div class="card-body">
  @if(can_do('ap.approve'))
  <form method="post" action="{{ route('ap.wht.settings') }}" id="whtSettings" class="form-grid">@csrf
    <label class="check span-2"><input type="checkbox" name="wht_enabled" value="1" @checked($cfg['wht_enabled'] === '1')> Withhold tax on supplier payments</label>
    <div class="field"><label for="f_wht_threshold">Annual threshold per supplier (goods, works, services)</label><input type="number" step="0.01" min="0" name="wht_threshold" id="f_wht_threshold" value="{{ $cfg['wht_threshold'] }}" required></div>
    <div class="field"><label for="f_wvat_rate">Withholding VAT rate %</label><input type="number" step="0.01" min="0" max="20" name="wvat_rate" id="f_wvat_rate" value="{{ $cfg['wvat_rate'] }}" required></div>
    <label class="check span-2"><input type="checkbox" name="wvat_agent" value="1" @checked($cfg['wvat_agent'] === '1')> The hotel is an appointed withholding VAT agent (withhold {{ $cfg['wvat_rate'] }}% of the taxable value on standard-rated supplies)</label>
    <div class="span-2"><button class="btn btn-primary">Save</button></div>
  </form>
  @else<dl class="kv"><dt>Withholding</dt><dd>{{ $cfg['wht_enabled'] === '1' ? 'on' : 'off' }}</dd><dt>Threshold</dt><dd>{{ money($cfg['wht_threshold']) }}</dd><dt>Withholding VAT agent</dt><dd>{{ $cfg['wvat_agent'] === '1' ? 'yes' : 'no' }}</dd></dl>@endif
  <table class="table table-compact mt-1"><thead><tr><th>Nature of payment (set per supplier)</th><th class="num">Rate</th><th>Threshold</th></tr></thead><tbody>
    @foreach(\App\Services\WhtService::CATEGORIES as $k => $c)@if($c[1] > 0)<tr><td class="small">{{ $c[0] }}</td><td class="num small">{{ rtrim(rtrim(number_format($c[1], 1), '0'), '.') }}%</td><td class="small">{{ $c[2] ? 'above '.money($cfg['wht_threshold']).' a year' : 'always' }}</td></tr>@endif @endforeach
  </tbody></table>
</div></div>
<div class="card flush"><div class="card-head"><h3>Filed returns</h3></div><div class="card-body table-wrap"><table class="table" id="whtFilings">
  <thead><tr><th>Month</th><th>Reference</th><th>Filed</th><th class="num">WHT</th><th>By</th></tr></thead><tbody>
  @forelse($filings as $f)<tr><td><a href="{{ route('ap.wht', ['period' => $f->period]) }}">{{ \Illuminate\Support\Carbon::parse($f->period.'-01')->format('M Y') }}</a></td><td class="mono small">{{ $f->reference }}</td><td class="small">{{ $f->filed_on->format('d M Y') }}</td><td class="num">{{ money($f->summary['wht'] ?? 0, false) }}</td><td class="small">{{ $f->filer?->name }}</td></tr>
  @empty<tr><td colspan="5"><x-empty text="No WHT returns filed yet." icon="lock"/></td></tr>@endforelse
  </tbody></table></div></div>
</div>
<p class="small muted">Income Tax Act 2015 (Act 896): resident persons withhold 3% on goods, 5% on works and 7.5% on services once payments to a supplier exceed the annual threshold, 8% / 15% on residential / commercial rent and 20% on payments to non-residents. Tax is calculated on the VAT-exclusive amount and is due to GRA by the 15th of the following month.</p>
@endsection
