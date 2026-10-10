@extends('layouts.app')
@section('title', 'Recurring Journals')
@section('crumbs') <a href="{{ route('close.index') }}">Period close</a> / Recurring journals @endsection
@php
  $R = \App\Models\RecurringJournal::class; $prep = can_do('close.prepare'); $appr = can_do('close.approve'); $me = auth()->id();
  $old = old('lines', []);
@endphp
@section('actions')
  @if($prep)<a class="btn btn-primary" href="#rjForm" id="newRjBtn"><x-icon name="plus"/> New template</a>@endif
@endsection
@section('content')
<div class="card flush"><div class="card-body table-wrap"><table class="table" id="rjTable">
  <thead><tr><th>Code</th><th>Template</th><th>Runs</th><th class="num">Amount</th><th>Last / next</th><th>Status</th><th></th></tr></thead>
  <tbody>@forelse($rows as $r) @php $next = in_array($r->status, ['active', 'pending']) ? $r->nextDue($r->last_period ? \App\Services\CloseService::next($r->last_period) : $r->start_period) : null; @endphp
    <tr data-rj="{{ $r->code }}" data-status="{{ $r->status }}" class="{{ $open === $r->id ? 'row-hl' : '' }}">
      <td class="bold">{{ $r->code }}</td>
      <td class="small"><details @if($open === $r->id) open @endif><summary><b>{{ $r->name }}</b></summary>
        <table class="table" style="margin:.4rem 0;font-size:.78rem"><tbody>@foreach($r->lines as $l)<tr><td>{{ $codes[$l[0]] ?? '' }} {{ $names[$l[0]] ?? '#'.$l[0] }}</td><td class="num">{{ (float) $l[1] ? money($l[1], false) : '' }}</td><td class="num">{{ (float) $l[2] ? money($l[2], false) : '' }}</td><td class="muted">{{ $l[3] ?? '' }}</td></tr>@endforeach</tbody></table>
        @if($r->postings->count())<div class="tiny">Posted: @foreach($r->postings->where('kind', 'post')->take(6) as $pp)<a href="{{ route('gl.journals.show', $pp->gl_journal_id ?? 0) }}">{{ $pp->journal?->journal_no }}</a> ({{ \Illuminate\Support\Carbon::parse($pp->period.'-01')->format('M y') }})@if(! $loop->last), @endif @endforeach</div>@endif
      </details></td>
      <td class="small">{{ $R::FREQUENCIES[$r->frequency] }}@if($r->auto_reverse)<div class="tiny muted">auto-reverses on the 1st</div>@endif<div class="tiny muted">from {{ \Illuminate\Support\Carbon::parse($r->start_period.'-01')->format('M Y') }}@if($r->end_period) to {{ \Illuminate\Support\Carbon::parse($r->end_period.'-01')->format('M Y') }}@endif</div></td>
      <td class="num">{{ money($r->total, false) }}</td>
      <td class="small">{{ $r->last_period ? \Illuminate\Support\Carbon::parse($r->last_period.'-01')->format('M Y') : '—' }}<div class="tiny muted">next {{ $next ? \Illuminate\Support\Carbon::parse($next.'-01')->format('M Y') : '—' }}</div></td>
      <td><span class="badge badge-{{ $R::TONES[$r->status] }}">{{ $R::STATUSES[$r->status] }}</span><div class="tiny muted">{{ $r->creator?->name }}@if($r->approver) → {{ $r->approver->name }}@endif</div>@if($r->decision_note)<div class="tiny">{{ $r->decision_note }}</div>@endif</td>
      <td class="nowrap right">
        @if($r->status === 'pending' && $appr && (int) $r->created_by !== (int) $me)
          <form method="post" action="{{ route('close.recurring.decide', $r) }}" style="display:inline" class="rj-decide">@csrf<input type="hidden" name="note" value=""><button class="btn btn-sm" name="decision" value="reject" onclick="const n=prompt('Reason for rejecting?'); if(!n) return false; this.form.note.value=n;">Reject</button><button class="btn btn-sm btn-primary" name="decision" value="approve">Approve</button></form>
        @elseif($r->status === 'pending')<span class="tiny muted">awaiting a second approver</span>@endif
        @if($appr && $r->status === 'active')<form method="post" action="{{ route('close.recurring.status', $r) }}" style="display:inline">@csrf<button class="btn btn-sm" name="status" value="paused">Pause</button></form>@endif
        @if($appr && $r->status === 'paused')<form method="post" action="{{ route('close.recurring.status', $r) }}" style="display:inline">@csrf<button class="btn btn-sm" name="status" value="active">Resume</button></form>@endif
        @if($appr && in_array($r->status, ['active', 'paused']))<form method="post" action="{{ route('close.recurring.status', $r) }}" style="display:inline" data-confirm="End {{ $r->code }}? It will not post again.">@csrf<button class="btn btn-sm btn-ghost" name="status" value="ended">End</button></form>@endif
      </td>
    </tr>
  @empty <tr><td colspan="7"><x-empty text="No recurring journals yet – e.g. monthly rent to the landlord, head-office management fee, or a standing accrual." icon="refresh"/></td></tr> @endforelse</tbody>
