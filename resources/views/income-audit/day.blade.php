@extends('layouts.app')
@section('title', 'Income audit – '.$day->label())
@section('crumbs') <a href="{{ route('audit.index') }}">Income Audit</a> / {{ dmy($day->business_date) }} @endsection
@php
  $tone = ['open' => 'amber', 'signed' => 'blue', 'closed' => 'green'];
  $sevTone = ['high' => 'red', 'medium' => 'amber', 'low' => 'slate'];
  $stTone = ['open' => 'red', 'escalated' => 'purple', 'explained' => 'blue', 'corrected' => 'green', 'accepted' => 'slate', 'cleared' => 'green'];
  $live = $all->where('status', '!=', 'cleared');
  $unres = $all->whereIn('status', \App\Models\AuditFinding::UNRESOLVED);
  $canAudit = can_do('income_audit.audit') || can_do('income_audit.close');
  $names = ['room' => 'Room revenue', 'other' => 'Other revenue (incl. POS)', 'tax' => 'Taxes & levies', 'collections' => 'Collections'];
@endphp
@section('actions')
  @if($prev)<a class="btn btn-ghost" href="{{ route('audit.day', $prev) }}" title="{{ dmy($prev->business_date) }}">‹ Prev</a>@endif
  @if($next)<a class="btn btn-ghost" href="{{ route('audit.day', $next) }}" title="{{ dmy($next->business_date) }}">Next ›</a>@endif
  <a class="btn" href="{{ route('audit.print', $day) }}" target="_blank"><x-icon name="print"/> Audit report</a>
  @if($day->status === 'open' && can_do('income_audit.audit'))
    <form method="post" action="{{ route('audit.run', $day) }}" style="display:inline">@csrf<button class="btn" id="rerunBtn"><x-icon name="refresh"/> Re-run checks</button></form>
    <button class="btn btn-primary" data-modal="signModal" id="signBtn" @disabled($unres->count() > 0) title="{{ $unres->count() ? 'Deal with every open / escalated exception first' : 'Sign as income auditor' }}"><x-icon name="check"/> Sign day</button>
  @endif
  @if($day->status === 'signed' && can_do('income_audit.close'))<button class="btn btn-primary" data-modal="closeModal" id="closeBtn"><x-icon name="lock"/> Close day</button>@endif
  @if($day->status !== 'open' && can_do('income_audit.close'))<button class="btn" data-modal="reopenModal" id="reopenBtn">Reopen</button>@endif
@endsection
@section('content')
<div class="mb-1"><span class="badge badge-{{ $tone[$day->status] }}" id="dayStatus">{{ \App\Models\AuditDay::STATUSES[$day->status] }}</span>
  <span class="small muted">· checks run {{ $day->checked_at ? $day->checked_at->format('d M Y H:i') : 'never' }}</span>
  @if($day->signed_at)<span class="small"> · signed by <b>{{ $day->signer?->name }}</b> {{ $day->signed_at->format('d M H:i') }}</span>@endif
  @if($day->closed_at)<span class="small"> · closed by <b>{{ $day->closer?->name }}</b> {{ $day->closed_at->format('d M H:i') }}</span>@endif
</div>
@if($day->status === 'closed')<div class="alert alert-info"><x-icon name="lock"/> This day is closed. Postings dated {{ dmy($day->business_date) }} are locked – corrections must be posted on today's business date.@if($day->close_note) Controller: “{{ $day->close_note }}”@endif</div>@endif
@if($day->reopened_at && $day->status === 'open')<div class="alert alert-warn"><x-icon name="alert"/> Reopened by {{ $day->reopener?->name }} on {{ $day->reopened_at->format('d M H:i') }} – {{ $day->reopen_reason }}</div>@endif

<div class="stats">
  <x-stat label="Exceptions" :value="$live->count()" icon="search" tone="blue" :sub="$all->where('status', 'cleared')->count().' cleared by re-check'"/>
  <x-stat label="Still open" :value="$unres->count()" icon="alert" :tone="$unres->count() ? 'red' : 'green'" :sub="$unres->where('severity', 'high')->count().' high · '.$all->where('status', 'escalated')->count().' escalated'"/>
  <x-stat label="Value at issue (open)" :value="money($unres->sum('amount'))" icon="money" tone="amber" :sub="money($live->sum('amount')).' across all exceptions'"/>
  <x-stat label="Pre-authorised" :value="$all->whereNotNull('revenue_authorisation_id')->count()" icon="check" tone="purple" sub="accepted automatically"/>
