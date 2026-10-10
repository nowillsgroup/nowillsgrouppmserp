@extends('layouts.app')
@section('title', 'VAT return – '.\Illuminate\Support\Carbon::parse($period.'-01')->format('F Y'))
@section('crumbs') Billing / <a href="{{ route('gst.index') }}">GRA VAT</a> / VAT return @endsection
@section('actions')
<form class="filters" style="margin:0"><input type="hidden" name="tab" value="{{ $tab }}"><select name="period" onchange="this.form.submit()" id="periodSel">@foreach($periods as $k => $l)<option value="{{ $k }}" @selected($k === $period)>{{ $l }}</option>@endforeach</select></form>
<a class="btn" href="{{ route('gst.return.export', ['period' => $period]) }}" id="exportReturn"><x-icon name="download"/> Sales register (CSV)</a>
@endsection
@section('content')
@include('gst._nav')
@php
  $T = $r['totals']; $R = $r['rows']; $mon = \Illuminate\Support\Carbon::parse($period.'-01')->format('F Y');
  $labels = ['b2b' => 'Standard-rated sales – VAT-registered buyers (B2B, buyer TIN)', 'b2c' => 'Standard-rated sales – final consumers (B2C)', 'pos' => 'Restaurant & outlet receipts (POS, paid directly)', 'credit' => 'Credit notes / refunds issued'];
  $blocking = collect($checks)->where('block', true)->count();
@endphp

@if($r['filed'])
<div class="alert alert-success" id="filedBanner"><x-icon name="check"/><div>Filed on <b>{{ $r['filed']->filed_on->format('d M Y') }}</b> · GRA reference <b class="mono">{{ $r['filed']->arn }}</b>{{ $r['filed']->filer ? ' · marked by '.$r['filed']->filer->name : '' }}. Invoices and credit notes dated in {{ $mon }} are locked.</div></div>
@else
<div class="alert {{ bdate()->gt($r['due']) ? 'alert-error' : 'alert-info' }}"><x-icon name="clock"/><div>VAT / NHIL / GETFund return for <b>{{ $mon }}</b> – file and pay on the GRA Taxpayers' Portal by <b>{{ $r['due']->format('d M Y') }}</b> (last working day of the following month).
  Tourism levy ({{ money($T['tl']) }}) goes to the Ghana Tourism Authority by <b>{{ $r['tl_due']->format('d M Y') }}</b>.</div></div>
@endif

@if(count($checks))
<div class="card mb-1" id="returnChecks"><div class="card-head"><h3><x-icon name="alert"/> Before you file</h3><span class="tiny muted">{{ $blocking }} blocking · {{ count($checks) - $blocking }} warnings</span></div>
  <div class="card-body"><ul class="bq-list">
  @foreach($checks as $c)<li><span>{!! $c['block'] ? '<span class="badge badge-red">fix</span>' : '<span class="badge badge-amber">check</span>' !!} {{ $c['text'] }}</span>@if(! empty($c['folio_id']))<a class="small" href="{{ route('folios.show', $c['folio_id']) }}">open</a>@endif</li>@endforeach
  </ul></div></div>
@endif

<div class="stats">
  <x-stat label="Taxable value (net)" :value="money($T['taxable'])" icon="chart" tone="blue" :sub="$T['count'].' invoices & credit notes + POS'"/>
  <x-stat label="Output VAT + levies" :value="money($T['output_gra'])" icon="money" tone="purple" :sub="'VAT '.money($T['vat']).' · NHIL '.money($T['nhil']).' · GETFund '.money($T['getfund'])"/>
  <x-stat label="Input tax claimed" :value="money($T['input'])" icon="receipt" tone="green" sub="purchases & supplier bills"/>
  <x-stat label="Net payable to GRA" :value="money($T['net_gra'])" icon="wallet" :tone="$T['net_gra'] < 0 ? 'green' : 'red'" :sub="$T['net_gra'] < 0 ? 'credit carried forward' : 'due '.$r['due']->format('d M')"/>
</div>

