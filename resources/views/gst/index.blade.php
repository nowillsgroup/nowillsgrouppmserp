@extends('layouts.app')
@section('title', 'GRA VAT & E-VAT')
@section('crumbs') Billing / GRA VAT @endsection
@section('actions')
<form class="filters" style="margin:0"><select name="period" onchange="this.form.submit()" id="periodSel">@foreach($periods as $k => $l)<option value="{{ $k }}" @selected($k === $period)>{{ $l }}</option>@endforeach</select></form>
<a class="btn" href="{{ route('gst.return', ['period' => $period]) }}"><x-icon name="book"/> VAT return</a>
@endsection
@section('content')
@include('gst._nav')
@php $T = $r['totals']; $R = $r['rows']; $mon = \Illuminate\Support\Carbon::parse($period.'-01')->format('M Y'); @endphp
@if(! $tinOk)
<div class="alert alert-error"><x-icon name="alert"/><div>The hotel TIN {{ $tin ? '“'.$tin.'” is not valid' : 'is not set' }}. VAT invoices, E-VAT and the VAT return need it (GRA TIN such as C0012345678, or a Ghana Card PIN) – @if(can_do('settings.manage'))<a href="{{ route('settings.index') }}">open Hotel settings</a>@else ask an administrator to fix it in Hotel settings @endif.</div></div>
@endif
@if($alerts['return_period'])
<div class="alert {{ $alerts['return_overdue'] ? 'alert-error' : 'alert-warn' }}" id="returnDue"><x-icon name="clock"/><div>The VAT return for <b>{{ \Illuminate\Support\Carbon::parse($alerts['return_period'].'-01')->format('F Y') }}</b> is {{ $alerts['return_overdue'] ? 'overdue' : 'due' }} ({{ $alerts['return_due']->format('d M Y') }} – last working day of the following month).
  <a href="{{ route('gst.return', ['period' => $alerts['return_period']]) }}">Prepare the return →</a></div></div>
@endif

<div class="stats req5">
  <x-stat :label="'Invoices · '.$mon" :value="$counts['invoices']" icon="receipt" tone="blue" :sub="$counts['b2b'].' B2B (buyer TIN) · '.($counts['invoices'] - $counts['b2b']).' B2C'"/>
  <x-stat label="Taxable value (net)" :value="money($T['taxable'])" icon="chart" tone="green" sub="invoices + restaurant receipts − credit notes"/>
  <x-stat label="Output tax to GRA" :value="money($T['output_gra'])" icon="money" tone="purple" :sub="'VAT '.money($T['vat']).' · NHIL '.money($T['nhil']).' · GETFund '.money($T['getfund'])"/>
  <x-stat label="Tourism levy to GTA" :value="money($T['tl'])" icon="globe" tone="amber" :sub="'1% of '.money($T['tl_base']).' · due '.$r['tl_due']->format('d M')"/>
  <x-stat label="E-VAT stamped" :value="($ei['generated'] ?? 0).' / '.($counts['invoices'] + $counts['notes'])" icon="qr" :tone="($ei['failed'] ?? 0) ? 'red' : 'green'" :sub="$enabled ? (($ei['failed'] ?? 0).' failed · '.(($ei['pending'] ?? 0) + ($ei['none'] ?? 0)).' not stamped · '.\App\Services\EInvoiceService::driverLabel($driver)) : 'E-VAT switched off'" :href="route('gst.einvoices')"/>
</div>

