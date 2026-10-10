@extends('layouts.app')
@section('title', $d->code.' '.$d->title)
@section('crumbs') Learning & SOPs / SOP library / {{ $d->code }} @endsection
@php $author = can_do('learning.sop'); $train = can_do('learning.train'); $show = $v ?? $d->versions->first(); $viewingOld = $v && $v->version !== $d->version; @endphp
@section('actions')
  @if($d->version)<a class="btn" href="{{ route('learning.sop.print', ['sop' => $d, 'v' => $show?->version]) }}" target="_blank"><x-icon name="print"/> Print</a>@endif
  @if($d->status === 'published' && $d->requires_ack)<a class="btn" href="{{ route('learning.sop.sheet', $d) }}" target="_blank"><x-icon name="edit"/> Sign-off sheet</a>@endif
  @if($author && $d->status !== 'retired')<a class="btn btn-primary" href="{{ route('learning.sop.edit', $d) }}" id="editSopBtn"><x-icon name="edit"/> Edit</a>@endif
@endsection
@section('content')
<div class="stats tr-stats" id="sopStats">
  <x-stat :label="$d->categoryLabel().' · '.($d->department?->name ?? 'Hotel-wide')" :value="$d->code.($d->version ? ' v'.$d->version : '')" icon="book" tone="blue" :sub="$d->statusLabel().($d->effective_date ? ' · effective '.$d->effective_date->format('d M Y') : '')"/>
  <x-stat label="Next review" :value="$d->review_due?->format('d M Y') ?? '—'" icon="clock" :tone="$d->reviewOverdue() ? 'red' : 'purple'" :sub="'every '.$d->review_months.' months'.($d->last_reviewed ? ' · last '.$d->last_reviewed->format('d M Y') : '')"/>
  <x-stat label="Signed by staff" :value="$d->requires_ack ? ($ack['required'] ? $ack['signed'].' / '.$ack['required'] : '—') : 'not required'" icon="edit" :tone="$ack['required'] && $ack['signed'] < $ack['required'] ? 'amber' : 'green'" :sub="$ack['version'] ? 'must have signed v'.$ack['version'].' or later' : 'publish to collect signatures'"/>
  <x-stat label="Owner" :value="$d->owner?->name ?? '—'" icon="user" tone="slate" :sub="$courses->count() ? 'taught in '.$courses->pluck('code')->implode(', ') : 'no linked course'"/>
</div>
<div id="sopStatus" data-status="{{ $d->status }}" data-version="{{ $d->version }}" data-draft="{{ $d->has_draft ? 1 : 0 }}" hidden></div>

@if($author && $d->status !== 'retired')
<div class="card mt-1" id="sopActions"><div class="card-body flex gap-1 wrap" style="align-items:flex-end">
  @if($d->has_draft)
    <form method="post" action="{{ route('learning.sop.publish', $d) }}" class="flex gap-1 wrap" style="align-items:flex-end" id="publishForm">@csrf
      @if($d->version)<div class="field" style="margin:0"><label for="changeNote">What changed in v{{ $d->version + 1 }}?</label><input name="change_note" id="changeNote" maxlength="255" required style="min-width:18rem"></div>
        <label class="small"><input type="checkbox" name="reack" value="1" checked id="reack"> Staff must sign again</label>@endif
      <button class="btn btn-primary" id="publishBtn"><x-icon name="check"/> Publish v{{ $d->version + 1 }}</button></form>
    @if($d->version)<form method="post" action="{{ route('learning.sop.discard', $d) }}" data-confirm="Throw away the unpublished edits and go back to v{{ $d->version }}?">@csrf<button class="btn">Discard edits</button></form>@endif
  @endif
  @if($d->status === 'published' && ! $d->has_draft)<form method="post" action="{{ route('learning.sop.review', $d) }}" data-confirm="Confirm {{ $d->code }} v{{ $d->version }} is still correct? The next review moves to {{ bdate()->copy()->addMonthsNoOverflow($d->review_months)->format('d M Y') }}.">@csrf<button class="btn" id="reviewBtn"><x-icon name="check"/> Mark reviewed – still correct</button></form>@endif
  <button class="btn btn-ghost" data-modal="retireModal" style="margin-left:auto" id="retireBtn"><x-icon name="trash"/> Retire</button>
