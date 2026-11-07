@extends('layouts.app')
@section('title', 'Company Receivables Ageing')
@section('crumbs') Accounts / Receivables @endsection
@section('actions')
<form method="get" class="flex gap-1 items-center"><label class="small">As at</label><input type="date" name="as_of" value="{{ $asOf }}" max="{{ bdate()->toDateString() }}" class="input input-sm" style="width:auto"><button class="btn btn-sm">Go</button></form>
<a class="btn" href="{{ route('reports.show', ['ar_aging', 'to' => $asOf, 'export' => 'csv']) }}"><x-icon name="download"/> CSV</a>
@endsection
@section('content')
<div class="stats">
  <x-stat label="Total receivable" :value="money($tot['outstanding'])" icon="wallet" tone="blue" sub="city ledger, FIFO aged"/>
  <x-stat label="Overdue" :value="money($tot['b30'] + $tot['b60'] + $tot['b90'])" icon="alert" :tone="($tot['b30'] + $tot['b60'] + $tot['b90']) > 0 ? 'red' : 'green'"
    :sub="$tot['b0'] > 0 ? money($tot['b0']).' is inside its terms' : 'nothing inside its terms'"/>
  <x-stat label="Over the credit limit" :value="$overLimit.' company'.($overLimit === 1 ? '' : 'ies')" icon="shield" :tone="$overLimit > 0 ? 'red' : 'green'"
    sub="measured against each company's own limit"/>
  <x-stat label="Receipts on account" :value="money($tot['unapplied'])" icon="money" tone="amber" sub="paid, not yet billed"/>
</div>

<div class="card flush"><div class="card-body table-wrap"><table class="table table-compact" id="arTable">
<thead><tr><th>Company</th><th>Type</th>
@foreach($buckets as $bk => $bl)<th class="num">{{ $bl }}@if(($counts[$bk] ?? 0) > 0)<div class="tiny muted">{{ $counts[$bk] }} doc{{ $counts[$bk] === 1 ? '' : 's' }}</div>@endif</th>@endforeach
<th class="num">On account</th><th class="num">Outstanding</th><th class="num">Credit limit</th><th class="num">Oldest</th><th></th></tr></thead>
<tbody>
@forelse($rows as $r)
<tr data-company="{{ $r['id'] }}">
  <td class="bold">{{ $r['company'] }}@if($r['flag'])<div class="tiny {{ $r['flag'] === 'OVER LIMIT' ? 'text-red' : 'muted' }}">{{ $r['flag'] }}</div>@endif</td>
  <td class="small muted">{{ $r['type'] }}</td>
  @foreach(array_keys($buckets) as $bk)<td class="num {{ $bk !== 'b0' && $r[$bk] > 0 ? 'age-bad' : '' }}">{{ $r[$bk] ? money($r[$bk], false) : '—' }}@if($r[$bk] && ($r['counts'][$bk] ?? 0) > 0)<div class="tiny muted">{{ $r['counts'][$bk] }} doc{{ $r['counts'][$bk] === 1 ? '' : 's' }}</div>@endif</td>@endforeach
  <td class="num">{{ $r['unapplied'] ? '−'.money($r['unapplied'], false) : '—' }}</td>
  <td class="num bold {{ $r['outstanding'] < 0 ? 'text-green' : '' }}">{{ money($r['outstanding'], false) }}</td>
  <td class="num muted">{{ $r['limit'] ? money($r['limit'], false) : '—' }}</td>
  <td class="num {{ $r['oldest_days'] > 30 ? 'text-red' : 'muted' }}">{{ $r['oldest'] }}</td>
  <td class="right nowrap"><a class="btn btn-sm" href="{{ route('accounts.company', $r['id']) }}">Ledger</a></td>
</tr>
@empty
<tr><td colspan="11" class="empty">No company owes anything as at {{ dmy($asOf) }}.</td></tr>
@endforelse
</tbody>
@if($rows->count())
<tfoot><tr class="bold"><td colspan="2">Total</td>
  @foreach(array_keys($buckets) as $bk)<td class="num">{{ money($tot[$bk], false) }}</td>@endforeach
  <td class="num">{{ $tot['unapplied'] ? '−'.money($tot['unapplied'], false) : '—' }}</td>
  <td class="num">{{ money($tot['outstanding'], false) }}</td><td></td><td></td><td></td></tr></tfoot>
@endif
</table></div></div>
<p class="small muted">A city ledger charge is not settled against itself: a company pays in lumps and the PMS records the money, not which
invoice it was for. So receipts are applied <b>oldest charge first</b> (FIFO) and the buckets are days past the due date — the bill's
own due date where one was set, otherwise the charge date plus the company's credit days. A receipt with nothing left to settle is a
credit on account. The outstanding column ties to the City Ledger control account on the General Ledger page once the day is closed.</p>

