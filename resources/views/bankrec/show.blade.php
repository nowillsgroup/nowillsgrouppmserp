@extends('layouts.app')
@section('title', $s->statement_no.' · '.$s->account->name)
@section('crumbs') Accounts / <a href="{{ route('bankrec.index') }}">Bank reconciliation</a> / {{ $s->statement_no }} @endsection
@php
  $draft = $s->isDraft();
  $manage = $draft && can_do('bankrec.manage');
  $c = $sum['counts'];
  $kinds = collect(\App\Services\BankRecService::ENTRY_KINDS)->map(fn ($k) => $k[0]);
  $shown = $filter ? $lines->where('status', $filter) : $lines;
  $typeLbl = ['reference' => 'auto · reference', 'amount' => 'auto · amount', 'group' => 'auto · batch', 'manual' => 'by hand', 'entry' => 'booked'];
@endphp
@section('actions')
{!! status_badge($s->status) !!}
@if($manage)<form method="post" action="{{ route('bankrec.automatch', $s) }}" class="inline">@csrf<button class="btn" id="autoMatchBtn"><x-icon name="refresh"/> Auto-match</button></form>@endif
<a class="btn" href="{{ route('bankrec.print', $s) }}" target="_blank" id="printBrs"><x-icon name="print"/> Reconciliation statement</a>
@if($draft && can_do('bankrec.approve'))<form method="post" action="{{ route('bankrec.finalise', $s) }}" class="inline" data-confirm="Finalise {{ $s->statement_no }}? The statement and its matches are locked.">@csrf<button class="btn btn-success" id="finaliseBtn" @disabled($blockers) title="{{ $blockers ? implode(' ', $blockers) : '' }}"><x-icon name="check"/> Finalise</button></form>@endif
@if(! $draft && $isLatest && can_do('bankrec.approve'))<button class="btn" data-modal="reopenModal" id="reopenBtn"><x-icon name="edit"/> Reopen</button>@endif
@if($manage)<form method="post" action="{{ route('bankrec.destroy', $s) }}" class="inline" data-confirm="Delete {{ $s->statement_no }} and its lines? Entries booked from it are reversed.">@csrf @method('DELETE')<button class="btn btn-danger btn-icon" title="Delete statement"><x-icon name="trash"/></button></form>@endif
@endsection
@section('content')
@if($s->needs_review)
<div class="alert alert-error" id="stmtReview"><x-icon name="alert"/><div>A book entry cleared on this statement changed after it was reconciled. Reopen it and match the line again.</div></div>
@endif
@if($draft)
  @if($blockers)
  <div class="alert alert-warn" id="recBlockers"><x-icon name="clock"/><div><b>Not ready to finalise:</b> {{ implode(' ', $blockers) }}</div></div>
  @else
  <div class="alert alert-success" id="recReady"><x-icon name="check"/><div><b>Reconciled – difference nil.</b> Finalise to lock the statement.</div></div>
  @endif
@else
  <div class="alert alert-success" id="recDone"><x-icon name="lock"/><div>Reconciled on {{ $s->reconciled_at?->format('d M Y H:i') }} by {{ $s->reconciler?->name ?? '—' }}.@if($s->reopen_reason) <span class="muted">Previously reopened: {{ $s->reopen_reason }}</span>@endif</div></div>
@endif

<div class="stats">
  <x-stat :label="$s->account->code.' '.$s->account->name" :value="$s->label()" icon="calendar" tone="slate" :sub="($s->source === 'csv' ? ($s->file_name ?: 'CSV import') : 'Entered by hand').' · '.$s->statement_no"/>
  <x-stat label="Opening → closing (statement)" :value="money($s->closing_balance)" icon="book" :tone="$sum['balances_ok'] ? 'blue' : 'red'" :sub="'from '.money($s->opening_balance).($sum['balances_ok'] ? '' : ' · lines give '.money($sum['computed_closing']))"/>
  <div id="recCounts" data-matched="{{ $c['matched'] }}" data-unmatched="{{ $c['unmatched'] }}" data-ignored="{{ $c['ignored'] }}" style="display:contents"><x-stat label="Lines matched" :value="$c['matched'].' / '.$c['total']" icon="check" :tone="$c['unmatched'] ? 'amber' : 'green'" :sub="$c['unmatched'].' to match · '.$c['ignored'].' ignored'"/></div>
  <div id="recDiff" data-diff="{{ number_format($sum['difference'], 2, '.', '') }}" style="display:contents"><x-stat label="Unexplained difference" :value="money($sum['difference'])" icon="alert" :tone="abs($sum['difference']) < 0.005 ? 'green' : 'red'" :sub="'book balance '.money($sum['book']).' at '.$s->period_to->format('d M')"/></div>