</div>

<div class="card flush mt-1" id="recon"><div class="card-head"><h3><x-icon name="chart"/> Revenue reconciliation</h3><span class="small muted">baseline taken {{ isset($figures['baseline_at']) ? dmy($figures['baseline_at'], 'd M H:i') : '—' }}</span></div><div class="card-body table-wrap"><table class="table">
  <thead><tr><th></th><th class="num">Night-audit report</th><th class="num">Baseline (day closed)</th><th class="num">Now</th><th class="num">Change since close</th></tr></thead>
  <tbody>@foreach($names as $k => $label) @php $b = $figures['baseline'][$k] ?? null; $n = $figures['now'][$k] ?? 0; $chg = $b !== null ? round($n - $b, 2) : null; @endphp
    <tr><td class="bold">{{ $label }}</td><td class="num">{{ isset($figures['reported'][$k]) ? money($figures['reported'][$k], false) : '—' }}</td><td class="num">{{ $b !== null ? money($b, false) : '—' }}</td><td class="num">{{ money($n, false) }}</td>
      <td class="num {{ $chg && abs($chg) > 0.009 ? 'bold text-red' : 'muted' }}">{{ $chg === null ? '—' : (abs($chg) > 0.009 ? ($chg > 0 ? '+' : '').money($chg, false) : 'nil') }}</td></tr>@endforeach
    <tr><td>Discounts &amp; allowances</td><td></td><td></td><td class="num">{{ money($figures['discounts'] ?? 0, false) }}</td><td></td></tr>
    <tr><td>Income posted to the general ledger</td><td></td><td></td><td class="num">{{ money($figures['gl_income'] ?? 0, false) }}</td><td class="small muted">for information – GL is posted after the day closes</td></tr>
    @if(! empty($figures['occupied']))<tr><td>Rooms occupied</td><td class="num">{{ $figures['occupied'] }} / {{ $figures['rooms'] }}</td><td colspan="3" class="small muted">room count: {{ $counts->count() ? $counts->count().' rooms counted, '.$counts->where('result', '!=', 'ok')->count().' discrepancy(ies)' : 'not done' }}</td></tr>@endif
  </tbody></table></div></div>

<form class="filters mt-1" method="get" id="findingFilter">
  <div class="field"><label>Show</label><select name="show" onchange="this.form.submit()">
    <option value="attention" @selected(request('show', 'attention') === 'attention')>Needs attention ({{ $unres->count() }})</option><option value="all" @selected(request('show') === 'all')>All ({{ $all->count() }})</option>
    @foreach(\App\Models\AuditFinding::STATUSES as $k => $v)@if(! in_array($k, ['open', 'escalated']))<option value="{{ $k }}" @selected(request('show') === $k)>{{ $v }} ({{ $all->where('status', $k)->count() }})</option>@endif @endforeach</select></div>
  <div class="field"><label>Check</label><select name="check" onchange="this.form.submit()"><option value="">All checks</option>@foreach($checks as $k => $c)@if($all->where('check', $k)->count())<option value="{{ $k }}" @selected(request('check') === $k)>{{ $c[0] }} ({{ $all->where('check', $k)->count() }})</option>@endif @endforeach</select></div>
</form>