</div></div>
@endif
@if($d->has_draft && $d->version)<div class="alert alert-info mt-1"><x-icon name="edit"/> There are unpublished edits (draft of v{{ $d->version + 1 }}). Staff still see v{{ $d->version }} below.</div>@endif
@if($d->status === 'retired')<div class="alert alert-error mt-1"><x-icon name="alert"/> This SOP is retired and no longer in force.</div>@endif

<div class="g-2-1 mt-1">
<div class="card" id="sopBody"><div class="card-head"><h3>{{ $show?->title ?? $d->title }}</h3>@if($show)<span class="badge badge-{{ $viewingOld ? 'amber' : 'green' }}">v{{ $show->version }}{{ $viewingOld ? ' (superseded)' : '' }}</span>@endif</div>
  <div class="card-body sop-body">@if($d->summary)<p class="muted"><i>{{ $d->summary }}</i></p>@endif
    {!! \App\Models\SopDocument::render($show ? $show->body : $d->body) !!}
    @if(! $show)<p class="tiny muted">Draft – not yet published.</p>@endif
  </div></div>

<div>
<div class="card flush" id="versionsCard"><div class="card-head"><h3><x-icon name="layers"/> Versions</h3></div>
<div class="card-body">@forelse($d->versions as $x)
  <div class="between-row" data-version="{{ $x->version }}"><div><a href="{{ route('learning.sop', ['sop' => $d, 'v' => $x->version]) }}"><b>v{{ $x->version }}</b></a> · {{ $x->effective_date->format('d M Y') }}<div class="tiny muted">{{ $x->change_note }} · {{ $x->publisher?->name }}</div></div>
    <span class="badge badge-{{ $x->reack ? 'amber' : 'slate' }}" title="{{ $x->reack ? 'staff had to sign again' : 'minor change – signatures kept' }}">{{ $x->reack ? 're-sign' : 'minor' }}</span></div>
@empty<div class="muted small">Not published yet.</div>@endforelse</div></div>

@if($d->status === 'published' && $d->requires_ack)
<div class="card flush mt-1" id="ackCard"><div class="card-head"><h3><x-icon name="users"/> Sign-off (v{{ $ack['version'] }}+)</h3></div>
@if($train)<form method="post" action="{{ route('learning.sop.sheet.store', $d) }}" id="sheetForm">@csrf @endif
<div class="card-body table-wrap"><table class="table table-compact" id="ackTable">
  <tbody>@forelse($ack['rows']->sortBy(fn ($r) => [$r['ack'] ? 1 : 0, $r['employee']->name]) as $r)
    <tr data-employee="{{ $r['employee']->id }}" data-signed="{{ $r['ack'] ? 1 : 0 }}"><td>@if(! $r['ack'] && $train)<input type="checkbox" name="employees[]" value="{{ $r['employee']->id }}" aria-label="Signed the sheet: {{ $r['employee']->name }}"> @endif<b class="small">{{ $r['employee']->name }}</b><div class="tiny muted">{{ $r['employee']->designation }}</div></td>
      <td class="small right">@if($r['ack'])<span class="text-green">✓ v{{ $r['ack']->version }}</span><div class="tiny muted">{{ $r['ack']->acknowledged_at->format('d M y') }} · {{ \App\Models\SopAcknowledgement::VIA[$r['ack']->via] ?? '' }}</div>@else<span class="muted">not signed</span>@endif</td></tr>
  @empty<tr><td class="empty">Nobody is in scope.</td></tr>@endforelse</tbody>
</table></div>
@if($train && $ack['signed'] < $ack['required'])<div class="card-body pad"><button class="btn btn-sm" id="sheetBtn"><x-icon name="edit"/> Record ticked from the paper sheet</button></div>@endif
@if($train)</form>@endif
</div>
@endif
</div>
</div>

@if($author && $d->status !== 'retired')
<div class="modal" id="retireModal"><div class="modal-box" style="max-width:30rem"><form method="post" action="{{ route('learning.sop.retire', $d) }}">@csrf
  <div class="modal-head"><h3>Retire {{ $d->code }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="field"><label>Reason</label><input name="reason" required maxlength="190" placeholder="e.g. replaced by SOP-FO-004"></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-danger">Retire</button></div>
</form></div></div>
@endif
@endsection