<div class="card flush" id="arItemsCard"><div class="card-head"><h3>Aged items</h3>
  <span class="muted small">every open charge behind the figures above – {{ count($items) }} of them, oldest first</span></div>
  <div class="card-body table-wrap"><table class="table table-compact" id="arItems">
  <thead><tr><th>Company</th><th>Charge</th><th>Kind</th><th class="num">Date</th><th class="num">Due</th><th class="num">Days past due</th><th>Bucket</th><th class="num">Amount</th></tr></thead>
  <tbody>
  @forelse($items as $it)
    <tr data-kind="{{ $it['kind'] }}" data-bucket="{{ $it['bucket'] }}">
      <td>{{ $it['company'] }}</td>
      <td class="bold">{{ $it['doc'] }}@if($it['desc'])<div class="tiny muted">{{ $it['desc'] }}</div>@endif</td>
      <td class="small">{{ $it['kind'] }}@if($it['note'])<div class="tiny muted">{{ $it['note'] }}</div>@endif</td>
      <td class="num">{{ dmy($it['date']) }}</td>
      <td class="num">{{ $it['due'] ? dmy($it['due']) : '—' }}</td>
      <td class="num {{ $it['days'] > 0 ? 'text-red' : 'muted' }}">{{ $it['days'] > 0 ? $it['days'] : '—' }}</td>
      <td class="small">{{ $buckets[$it['bucket']] ?? $it['bucket'] }}</td>
      <td class="num {{ $it['amount'] < 0 ? 'text-green' : '' }}">{{ $it['amount'] < 0 ? '−'.money(abs($it['amount']), false) : money($it['amount'], false) }}</td>
    </tr>
  @empty
    <tr><td colspan="8" class="empty">Nothing outstanding – there is no open charge.</td></tr>
  @endforelse
  </tbody>
  @if(count($items))
  <tfoot><tr class="bold"><td colspan="7">These charges add up to</td><td class="num">{{ money($itemsTotal, false) }}</td></tr></tfoot>
  @endif
  </table></div>
  <div class="card-body" style="border-top:1px solid var(--c-border)">
    @if(abs($itemsTotal - $tot['outstanding']) < 0.005)
      <p class="small" id="arTie" style="margin:0"><x-icon name="check"/>The {{ count($items) }} documents add up to
        {{ money($itemsTotal) }}, which is the outstanding balance above. Every figure in the buckets is a document on this list.</p>
    @else
      <p class="small text-red" id="arTie" style="margin:0"><x-icon name="alert"/>The documents add up to {{ money($itemsTotal) }}
        but the table says {{ money($tot['outstanding']) }} – a difference of {{ money(abs($itemsTotal - $tot['outstanding'])) }}.
        A charge or a receipt is being counted twice or not at all; do not report the ageing until it is found.</p>
    @endif
    <p class="tiny muted" style="margin:.4rem 0 0">Each charge shows what is left of it <b>after</b> the FIFO application of receipts —
      that is why a part-paid bill appears at its remainder, not its face value. A receipt nobody has been billed for yet is listed as a
      negative line, because it is money held against future charges.</p>
  </div>
</div>

<div class="card" id="controlTie"><div class="card-head"><h3>Does it tie to the ledger?</h3>
  <span class="muted small">{{ $tie['label'] }} · as at {{ dmy($tie['as_of']) }}</span></div>
  <div class="card-body"><div class="stats" style="margin:0">
    <x-stat label="This screen" :value="money($tie['sub'])" icon="wallet" tone="slate" sub="what the ageing ages"/>
    <x-stat label="Control account" :value="money($tie['gl'])" icon="money" tone="slate" sub="the same money, posted"/>
    <x-stat label="Difference" :value="money(abs($tie['diff']))" icon="alert" :tone="$tie['ties'] ? 'green' : ($tie['behind'] ? 'amber' : 'red')"
      :sub="$tie['ties'] ? 'the two agree' : ($tie['behind'] ? 'the ledger is not posted to this date yet' : 'the two disagree')"/>
  </div>
  @if($tie['ties'])
    <p class="small" id="controlTieState" style="margin:.6rem 0 0"><x-icon name="check"/>The company sub-ledger and the City Ledger
      control account agree to the cent at {{ dmy($tie['as_of']) }}. The ageing can be reported and the month closed on it.</p>
  @elseif($tie['behind'])
    <p class="small" id="controlTieState" style="margin:.6rem 0 0"><x-icon name="clock"/>The ledger is posted through
      <b>{{ dmy($tie['posted_through']) }}</b>, so {{ dmy($tie['as_of']) }} is not in it yet: {{ money(abs($tie['diff'])) }} of the
      difference is the day that has not been posted. Post the day (the night audit does it) and this must come back to nothing —
      a difference that survives the posting is a real one, not a timing one.</p>
  @else
    <p class="small text-red" id="controlTieState" style="margin:.6rem 0 0"><x-icon name="alert"/>The company sub-ledger is
      {{ money($tie['sub']) }} but the City Ledger control account says {{ money($tie['gl']) }} — a difference of {{ money(abs($tie['diff'])) }}
      at a date the ledger has already posted. One of the two is wrong: a charge or a receipt has moved the sub-ledger without posting,
      or something posted to the control account that is not a company document. Do not close the month on this ageing.</p>
  @endif
  </div>
</div>
@endsection
