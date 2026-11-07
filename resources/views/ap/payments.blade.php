@extends('layouts.app')
@section('title', 'Supplier Payments')
@section('crumbs') Accounts / Payables @endsection
@section('actions')
<a class="btn" href="{{ route('ap.bills') }}"><x-icon name="receipt"/> Bills</a>
@if(can_do('ap.pay'))<a class="btn btn-primary" href="{{ route('ap.payments.create') }}"><x-icon name="money"/> Pay supplier</a>@endif
@endsection
@section('content')
@php $share = fn ($v) => $aged['total'] > 0 ? round(100 * $v / $aged['total']) : 0; @endphp

{{-- v9.15 money that has left the bank and has not reached a bill: the header carries the age now, not only the total --}}
<div class="stats">
  <x-stat label="Unapplied payments" :value="money($kpi['payments'])" icon="wallet" :tone="$kpi['payments'] > 0 ? 'amber' : 'green'" href="?unapplied=1"
    :sub="$kpi['payment_count'].' payment(s) paid and not yet applied to a bill'.($aged['oldest'] ? ' · oldest '.$aged['oldest'].' days' : '')"/>
  <x-stat label="Open debit notes" :value="money($kpi['debit_notes'])" icon="minus" tone="blue"
    :sub="$kpi['debit_note_count'].' note(s) raised against a supplier and not yet applied'"/>
  <x-stat label="Credits on the ageing" :value="money($kpi['credits'])" icon="refresh" tone="slate"
    :sub="'unapplied payments + open debit notes — the figure the ageing nets off and the bills list takes off what is payable'"/>
  <x-stat label="Paid in the last 30 days" :value="money($kpi['paid'])" icon="money" tone="green"
    :sub="$kpi['paid_count'].' payment(s); cancelled ones are left out'"/>
</div>

{{-- v9.15 the ageing, built from ApService::openCredits() — the same collection the header is summed from --}}
<div class="card" id="creditAge">
  <div class="card-head"><h3>How long has the supplier's money been on our books?</h3>
    <span class="muted small">{{ $aged['count'] }} payment{{ $aged['count'] === 1 ? '' : 's' }} · {{ money($aged['total']) }} unapplied · oldest {{ $aged['oldest'] }} days</span></div>
  <div class="card-body">
    <div class="table-wrap"><table class="table table-compact" id="creditAgeTable">
      <thead><tr><th>Age of the unapplied money</th><th class="num">Payments</th><th class="num">Unapplied</th><th class="num">Share</th></tr></thead><tbody>
      @foreach($buckets as $bk => $bl)<tr data-bucket="{{ $bk }}">
        <td class="{{ $aged[$bk] == 0 ? 'muted' : ($bk === 'b90' ? 'text-red bold' : '') }}">{{ $bl }}</td>
        <td class="num">{{ $aged['n'.$bk] }}</td>
        <td class="num {{ $aged[$bk] == 0 ? 'muted' : ($bk === 'b90' ? 'text-red bold' : '') }}">{{ $aged[$bk] ? money($aged[$bk], false) : '—' }}</td>
        <td class="num muted">{{ $aged[$bk] ? $share($aged[$bk]).' %' : '—' }}</td></tr>@endforeach
      </tbody>
      <tfoot><tr class="bold"><td>Total — {{ $aged['count'] }} payment{{ $aged['count'] === 1 ? '' : 's' }}</td>
        <td class="num" id="ageSumCount">{{ $aged['count'] }}</td>
        <td class="num" id="ageSumTotal">{{ money($aged['total'], false) }}</td>
        <td class="num">{{ $aged['total'] > 0 ? '100 %' : '—' }}</td></tr></tfoot>
    </table></div>
    <p class="small {{ $aged['beyond'] ? 'text-red' : '' }}" id="creditAgeState" style="margin:.6rem 0 0">
      @if($aged['beyond'])
        <x-icon name="alert"/>{{ $aged['beyond'] }} payment{{ $aged['beyond'] === 1 ? '' : 's' }} {{ $aged['beyond'] === 1 ? 'has' : 'have' }} been unapplied for over ninety days —
        {{ $aged['worst']['doc'] }} from {{ $aged['worst']['supplier'] }}, {{ money($aged['worst']['free']) }} since {{ $aged['worst']['days'] }} days ago.
        {{ money($aged['beyond_amount']) }} is money we are holding with no bill to apply it to: find the bill, or refund it.
      @elseif($aged['worst'])
        <x-icon name="check"/>The oldest is {{ $aged['worst']['doc'] }} from {{ $aged['worst']['supplier'] }} — {{ money($aged['worst']['free']) }} unapplied for {{ $aged['worst']['days'] }} days.
        Nothing has been unapplied for more than ninety days, so no bill has sat unfound long enough to be written off.
      @else
        <x-icon name="check"/>Nothing is unapplied: every payment on these books has reached a bill.
      @endif
    </p>
  </div>
</div>

