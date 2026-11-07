@extends('layouts.app')
@section('title', 'Accounts Payable Ageing')
@section('crumbs') Accounts / Payables @endsection
@section('actions')
<form method="get" class="flex gap-1 items-center"><label class="small">As at</label><input type="date" name="as_of" value="{{ $asOf }}" max="{{ bdate()->toDateString() }}" class="input input-sm" style="width:auto"><button class="btn btn-sm">Go</button></form>
<a class="btn" href="{{ route('reports.show', ['ap_ageing', 'to' => $asOf, 'export' => 'csv']) }}"><x-icon name="download"/> CSV</a>
@endsection
@section('content')
<div class="stats">
  <x-stat label="Total payable" :value="money($tot['balance'])" icon="wallet" tone="blue"/>
  <x-stat label="Not yet due" :value="money($tot['current'])" icon="clock" tone="green"/>
  <x-stat label="Overdue" :value="money($tot['d30'] + $tot['d60'] + $tot['d90'] + $tot['d90p'])" icon="alert" :tone="($tot['d30'] + $tot['d60'] + $tot['d90'] + $tot['d90p']) > 0 ? 'red' : 'green'"
    :sub="$due['counts']['overdue'].' document'.($due['counts']['overdue'] === 1 ? '' : 's').' past the due date'"/>
  <x-stat label="Due in next 7 days" :value="money($due['due7'])" icon="clock" tone="amber"
    :sub="$due['counts']['due7'].' document'.($due['counts']['due7'] === 1 ? '' : 's').' · the same figure the supplier bills screen prints'"/>
  <x-stat label="Unapplied credits" :value="money($tot['credit'])" icon="money" :tone="$tot['credit'] > 0 ? 'amber' : 'green'"
    :sub="number_format($credits['payment_count']).' payment(s) and '.number_format($credits['debit_note_count']).' debit note(s) — the payments screen lists the money'"/>
</div>
<div class="card flush"><div class="card-body table-wrap"><table class="table" id="ageTable">
<thead><tr><th>Supplier</th><th class="num">Terms</th>
@foreach($buckets as $bk => $bl)<th class="num">{{ $bl }}@if(($counts[$bk] ?? 0) > 0)<div class="tiny muted">{{ $counts[$bk] }} doc{{ $counts[$bk] === 1 ? '' : 's' }}</div>@endif</th>@endforeach
<th class="num">Credits</th><th class="num">Balance</th><th class="num">of which unbilled</th><th></th></tr></thead><tbody>
@forelse($rows as $r)
<tr><td class="bold">{{ $r['supplier'] }}@if($r['oldest'] > 0)<div class="tiny {{ $r['oldest'] > 30 ? 'text-red' : 'muted' }}">oldest {{ $r['oldest'] }} days past due</div>@endif</td><td class="num">{{ $r['terms'] }}d</td>
@foreach(['current', 'd30', 'd60', 'd90', 'd90p'] as $k)<td class="num {{ $k !== 'current' && $r[$k] > 0 ? 'age-bad' : '' }}">{{ $r[$k] ? money($r[$k], false) : '—' }}@if($r[$k] && ($r['counts'][$k] ?? 0) > 0)<div class="tiny muted">{{ $r['counts'][$k] }} doc{{ $r['counts'][$k] === 1 ? '' : 's' }}</div>@endif</td>@endforeach
<td class="num">{{ $r['credit'] ? '−'.money($r['credit'], false) : '—' }}</td><td class="num bold">{{ money($r['balance'], false) }}</td><td class="num muted">{{ $r['unbilled'] ? money($r['unbilled'], false) : '—' }}</td>
<td class="right nowrap"><a class="btn btn-sm" href="{{ route('ap.statement', $r['supplier_id']) }}">Statement</a> @if(can_do('ap.pay'))<a class="btn btn-sm btn-primary" href="{{ route('ap.payments.create', ['supplier' => $r['supplier_id']]) }}">Pay</a>@endif</td></tr>
@empty<tr><td colspan="11" class="empty">Nothing owed to suppliers.</td></tr>@endforelse
</tbody>@if($rows->count())<tfoot><tr class="bold"><td colspan="2">Total</td>@foreach(['current', 'd30', 'd60', 'd90', 'd90p'] as $k)<td class="num">{{ money($tot[$k], false) }}</td>@endforeach<td class="num">−{{ money($tot['credit'], false) }}</td><td class="num">{{ money($tot['balance'], false) }}</td><td class="num">{{ money($tot['unbilled'], false) }}</td><td></td></tr></tfoot>@endif</table></div></div>
<p class="small muted">Buckets are days past the due date (bill date + supplier terms). Goods received but not yet billed are aged from the receipt date. The balance ties to the Accounts Payable control account on the General Ledger page once the day is closed.</p>

