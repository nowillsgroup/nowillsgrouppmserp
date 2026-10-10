@extends('layouts.app')
@section('title', $e->number.' '.$e->name)
@section('crumbs') <a href="{{ route('sales.marketing') }}">Sales & Marketing / Marketing calendar</a> / {{ $e->number }} @endsection
@php $M = \App\Models\MarketingEvent::class; $live = in_array($e->status, ['planned', 'confirmed'], true); @endphp
@section('actions')
  @if(can_do('sales.edit') || can_do('sales.manage'))<a class="btn" href="{{ route('sales.opportunities', ['mevent' => $e->id]) }}#new" data-modal="oppModalNew" id="newOppBtn"><x-icon name="plus"/> Opportunity from this event</a>@endif
  @if($canEdit && $live)<button class="btn" data-modal="meventModal" id="editEventBtn"><x-icon name="edit"/> Edit</button>
    @if($e->status === 'planned')<form method="post" action="{{ route('sales.mevent.status', $e) }}">@csrf<input type="hidden" name="status" value="confirmed"><button class="btn" id="confirmBtn"><x-icon name="check"/> Confirm</button></form>@endif
    <button class="btn btn-primary" data-modal="closeEventModal" id="closeEventBtn"><x-icon name="star"/> Close with results</button>
    <button class="btn btn-danger" data-modal="cancelEventModal" id="cancelEventBtn">Cancel</button>@endif
@endsection
@section('content')
<div class="flex gap-1 wrap mb-1"><span class="badge badge-{{ $e->statusColor() }}" id="eventStatus" data-status="{{ $e->status }}">{{ $e->statusLabel() }}</span><span class="badge badge-slate"><x-icon :name="$e->typeIcon()"/> {{ $e->typeLabel() }}</span></div>
<div class="stats" id="eventStats">
  <x-stat label="Budget" :value="money_short($e->budget)" icon="wallet" tone="blue" :sub="in_array($e->status, ['done', 'cancelled']) ? 'actual '.money($e->actual_cost) : 'not yet spent'"/>
  <x-stat label="Leads" :value="$e->leads" icon="users" tone="purple" :sub="'target '.$e->target_leads.($roi['cpl'] !== null ? ' · '.money($roi['cpl']).' per lead' : '')"/>
  <x-stat label="Opportunities" :value="$roi['opps']" icon="trend" tone="amber" :sub="money($roi['open_value']).' still open'"/>
  <x-stat label="Won" :value="money_short($roi['won_value'])" icon="star" tone="green" :sub="$roi['roi'] !== null ? 'ROI '.number_format($roi['roi'], 0).'%' : $roi['won'].' deals'"/>
</div>
<div class="g-2-1 mt-1">
<div>
<div class="card" id="eventDetail"><div class="card-body"><dl class="kv"><dt>Dates</dt><dd>{{ $e->start_date->format('D d M Y') }}{{ $e->end_date->ne($e->start_date) ? ' – '.$e->end_date->format('D d M Y') : '' }}</dd><dt>Location</dt><dd>{{ $e->location ?? '—' }}</dd>
  <dt>Segment</dt><dd>{{ $M::SEGMENTS[$e->segment] ?? '—' }}</dd><dt>Market</dt><dd>{{ $M::MARKETS[$e->market] ?? '—' }}</dd><dt>Owner</dt><dd>{{ $e->owner?->name }}</dd></dl>
  @if($e->objectives)<h4 class="mt-1">Objectives</h4><p class="small sa-notes">{{ $e->objectives }}</p>@endif
  @if($e->results)<h4 class="mt-1">Results</h4><p class="small sa-notes" id="eventResults">{{ $e->results }}</p>@endif
</div></div>
<div class="card flush mt-1" id="eventOpps"><div class="card-head"><h3><x-icon name="trend"/> Opportunities from this event</h3></div>
<div class="card-body table-wrap"><table class="table table-compact"><thead><tr><th>Opportunity</th><th>Account</th><th>Stage</th><th class="num">Value</th></tr></thead>
  <tbody>@forelse($e->opportunities as $o)<tr data-opp="{{ $o->number }}"><td><a href="{{ route('sales.opportunity', $o) }}"><b>{{ $o->number }}</b> {{ $o->name }}</a></td><td class="small">{{ $o->company->name }}</td><td><span class="badge badge-{{ $o->stageColor() }}">{{ $o->stageLabel() }}</span></td><td class="num">{{ money($o->total()) }}</td></tr>
  @empty<tr><td colspan="4" class="empty">No opportunities attributed yet – pick this event as the source when you open an opportunity.</td></tr>@endforelse</tbody></table></div></div>
</div>
<div><div class="card"><div class="card-body small muted">Planned by {{ $e->owner?->name }} · created {{ $e->created_at->format('d M Y') }}</div></div></div>
</div>
@if(can_do('sales.edit') || can_do('sales.manage'))
@include('sales._opp_modal', ['o' => null, 'presetEvent' => $e->id, 'mevents' => collect([$e]), 'accounts' => \App\Models\Company::where('is_active', true)->whereIn('type', ['corporate', 'travel_agent'])->orderBy('name')->get(['id', 'name']), 'sellers' => \App\Services\SalesService::sellers()])
@endif
@if($canEdit && $live)
@include('sales._mevent_modal', ['e' => $e])
<div class="modal" id="closeEventModal"><div class="modal-box" style="max-width:40rem"><form method="post" action="{{ route('sales.mevent.status', $e) }}">@csrf<input type="hidden" name="status" value="done">
  <div class="modal-head"><h3>Close {{ $e->number }} with results</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field"><label>Actual cost (GH₵)</label><input type="number" name="actual_cost" min="0" step="0.01" required value="{{ $e->budget }}"></div>
    <div class="field"><label>Leads generated</label><input type="number" name="leads" min="0" required value="0"></div>
    <div class="field span-all"><label>Results</label><textarea name="results" rows="4" required placeholder="Buyers met, appointments, contracts discussed, lessons for next year"></textarea></div>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Close event</button></div>
</form></div></div>
<div class="modal" id="cancelEventModal"><div class="modal-box" style="max-width:34rem"><form method="post" action="{{ route('sales.mevent.status', $e) }}">@csrf<input type="hidden" name="status" value="cancelled">
  <div class="modal-head"><h3>Cancel {{ $e->number }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field span-all"><label>Reason</label><input name="reason" maxlength="500" required></div>
    <div class="field"><label>Cost already incurred (GH₵)</label><input type="number" name="actual_cost" min="0" step="0.01" value="0"></div>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-danger">Cancel event</button></div>
</form></div></div>
@endif
@endsection