</div>

<div class="grid g-2-1 mt-1">
<div class="card flush">
  <div class="card-head"><h3>Statement lines</h3>
    <div class="tabs" style="margin:0">@foreach([null => 'All', 'unmatched' => 'To match', 'matched' => 'Matched', 'ignored' => 'Ignored'] as $k => $l)<a href="{{ route('bankrec.show', [$s, 'filter' => $k ?: null]) }}" class="{{ $filter === ($k ?: null) ? 'active' : '' }}">{{ $l }}</a>@endforeach</div>
  </div>
  <div class="card-body table-wrap"><table class="table" id="lineTable">
    <thead><tr>@if($manage)<th></th>@endif<th>Date</th><th>Details</th><th class="num">In</th><th class="num">Out</th><th>Status</th><th></th></tr></thead>
    <tbody>
    @forelse($shown as $l)
    @php $amt = (float) $l->amount; @endphp
    <tr data-line="{{ $l->id }}" data-status="{{ $l->status }}" class="{{ $l->status === 'ignored' ? 'muted' : '' }}">
      @if($manage)<td>@if($l->status === 'unmatched')<input type="radio" name="line_id" value="{{ $l->id }}" form="matchForm" data-amount="{{ number_format($amt, 2, '.', '') }}" class="lineRadio" aria-label="Select line">@endif</td>@endif
      <td class="small nowrap">{{ $l->date->format('d M') }}</td>
      <td class="small">{{ $l->description ?: '—' }}@if($l->reference)<div class="tiny mono muted">{{ $l->reference }}</div>@endif
        @if($l->note)<div class="tiny text-red">{{ $l->note }}</div>@endif
        @if($l->status === 'matched')<div class="tiny">@foreach($l->glLines as $g)@php $url = $g->journal->sourceUrl(); @endphp<span class="muted">↳</span> {{ $g->date->format('d M') }} @if($url)<a href="{{ $url }}">{{ $g->journal->journal_no }}</a>@else{{ $g->journal->journal_no }}@endif <span class="muted">{{ \Illuminate\Support\Str::limit($g->journal->narration, 50) }}</span> {{ money((float) $g->debit - (float) $g->credit, false) }}<br>@endforeach</div>@endif
        @if($l->status === 'ignored')<div class="tiny">Ignored: {{ $l->ignore_reason }}</div>@endif
      </td>
      <td class="num">{{ $amt > 0 ? money($amt, false) : '' }}</td><td class="num">{{ $amt < 0 ? money(-$amt, false) : '' }}</td>
      <td class="small">{!! status_badge($l->status) !!}@if($l->match_type)<div class="tiny muted">{{ $typeLbl[$l->match_type] ?? $l->match_type }}{{ $l->entry_kind ? ' · '.($kinds[$l->entry_kind] ?? '') : '' }}</div>@endif</td>
      <td class="right nowrap">@if($manage)
        @if($l->status === 'unmatched')
          <button type="button" class="btn btn-sm btn-ghost" data-modal="entryModal" data-action="{{ route('bankrec.lines.entry', $l) }}" data-title="Book {{ money($amt) }} · {{ $l->date->format('d M') }} {{ \Illuminate\Support\Str::limit($l->description, 40) }}" title="Book charges / interest / fees" data-entry="{{ $l->id }}"><x-icon name="book" size="sm"/></button>
          <button type="button" class="btn btn-sm btn-ghost" data-modal="ignoreModal" data-action="{{ route('bankrec.lines.ignore', $l) }}" title="Ignore" data-ignore="{{ $l->id }}"><x-icon name="minus" size="sm"/></button>
          <form method="post" action="{{ route('bankrec.lines.delete', $l) }}" class="inline" data-confirm="Delete this statement line?">@csrf @method('DELETE')<button class="btn btn-sm btn-ghost" title="Delete line"><x-icon name="trash" size="sm"/></button></form>
        @elseif($l->status === 'matched')
          <form method="post" action="{{ route('bankrec.lines.unmatch', $l) }}" class="inline" @if($l->entry_kind) data-confirm="Unmatch and reverse the booked entry?" @endif>@csrf<button class="btn btn-sm btn-ghost" title="Unmatch" data-unmatch="{{ $l->id }}"><x-icon name="x" size="sm"/></button></form>
        @else
          <form method="post" action="{{ route('bankrec.lines.unignore', $l) }}" class="inline">@csrf<button class="btn btn-sm btn-ghost" title="Back to unmatched"><x-icon name="refresh" size="sm"/></button></form>
        @endif
      @endif</td>
    </tr>
    @empty<tr><td colspan="7"><x-empty text="{{ $lines->isEmpty() ? 'No lines yet – add them below.' : 'Nothing here.' }}" icon="book"/></td></tr>@endforelse
    </tbody>
    <tfoot><tr>@if($manage)<th></th>@endif<th colspan="2">{{ $c['total'] }} line(s)</th><th class="num">{{ money($lines->where('amount', '>', 0)->sum('amount'), false) }}</th><th class="num">{{ money(-$lines->where('amount', '<', 0)->sum('amount'), false) }}</th><th colspan="2"></th></tr></tfoot>
  </table></div>
  @if($manage)
  <div class="card-body" style="border-top:1px solid var(--c-border, #e2e8f0)">
    <form method="post" action="{{ route('bankrec.lines.add', $s) }}" class="flex gap-1 wrap" id="addLineForm" style="align-items:flex-end">@csrf
      <div class="field" style="margin:0"><label for="f_date">Date</label><input type="date" name="date" id="f_date" required min="{{ $s->period_from->toDateString() }}" max="{{ $s->period_to->toDateString() }}" value="{{ old('date', $s->period_to->toDateString()) }}"></div>
      <div class="field" style="margin:0;flex:1"><label for="f_description">Description</label><input name="description" id="f_description" maxlength="255" value="{{ old('description') }}"></div>
      <div class="field" style="margin:0"><label for="f_reference">Reference</label><input name="reference" id="f_reference" maxlength="80" value="{{ old('reference') }}"></div>
      <div class="field" style="margin:0"><label for="f_amount">Amount (+ in / − out)</label><input type="number" step="0.01" name="amount" id="f_amount" required value="{{ old('amount') }}"></div>
      <button class="btn" id="addLineBtn"><x-icon name="plus"/> Add line</button>
    </form>
    <form method="post" action="{{ route('bankrec.closing', $s) }}" class="flex gap-1 mt-1" style="align-items:flex-end" id="closingForm">@csrf
      <div class="field" style="margin:0"><label for="f_closing_balance">Closing balance per the statement</label><input type="number" step="0.01" name="closing_balance" id="f_closing_balance" value="{{ number_format((float) $s->closing_balance, 2, '.', '') }}" required></div>
      <button class="btn btn-sm">Update</button>
    </form>
  </div>
  @endif
