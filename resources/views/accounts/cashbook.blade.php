@extends('layouts.app')
@section('title', 'Cash Book / Daily Collection')
@section('crumbs') Accounts / Cash Book @endsection
@section('actions')<form method="get" class="flex gap-1 items-center"><input class="input" type="date" name="date" value="{{ $date }}" max="{{ bdate()->toDateString() }}" data-autosubmit></form><button class="btn" onclick="window.print()"><x-icon name="print"/> Print</button>@endsection
@section('content')

{{-- v9.9 a cash book opens with what the tills held, moves, and closes with what they hold: the day's net alone is not a
     figure anyone can count a drawer against, and a till that closes below zero has paid out money it never held --}}
<div class="card" id="cashBookControl"><div class="card-head"><h3>Does this day close?</h3>
  <span class="muted small">{{ dmy($date) }} · posted through {{ dmy($posting['posted_through']) }}</span></div>
  <div class="card-body"><div class="stats" style="margin:0">
    <x-stat label="Brought forward" :value="money($totals['open'])" icon="wallet" tone="slate" :sub="'what the tills held before '.dmy($date)"/>
    <x-stat label="This day moves" :value="money($totals['in'] - $totals['out'])" icon="money" tone="blue" :sub="$sourcesNote"/>
    <x-stat label="Carried forward" :value="money($totals['close'])" icon="book" :tone="count($drawn) ? 'red' : 'green'" :sub="count($drawn) ? 'a till closes below zero' : 'receipts '.money($totals['in'], false).' less payments '.money($totals['out'], false)"/>
  </div>
  <p class="small" id="cashBookState" style="margin:.6rem 0 0">
    @if($posting['posted'])
      <x-icon name="check"/>The ledger is posted through {{ dmy($posting['posted_through']) }}, so this day is in the books: its {{ money($totals['in'], false) }} of receipts
      and {{ money($totals['out'], false) }} of payments have reached the general ledger, and the tills close at {{ money($totals['close']) }}.
    @else
      <x-icon name="clock"/>The ledger is posted through {{ dmy($posting['posted_through']) }}, so {{ dmy($date) }} is a day still open: its {{ money($totals['in'], false) }} of receipts
      and {{ money($totals['out'], false) }} of payments have not reached the general ledger yet, and the tills stand at {{ money($totals['close']) }} until they do.
    @endif
  </p>
  @if(count($drawn) > 0)
    <p class="small text-red" id="cashBookDrawn" style="margin:.3rem 0 0"><x-icon name="alert"/>@foreach($drawn as $mode){{ $mode }} @if(! $loop->last), @endif @endforeach
      {{ count($drawn) === 1 ? 'closes' : 'close' }} below zero. A drawer cannot pay out more than it held, so either a refund was taken from the wrong mode or the
      opening balance is short — check the day's payments against the float before the day is banked.</p>
  @endif
  </div>
</div>

<div class="card flush"><div class="card-head"><h3>By payment mode</h3><span class="muted small">brought forward · receipts · payments · carried forward</span></div>
<div class="card-body table-wrap"><table class="table table-compact" id="cashBookModes">
<thead><tr><th>Mode</th><th class="num">Brought forward</th><th class="num">Receipts</th><th class="num">Payments</th><th class="num">Carried forward</th></tr></thead><tbody>
@forelse($modes as $m)
<tr data-mode="{{ $m['mode'] }}">
  <td class="bold">{{ $m['mode'] }} @if($m['over'])<div class="tiny text-red">below zero</div>@endif</td>
  <td class="num">{{ money($m['open'], false) }}</td>
  <td class="num">{{ $m['in'] ? money($m['in'], false) : '—' }}</td>
  <td class="num">{{ $m['out'] ? money($m['out'], false) : '—' }}</td>
  <td class="num bold {{ $m['over'] ? 'text-red' : '' }}">{{ money($m['close'], false) }}</td>
</tr>
@empty<tr><td colspan="5"><x-empty text="No money has moved through the tills." icon="money"/></td></tr>@endforelse
</tbody><tfoot><tr class="bold"><td>Total</td><td class="num">{{ money($totals['open'], false) }}</td><td class="num">{{ money($totals['in'], false) }}</td><td class="num">{{ money($totals['out'], false) }}</td><td class="num" id="cashClosing">{{ money($totals['close'], false) }}</td></tr></tfoot></table></div></div>

<div class="card flush"><div class="card-head"><h3>What moved on {{ dmy($date) }}</h3><span class="muted small">{{ $rows->count() }} {{ $rows->count() === 1 ? 'entry' : 'entries' }} from the front office, the tills and the voucher register</span></div>
<div class="card-body table-wrap"><table class="table" id="cashBookRows">
<thead><tr><th>Source</th><th>Ref</th><th>Particulars</th><th>Mode</th><th class="num">Receipt</th><th class="num">Payment</th></tr></thead><tbody>
@forelse($rows as $r)<tr data-src="{{ $r['src'] }}"><td><span class="badge badge-slate">{{ $r['src'] }}</span></td><td>{{ $r['ref'] }}</td><td>{{ $r['desc'] }}</td><td>{{ $r['mode'] }}</td><td class="num">{{ $r['in'] ? money($r['in'], false) : '' }}</td><td class="num">{{ $r['out'] ? money($r['out'], false) : '' }}</td></tr>
@empty<tr><td colspan="6"><x-empty text="No transactions on this date." icon="money"/></td></tr>@endforelse</tbody>
<tfoot><tr class="bold"><td colspan="4" class="right">Total</td><td class="num" id="cashIn">{{ money($rows->sum('in')) }}</td><td class="num" id="cashOut">{{ money($rows->sum('out')) }}</td></tr></tfoot></table></div></div>

<p class="small muted">The book carries every mode forward from the day before: what it held, plus the day's receipts, less the day's payments, is what it holds at
the close. Three things move money and all three are here — guest payments and refunds on the folios, settled takings paid at a POS counter, and the voucher
register. The general ledger is posted through yesterday, so today's collections sit outside it until the next sync; a mode that closes below zero has paid
out more than it held and must be looked at before the day is banked.</p>
@endsection
