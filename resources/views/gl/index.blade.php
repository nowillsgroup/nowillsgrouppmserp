@extends('layouts.app')
@section('title', 'General Ledger')
@section('actions')
@if(can_do('gl.journal'))<a class="btn btn-primary" href="{{ route('gl.journals.create') }}"><x-icon name="plus"/> Manual Journal</a>@endif
<a class="btn" href="{{ route('gl.journals') }}"><x-icon name="book"/> Journals</a>
@if(can_do('gl.manage'))<form method="post" action="{{ route('gl.sync') }}" style="display:inline">@csrf<button class="btn" id="gl-sync"><x-icon name="refresh"/> Sync now</button></form>@endif
@endsection
@section('content')
@php
  $fmt = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d M Y') : '—';
  $diffTotals = round($totals['close_dr'] - $totals['close_cr'], 2);
@endphp
<div class="stats">
  <x-stat label="Posted up to" :value="$fmt($cutoff)" icon="book" tone="blue" :sub="$cutoff && $cutoff < $nextCutoff ? 'Next sync posts up to '.$fmt($nextCutoff) : 'All closed days posted'"/>
  <x-stat label="Last sync" :value="$syncedAt ? \Illuminate\Support\Carbon::parse($syncedAt)->diffForHumans() : 'never'" icon="refresh" tone="slate" :sub="$journalCount.' journals in the ledger'"/>
  <x-stat label="Books locked up to" :value="$lock ? $fmt($lock) : 'Open'" icon="lock" :tone="$lock ? 'purple' : 'amber'"/>
  <x-stat label="Trial balance" :value="abs($diffTotals) < 0.01 ? 'Balanced' : 'Off by '.money($diffTotals)" icon="check" :tone="abs($diffTotals) < 0.01 ? 'green' : 'red'"/>
</div>

@if(count($glErrors))
<div class="alert alert-error"><strong>{{ count($glErrors) }} source document(s) could not be posted in the last sync.</strong> They are retried automatically on every sync.
<ul class="small mt-2">@foreach(array_slice($glErrors, 0, 10) as $e)<li>{{ $e }}</li>@endforeach</ul></div>
@endif

<div class="grid g-2">
<div class="card flush"><div class="card-head"><h3>Control accounts vs sub-ledgers</h3><span class="muted small">as at {{ $fmt($cutoff) }}</span></div>
<div class="card-body table-wrap"><table class="table table-compact" id="gl-controls">
<thead><tr><th>Control</th><th class="num">Sub-ledger</th><th class="num">General ledger</th><th class="num">Difference</th><th></th></tr></thead><tbody>
@forelse($controls as $c)
@php $d = round($c['gl'] - $c['sub'], 2); @endphp
<tr><td>{{ $c['label'] }}</td><td class="num">{{ money($c['sub'], false) }}</td>
<td class="num"><a href="{{ route('gl.ledger', \App\Models\AccountHead::sys($c['key'])) }}">{{ money($c['gl'], false) }}</a></td>
<td class="num">{{ money($d, false) }}</td>
<td>@if(abs($d) < 0.01)<span class="ctl-ok">✓ Reconciled</span>@elseif($c['strict'])<span class="ctl-bad">Mismatch</span>@else<span class="ctl-info" title="Opening stock and stock-take revaluations use the latest cost">Indicative</span>@endif</td></tr>
@empty<tr><td colspan="5" class="muted">The ledger has not been synced yet.</td></tr>@endforelse
</tbody></table>
<p class="small muted" style="padding:0 1.1rem .8rem">Guest and city ledger must reconcile to the cent. Inventory is compared with quantity × latest cost, so it is indicative only.</p></div></div>

