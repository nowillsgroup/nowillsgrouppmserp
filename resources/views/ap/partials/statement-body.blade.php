@if($age)
<div class="ap-summary mb-1">
  <div><div class="l">Not yet due</div><div class="v">{{ money($age['current']) }}</div></div>
  <div><div class="l">Overdue</div><div class="v {{ ($age['d30'] + $age['d60'] + $age['d90'] + $age['d90p']) > 0 ? 'red' : '' }}">{{ money($age['d30'] + $age['d60'] + $age['d90'] + $age['d90p']) }}</div></div>
  <div><div class="l">Unapplied credit</div><div class="v">{{ money($age['credit']) }}</div></div>
  <div><div class="l">Balance owed</div><div class="v">{{ money($age['balance']) }}</div></div>
</div>
@endif
@unless(isset($print))
{{-- v9.3 the statement and the ageing are two readings of one balance: on screen, say whether they agree before it is sent --}}
<div class="card" id="stmtTie"><div class="card-head"><h3>Does it agree with the ageing?</h3>
  <span class="muted small">{{ $supplier->name }} · as at {{ dmy($tie['as_of']) }}</span></div>
  <div class="card-body"><div class="stats" style="margin:0">
    <x-stat label="This statement closes at" :value="money($tie['statement'])" icon="receipt" tone="slate" sub="the opening balance and every document in the period"/>
    <x-stat label="The ageing says" :value="money($tie['ageing'])" icon="chart" tone="slate" :sub="'bills and goods received, less the credits, at '.dmy($tie['as_of'])"/>
    <x-stat label="Difference" :value="money(abs($tie['diff']))" icon="alert" :tone="$tie['ties'] ? 'green' : 'red'" :sub="$tie['ties'] ? 'the two agree' : 'the two disagree'"/>
  </div>
  @if($tie['ties'])
    <p class="small" id="stmtTieState" style="margin:.6rem 0 0"><x-icon name="check"/>The statement closes at
      {{ money($tie['statement']) }} and the ageing at {{ dmy($tie['as_of']) }} says the same. The supplier can be sent this statement.</p>
  @else
    <p class="small text-red" id="stmtTieState" style="margin:.6rem 0 0"><x-icon name="alert"/>The statement closes at
      {{ money($tie['statement']) }} but the ageing at {{ dmy($tie['as_of']) }} says {{ money($tie['ageing']) }} — a difference of
      {{ money(abs($tie['diff'])) }}. One of them is counting a document the other is not: check this supplier's bills, the goods
      received against them and the credit on account before the statement goes out.</p>
  @endif
  </div>
</div>
@endunless

<div class="card flush"><div class="card-body table-wrap"><table class="table table-compact" id="stmtTable">
<thead><tr><th>Date</th><th>Document</th><th>Particulars</th><th>Supplier ref.</th><th class="num">Debit (paid / credited)</th><th class="num">Credit (owed)</th><th class="num">Balance</th></tr></thead><tbody>
<tr class="stmt-open"><td>{{ dmy($from) }}</td><td colspan="5">Opening balance</td><td class="num">{{ money($st['opening'], false) }}</td></tr>
@forelse($st['rows'] as $r)
<tr><td class="nowrap">{{ dmy($r['date']) }}</td><td>@if(isset($print)){{ $r['doc'] }}@else <a href="{{ $r['link'] }}">{{ $r['doc'] }}</a>@endif</td><td>{{ $r['type'] }}@if(! empty($r['info']))<span class="tiny muted"> · invoice {{ $r['info'] }}</span>@endif</td><td>{{ $r['ref'] }}</td>
<td class="num">{{ $r['dr'] ? money($r['dr'], false) : '' }}</td><td class="num">{{ $r['cr'] ? money($r['cr'], false) : '' }}</td><td class="num">{{ money($r['balance'], false) }}</td></tr>
@empty<tr><td colspan="7" class="empty">No transactions in this period.</td></tr>@endforelse
<tr class="stmt-close"><td>{{ dmy($to) }}</td><td colspan="3">Closing balance</td><td class="num">{{ money($st['dr'], false) }}</td><td class="num">{{ money($st['cr'], false) }}</td><td class="num">{{ money($st['closing'], false) }}</td></tr>
</tbody></table></div></div>
<p class="small muted">Goods are owed from the date they are received; a matched supplier invoice then only adds its price variance. Bills on hold are shown for reference at zero.</p>
