@extends('layouts.app')
@section('title', $o->number.' '.$o->name)
@section('crumbs') <a href="{{ route('sales.opportunities') }}">Sales / Opportunities</a> / {{ $o->number }} @endsection
@php $O = \App\Models\SalesOpportunity::class; $idx = array_search($o->stage, $O::OPEN, true); @endphp
@section('actions')
  <a class="btn" href="{{ route('sales.proposal', $o) }}" target="_blank" id="proposalBtn"><x-icon name="print"/> Proposal</a>
  @if($canEdit && $o->isOpen())<button class="btn" data-modal="actModal" data-title="Plan an activity for {{ $o->number }}" id="planActBtn"><x-icon name="calendar"/> Plan activity</button>
  <button class="btn" data-modal="oppModal" id="editOppBtn"><x-icon name="edit"/> Edit</button>@endif
  @if($canEdit && ! $o->isOpen())<form method="post" action="{{ route('sales.opportunity.reopen', $o) }}" data-confirm="Reopen {{ $o->number }}?">@csrf<button class="btn" id="reopenBtn"><x-icon name="refresh"/> Reopen</button></form>@endif
@endsection
@section('content')
<div class="sa-stages" id="stageBar" data-stage="{{ $o->stage }}">@foreach($O::OPEN as $i => $s)
  <div class="sa-step {{ $o->stage === $s ? 'current' : (($idx !== false && $i < $idx) || ! $o->isOpen() ? 'past' : '') }}">{{ $O::STAGES[$s][0] }}</div>@endforeach
  <div class="sa-step {{ $o->stage === 'won' ? 'won' : ($o->stage === 'lost' ? 'lost' : '') }}">{{ $o->stage === 'lost' ? 'Lost' : 'Won' }}</div></div>

@if($o->stage === 'lost')<div class="alert alert-error" id="lostAlert"><x-icon name="x"/> Lost on {{ $o->closed_on?->format('d M Y') }} – {{ $O::LOST_REASONS[$o->lost_reason] ?? $o->lost_reason }}{{ $o->lost_note ? ': '.$o->lost_note : '' }}</div>@endif
@if($o->stage === 'won')<div class="alert alert-success" id="wonAlert"><x-icon name="star"/> Won on {{ $o->closed_on?->format('d M Y') }} – {{ money($o->total()) }}.</div>@endif

@if($canEdit && $o->isOpen())
<div class="card" id="stageCard"><div class="card-body flex gap-1 wrap" style="align-items:center"><span class="small bold">Move to:</span>
  @foreach($O::OPEN as $s)@continue($s === $o->stage)<form method="post" action="{{ route('sales.opportunity.stage', $o) }}">@csrf<input type="hidden" name="stage" value="{{ $s }}"><button class="btn btn-sm" data-move="{{ $s }}">{{ $O::STAGES[$s][0] }}</button></form>@endforeach
  <form method="post" action="{{ route('sales.opportunity.stage', $o) }}" data-confirm="Mark {{ $o->number }} as won?">@csrf<input type="hidden" name="stage" value="won"><button class="btn btn-sm btn-primary" id="wonBtn"><x-icon name="star"/> Won</button></form>
  <button class="btn btn-sm btn-danger" data-modal="lostModal" id="lostBtn"><x-icon name="x"/> Lost</button>
</div></div>
@endif

<div class="g-2-1 mt-1">
<div>
<div class="card" id="oppDetail"><div class="card-body"><div class="g-2">
  <dl class="kv"><dt>Account</dt><dd><a href="{{ route('sales.account', $o->company) }}"><b>{{ $o->company->name }}</b></a></dd><dt>Contact</dt><dd>{{ $o->contact ? $o->contact->name.($o->contact->title ? ' ('.$o->contact->title.')' : '') : '—' }}</dd>
    <dt>Type</dt><dd>{{ $o->typeLabel() }}</dd><dt>Source</dt><dd>{{ $O::SOURCES[$o->source] ?? $o->source }}</dd><dt>Owner</dt><dd>{{ $o->owner?->name }}</dd><dt>Competitors</dt><dd>{{ $o->competitors ?? '—' }}</dd></dl>
  <dl class="kv"><dt>Expected close</dt><dd class="{{ $o->isOpen() && $o->expected_close->lt(bdate()->copy()->startOfDay()) ? 'text-red bold' : '' }}">{{ $o->expected_close->format('d M Y') }}</dd><dt>Dates</dt><dd>{{ $o->arrival ? $o->arrival->format('d M Y').' – '.$o->departure->format('d M Y') : '—' }}</dd>
    <dt>Room nights</dt><dd>{{ number_format($o->room_nights) }}{{ $o->adr() ? ' @ '.money($o->adr()).' ADR' : '' }}</dd><dt>Rooms</dt><dd>{{ money($o->rooms_revenue) }}</dd><dt>F&amp;B / other</dt><dd>{{ money($o->fb_revenue) }} / {{ money($o->other_revenue) }}</dd>
    <dt>Total</dt><dd><b>{{ money($o->total()) }}</b> · {{ $o->probability }}% → <b id="weighted">{{ money($o->weighted()) }}</b></dd></dl>
