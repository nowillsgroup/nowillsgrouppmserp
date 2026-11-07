@extends('layouts.app')
@section('title', 'Profit & Loss Statement')
@section('crumbs') Accounts / Profit &amp; Loss @endsection
@section('actions')<form method="get" class="flex gap-1 items-center"><input class="input input-sm" type="date" name="from" value="{{ $from }}" max="{{ bdate()->toDateString() }}" style="width:auto"><span class="small">to</span><input class="input input-sm" type="date" name="to" value="{{ $to }}" max="{{ bdate()->toDateString() }}" style="width:auto"><button class="btn btn-sm">Generate</button></form><button class="btn" onclick="window.print()"><x-icon name="print"/> Print</button>@endsection
@section('content')

{{-- v9.10 the statement is built from documents and the ledger from posted journals: two readings of one period, so it
     says whether they agree instead of leaving the reader to hope --}}
<div class="card" id="pnlTie"><div class="card-head"><h3>Does the ledger say the same?</h3>
  <span class="muted small">{{ dmy($tie['from']) }} – {{ dmy($tie['to']) }} · this statement read from charges, POS takings and vouchers</span></div>
  <div class="card-body table-wrap"><table class="table table-compact" id="pnlTieTable">
    <thead><tr><th>Line</th><th class="num">This statement</th><th class="num">The general ledger</th><th class="num">Difference</th></tr></thead>
    <tbody>
      <tr data-line="revenue"><td class="bold">Revenue</td><td class="num">{{ money($tie['revenue']['pnl'], false) }}</td><td class="num">{{ money($tie['revenue']['gl'], false) }}</td>
        <td class="num {{ $tie['out']['revenue'] ? 'text-red bold' : '' }}">{{ money($tie['revenue']['diff'], false) }}</td></tr>
      <tr data-line="expense"><td class="bold">Expenses</td><td class="num">{{ money($tie['expense']['pnl'], false) }}</td><td class="num">{{ money($tie['expense']['gl'], false) }}</td>
        <td class="num {{ $tie['out']['expense'] ? 'text-red bold' : '' }}">{{ money($tie['expense']['diff'], false) }}</td></tr>
    </tbody>
  </table>
  @if($tie['ties'])
    <p class="small" id="pnlTieState" style="margin:.6rem 0 0"><x-icon name="check"/>The ledger posted {{ money($tie['revenue']['gl']) }} of income and {{ money($tie['expense']['gl']) }} of expense
      in this period — the same figures this statement reads from the documents. It can be issued as it stands.</p>
  @else
    <p class="small text-red" id="pnlTieState" style="margin:.6rem 0 0"><x-icon name="alert"/>@if($tie['out']['revenue'] && $tie['out']['expense'])Revenue is out by {{ money($tie['revenue']['abs']) }} and expenses are out by {{ money($tie['expense']['abs']) }}.@elseif($tie['out']['revenue'])Revenue is out by {{ money($tie['revenue']['abs']) }}.@else Expenses are out by {{ money($tie['expense']['abs']) }}.@endif
      The statement reads documents and the ledger reads posted journals, so the difference is a document the last sync has not posted, or one
      posted to a head that is not income or expense. Check the sync failures on the general ledger page before this statement is issued.</p>
  @endif
  @if(! $tie['posted'])
    <p class="tiny muted" id="pnlTiePosted" style="margin:.3rem 0 0"><x-icon name="clock"/>The period ends after the posted-through day, so part of it is
      still outside the ledger and a difference here is expected rather than a fault.</p>
  @endif
  </div>
</div>

<div class="stats">
  <x-stat label="Net profit / (loss)" :value="money($net)" icon="chart" :tone="$net >= 0 ? 'green' : 'red'" :sub="money($totalIncome, false).' income less '.money($exp, false).' expense'"/>
  <x-stat label="Net margin" :value="$margin.'%'" icon="trend" :tone="$margin >= 0 ? 'green' : 'red'" sub="of the period's income"/>
  <x-stat label="Taxes collected (liability, excluded)" :value="money($taxCollected)" icon="receipt" tone="slate" sub="held for GRA, never income"/>
</div>

<div class="grid g-2">
<div class="card flush"><div class="card-head"><h3>Income</h3><span class="muted small">{{ count($income) + count($otherIncome) }} {{ count($income) + count($otherIncome) === 1 ? 'line' : 'lines' }}</span></div><div class="card-body"><table class="table" id="pnlIncome">
<tbody>@foreach($income as $k=>$v)<tr><td>{{ $labels[$k] ?? $k }}</td><td class="num">{{ money($v, false) }}</td></tr>@endforeach
@foreach($otherIncome as $k=>$v)<tr><td>{{ $k }}</td><td class="num">{{ money($v, false) }}</td></tr>@endforeach</tbody>
<tfoot><tr class="bold"><td>Total Income</td><td class="num" id="pnlIncomeTotal">{{ money($totalIncome) }}</td></tr></tfoot></table></div></div>
<div class="card flush"><div class="card-head"><h3>Expenses</h3><span class="muted small">{{ count($expenses) }} {{ count($expenses) === 1 ? 'head' : 'heads' }} from the voucher register</span></div><div class="card-body"><table class="table" id="pnlExpenses">
<tbody>@forelse($expenses as $k=>$v)<tr><td>{{ $k }}</td><td class="num">{{ money($v, false) }}</td></tr>@empty<tr><td colspan="2" class="muted">No expense vouchers.</td></tr>@endforelse</tbody>
<tfoot><tr class="bold"><td>Total Expenses</td><td class="num" id="pnlExpenseTotal">{{ money($exp) }}</td></tr></tfoot></table></div></div>
</div>

<p class="small muted">Income is the charges posted to guest and city folios in the period, less discounts and allowances, plus POS takings settled directly at the
counter and receipt vouchers that are not company settlements. Expenses are the payment vouchers of the period, by the account head they were written
against. Tax collected on the charges is shown above but never counted as income: it is a liability to GRA until it is filed. The ledger column is what
the posted journals moved in the same two account natures over the same dates — the floor and the ceiling of the same period.</p>
@endsection