</div>

<div>
  @if($manage)
  <div class="card flush" id="openBook">
    <div class="card-head"><h3>Book entries not yet cleared</h3><span class="tiny muted">{{ $open->count() }}</span></div>
    <form method="post" action="{{ route('bankrec.match', $s) }}" id="matchForm">@csrf
    <div class="card-body table-wrap" style="max-height:520px;overflow:auto"><table class="table" id="openTable">
      <thead><tr><th></th><th>Date</th><th>Entry</th><th class="num">Amount</th></tr></thead>
      <tbody>
      @forelse($open as $g)
      @php $v = round((float) $g->debit - (float) $g->credit, 2); @endphp
      <tr class="{{ $g->date->gt($s->period_to) ? 'muted' : '' }}"><td><input type="checkbox" name="gl[]" value="{{ $g->id }}" data-amount="{{ number_format($v, 2, '.', '') }}" class="glBox" aria-label="Select entry"></td>
        <td class="small nowrap">{{ $g->date->format('d M') }}</td>
        <td class="small">{{ \Illuminate\Support\Str::limit($g->journal->narration, 42) }}<div class="tiny muted">{{ $g->journal->journal_no }} · {{ \App\Models\GlJournal::SOURCES[$g->journal->source_type] ?? $g->journal->source_type }}{{ $g->journal->reference ? ' · '.$g->journal->reference : '' }}</div></td>
        <td class="num {{ $v < 0 ? 'text-red' : '' }}">{{ money($v, false) }}</td></tr>
      @empty<tr><td colspan="4"><x-empty text="Every book entry is cleared." icon="check"/></td></tr>@endforelse
      </tbody></table></div>
    <div class="card-body flex gap-1" style="align-items:center;justify-content:space-between">
      <span class="small">Line <b id="selLine">—</b> · ticked <b id="selSum">0.00</b></span>
      <button class="btn btn-primary btn-sm" id="matchBtn" disabled><x-icon name="link"/> Match</button>
    </div>
    </form>
  </div>
  <p class="tiny muted">Pick a statement line on the left, tick the book entries that make it up (a day's card or MoMo takings can clear one settlement), then Match. Greyed entries are dated after the statement.</p>
  @endif

  <div class="card mt-1" id="brsCard"><div class="card-head"><h3><x-icon name="receipt"/> Reconciliation at {{ $s->period_to->format('d M Y') }}</h3></div>
  <div class="card-body"><table class="table" id="brsTable" style="font-size:13px">
    <tr><td>Balance per statement</td><td class="num bold">{{ money($sum['closing'], false) }}</td></tr>
    <tr><td>Add: deposits not yet on the statement</td><td class="num">{{ money($sum['uncleared_in'], false) }}</td></tr>
    <tr><td>Less: payments not yet on the statement</td><td class="num">({{ money(-$sum['uncleared_out'], false) }})</td></tr>
    <tr><td>Adjust: statement items not in the books</td><td class="num">{{ money(-$sum['bank_only'], false) }}</td></tr>
    <tr style="border-top:2px solid #cbd5e1"><td><b>Adjusted balance</b></td><td class="num bold">{{ money($sum['adjusted'], false) }}</td></tr>
    @if(abs($sum['opening_diff']) >= 0.005)<tr><td title="Statement opening balance less the book balance the day before the first statement – usually items that were already outstanding">Adjust: opening difference b/f at {{ \Illuminate\Support\Carbon::parse($sum['start'])->format('d M Y') }}</td><td class="num">{{ money(-$sum['opening_diff'], false) }}</td></tr>@endif
    <tr><td>Balance per books ({{ $s->account->code }})</td><td class="num bold">{{ money($sum['book'], false) }}</td></tr>
    <tr class="{{ abs($sum['difference']) < 0.005 ? 'text-green' : 'text-red' }}"><td><b>Difference</b></td><td class="num bold" id="brsDiff">{{ money($sum['difference'], false) }}</td></tr>
  </table>
  <p class="tiny muted">{{ $sum['U']->count() }} book item(s) outstanding · {{ $sum['S']->count() }} statement item(s) not in the books (incl. ignored).</p></div></div>
</div>
</div>

@if($manage)
<div class="modal" id="entryModal"><div class="modal-box"><form method="post" action="#">@csrf
  <div class="modal-head"><h3 data-title>Book from the statement</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">
    <div class="form-grid">
      <x-field name="kind" label="What is it?" type="select" required :options="$kinds" :placeholder="false"/>
      <x-field name="account_head_id" label="Account (for “Other”)" type="select" :options="$heads" placeholder="— choose —"/>
      <x-field name="memo" label="Memo" maxlength="150" class="span-2"/>
    </div>
    <p class="tiny muted">Posts a journal on the statement date: {{ $s->account->name }} against the chosen account, and matches this line to it. Unmatching reverses it.</p>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="entrySave">Book &amp; match</button></div>
</form></div></div>
<div class="modal" id="ignoreModal"><div class="modal-box"><form method="post" action="#">@csrf
  <div class="modal-head"><h3>Ignore statement line</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="reason" label="Why is it not in the books?" required maxlength="255" placeholder="e.g. bank error – reversed by the bank on 2 Oct"/>
    <p class="tiny muted">Ignored lines stay on the reconciliation as statement items not in the books.</p></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="ignoreSave">Ignore</button></div>
</form></div></div>
@endif
@if(! $draft && $isLatest && can_do('bankrec.approve'))
<div class="modal" id="reopenModal"><div class="modal-box"><form method="post" action="{{ route('bankrec.reopen', $s) }}">@csrf
  <div class="modal-head"><h3>Reopen {{ $s->statement_no }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="reason" label="Reason" required maxlength="255"/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="reopenSave">Reopen</button></div>
</form></div></div>
@endif
@if($manage)
<script>
(function () {
  const btn = document.getElementById('matchBtn'), sl = document.getElementById('selLine'), ss = document.getElementById('selSum');
  const fmt = v => v.toLocaleString('en-GB', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  function upd() {
    const r = document.querySelector('.lineRadio:checked');
    const sum = [...document.querySelectorAll('.glBox:checked')].reduce((a, b) => a + parseFloat(b.dataset.amount), 0);
    sl.textContent = r ? fmt(parseFloat(r.dataset.amount)) : '—';
    ss.textContent = fmt(sum);
    const ok = r && Math.abs(sum - parseFloat(r.dataset.amount)) < 0.005 && document.querySelectorAll('.glBox:checked').length;
    btn.disabled = !ok;
    ss.className = r && !ok && sum !== 0 ? 'text-red' : (ok ? 'text-green' : '');
  }
  document.querySelectorAll('.lineRadio, .glBox').forEach(e => e.addEventListener('change', upd));
  upd();
})();
</script>
@endif
@endsection
