@extends('layouts.app')
@section('title', 'Journal '.$j->journal_no)
@section('actions')
@if($url = $j->sourceUrl())<a class="btn" href="{{ $url }}"><x-icon name="link"/> Source document</a>@endif
@if($j->is_manual && can_do('gl.journal'))<a class="btn" href="{{ route('gl.journals.create', ['copy' => $j->id]) }}"><x-icon name="layers"/> Copy</a>@endif
@if($unallocated && can_do('gl.journal'))<button class="btn" data-modal="allocModal" id="allocBtn"><x-icon name="users"/> Allocate cost centres</button>@endif
@if($j->is_manual && ! $j->reversed_by_id && ! $j->reversal_of_id && can_do('gl.journal'))<button class="btn btn-danger" data-modal="revModal"><x-icon name="swap"/> Reverse</button>@endif
<button class="btn" onclick="window.print()"><x-icon name="print"/> Print</button>
@endsection
@section('content')
@if($j->reversed_by_id)<div class="alert alert-warn">Reversed by <a href="{{ route('gl.journals.show', $j->reversed_by_id) }}">{{ $j->reversedBy?->journal_no }}</a> on {{ $j->reversedBy?->date->format('d M Y') }}.</div>@endif
@if($j->reversal_of_id)<div class="alert alert-info">This journal reverses <a href="{{ route('gl.journals.show', $j->reversal_of_id) }}">{{ $j->reversalOf?->journal_no }}</a>.</div>@endif
<div class="card"><div class="card-body form-grid">
<div><div class="muted small">Date</div><div class="bold">{{ $j->date->format('d M Y') }}</div></div>
<div><div class="muted small">Source</div><div class="bold">{{ \App\Models\GlJournal::SOURCES[$j->source_type] ?? $j->source_type }}</div></div>
<div><div class="muted small">Reference</div><div class="bold">{{ $j->reference ?: '—' }}</div></div>
<div><div class="muted small">Cost centre</div><div class="bold">{{ $j->department?->label() ?? 'Mixed or none' }}</div></div>
<div><div class="muted small">Posted by</div><div class="bold">{{ $j->creator?->name ?? 'System' }} · {{ $j->created_at->format('d M Y H:i') }}</div></div>
<div class="span-all"><div class="muted small">Narration</div><div>{{ $j->narration }}</div></div>
</div></div>
@if($unallocated)
<div class="alert alert-warn" id="ccAlert"><x-icon name="alert"/><div>{{ $unallocated }} expense line(s) on this journal name no <b>cost centre</b>, so they are not in any department's figures.
@if(can_do('gl.journal')) Allocate them below – the money does not move and the journal is not re-posted.@endif</div></div>
@endif
<div class="card flush"><div class="card-body table-wrap"><table class="table" id="jv-lines">
<thead><tr><th>Account</th><th>Cost centre</th><th>Memo</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead><tbody>
@foreach($j->lines as $l)<tr data-line="{{ $l->id }}"><td><a href="{{ route('gl.ledger', [$l->account_head_id, 'from' => $j->date->copy()->startOfMonth()->toDateString(), 'to' => $j->date->toDateString()]) }}">{{ $l->account->label }}</a></td>
<td class="small {{ $l->department_id ? '' : 'muted' }}">{{ $l->department?->label() ?? '—' }}</td><td class="small muted">{{ $l->memo }}</td>
<td class="num">{{ (float) $l->debit ? money($l->debit, false) : '' }}</td><td class="num">{{ (float) $l->credit ? money($l->credit, false) : '' }}</td></tr>@endforeach
</tbody><tfoot><tr><td colspan="3">Total</td><td class="num">{{ money($j->lines->sum('debit'), false) }}</td><td class="num">{{ money($j->lines->sum('credit'), false) }}</td></tr></tfoot></table></div></div>
@if(! $j->is_manual)<p class="small muted">Automatic journals are corrected by amending the source document; the next sync reverses this journal and posts the corrected one.</p>@endif
@if($unallocated && can_do('gl.journal'))
<div class="modal" id="allocModal"><div class="modal-box"><form method="post" action="{{ route('gl.journals.allocate', $j) }}">@csrf
<div class="modal-head"><h3>Cost centres on {{ $j->journal_no }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
<div class="modal-body">
  <p class="small muted">Set the department each line belongs to. Blank leaves it unallocated. The amounts, the date and the hash of the journal do not change.</p>
  <div class="table-wrap"><table class="table table-compact"><thead><tr><th>Account</th><th>Memo</th><th class="num">Amount</th><th>Cost centre</th></tr></thead><tbody>
  @foreach($j->lines as $l)<tr><td class="small">{{ $l->account?->label }}</td><td class="small muted">{{ $l->memo }}</td>
    <td class="num">{{ money((float) $l->debit ?: (float) $l->credit, false) }}</td>
    <td><select class="input input-sm" name="d[{{ $l->id }}]"><option value="">— unallocated —</option>@foreach($departments as $id => $nm)<option value="{{ $id }}" @selected($l->department_id == $id)>{{ $nm }}</option>@endforeach</select></td></tr>@endforeach
  </tbody></table></div>
  <div class="field mt-2"><label>Why</label><input name="why" maxlength="120" class="input" placeholder="e.g. stores issue 14/10 – kitchen"></div>
</div>
<div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary"><x-icon name="check"/> Save cost centres</button></div></form></div></div>
@endif
@if($j->is_manual && ! $j->reversed_by_id && ! $j->reversal_of_id && can_do('gl.journal'))
<div class="modal" id="revModal"><div class="modal-box"><form method="post" action="{{ route('gl.journals.reverse', $j) }}">@csrf
<div class="modal-head"><h3>Reverse {{ $j->journal_no }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
<div class="modal-body form-grid">
<div class="field"><label>Reversal date <span class="req">*</span></label><input type="date" name="date" value="{{ max($minDate, $j->date->toDateString(), bdate()->copy()->subDay()->toDateString()) }}" min="{{ max($minDate, $j->date->toDateString()) }}" max="{{ bdate()->toDateString() }}" required></div>
<div class="field span-all"><label>Reason <span class="req">*</span></label><input name="reason" maxlength="120" required></div>
</div>
<div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-danger">Post reversal</button></div></form></div></div>
@endif
@endsection
