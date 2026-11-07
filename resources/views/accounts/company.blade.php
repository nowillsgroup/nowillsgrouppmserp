@extends('layouts.app')
@section('title', 'Ledger – '.$company->name)
@section('crumbs') <a href="{{ route('accounts.cityledger') }}">City Ledger</a> / {{ $company->name }} @endsection
@section('actions')<form method="get" class="flex gap-1 items-center"><label class="small">As at</label><input type="date" name="as_of" value="{{ $asOf }}" max="{{ bdate()->toDateString() }}" class="input input-sm" style="width:auto"><button class="btn btn-sm">Go</button></form>
<button class="btn" onclick="window.print()"><x-icon name="print"/> Print</button>@if(can_do('accounts.manage'))<button class="btn btn-success" data-modal="rcModal"><x-icon name="money"/> Receive Payment</button>@endif @endsection
@section('content')

{{-- v9.7 the ledger and the ageing are two readings of one balance: say whether they agree, as the supplier statement does --}}
<div class="card" id="companyTie"><div class="card-head"><h3>Does it agree with the ageing?</h3>
  <span class="muted small">{{ $company->name }} · as at {{ dmy($tie['as_of']) }}</span></div>
  <div class="card-body"><div class="stats" style="margin:0">
    <x-stat label="This ledger closes at" :value="money($tie['ledger'])" icon="receipt" tone="slate" sub="every charge less every receipt up to the date"/>
    <x-stat label="The ageing says" :value="money($tie['ageing'])" icon="chart" tone="slate" :sub="'the same charges, read FIFO into buckets, at '.dmy($tie['as_of'])"/>
    <x-stat label="Difference" :value="money(abs($tie['diff']))" icon="alert" :tone="$tie['ties'] ? 'green' : 'red'" :sub="$tie['ties'] ? 'the two agree' : 'the two disagree'"/>
  </div>
  @if($tie['ties'])
    <p class="small" id="companyTieState" style="margin:.6rem 0 0"><x-icon name="check"/>The ledger closes at {{ money($tie['ledger']) }}
      and the receivables ageing at {{ dmy($tie['as_of']) }} says the same. The company can be sent this ledger.</p>
  @else
    <p class="small text-red" id="companyTieState" style="margin:.6rem 0 0"><x-icon name="alert"/>The ledger closes at {{ money($tie['ledger']) }}
      but the ageing at {{ dmy($tie['as_of']) }} says {{ money($tie['ageing']) }} — a difference of {{ money(abs($tie['diff'])) }}.
      One of them is counting a charge or a receipt the other is not: check this company's city-ledger entries and receipts before the ledger goes out.</p>
  @endif
  </div>
</div>

<div class="card flush"><div class="card-body table-wrap"><table class="table">
<thead><tr><th>Date</th><th>Due</th><th>Ref</th><th>Particulars</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Balance</th></tr></thead><tbody>
@forelse($rows as $r)
<tr><td>{{ dmy($r['date']) }}</td><td>{{ $r['due'] ? dmy($r['due']) : '' }}</td><td>@if($r['folio'])<a href="{{ route('folios.show', $r['folio']) }}">{{ $r['ref'] }}</a>@else{{ $r['ref'] }}@endif</td><td>{{ $r['desc'] }}</td><td class="num">{{ $r['debit'] ? money($r['debit'], false) : '' }}</td><td class="num">{{ $r['credit'] ? money($r['credit'], false) : '' }}</td><td class="num bold">{{ money($r['balance'], false) }}</td></tr>
@empty<tr><td colspan="7"><x-empty text="No city ledger entries." icon="receipt"/></td></tr>@endforelse
</tbody><tfoot><tr><td colspan="4" class="right">Closing balance</td><td class="num">{{ money($rows->sum('debit'), false) }}</td><td class="num">{{ money($rows->sum('credit'), false) }}</td><td class="num" id="ledgerClosing">{{ money($closing) }}</td></tr></tfoot></table></div></div>
<div class="modal" id="rcModal"><div class="modal-box"><form method="post" action="{{ route('accounts.company.receipt', $company) }}">@csrf
<div class="modal-head"><h3>Receive Payment – {{ $company->name }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
<div class="modal-body">
  <div class="form-grid" id="company-receipt-single"><x-field name="amount" label="Amount" type="number" step="0.01" min="0.01" :value="max(0, $closing)" required/><x-field name="payment_mode_id" label="Mode" type="select" :options="$paymentModes" required/><x-field name="reference" label="Cheque / UTR no."/></div>
  <div class="form-grid mt-1"><x-field name="date" label="Date" type="date" :value="$asOf" required/></div>
  <x-payment-split-tender widget-id="city-ledger-receipt-split" single-id="company-receipt-single" :modes="$splitModes" label="company receipt" :show-cheque="false"/>
  <p class="tiny muted">Split receipts can be partial; each tender is recorded separately against the company receivable.</p>
</div>
<div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-success">Save Receipt</button></div></form></div></div>
@endsection