<div class="card flush">
  <div class="card-head"><div class="tabs" style="margin:0;border:0" id="returnTabs">
    @foreach(['summary' => 'Summary', 'sales' => 'Sales register', 'levy' => 'Tourism levy', 'docs' => 'Documents issued'] as $k => $l)<a href="{{ route('gst.return', ['period' => $period, 'tab' => $k]) }}" class="{{ $tab === $k ? 'active' : '' }}">{{ $l }}</a>@endforeach
  </div></div>

  @if($tab === 'summary')
  <div class="card-body table-wrap"><table class="table" id="returnSummary">
    <thead><tr><th>Supplies</th><th class="num">Docs</th><th class="num">Taxable</th><th class="num">VAT 15%</th><th class="num">NHIL 2.5%</th><th class="num">GETFund 2.5%</th><th class="num">Total to GRA</th></tr></thead><tbody>
    @foreach($labels as $k => $l) @php $x = $R[$k]; @endphp
    <tr data-row="{{ $k }}"><td>{{ $l }}</td><td class="num">{{ $x['count'] }}</td><td class="num">{{ money($x['taxable'], false) }}</td><td class="num">{{ money($x['vat'], false) }}</td><td class="num">{{ money($x['nhil'], false) }}</td><td class="num">{{ money($x['getfund'], false) }}</td><td class="num bold">{{ money($x['vat'] + $x['nhil'] + $x['getfund'], false) }}</td></tr>
    @endforeach
    </tbody><tfoot>
      <tr><td class="bold">Output tax</td><td></td><td class="num bold">{{ money($T['taxable'], false) }}</td><td class="num bold">{{ money($T['vat'], false) }}</td><td class="num bold">{{ money($T['nhil'], false) }}</td><td class="num bold">{{ money($T['getfund'], false) }}</td><td class="num bold">{{ money($T['output_gra'], false) }}</td></tr>
      <tr><td colspan="6" class="right">Less input VAT, NHIL &amp; GETFund on purchases</td><td class="num">({{ money($T['input'], false) }})</td></tr>
      <tr><td colspan="6" class="right bold">Net VAT payable {{ $T['net_gra'] < 0 ? '(credit)' : '' }}</td><td class="num bold big" id="netPayable">{{ money($T['net_gra'], false) }}</td></tr>
    </tfoot></table>
    <p class="tiny muted" style="padding:0 1rem 1rem">VAT Act 2025 (Act 1151): VAT 15%, NHIL 2.5% and GETFund 2.5% are charged on the same taxable value (no cascading) and all three are input-deductible. The 1% Tourism Levy is not part of the VAT return – see the Tourism levy tab.</p>
  </div>

  @elseif($tab === 'sales')
  <div class="card-body table-wrap"><table class="table" id="salesRegister">
    <thead><tr><th>Document</th><th>Date</th><th>Type</th><th>Buyer</th><th class="num">Taxable</th><th class="num">VAT</th><th class="num">NHIL</th><th class="num">GETFund</th><th class="num">TL</th><th class="num">Total</th><th>E-VAT</th></tr></thead><tbody>
    @forelse($r['sales'] as $s)
    <tr><td class="nowrap"><a class="bold" href="{{ route('folios.show', $s['folio_id']) }}">{{ $s['no'] }}</a>@if($s['against'])<div class="tiny muted">against {{ $s['against'] }}</div>@endif</td>
      <td class="small nowrap">{{ \Illuminate\Support\Carbon::parse($s['date'])->format('d M') }}</td><td class="small">{{ $s['type'] }} · {{ $s['supply'] }}</td>
      <td class="small">{{ $s['name'] }}@if($s['tin'])<div class="tiny muted mono">{{ $s['tin'] }}</div>@endif</td>
      <td class="num">{{ money($s['taxable'], false) }}</td><td class="num">{{ money($s['vat'], false) }}</td><td class="num">{{ money($s['nhil'], false) }}</td><td class="num">{{ money($s['getfund'], false) }}</td><td class="num">{{ money($s['tl'], false) }}</td><td class="num bold">{{ money($s['total'], false) }}</td>
      <td class="small">@if($s['sdc'])<span class="badge badge-green" title="{{ $s['sdc'] }}">stamped</span>@elseif($s['evat_status'] === 'failed')<span class="badge badge-red">failed</span>@else<span class="muted">—</span>@endif</td></tr>
    @empty<tr><td colspan="11"><x-empty text="No invoices or credit notes dated in this month." icon="receipt"/></td></tr>@endforelse
    </tbody></table>
    @if($R['pos']['count'])<p class="tiny muted" style="padding:0 1rem 1rem">Plus {{ $R['pos']['count'] }} restaurant / outlet receipts paid directly: taxable {{ money($R['pos']['taxable']) }}, tax {{ money($R['pos']['vat'] + $R['pos']['nhil'] + $R['pos']['getfund'] + $R['pos']['tl']) }}.</p>@endif
  </div>

  @elseif($tab === 'levy')
  <div class="card-body">
    <ul class="bq-list" id="levySummary">
      <li><span>Net cost of hotel & restaurant services ({{ $mon }})</span><b>{{ money($T['tl_base']) }}</b></li>
      <li><span>Tourism levy collected (1%)</span><b>{{ money($T['tl']) }}</b></li>
      <li><span>— on rooms, services & folio charges</span><b>{{ money($R['b2b']['tl'] + $R['b2c']['tl'] + $R['credit']['tl']) }}</b></li>
      <li><span>— on restaurant receipts paid directly</span><b>{{ money($R['pos']['tl']) }}</b></li>
      <li><span>Remit to the Ghana Tourism Authority (Tourism Development Fund) by</span><b>{{ $r['tl_due']->format('d M Y') }}</b></li>
    </ul>
    <p class="tiny muted mt-1">Tourism Act 2011 (Act 817) and L.I. 2185: 1% of the net cost of services at hotels, restaurants and other tourism enterprises, charged in addition to VAT and shown separately on the invoice. It is booked to the <b>Tourism levy payable</b> account in the ledger, separate from VAT payable.</p>
  </div>

  @else
  <div class="card-body table-wrap"><table class="table" id="docSummary">
    <thead><tr><th>Series</th><th>From</th><th>To</th><th class="num">Total</th><th class="num">Cancelled</th><th class="num">Net issued</th><th>Gaps</th></tr></thead><tbody>
    @forelse($r['docs'] as $d)<tr><td>{{ $d['label'] }}</td><td class="mono small">{{ $d['from'] }}</td><td class="mono small">{{ $d['to'] }}</td><td class="num">{{ $d['total'] }}</td><td class="num">{{ $d['cancelled'] }}</td><td class="num bold">{{ $d['net'] }}</td>
      <td class="small">{!! $d['gaps'] ? '<span class="text-red">'.e(implode(', ', array_slice($d['gaps'], 0, 10))).(count($d['gaps']) > 10 ? ' …' : '').'</span>' : '<span class="text-green">none</span>' !!}</td></tr>
    @empty<tr><td colspan="7"><x-empty text="No documents issued in this month." icon="receipt"/></td></tr>@endforelse
    </tbody></table>
    <p class="tiny muted" style="padding:0 1rem 1rem">GRA requires consecutive invoice numbers. Keep all documents (and E-VAT responses) for at least six years.</p>
  </div>
  @endif
</div>

@if(! $r['filed'] && can_do('gst.manage'))
<div class="card mt-1" id="fileCard"><div class="card-head"><h3><x-icon name="lock"/> Mark as filed</h3><span class="tiny muted">after submitting on the GRA Taxpayers' Portal (gra.gov.gh)</span></div>
  <form method="post" action="{{ route('gst.file') }}" id="fileForm" data-confirm="Lock all invoices and credit notes dated in {{ $mon }}?">@csrf
    <input type="hidden" name="period" value="{{ $period }}">
    <div class="card-body grid g-2">
      <x-field name="arn" label="GRA acknowledgement / reference no." required placeholder="e.g. VAT/2026/09/000123"/>
      <x-field name="filed_on" label="Filed on" type="date" :value="old('filed_on', now()->toDateString())" required/>
    </div>
    <div class="card-foot"><button class="btn btn-primary" id="fileBtn" @disabled($blocking)><x-icon name="check"/> Mark {{ $mon }} as filed</button>@if($blocking)<span class="tiny muted"> fix the blocking items first</span>@endif</div>
  </form></div>
@endif
@endsection
