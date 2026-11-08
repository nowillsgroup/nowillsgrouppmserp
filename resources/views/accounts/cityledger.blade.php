@extends('layouts.app')
@section('title', 'City Ledger – Company Receivables')
@section('crumbs') Accounts / City Ledger @endsection
@section('actions')<form method="get" class="flex gap-1 items-center"><label class="small">As at</label><input type="date" name="as_of" value="{{ $asOf }}" max="{{ bdate()->toDateString() }}" class="input input-sm" style="width:auto"><button class="btn btn-sm">Go</button></form>
<button class="btn" onclick="window.print()"><x-icon name="print"/> Print</button>@endsection
@section('content')
<div class="stats"><x-stat label="Total billed" :value="money($companies->sum('billed'))" icon="receipt" tone="blue"/><x-stat label="Received" :value="money($companies->sum('received'))" icon="money" tone="green"/><x-stat label="Outstanding" :value="money($tie['list'])" icon="alert" :tone="$tie['list'] > 0 ? 'red' : 'green'" :sub="'as at '.dmy($asOf)"/></div>

{{-- v9.8 this list and the ageing are two queries over one sub-ledger: say whether they land on the same total, and name
     the companies whose rows disagree, before a statement is sent from a balance that is out --}}
<div class="card" id="cityLedgerTie"><div class="card-head"><h3>Does the list agree with the ageing?</h3>
  <span class="muted small">{{ $tie['rows'] }} companies · as at {{ dmy($tie['as_of']) }}</span></div>
  <div class="card-body"><div class="stats" style="margin:0">
    <x-stat label="This list totals" :value="money($tie['list'])" icon="building" tone="slate" sub="every company's charges less its receipts up to the date"/>
    <x-stat label="The ageing says" :value="money($tie['ageing'])" icon="chart" tone="slate" :sub="'the same money read FIFO into buckets, at '.dmy($tie['as_of'])"/>
    <x-stat label="Difference" :value="money(abs($tie['diff']))" icon="alert" :tone="$tie['ties'] ? 'green' : 'red'" :sub="$tie['ties'] ? 'the two agree' : 'the two disagree'"/>
  </div>
  @if($tie['ties'])
    <p class="small" id="cityLedgerTieState" style="margin:.6rem 0 0"><x-icon name="check"/>The list totals {{ money($tie['list']) }}
      and the receivables ageing at {{ dmy($tie['as_of']) }} says the same, company by company. Every row below can be read as it stands.
  </p>
  @else
    <p class="small text-red" id="cityLedgerTieState" style="margin:.6rem 0 0"><x-icon name="alert"/>The list totals {{ money($tie['list']) }}
      but the ageing at {{ dmy($tie['as_of']) }} says {{ money($tie['ageing']) }} — a difference of {{ money(abs($tie['diff'])) }}.
      @if($tie['drift']) The rows that disagree are
        @foreach($tie['drift'] as $d){{ $d['name'] }} (list {{ money($d['list'], false) }}, ageing {{ money($d['ageing'], false) }}) @if(! $loop->last), @endif @endforeach
        — open each ledger and check its charges and receipts before this list goes out. @else
        The rows agree one by one, so the difference is in a company the ageing lists and this list does not. @endif</p>
  @endif
  </div>
  @if($tie['over_limit'] > 0)
    <p class="small" id="cityLedgerLimit" style="margin:.3rem 0 0"><x-icon name="shield"/>@if($tie['over_limit'] === 1)One of these companies is over its credit limit and the ageing names it. @else{{ $tie['over_limit'] }} of these companies are over their credit limits, and the ageing names them. @endif</p>
  @endif
</div>

<div class="card flush"><div class="card-body table-wrap"><table class="table" id="clTable">
<thead><tr><th>Company / Agent</th><th>Type</th><th>Contact</th><th class="num">Credit limit</th><th class="num">Billed</th><th class="num">Received</th><th class="num">Outstanding</th><th></th></tr></thead><tbody>
@forelse($companies as $c)
<tr data-company="{{ $c->id }}" class="{{ $c->quiet ? 'quiet-row' : '' }}">
  <td class="bold">{{ $c->name }} @if($c->flag)<div class="tiny {{ $c->flag === 'OVER LIMIT' ? 'text-red' : 'muted' }}">{{ $c->flag }}</div>@endif</td>
  <td>{{ ucfirst(str_replace('_',' ',$c->type)) }}</td>
  <td class="small">{{ $c->contact_person }} {{ $c->phone }}</td>
  <td class="num">{{ $c->credit_limit ? money($c->credit_limit, false) : '—' }}</td>
  <td class="num">{{ $c->quiet ? '—' : money($c->billed, false) }}</td>
  <td class="num">{{ $c->quiet ? '—' : money($c->received, false) }}</td>
  <td class="num bold {{ $c->outstanding > 0 ? 'text-red' : '' }} {{ $c->outstanding < 0 ? 'text-green' : '' }}">{{ $c->quiet ? '—' : money($c->outstanding, false) }}
    @if($c->quiet)<div class="tiny muted">no entries yet</div>@endif
    @if($c->outstanding < 0)<div class="tiny muted">credit on account</div>@endif
    @if(! $c->ties)<div class="tiny text-red">the ageing says {{ money($c->aged, false) }}</div>@endif</td>
  <td class="right nowrap"><a class="btn btn-sm" href="{{ route('accounts.company', $c) }}">Ledger</a></td>
</tr>
@empty<tr><td colspan="8"><x-empty text="No companies." icon="building"/></td></tr>@endforelse
</tbody><tfoot><tr class="bold"><td colspan="4">Total — {{ $companies->count() }} {{ $companies->count() === 1 ? 'company' : 'companies' }}</td><td class="num">{{ money($companies->sum('billed'), false) }}</td><td class="num">{{ money($companies->sum('received'), false) }}</td><td class="num" id="clClosing">{{ money($tie['list']) }}</td><td></td></tr></tfoot></table></div></div>

<p class="small muted">Outstanding is every city-ledger charge dated up to the date read, less every receipt dated up to it; a charge dated after
the date has not happened yet and a receipt dated after it has not been banked. The ageing applies the same receipts <b>oldest charge first</b>, so a
company paid in advance reads as a credit on account rather than being dropped from the screen — which is why the two totals must agree. The flag
beside a name is the ageing's own: <em>over limit</em> is measured against that company's credit limit and <em>90+ overdue</em> against the oldest open charge.</p>
@endsection
