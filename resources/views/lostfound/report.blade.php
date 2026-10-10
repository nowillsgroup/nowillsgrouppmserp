@extends('layouts.app')
@section('title', $r->report_no)
@section('crumbs') Desk Operations / <a href="{{ route('lostfound.index', ['tab' => 'reports']) }}">Lost & Found</a> / {{ $r->report_no }} @endsection
@section('actions')
  @if($r->status !== 'closed')<button class="btn" data-modal="closeRepModal" id="closeReportBtn">Close report</button>@endif
@endsection
@section('content')
<div style="display:grid;grid-template-columns:minmax(0,2fr) minmax(0,3fr);gap:1rem;align-items:start">
<div class="card"><div class="card-head"><h3>{{ $r->item }}</h3><span class="badge badge-{{ ['open' => 'amber', 'matched' => 'blue', 'closed' => 'slate'][$r->status] }}" id="reportStatus">{{ ucfirst($r->status) }}</span></div>
  <div class="card-body"><dl class="kv">
    <dt>Report</dt><dd>{{ $r->report_no }} · {{ $r->created_at->format('d M Y H:i') }} · {{ $r->creator?->name }}</dd>
    <dt>Guest</dt><dd>{{ $r->name }}@if($r->guest) (<a href="{{ route('guests.show', $r->guest) }}">profile</a>)@endif</dd>
    <dt>Contact</dt><dd>{{ $r->phone ?? '—' }} {{ $r->email ? '· '.$r->email : '' }}</dd>
    <dt>Category</dt><dd>{{ \App\Models\LostFoundItem::CATEGORIES[$r->category][0] ?? $r->category }}</dd>
    <dt>Description</dt><dd style="white-space:pre-line">{{ $r->description ?: '—' }}</dd>
    <dt>Lost</dt><dd>{{ $r->lost_date?->format('d M Y') ?? '—' }} · {{ $r->where_lost ?? '—' }}</dd>
    @if($r->foundItem)<dt>Matched item</dt><dd><a href="{{ route('lostfound.show', $r->foundItem) }}">{{ $r->foundItem->label() }}</a> ({{ $r->foundItem->statusLabel() }})</dd>@endif
    @if($r->close_note)<dt>Closed</dt><dd>{{ $r->close_note }}</dd>@endif
  </dl></div></div>
@if($r->status === 'open')
<div class="card flush" id="candidates"><div class="card-head"><h3>Possible matches in store ({{ $candidates->count() }})</h3></div><div class="card-body table-wrap"><table class="table"><thead><tr><th>Item</th><th>Found</th><th>Score</th><th></th></tr></thead><tbody>
  @forelse($candidates as $i)<tr data-candidate="{{ $i->id }}"><td><a href="{{ route('lostfound.show', $i) }}">{{ $i->item_no }}</a> · <b>{{ $i->item_name }}</b><div class="small muted">{{ $i->categoryLabel() }}{{ $i->description ? ' · '.\Illuminate\Support\Str::limit($i->description, 70) : '' }}</div></td>
    <td class="small">{{ $i->found_date->format('d M') }} · {{ $i->location }}</td><td><span class="badge badge-{{ $i->match_score >= 6 ? 'green' : 'amber' }}">{{ $i->match_score }}</span></td>
    <td class="right"><form method="post" action="{{ route('lostfound.report.match', [$r, $i]) }}">@csrf<button class="btn btn-sm btn-primary" data-act="match">This is it</button></form></td></tr>
  @empty<tr><td colspan="4" class="muted" id="noCandidates">Nothing similar in store yet. Keep the report open – new found items can be matched from their page.</td></tr>@endforelse
</tbody></table></div></div>
@endif
</div>
<div class="modal" id="closeRepModal"><div class="modal-box"><form method="post" action="{{ route('lostfound.report.close', $r) }}">@csrf
  <div class="modal-head"><h3>Close {{ $r->report_no }}</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="close_note" label="Outcome" required placeholder="e.g. not found – guest informed by e-mail"/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Back</button><button class="btn btn-primary">Close report</button></div>
</form></div></div>
@endsection