<div class="card flush" id="agedItemsCard"><div class="card-head"><h3>Aged items</h3>
  <span class="muted small">every document behind the figures above – {{ count($items) }} of them, oldest first</span></div>
  <div class="card-body table-wrap"><table class="table table-compact" id="agedItems">
  <thead><tr><th>Supplier</th><th>Document</th><th>Kind</th><th class="num">Date</th><th class="num">Due</th><th class="num">Days past due</th><th>Bucket</th><th class="num">Amount</th></tr></thead>
  <tbody>
  @forelse($items as $it)
    <tr data-kind="{{ $it['kind'] }}" data-bucket="{{ $it['bucket'] }}">
      <td>{{ $it['supplier'] }}</td>
      <td class="bold">{{ $it['doc'] }}@if($it['ref'])<div class="tiny muted">{{ $it['ref'] }}</div>@endif</td>
      <td class="small">{{ $it['kind'] }}@if($it['note'])<div class="tiny muted">{{ $it['note'] }}</div>@endif</td>
      <td class="num">{{ dmy($it['date']) }}</td>
      <td class="num">{{ $it['due'] ? dmy($it['due']) : '—' }}</td>
      <td class="num {{ $it['days'] > 0 ? 'text-red' : 'muted' }}">{{ $it['days'] > 0 ? $it['days'] : '—' }}</td>
      <td class="small">{{ $buckets[$it['bucket']] ?? $it['bucket'] }}</td>
      <td class="num {{ $it['amount'] < 0 ? 'text-green' : '' }}">{{ $it['amount'] < 0 ? '−'.money(abs($it['amount']), false) : money($it['amount'], false) }}</td>
    </tr>
  @empty
    <tr><td colspan="8" class="empty">Nothing owed to suppliers – there are no open documents.</td></tr>
  @endforelse
  </tbody>
  @if(count($items))
  <tfoot><tr class="bold"><td colspan="7">These documents add up to</td><td class="num">{{ money($itemsTotal, false) }}</td></tr></tfoot>
  @endif
  </table></div>
  <div class="card-body" style="border-top:1px solid var(--c-border)">
    @if(abs($itemsTotal - $tot['balance']) < 0.005)
      <p class="small" id="itemsTie" style="margin:0"><x-icon name="check"/>The {{ count($items) }} documents add up to
        {{ money($itemsTotal) }}, which is the balance above. Nothing is in the ageing that is not on this list, and the other
        way round.</p>
    @else
      <p class="small text-red" id="itemsTie" style="margin:0"><x-icon name="alert"/>The documents add up to {{ money($itemsTotal) }}
        but the ageing says {{ money($tot['balance']) }} – a difference of {{ money(abs($itemsTotal - $tot['balance'])) }}. A
        bucket total or a document is being counted twice or not at all; do not report the ageing until it is found.</p>
    @endif
    <p class="tiny muted" style="margin:.4rem 0 0">A debit note and an unapplied payment are credits, printed negative, because they
      reduce what is owed. Goods received but not yet billed are real obligations with no bill to point at, so they are listed here
      and marked as such.</p>
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
    <p class="small" id="controlTieState" style="margin:.6rem 0 0"><x-icon name="check"/>The supplier sub-ledger and the Accounts Payable
      control account agree to the cent at {{ dmy($tie['as_of']) }}. The ageing can be reported and the month closed on it.</p>
  @elseif($tie['behind'])
    <p class="small" id="controlTieState" style="margin:.6rem 0 0"><x-icon name="clock"/>The ledger is posted through
      <b>{{ dmy($tie['posted_through']) }}</b>, so {{ dmy($tie['as_of']) }} is not in it yet: {{ money(abs($tie['diff'])) }} of the
      difference is the day that has not been posted. Post the day (the night audit does it) and this must come back to nothing —
      a difference that survives the posting is a real one, not a timing one.</p>
  @else
    <p class="small text-red" id="controlTieState" style="margin:.6rem 0 0"><x-icon name="alert"/>The supplier sub-ledger is
      {{ money($tie['sub']) }} but the control account says {{ money($tie['gl']) }} — a difference of {{ money(abs($tie['diff'])) }}
      at a date the ledger has already posted. One of the two is wrong: a bill or a payment has moved the sub-ledger without posting,
      or something posted to the control account that is not a supplier document. Do not close the month on this ageing.</p>
  @endif
  </div>
