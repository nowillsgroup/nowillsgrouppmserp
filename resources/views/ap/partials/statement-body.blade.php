@if($age)
<div class="ap-summary mb-1">
  <div><div class="l">Not yet due</div><div class="v">{{ money($age['current']) }}</div></div>
  <div><div class="l">Overdue</div><div class="v {{ ($age['d30'] + $age['d60'] + $age['d90'] + $age['d90p']) > 0 ? 'red' : '' }}">{{ money($age['d30'] + $age['d60'] + $age['d90'] + $age['d90p']) }}</div></div>
  <div><div class="l">Unapplied credit</div><div class="v">{{ money($age['credit']) }}</div></div>
  <div><div class="l">Balance owed</div><div class="v">{{ money($age['balance']) }}</div></div>
</div>
@endif
<div class="card flush"><div class="card-body table-wrap"><table class="table table-compact" id="stmtTable">
<thead><tr><th>Date</th><th>Document</th><th>Particulars</th><th>Supplier ref.</th><th class="num">Debit (paid / credited)</th><th class="num">Credit (owed)</th><th class="num">Balance</th></tr></thead><tbody>
<tr class="stmt-open"><td>{{ \Illuminate\Support\Carbon::parse($from)->format('d M Y') }}</td><td colspan="5">Opening balance</td><td class="num">{{ money($st['opening'], false) }}</td></tr>
@forelse($st['rows'] as $r)
<tr><td class="nowrap">{{ \Illuminate\Support\Carbon::parse($r['date'])->format('d M Y') }}</td><td>@isset($print){{ $r['doc'] }}@else<a href="{{ $r['link'] }}">{{ $r['doc'] }}</a>@endisset</td><td>{{ $r['type'] }}@if(! empty($r['info']))<span class="tiny muted"> · invoice {{ $r['info'] }}</span>@endif</td><td>{{ $r['ref'] }}</td>
<td class="num">{{ $r['dr'] ? money($r['dr'], false) : '' }}</td><td class="num">{{ $r['cr'] ? money($r['cr'], false) : '' }}</td><td class="num">{{ money($r['balance'], false) }}</td></tr>
@empty<tr><td colspan="7" class="empty">No transactions in this period.</td></tr>@endforelse
<tr class="stmt-close"><td>{{ \Illuminate\Support\Carbon::parse($to)->format('d M Y') }}</td><td colspan="3">Closing balance</td><td class="num">{{ money($st['dr'], false) }}</td><td class="num">{{ money($st['cr'], false) }}</td><td class="num">{{ money($st['closing'], false) }}</td></tr>
</tbody></table></div></div>
<p class="small muted">Goods are owed from the date they are received; a matched supplier invoice then only adds its price variance. Bills on hold are shown for reference at zero.</p>