{{-- v9.15 the certificate: tax withheld is a GRA liability the supplier claims back only against a certificate in their hand --}}
<div class="card" id="whtCert">
  <div class="card-head"><h3>Is every withheld cedi certified?</h3>
    <span class="muted small">{{ $cert['payments'] }} payments withheld tax · {{ $cert['certified'] }} certified · {{ $cert['missing'] }} without a certificate</span></div>
  <div class="card-body">
    <div class="stats" style="margin:0">
      <x-stat label="Tax withheld" :value="money($cert['amount'])" icon="receipt" tone="slate" :sub="'on '.$cert['payments'].' supplier payments — a GRA liability, not a discount'"/>
      <x-stat label="Certified" :value="money($cert['certified_amount'])" icon="check" tone="slate" :sub="'Certificates issued to the supplier: '.$cert['certified']"/>
      <x-stat label="Without a certificate" :value="money($cert['missing_amount'])" icon="alert" :tone="$cert['missing'] ? 'red' : 'green'"
        :sub="$cert['missing'] ? $cert['missing'].' payment'.($cert['missing'] === 1 ? '' : 's').' the supplier cannot claim' : 'every withheld cedi has its certificate'"/>
    </div>
    @if($cert['missing'] === 0)
      <p class="small" id="whtCertState" style="margin:.6rem 0 0"><x-icon name="check"/>Each of the {{ $cert['payments'] }} payments that withheld tax carries a certificate number,
        so the supplier can claim the {{ money($cert['amount']) }} against their own assessment. Nothing is withheld without the paper for it.</p>
    @else
      <p class="small text-red" id="whtCertState" style="margin:.6rem 0 0"><x-icon name="alert"/>{{ $cert['missing'] }} payment{{ $cert['missing'] === 1 ? '' : 's' }} withheld
        {{ money($cert['missing_amount']) }} with no certificate number. The supplier cannot claim that credit without one, and GRA will ask for it on the return — issue the certificate or correct the withholding.</p>
      <div class="table-wrap" style="margin-top:.6rem"><table class="table table-compact" id="certGap">
        <thead><tr><th>Payment</th><th>Supplier</th><th>Date</th><th class="num">Age</th><th class="num">Gross</th><th class="num">Withheld</th></tr></thead><tbody>
        @foreach($cert['missing_rows'] as $m)<tr data-gap="cert">
          <td class="bold">{{ $m['payment_no'] }}</td><td class="small">{{ $m['supplier'] }}</td>
          <td class="small">{{ dmy($m['date']) }}</td><td class="num">{{ $m['days'] }} d</td>
          <td class="num">{{ money($m['gross'], false) }}</td><td class="num text-red">{{ money($m['amount'], false) }}</td></tr>@endforeach
        </tbody></table></div>
    @endif
  </div>
</div>

{{-- v9.15 the tie: the allocated column is what the ageing and the supplier statement rest on, so it must be the allocations --}}
<div class="card" id="allocTie">
  <div class="card-head"><h3>Does the allocated column match the allocations?</h3>
    <span class="muted small">{{ $alloc['payments'] }} payment{{ $alloc['payments'] === 1 ? '' : 's' }} applied · {{ $alloc['breaks'] }} where the two disagree</span></div>
  <div class="card-body">
    <div class="stats" style="margin:0">
      <x-stat label="Allocated, per the payments" :value="money($alloc['allocated'])" icon="book" tone="slate" sub="the figure the ageing and the statement are built from"/>
      <x-stat label="Allocations on file" :value="money($alloc['on_file'])" icon="receipt" tone="slate" sub="the documents behind them, summed"/>
      <x-stat label="Difference" :value="money($alloc['diff'])" icon="alert" :tone="$alloc['breaks'] ? 'red' : 'green'"
        :sub="$alloc['breaks'] ? 'the two records do not agree' : 'every payment adds up to its own allocations'"/>
    </div>
    @if($alloc['breaks'] === 0)
      <p class="small" id="allocState" style="margin:.6rem 0 0"><x-icon name="check"/>Every one of the {{ $alloc['payments'] }} payments with an allocation carries an
        allocated figure equal to the allocations on file, {{ money($alloc['allocated']) }} in all. The column can be trusted where the ageing reads it.</p>
    @else
      <p class="small text-red" id="allocState" style="margin:.6rem 0 0"><x-icon name="alert"/>{{ $alloc['breaks'] }} payment{{ $alloc['breaks'] === 1 ? ' reports' : 's report' }} an allocated figure that is not
        the allocations behind {{ $alloc['breaks'] === 1 ? 'it' : 'them' }}, {{ money(abs($alloc['diff'])) }} apart in all. One of the two records is stale — the unapplied figure on the ageing,
        and what the supplier is told they owe, both rest on the column.</p>
      <div class="table-wrap" style="margin-top:.6rem"><table class="table table-compact" id="allocGap">
        <thead><tr><th>Payment</th><th>Supplier</th><th>Date</th><th class="num">Allocated</th><th class="num">On file</th><th class="num">Difference</th></tr></thead><tbody>
        @foreach($alloc['rows'] as $r)<tr data-gap="alloc">
          <td class="bold">{{ $r['payment_no'] }}</td><td class="small">{{ $r['supplier'] }}</td>
          <td class="small">{{ dmy($r['date']) }}</td><td class="num">{{ money($r['allocated'], false) }}</td>
          <td class="num">{{ money($r['on_file'], false) }}</td>
          <td class="num text-red">{{ money($r['allocated'] - $r['on_file'], false) }}</td></tr>@endforeach
        </tbody></table></div>
    @endif
  </div>