</div>@if($o->notes)<p class="small mt-1 sa-notes">{{ $o->notes }}</p>@endif</div></div>

<div class="card flush mt-1" id="actsCard"><div class="card-head"><h3><x-icon name="calendar"/> Activity</h3></div>
<div class="card-body">@include('sales._activity_rows', ['acts' => $acts, 'showAccount' => false, 'showOpp' => false])</div></div>
</div>

<div>
@if($o->stage === 'won')
<div class="card" id="linkCard"><div class="card-head"><h3><x-icon name="link"/> What it became</h3></div><div class="card-body">
  @if(in_array($o->type, $O::CONTRACT_TYPES, true))
    @if($o->contract)<div class="between-row"><div>Rate contract <b>@if(can_do('contracts.view'))<a href="{{ route('contracts.show', $o->contract) }}">{{ $o->contract->code }}</a>@else{{ $o->contract->code }}@endif</b><div class="tiny muted">{{ ucfirst($o->contract->status) }}</div></div>
      @if($canEdit)<form method="post" action="{{ route('sales.opportunity.link', $o) }}">@csrf<input type="hidden" name="kind" value="contract"><button class="btn btn-sm btn-ghost">Unlink</button></form>@endif</div>
    @elseif($canEdit)<form method="post" action="{{ route('sales.opportunity.contract', $o) }}" data-confirm="Draft a rate contract for {{ $o->company->name }}?">@csrf<button class="btn btn-primary" id="draftContractBtn"><x-icon name="receipt"/> Draft rate contract</button></form>
      @if($contracts->count())<form method="post" action="{{ route('sales.opportunity.link', $o) }}" class="flex gap-1 mt-1">@csrf<input type="hidden" name="kind" value="contract"><select name="id" aria-label="Contract">@foreach($contracts as $k)<option value="{{ $k->id }}">{{ $k->code }} ({{ $k->status }})</option>@endforeach</select><button class="btn btn-sm">Link existing</button></form>@endif
    @endif
  @endif
  @foreach(['block' => [$o->block, $blocks, 'Room block', fn ($b) => $b->block_no.' '.$b->name, fn ($b) => route('blocks.show', $b)], 'event' => [$o->event, $events, 'Banquet event', fn ($e) => $e->event_no.' '.($e->title ?? ''), fn ($e) => route('banquet.show', $e)]] as $kind => [$cur, $opts, $label, $name, $url])
    <div class="mt-1" data-link="{{ $kind }}">@if($cur)<div class="between-row"><div>{{ $label }} <b><a href="{{ $url($cur) }}">{{ $name($cur) }}</a></b></div>@if($canEdit)<form method="post" action="{{ route('sales.opportunity.link', $o) }}">@csrf<input type="hidden" name="kind" value="{{ $kind }}"><button class="btn btn-sm btn-ghost">Unlink</button></form>@endif</div>
    @elseif($canEdit && $opts->count())<form method="post" action="{{ route('sales.opportunity.link', $o) }}" class="flex gap-1">@csrf<input type="hidden" name="kind" value="{{ $kind }}"><select name="id" aria-label="{{ $label }}">@foreach($opts as $x)<option value="{{ $x->id }}">{{ $name($x) }}</option>@endforeach</select><button class="btn btn-sm">Link {{ strtolower($label) }}</button></form>@endif</div>
  @endforeach
</div></div>
@endif
<div class="card mt-1"><div class="card-body small muted">Created {{ $o->created_at->format('d M Y') }} · stage changed {{ $o->stage_changed_at?->format('d M Y') }}</div></div>
</div>
</div>

@if($canEdit && $o->isOpen())
@include('sales._opp_modal', ['o' => $o])
<div class="modal" id="lostModal"><div class="modal-box" style="max-width:34rem"><form method="post" action="{{ route('sales.opportunity.stage', $o) }}">@csrf<input type="hidden" name="stage" value="lost">
  <div class="modal-head"><h3>Mark {{ $o->number }} as lost</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field span-all"><label>Reason</label><select name="lost_reason" required><option value="">Choose…</option>@foreach($O::LOST_REASONS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
    <div class="field span-all"><label>Details <span class="tiny muted">which hotel won it, what the rate was…</span></label><input name="lost_note" maxlength="255"></div>
    <p class="span-all tiny muted">Planned activities for this deal will be cancelled.</p>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-danger">Mark lost</button></div>
</form></div></div>
@include('sales._activity_modals', ['contacts' => $o->company->contacts->where('is_active', true), 'openOpps' => collect([$o]), 'presetCompany' => $o->company_id, 'presetOpp' => $o->id, 'cfg' => \App\Services\SalesService::cfg()])
@elseif($canEdit)
@include('sales._activity_modals', ['contacts' => collect(), 'openOpps' => collect(), 'cfg' => \App\Services\SalesService::cfg()])
@endif
@endsection
