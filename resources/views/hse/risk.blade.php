@extends('layouts.app')
@section('title', $x->number.' · '.$x->title)
@section('crumbs') Health &amp; Safety / <a href="{{ route('hse.risks') }}">Risk assessments</a> / {{ $x->number }} @endsection
@php $u = auth()->user(); $canMg = \App\Services\HseService::canManage($u); [, $il, $it] = \App\Models\HseRisk::band($x->score()); [, $rl, $rt] = \App\Models\HseRisk::band($x->residual()); @endphp
@section('actions')
  @if($canMg && $x->status === 'pending' && $x->assessed_by !== $u->id)<form method="post" action="{{ route('hse.risks.accept', $x) }}" class="inline" data-confirm="Accept residual risk {{ $x->residual() }} for {{ $x->title }}?">@csrf<button class="btn btn-primary" id="hseRiskAccept"><x-icon name="check"/> Accept residual risk</button></form>@endif
  @if(($canMg || $x->owner_id === $u->id) && $x->status !== 'archived')<button class="btn" data-modal="hseReviewModal" id="hseRiskReviewBtn"><x-icon name="refresh"/> Reviewed – still valid</button>@endif
  @if($canMg)<button class="btn" data-modal="hseRiskEdit"><x-icon name="edit"/> Edit</button>
  <form method="post" action="{{ route('hse.risks.archive', $x) }}" class="inline">@csrf<input type="hidden" name="archive" value="{{ $x->status === 'archived' ? 0 : 1 }}"><button class="btn">{{ $x->status === 'archived' ? 'Restore' : 'Archive' }}</button></form>@endif
@endsection
@section('content')
@if($x->status === 'pending')<div class="alert alert-warn" id="hseRiskPending"><x-icon name="alert"/><div>Residual risk <b>{{ $x->residual() }}</b> is high: a manager other than the assessor ({{ $x->assessor?->name }}) must accept it, with the further actions in hand.</div></div>@endif
<div class="g-2-1">
  <div><div class="card"><div class="card-head"><h3>{{ $x->title }}</h3><span class="badge badge-slate">{{ ['active' => 'Live', 'pending' => 'Awaiting acceptance', 'archived' => 'Archived'][$x->status] }}</span></div><div class="card-body">
    <div class="kv-grid"><div><span>Area</span>{{ $x->area }}{{ $x->department ? ' · '.$x->department->name : '' }}</div><div><span>Who is at risk</span>{{ $x->who_at_risk }}</div>
      <div><span>Owner</span>{{ $x->owner?->name ?? '—' }}</div><div><span>Assessed by</span>{{ $x->assessor?->name }} · {{ $x->created_at->format('d M Y') }}</div>
      @if($x->accepted_at)<div><span>Accepted by</span>{{ $x->acceptor?->name }} · {{ $x->accepted_at->format('d M Y') }}</div>@endif
      <div><span>Last reviewed</span>{{ $x->last_reviewed_on?->format('d M Y') ?? '—' }}</div><div><span>Next review</span>{{ $x->review_on->format('d M Y') }}</div></div>
    <h4 class="mt-1">Hazard</h4><p class="small" style="white-space:pre-line">{{ $x->hazard }}</p>
    <h4>Controls in place</h4><p class="small" style="white-space:pre-line">{{ $x->controls }}</p>
    @if($x->further_actions)<h4>Further actions</h4><p class="small" style="white-space:pre-line">{{ $x->further_actions }}</p>@endif
  </div></div></div>
  <div><div class="card"><div class="card-head"><h3>Rating</h3></div><div class="card-body" id="hseRating">
    <div class="hse-rate"><div><span class="tiny muted">Before controls</span><div class="hse-score badge-{{ $it }}">{{ $x->score() }}</div><div class="tiny">{{ \App\Models\HseRisk::LIKELIHOOD[$x->likelihood] }} × {{ \App\Models\HseRisk::SEVERITY[$x->severity] }}</div><b class="small">{{ $il }}</b></div>
      <div class="hse-arrow">→</div>
      <div><span class="tiny muted">Residual</span><div class="hse-score badge-{{ $rt }}">{{ $x->residual() }}</div><div class="tiny">{{ \App\Models\HseRisk::LIKELIHOOD[$x->residual_likelihood] }} × {{ \App\Models\HseRisk::SEVERITY[$x->residual_severity] }}</div><b class="small">{{ $rl }}</b></div></div>
  </div></div></div>
</div>
<div class="modal" id="hseReviewModal"><div class="modal-box"><form method="post" action="{{ route('hse.risks.review', $x) }}">@csrf
  <div class="modal-head"><h3>Review {{ $x->number }}</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body"><p class="small">Confirm the hazard, controls and ratings are still right. If anything changed, edit the assessment instead.</p>
    <div class="field"><label>Next review</label><input type="date" name="review_on" value="{{ bdate()->copy()->addDays(\App\Services\HseService::cfg()['review_days'])->toDateString() }}"></div>
    <div class="field"><label>Note</label><input name="note" maxlength="190"></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Record review</button></div>
</form></div></div>
@if($canMg)<div class="modal" id="hseRiskEdit"><div class="modal-box lg"><form method="post" action="{{ route('hse.risks.update', $x) }}">@csrf
  <div class="modal-head"><h3>Edit {{ $x->number }}</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body">@include('hse._risk_fields', ['x' => $x])</div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Save</button></div>
</form></div></div><datalist id="hseAreas"></datalist>@endif
@endsection