</div>

@php $tab = request('unapplied') ? 'unapplied' : (request('cancelled') ? 'cancelled' : ''); @endphp
<div class="tabs">@foreach(['' => 'All', 'unapplied' => 'Unapplied', 'cancelled' => 'Cancelled'] as $k => $v)<a href="?{{ http_build_query(array_merge(request()->except('page', 'unapplied', 'cancelled'), $k ? [$k => 1] : [])) }}" class="{{ $tab === $k ? 'active' : '' }}">{{ $v }}</a>@endforeach</div>
<form class="filters mb-1 flex gap-1 wrap items-center" method="get"><input type="hidden" name="unapplied" value="{{ request('unapplied') }}">
  <select name="supplier" class="input input-sm" style="width:auto;min-width:200px" onchange="this.form.submit()"><option value="">All suppliers</option>@foreach($suppliers as $s)<option value="{{ $s->id }}" @selected(request('supplier') == $s->id)>{{ $s->name }}</option>@endforeach</select>
  <input type="date" name="from" value="{{ request('from') }}" class="input input-sm" style="width:auto"> – <input type="date" name="to" value="{{ request('to') }}" class="input input-sm" style="width:auto">
  <input name="q" value="{{ request('q') }}" placeholder="Payment no. / reference" class="input input-sm" style="width:190px">
  <button class="btn btn-sm">Filter</button>
</form>
<div class="card flush"><div class="card-body table-wrap"><table class="table" id="payTable">
<thead><tr><th>Payment</th><th>Date</th><th>Supplier</th><th>Mode</th><th>Reference</th><th class="num">Amount</th><th class="num">Allocated</th><th class="num">Unapplied</th><th class="num">Age</th><th>WHT certificate</th><th></th></tr></thead><tbody>
@forelse($payments as $p)
<tr class="{{ $p->cancelled_at ? 'muted' : '' }}" data-payment="{{ $p->payment_no }}" data-bucket="{{ $p->bucket }}"><td><a class="bold" href="{{ route('ap.payments.show', $p) }}">{{ $p->payment_no }}</a></td><td class="nowrap">{{ $p->date->format('d M Y') }}</td><td>{{ $p->supplier->name }}</td><td>{{ $p->paymentMode?->name }}</td><td>{{ $p->reference ?? '—' }}</td>
<td class="num">{{ money($p->amount, false) }}@if($p->isForeign())<div class="tiny muted">{{ $p->fxLabel() }} @ {{ number_format((float) $p->fx_rate, 4) }}{{ $p->fxDifference() != 0 ? ' · '.money(abs($p->fxDifference()), false).' '.($p->fxDifference() > 0 ? 'loss' : 'gain') : '' }}</div>@endif</td><td class="num">{{ money($p->allocated, false) }}</td><td class="num {{ $p->unapplied() > 0 ? 'text-amber bold' : '' }}">{{ money($p->unapplied(), false) }}</td>
<td class="num {{ $p->bucket === 'b90' ? 'text-red bold' : 'muted' }}">{{ $p->days }} d</td>
<td class="nowrap">@if((float) $p->wht_amount > 0)
  @if($p->wht_cert_no)<a href="{{ route('ap.payments.certificate', $p) }}" target="_blank" class="mono small">{{ $p->wht_cert_no }}</a>
  @else<span class="badge badge-red">none</span>@endif
  <div class="tiny muted">{{ money($p->wht_amount, false) }}</div>
@else<span class="tiny muted">—</span>@endif</td>
<td>@if($p->cancelled_at)<span class="badge badge-red">Cancelled</span>@elseif($p->unapplied() > 0)<span class="badge badge-amber">Credit</span>@else<span class="badge badge-green">Allocated</span>@endif</td></tr>
@empty<tr><td colspan="11" class="empty">No supplier payments yet.</td></tr>@endforelse
</tbody></table></div></div>
{{ $payments->links('partials.pagination') }}
<p class="tiny muted">Unapplied money is a credit with the supplier, not a discount: it is netted off the ageing and taken off what is payable, and it is only ever credit in the property's own currency — a foreign payment's remainder is an exchange difference already posted. Tax withheld is due to GRA by the 15th of the following month and the supplier claims it back against the certificate.</p>
@endsection