</table></div></div>

@if($prep)
<div class="card mt-1" id="rjForm"><div class="card-head"><h3><x-icon name="plus"/> New recurring journal</h3><span class="tiny muted">posted at the end of every due period · needs a second approver</span></div><div class="card-body">
<form method="post" action="{{ route('close.recurring.store') }}">@csrf
  <div class="form-grid">
    <div class="field span-2"><label for="rjName">Name <span class="req">*</span></label><input name="name" id="rjName" maxlength="120" required value="{{ old('name') }}" placeholder="e.g. Head-office management fee (2% of budgeted revenue)"></div>
    <div class="field"><label for="rjFreq">Frequency</label><select name="frequency" id="rjFreq">@foreach($R::FREQUENCIES as $k => $v)<option value="{{ $k }}" @selected(old('frequency', 'monthly') === $k)>{{ $v }}</option>@endforeach</select></div>
    <div class="field"><label for="rjStart">First period <span class="req">*</span></label><input type="month" name="start_period" id="rjStart" min="{{ $minStart }}" required value="{{ old('start_period', $curPeriod) }}"></div>
    <div class="field"><label for="rjEnd">Last period</label><input type="month" name="end_period" id="rjEnd" value="{{ old('end_period') }}"></div>
    <div class="field span-2" style="align-self:end"><label class="check"><input type="checkbox" name="auto_reverse" value="1" id="rjRev" @checked(old('auto_reverse'))> <span>Reverse automatically on the 1st of the next month (standing accrual)</span></label></div>
  </div>
  <table class="table mt-1" id="rjLines"><thead><tr><th style="width:44%">Account</th><th class="num">Debit</th><th class="num">Credit</th><th>Memo</th></tr></thead><tbody>
    @for($i = 0; $i < 4; $i++)<tr>
      <td><select name="lines[{{ $i }}][account_head_id]" id="rjAcc{{ $i }}"><option value="">—</option>@foreach($accounts as $h)<option value="{{ $h->id }}" @selected((int) ($old[$i]['account_head_id'] ?? 0) === $h->id)>{{ $h->code }} · {{ $h->name }}</option>@endforeach</select></td>
      <td><input type="number" step="0.01" min="0" name="lines[{{ $i }}][debit]" id="rjDr{{ $i }}" value="{{ $old[$i]['debit'] ?? '' }}" style="text-align:right"></td>
      <td><input type="number" step="0.01" min="0" name="lines[{{ $i }}][credit]" id="rjCr{{ $i }}" value="{{ $old[$i]['credit'] ?? '' }}" style="text-align:right"></td>
      <td><input name="lines[{{ $i }}][memo]" maxlength="150" value="{{ $old[$i]['memo'] ?? '' }}"></td></tr>@endfor
  </tbody><tfoot><tr><td class="right small muted">Totals</td><td class="num bold" id="rjTotDr">0.00</td><td class="num bold" id="rjTotCr">0.00</td><td class="small" id="rjDiff"></td></tr></tfoot></table>
  <div class="right mt-1"><button class="btn btn-primary" id="rjSave">Save for approval</button></div>
</form></div></div>
<script>
(function () {
  const f = document.getElementById('rjLines'); if (!f) return;
  const sum = () => { let d = 0, c = 0; f.querySelectorAll('input[name$="[debit]"]').forEach(i => d += +i.value || 0); f.querySelectorAll('input[name$="[credit]"]').forEach(i => c += +i.value || 0);
    document.getElementById('rjTotDr').textContent = d.toFixed(2); document.getElementById('rjTotCr').textContent = c.toFixed(2);
    const df = Math.round((d - c) * 100) / 100; const el = document.getElementById('rjDiff'); el.textContent = df ? 'out of balance by ' + df.toFixed(2) : (d ? 'balanced' : ''); el.className = 'small ' + (df ? 'text-red' : 'text-green'); };
  f.addEventListener('input', sum); sum();
})();
</script>
@endif
@endsection