<div class="card"><div class="card-head"><h3>Period close</h3></div><div class="card-body">
<p class="small muted mb-1">Operational modules keep working after a day is closed. The ledger posts <strong>closed business days only</strong> (automatically at night audit). If a closed day is amended later (void, edit, deleted voucher), the original journal is reversed and a corrected one posted on the next open day, so nothing already reported changes silently.</p>
@if(can_do('gl.manage'))
<form method="post" action="{{ route('gl.lock') }}" class="filters mt-2">@csrf
<div class="field"><label>Lock books up to</label><input type="date" name="lock_date" value="{{ $lock ?? $cutoff }}" max="{{ $cutoff ?? $nextCutoff }}"></div>
<div class="field" style="min-width:220px"><label>Reason (required to move back)</label><input name="reason" maxlength="120" placeholder="e.g. August closed"></div>
<button class="btn btn-primary"><x-icon name="lock"/> Lock</button>
@if($lock)<button class="btn btn-ghost" name="unlock" value="1">Unlock</button>@endif
</form>
<form method="post" action="{{ route('gl.sync') }}" class="mt-2" data-confirm="Re-check every source document after the lock date and correct any differences (e.g. after changing the GL mapping)?">@csrf<input type="hidden" name="full" value="1"><button class="btn btn-sm"><x-icon name="refresh" size="sm"/> Re-check all open periods</button></form>
@endif
</div></div>
</div>

<div class="card flush"><div class="card-head"><h3>Trial balance</h3>
<form class="filters no-print"><div class="field"><label>From</label><input type="date" name="from" value="{{ $from }}"></div><div class="field"><label>To</label><input type="date" name="to" value="{{ $to }}"></div><button class="btn btn-primary">Show</button>
<a class="btn" href="{{ route('reports.show', ['trial_balance', 'from' => $from, 'to' => $to]) }}"><x-icon name="print"/> Print / CSV</a></form></div>
<div class="card-body table-wrap"><table class="table table-compact" id="tb">
<thead><tr><th>Code</th><th>Account</th><th class="num">Opening</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Closing Dr</th><th class="num">Closing Cr</th></tr></thead>
<tbody>
@forelse($tb as $type => $rows)
<tr class="gl-type"><td colspan="7">{{ $type }}</td></tr>
@foreach($rows as $r)
@php $open = $r['open_dr'] - $r['open_cr']; @endphp
<tr><td class="muted">{{ $r['code'] }}</td><td><a href="{{ route('gl.ledger', [$r['id'], 'from' => $from, 'to' => $to]) }}">{{ $r['account'] }}</a></td>
<td class="num">{{ $open ? money(abs($open), false) : '' }}@if($open)<span class="dr-cr">{{ $open > 0 ? 'Dr' : 'Cr' }}</span>@endif</td>
<td class="num">{{ $r['dr'] ? money($r['dr'], false) : '' }}</td><td class="num">{{ $r['cr'] ? money($r['cr'], false) : '' }}</td>
<td class="num bold">{{ $r['close_dr'] ? money($r['close_dr'], false) : '' }}</td><td class="num bold">{{ $r['close_cr'] ? money($r['close_cr'], false) : '' }}</td></tr>
@endforeach
@empty<tr><td colspan="7"><x-empty text="No postings yet – run a sync after the first night audit." icon="book"/></td></tr>@endforelse
</tbody>
<tfoot><tr><td></td><td>Total</td><td></td><td class="num">{{ money($totals['dr'], false) }}</td><td class="num">{{ money($totals['cr'], false) }}</td><td class="num">{{ money($totals['close_dr'], false) }}</td><td class="num">{{ money($totals['close_cr'], false) }}</td></tr></tfoot>
</table></div></div>
<p class="small muted">Statements: <a href="{{ route('reports.show', ['gl_pnl', 'from' => $from, 'to' => $to]) }}">Profit &amp; Loss</a> · <a href="{{ route('reports.show', ['balance_sheet', 'to' => $to]) }}">Balance Sheet</a> · <a href="{{ route('reports.show', ['journal_register', 'from' => $from, 'to' => $to]) }}">Journal register</a>@if(can_do('gl.manage')) · <a href="{{ route('gl.mapping') }}">GL mapping</a> · <a href="{{ route('masters.index', 'account-heads') }}">Chart of accounts</a>@endif</p>
@endsection
