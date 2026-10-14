@extends('layouts.app')
@section('title', 'Bank Reconciliation')
@section('crumbs') Accounts / Bank reconciliation @endsection
@php
  $states = ['not_started' => ['slate', 'Not started'], 'in_progress' => ['blue', 'In progress'], 'up_to_date' => ['green', 'Up to date'], 'overdue' => ['red', 'Overdue']];
@endphp
@section('actions')
@if(can_do('bankrec.manage'))<a class="btn btn-primary" href="{{ route('bankrec.create') }}" id="newStatementBtn"><x-icon name="plus"/> New statement</a>@endif
@if(can_do('reports.view'))<a class="btn" href="{{ route('reports.show', ['bank_reconciliation', 'to' => bdate()->copy()->subDay()->toDateString()]) }}"><x-icon name="chart"/> Reconciliation report</a>@endif
@endsection
@section('content')
@if($overview->where('review', true)->isNotEmpty())
<div class="alert alert-error" id="recReview"><x-icon name="alert"/><div><b>Review needed:</b> a book entry cleared on a reconciled statement was changed afterwards ({{ $overview->where('review', true)->map(fn ($o) => $o['account']->name)->join(', ') }}). Reopen the latest statement and match it again.</div></div>
@endif

<div class="stats" id="recAccounts">
@foreach($overview as $o)
  @php [$tone, $txt] = $states[$o['state']]; $a = $o['account']; @endphp
  <div id="recAcc{{ $a->code }}" data-state="{{ $o['state'] }}" style="display:contents">
  <x-stat :label="$a->code.' '.$a->name" :value="money($o['book'])" :icon="$a->system_key === 'wallet_clearing' ? 'phone' : ($a->system_key === 'card_clearing' ? 'wallet' : 'building')" :tone="$tone"
    :href="$o['draft'] ? route('bankrec.show', $o['draft']) : ($o['reconciled'] ? route('bankrec.show', $o['reconciled']) : (can_do('bankrec.manage') ? route('bankrec.create', ['account' => $a->id]) : null))"
    :sub="$txt.($o['reconciled'] ? ' · reconciled to '.$o['reconciled']->period_to->format('d M') : '').($o['draft'] ? ' · '.$o['draft']->statement_no.($o['unmatched'] ? ', '.$o['unmatched'].' to match' : '') : '')"/></div>
@endforeach
</div>
<p class="tiny muted">Book balance per the general ledger today. An account is overdue when its last reconciled statement ended more than {{ $cfg['bankrec_stale_days'] }} days ago.</p>

<div class="card flush mt-1">
  <div class="card-head"><h3>Statements</h3>
    <form class="filters" style="margin:0"><select name="account" class="input" onchange="this.form.submit()" id="recAccFilter"><option value="">All accounts</option>@foreach($accounts as $a)<option value="{{ $a->id }}" @selected($account === $a->id)>{{ $a->code }} {{ $a->name }}</option>@endforeach</select></form>
  </div>
  <div class="card-body table-wrap"><table class="table" id="statementTable">
    <thead><tr><th>Statement</th><th>Account</th><th>Period</th><th class="num">Opening</th><th class="num">Closing</th><th>Lines</th><th>Status</th><th>Reconciled</th></tr></thead>
    <tbody>
    @forelse($statements as $s)
    <tr><td><a href="{{ route('bankrec.show', $s) }}" class="bold">{{ $s->statement_no }}</a><div class="tiny muted">{{ $s->sourceLabel() }}</div></td>
      <td class="small">{{ $s->account->code }} {{ $s->account->name }}</td><td class="small">{{ $s->label() }}</td>
      <td class="num">{{ money($s->opening_balance, false) }}</td><td class="num">{{ money($s->closing_balance, false) }}</td>
      <td class="small">{{ $s->matched_count }} / {{ $s->lines_count }} matched @if($s->open_count)<span class="text-red">· {{ $s->open_count }} open</span>@endif</td>
      <td>{!! status_badge($s->status) !!}@if($s->needs_review) <span class="badge badge-red">review</span>@endif</td>
      <td class="small">{{ $s->reconciled_at ? $s->reconciled_at->format('d M Y') : '—' }}<div class="tiny muted">{{ $s->reconciler?->name }}</div></td></tr>
    @empty<tr><td colspan="8"><x-empty text="No statements yet. Import your bank or Mobile Money statement to start." icon="book"/></td></tr>@endforelse
    </tbody></table></div>
  @if($statements->hasPages())<div class="card-body">{{ $statements->links() }}</div>@endif
</div>

<div class="card mt-2"><div class="card-head"><h3><x-icon name="tool"/> Settings</h3></div><div class="card-body">
  @if(can_do('bankrec.approve'))
  <form method="post" action="{{ route('bankrec.settings') }}" class="form-grid" id="recSettings">@csrf
    <div class="field"><label for="f_bankrec_window_days">Auto-match: days either side</label><input type="number" min="0" max="31" name="bankrec_window_days" id="f_bankrec_window_days" value="{{ $cfg['bankrec_window_days'] }}" required></div>
    <div class="field"><label for="f_bankrec_stale_days">Overdue after (days since the last reconciled statement)</label><input type="number" min="7" max="365" name="bankrec_stale_days" id="f_bankrec_stale_days" value="{{ $cfg['bankrec_stale_days'] }}" required></div>
    <div class="field"><label class="check"><input type="checkbox" name="bankrec_segregation" value="1" @checked($cfg['bankrec_segregation'] === '1')> Whoever loads a statement cannot finalise it</label></div>
    <div><button class="btn btn-primary">Save</button></div>
  </form>
  @else
  <p class="small">Auto-match window ±{{ $cfg['bankrec_window_days'] }} days · overdue after {{ $cfg['bankrec_stale_days'] }} days.</p>
  @endif
  <p class="tiny muted mt-1">Statements follow on from each other: one in progress per account, each starting the day after the previous one ends with its closing balance. Book entries before an account's first statement are treated as its opening balance.</p>
</div></div>
@endsection
