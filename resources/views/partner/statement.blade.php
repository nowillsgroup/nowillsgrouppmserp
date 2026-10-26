@extends('partner.layout')
@section('title', 'Account statement')
@section('actions') <button class="btn" type="button" onclick="window.print()"><x-icon name="print"/> Print</button> @endsection
@section('content')
@if($errors->has('pay'))<div class="alert alert-error"><x-icon name="alert"/><div>{{ $errors->first('pay') }}</div></div>@endif
@php $p = $position; @endphp
<div class="stats" id="ptnPosition">
  <x-stat label="Outstanding" :value="money($p['outstanding'])" icon="receipt" :tone="$p['overdue'] > 0 ? 'red' : 'amber'"/>
  <x-stat label="Overdue" :value="money($p['overdue'])" icon="alert" :tone="$p['overdue'] > 0 ? 'red' : 'green'" :sub="$p['overdue'] > 0 ? 'oldest '.$p['overdue_days'].' days past due' : 'all within '.$company->credit_days.' days'"/>
  <x-stat label="Credit limit" :value="$p['limit'] > 0 ? money($p['limit']) : 'Not set'" icon="shield" tone="slate" :sub="$p['limit'] > 0 ? money($p['available']).' available' : null"/>
  <x-stat label="Unbilled stays in house" :value="money($p['pending'] ?? 0)" icon="bed" tone="blue"/>
</div>
@if($company->onHold())<div class="alert alert-warn"><x-icon name="alert"/><div>Your account is on credit hold{{ $company->credit_hold_reason ? ': '.$company->credit_hold_reason : '' }}. New company-billed bookings need the hotel's approval.</div></div>@endif
@if($canPay && $p['outstanding'] > 0)
<div class="card no-print" id="ptnPayCard"><div class="card-body">
  <form method="post" action="{{ route('partner.pay') }}" class="ptn-pay" id="ptnPayForm">
    @csrf
    <div class="field"><label for="amount">Pay online (card / mobile money)</label><input class="input" type="number" step="0.01" min="1" max="{{ round($p['outstanding'], 2) }}" id="amount" name="amount" value="{{ number_format(max($p['overdue'], 0) > 0 ? $p['overdue'] : $p['outstanding'], 2, '.', '') }}" required></div>
    <button class="btn btn-primary" type="submit"><x-icon name="wallet"/> Pay now</button>
    <span class="tiny muted">Recorded on your account as soon as the payment succeeds. Bank transfers: quote your company name.</span>
  </form>
</div></div>
@endif
<div class="card"><div class="card-head"><h3><x-icon name="receipt"/> {{ $company->name }} – city-ledger statement</h3><span class="tiny muted">as at {{ bdate()->format('d M Y') }} · billed {{ money($billed) }} · paid {{ money($paid) }}</span></div><div class="card-body table-wrap">
  @if($rows->isEmpty())<x-empty text="No invoices or payments yet." icon="receipt"/>@else
  <table class="table" id="ptnLedger"><thead><tr><th>Date</th><th>Due</th><th>Reference</th><th>Description</th><th class="right">Invoiced</th><th class="right">Paid</th><th class="right">Balance</th></tr></thead><tbody>
  @foreach($rows as $x)
    <tr><td class="nowrap">{{ $x['date']->format('d M Y') }}</td><td class="nowrap">{{ $x['due']?->format('d M Y') }}</td><td>@if($x['folio_id'])<a href="{{ route('partner.invoice', $x['folio_id']) }}">{{ $x['ref'] }}</a>@else{{ $x['ref'] }}@endif</td><td>{{ $x['desc'] }}</td>
      <td class="right">{{ $x['debit'] ? money($x['debit']) : '' }}</td><td class="right">{{ $x['credit'] ? money($x['credit']) : '' }}</td><td class="right"><b>{{ money($x['balance']) }}</b></td></tr>
  @endforeach
  </tbody></table>@endif
</div></div>
@if(! empty($p['open_bills']))
<div class="card"><div class="card-head"><h3><x-icon name="clock"/> Open invoices by age</h3></div><div class="card-body table-wrap">
  <table class="table table-compact"><thead><tr><th>Invoice date</th><th>Due</th><th class="right">Open amount</th><th>Status</th></tr></thead><tbody>
  @foreach($p['open_bills'] as $b)<tr class="{{ ($b['overdue_days'] ?? 0) > 0 ? 'row-red' : '' }}"><td>{{ \Carbon\Carbon::parse($b['date'])->format('d M Y') }}</td><td>{{ \Carbon\Carbon::parse($b['due'])->format('d M Y') }}</td><td class="right">{{ money($b['amount']) }}</td><td class="small">{{ ($b['overdue_days'] ?? 0) > 0 ? $b['overdue_days'].' days overdue' : 'not due yet' }}</td></tr>@endforeach
  </tbody></table>
</div></div>
@endif
@endsection
