@extends('layouts.app')
@section('title', 'Journal '.$j->journal_no)
@section('actions')
@if($url = $j->sourceUrl())<a class="btn" href="{{ $url }}"><x-icon name="link"/> Source document</a>@endif
@if($j->is_manual && can_do('gl.journal'))<a class="btn" href="{{ route('gl.journals.create', ['copy' => $j->id]) }}"><x-icon name="layers"/> Copy</a>@endif
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
<div><div class="muted small">Posted by</div><div class="bold">{{ $j->creator?->name ?? 'System' }} · {{ $j->created_at->format('d M Y H:i') }}</div></div>
<div class="span-all"><div class="muted small">Narration</div><div>{{ $j->narration }}</div></div>
</div></div>
<div class="card flush"><div class="card-body table-wrap"><table class="table" id="jv-lines">
<thead><tr><th>Account</th><th>Memo</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead><tbody>
@foreach($j->lines as $l)<tr><td><a href="{{ route('gl.ledger', [$l->account_head_id, 'from' => $j->date->copy()->startOfMonth()->toDateString(), 'to' => $j->date->toDateString()]) }}">{{ $l->account->label }}</a></td><td class="small muted">{{ $l->memo }}</td>
<td class="num">{{ (float) $l->debit ? money($l->debit, false) : '' }}</td><td class="num">{{ (float) $l->credit ? money($l->credit, false) : '' }}</td></tr>@endforeach
</tbody><tfoot><tr><td colspan="2">Total</td><td class="num">{{ money($j->lines->sum('debit'), false) }}</td><td class="num">{{ money($j->lines->sum('credit'), false) }}</td></tr></tfoot></table></div></div>
@if(! $j->is_manual)<p class="small muted">Automatic journals are corrected by amending the source document; the next sync reverses this journal and posts the corrected one.</p>@endif
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