<div class="card flush" id="findings"><div class="card-head"><h3><x-icon name="alert"/> Exceptions</h3></div><div class="card-body table-wrap"><table class="table">
  <thead><tr><th>Severity</th><th>Check</th><th>Room</th><th>Reference</th><th>What was found</th><th class="num">Amount</th><th>Status / resolution</th><th></th></tr></thead>
  <tbody>@forelse($findings as $f) @php $link = $f->link(); @endphp
    <tr data-finding="{{ $f->check }}" data-status="{{ $f->status }}" class="{{ $f->status === 'cleared' ? 'muted' : '' }}">
      <td><span class="badge badge-{{ $sevTone[$f->severity] }}">{{ $f->severity }}</span></td>
      <td class="small bold">{{ $checks[$f->check][0] ?? $f->check }}</td>
      <td class="small">{{ $f->room_no }}</td>
      <td class="small">@if($link)<a href="{{ $link }}">{{ $f->ref_label }}</a>@else{{ $f->ref_label }}@endif</td>
      <td class="small" style="max-width:26rem">{{ $f->detail }}</td>
      <td class="num">{{ (float) $f->amount ? money($f->amount, false) : '' }}</td>
      <td class="small"><span class="badge badge-{{ $stTone[$f->status] }}">{{ \App\Models\AuditFinding::STATUSES[$f->status] }}</span>
        @if($f->resolution)<div class="tiny">{{ $f->resolution }}</div>@endif
        @if($f->resolver)<div class="tiny muted">{{ $f->resolver->name }} · {{ $f->resolved_at?->format('d M H:i') }}</div>@endif</td>
      <td class="num">@if($day->status === 'open' && $canAudit && $f->status !== 'cleared')<button class="btn btn-sm" data-modal="resolveModal" data-title="{{ $checks[$f->check][0] ?? '' }} – {{ $f->room_no ? 'Room '.$f->room_no : $f->ref_label }}" data-action="{{ route('audit.resolve', $f) }}" data-f-status="{{ $f->status === 'open' ? 'explained' : $f->status }}" data-f-resolution="{{ $f->status === 'open' ? '' : $f->resolution }}">Resolve</button>@endif</td></tr>
  @empty <tr><td colspan="8"><x-empty :text="$all->count() ? 'Nothing needs attention – every exception is dealt with.' : ($day->checked_at ? 'The checks found nothing.' : 'Checks have not run yet.')" icon="check"/></td></tr> @endforelse</tbody>
</table></div></div>

@if($day->sign_note || $day->close_note)<div class="card mt-1"><div class="card-body small">@if($day->sign_note)<p><b>Auditor:</b> {{ $day->sign_note }}</p>@endif @if($day->close_note)<p><b>Controller:</b> {{ $day->close_note }}</p>@endif</div></div>@endif

<div class="modal" id="resolveModal"><div class="modal-box"><form method="post" action="" data-reset>@csrf
  <div class="modal-head"><h3>Resolve exception</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field span-2"><label for="r_status">Outcome <span class="req">*</span></label><select name="status" id="r_status" required>
      <option value="explained">Explained – legitimate, reason recorded</option><option value="corrected">Corrected – put right (posting / allowance / rate fixed)</option>
      <option value="escalated">Escalated – to the financial controller / GM</option>@if(can_do('income_audit.close'))<option value="accepted">Accepted by controller</option>@endif<option value="open">Back to open</option></select></div>
    <div class="field span-2"><label for="r_res">What was found / done <span class="req">*</span></label><textarea name="resolution" id="r_res" rows="3" placeholder="e.g. Corporate rate agreed by the GM by e-mail 26/09 – authorisation AUT-00004 raised retrospectively"></textarea></div>
  </div><p class="tiny muted">Corrections (allowances, missed tariffs, rate changes) are posted on today's business date in the folio – then re-run the checks.</p></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="resolveSave">Save</button></div>
</form></div></div>

<div class="modal" id="signModal"><div class="modal-box"><form method="post" action="{{ route('audit.sign', $day) }}">@csrf
  <div class="modal-head"><h3>Sign {{ dmy($day->business_date) }} as income auditor</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><p class="small">The checks re-run before signing. You confirm the day's revenue, taxes and collections are complete and correct and every exception is explained, corrected or accepted.</p>
    <x-field name="note" label="Note to the controller (optional)" type="textarea" rows="3"/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="signSave">Sign</button></div>
</form></div></div>

<div class="modal" id="closeModal"><div class="modal-box"><form method="post" action="{{ route('audit.close', $day) }}">@csrf
  <div class="modal-head"><h3>Close {{ dmy($day->business_date) }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><p class="small">Signed by {{ $day->signer?->name }}. Closing locks the day: postings dated on it can no longer be voided.</p>
    <x-field name="note" label="Controller's note (optional)" type="textarea" rows="3"/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="closeSave">Close day</button></div>
</form></div></div>

<div class="modal" id="reopenModal"><div class="modal-box"><form method="post" action="{{ route('audit.reopen', $day) }}">@csrf
  <div class="modal-head"><h3>Reopen {{ dmy($day->business_date) }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="reason" label="Reason" required placeholder="e.g. Late supplier credit note to be reflected"/><p class="tiny muted">The sign-off is withdrawn; the auditor must re-run the checks and sign again.</p></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="reopenSave">Reopen</button></div>
</form></div></div>
@endsection