<div class="grid g-2-1">
  <div>
    <div class="card flush"><div class="card-head"><h3><x-icon name="layers"/> {{ $mon }} – VAT return summary</h3><a class="small" href="{{ route('gst.return', ['period' => $period]) }}">Details →</a></div>
      <div class="card-body table-wrap"><table class="table" id="summaryTable">
        <thead><tr><th>Supplies</th><th class="num">Docs</th><th class="num">Taxable value</th><th class="num">VAT</th><th class="num">NHIL</th><th class="num">GETFund</th><th class="num">Tourism levy</th></tr></thead><tbody>
        @foreach(['b2b' => 'Tax invoices – registered buyers (TIN)', 'b2c' => 'Tax invoices – other buyers', 'pos' => 'Restaurant & bar receipts (POS)', 'credit' => 'Credit notes / refunds'] as $k => $l)
        <tr><td>{{ $l }}</td><td class="num">{{ $R[$k]['count'] }}</td>@foreach(['taxable', 'vat', 'nhil', 'getfund', 'tl'] as $c)<td class="num">{{ money($R[$k][$c], false) }}</td>@endforeach</tr>
        @endforeach
        </tbody><tfoot>
          <tr><td colspan="2" class="right bold">Output</td>@foreach(['taxable', 'vat', 'nhil', 'getfund', 'tl'] as $c)<td class="num bold">{{ money($T[$c === 'tl' ? 'tl' : $c], false) }}</td>@endforeach</tr>
          <tr><td colspan="3" class="right">Less input tax (VAT + NHIL + GETFund on purchases)</td><td class="num" colspan="3">−{{ money($T['input'], false) }}</td><td></td></tr>
          <tr><td colspan="3" class="right bold">Net payable to GRA</td><td class="num bold" colspan="3" id="netGra">{{ money($T['net_gra']) }}</td><td class="num bold" id="tlTotal">{{ money($T['tl']) }}</td></tr>
        </tfoot></table></div></div>

    <div class="card flush mt-1"><div class="card-head"><h3><x-icon name="alert"/> Documents E-VAT did not accept</h3>@if($failed->count() && can_do('gst.manage') && $enabled)<form method="post" action="{{ route('gst.retry') }}">@csrf<button class="btn btn-sm"><x-icon name="refresh" size="sm"/> Retry now</button></form>@endif</div>
      <div class="card-body table-wrap"><table class="table">
      <thead><tr><th>Document</th><th>Buyer</th><th>Error</th><th>Next try</th></tr></thead><tbody>
      @forelse($failed as $f)
      <tr><td class="nowrap"><a class="bold" href="{{ route('folios.show', $f) }}">{{ $f->invoice_no }}</a><div class="tiny muted">{{ $f->invoice_date?->format('d M Y') }}</div></td><td class="small">{{ $f->buyer_name }}<div class="tiny muted mono">{{ $f->buyer_gstin }}</div></td>
        <td class="small">{{ $f->irn_error }}</td><td class="small nowrap">{!! $f->irn_retryable ? ($f->irn_next_try_at ? $f->irn_next_try_at->format('d M H:i') : '—') : '<span class="badge badge-amber">fix data</span>' !!}</td></tr>
      @empty<tr><td colspan="4"><x-empty text="No rejected documents." icon="check"/></td></tr>@endforelse
      </tbody></table></div></div>
  </div>
  <div>
    <div class="card"><div class="card-head"><h3><x-icon name="shield"/> Checks before filing · {{ $mon }}</h3></div><div class="card-body">
      @forelse($checks as $c)
      <div class="alert {{ $c['block'] ? 'alert-error' : 'alert-warn' }}" style="margin-bottom:.5rem"><x-icon name="alert"/><div class="small">{{ $c['text'] }} @if(isset($c['folio_id']))<a href="{{ route('folios.show', $c['folio_id']) }}">Open →</a>@endif</div></div>
      @empty<p class="small" id="checksOk"><span class="badge badge-green">OK</span> No problems found for this month.</p>@endforelse
    </div></div>
    <div class="card mt-1"><div class="card-head"><h3><x-icon name="building"/> Registration</h3></div><div class="card-body">
      <ul class="bq-list">
        <li><span>TIN</span><b class="mono">{{ $tin ?? '—' }} {!! $tinOk ? '<span class="badge badge-green">valid</span>' : '<span class="badge badge-red">invalid</span>' !!}</b></li>
        <li><span>Tax components</span><b>@foreach(\App\Services\TaxService::labels('room') as $l)<span class="badge badge-slate">{{ $l }}</span> @endforeach</b></li>
        <li><span>E-VAT</span><b>{!! $enabled ? '<span class="badge badge-green">on</span> '.e(\App\Services\EInvoiceService::driverLabel($driver)) : '<span class="badge badge-slate">off</span>' !!}</b></li>
      </ul></div></div>
    <div class="card flush mt-1"><div class="card-head"><h3><x-icon name="book"/> Filing history</h3></div><div class="card-body table-wrap"><table class="table" id="filingTable">
      <thead><tr><th>Period</th><th>GRA reference</th><th>Filed</th></tr></thead><tbody>
      @forelse($filings as $p)<tr><td><a href="{{ route('gst.return', ['period' => $p->period]) }}">{{ \Illuminate\Support\Carbon::parse($p->period.'-01')->format('M Y') }}</a></td><td class="mono small">{{ $p->arn }}</td><td class="small">{{ $p->filed_on->format('d M Y') }}<div class="tiny muted">{{ $p->filer?->name }}</div></td></tr>
      @empty<tr><td colspan="3"><x-empty text="No returns marked as filed yet." icon="book"/></td></tr>@endforelse
      </tbody></table></div></div>
  </div>
</div>
@endsection