</div>

@if($fx['rows'])
<div class="card" id="fxExposure"><div class="card-head"><h3>Foreign-currency exposure</h3><span class="badge badge-blue">at the board of {{ dmy($fx['as_of']) }}</span></div>
<div class="card-body table-wrap"><table class="table">
<thead><tr><th>Currency</th><th class="num">Open bills</th><th class="num">Still owed</th><th class="num">Booked in {{ $fx['home'] }}</th><th class="num">Board now</th><th class="num">Cost to settle</th><th class="num">Unrealised</th><th class="num">Settled in it</th><th class="num">Realised</th></tr></thead><tbody>
@foreach($fx['rows'] as $r)
<tr><td class="bold">{{ $r['currency'] }}@if(!$r['bills']) <span class="tiny muted">settled</span>@endif</td><td class="num">{{ $r['bills'] }}</td><td class="num">{{ number_format($r['foreign'], 2) }}</td>
<td class="num">{{ money($r['booked'], false) }}</td>
<td class="num">{{ $r['rate_now'] === null ? '—' : number_format($r['rate_now'], 4) }}@if($r['stale']) *@endif</td>
<td class="num">{{ $r['at_board'] === null ? 'no rate' : money($r['at_board'], false) }}</td>
<td class="num bold {{ ($r['movement'] ?? 0) > 0 ? 'text-red' : ((($r['movement'] ?? 0) < 0) ? 'green' : 'muted') }}">{{ $r['movement'] === null ? '—' : ($r['movement'] > 0 ? '+' : '').money($r['movement'], false) }}</td>
<td class="num">{{ $r['payments'] ? $r['currency'].' '.number_format($r['paid_foreign'], 2) : '—' }}@if($r['payments'])<div class="tiny muted">{{ $r['payments'] }} payment(s)</div>@endif</td>
<td class="num bold {{ $r['realised'] > 0 ? 'text-red' : ($r['realised'] < 0 ? 'green' : 'muted') }}">{{ $r['payments'] ? (($r['realised'] > 0 ? '+' : '').money($r['realised'], false)) : '—' }}</td></tr>
@endforeach
</tbody>@if(count($fx['rows']) > 1 || ($fx['payments'] ?? 0) > 0)<tfoot><tr class="bold"><td colspan="3">Total</td><td class="num">{{ money($fx['booked'], false) }}</td><td></td><td class="num">{{ $fx['at_board'] === null ? '—' : money($fx['at_board'], false) }}</td><td class="num {{ ($fx['movement'] ?? 0) > 0 ? 'text-red' : 'green' }}">{{ $fx['movement'] === null ? '—' : ($fx['movement'] > 0 ? '+' : '').money($fx['movement'], false) }}</td><td class="num">{{ ($fx['payments'] ?? 0) ? $fx['payments'].' payment(s)' : '—' }}</td><td class="num {{ ($fx['realised'] ?? 0) > 0 ? 'text-red' : (($fx['realised'] ?? 0) < 0 ? 'green' : 'muted') }}">{{ ($fx['payments'] ?? 0) ? (($fx['realised'] > 0 ? '+' : '').money($fx['realised'], false)) : '—' }}</td></tr></tfoot>@endif</table>
<p class="small muted">A bill booked in a foreign currency stays an obligation in that currency: the ledger carries it at the board for its invoice date, and settling it later costs whatever the board says then. <b>Unrealised</b> is that difference on what is still open — reported, not posted, because a revaluation belongs to the period end. <b>Realised</b> is what settling in the currency itself has already posted to Exchange Gain / Loss: the gap between what the bank took in {{ $fx['home'] }} and what the bills it settled were booked at. * the board for that date had no rate, so the most recent one within the window was used.@if($fx['unpriced']) {{ $fx['unpriced'] }} of these currencies have no rate on the board at all and cannot be valued.@endif</p>
</div></div>
@endif
@endsection
