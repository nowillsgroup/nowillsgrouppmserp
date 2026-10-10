@php $M = \App\Models\MarketingEvent::class; $mid = $e ? 'meventModal' : 'meventModalNew'; @endphp
<div class="modal" id="{{ $mid }}"><div class="modal-box" style="max-width:46rem"><form method="post" action="{{ $e ? route('sales.mevent.update', $e) : route('sales.mevents.store') }}">@csrf @if($e) @method('PUT') @endif
  <div class="modal-head"><h3>{{ $e ? 'Edit '.$e->number : 'New marketing event' }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field span-all"><label>Name</label><input name="name" maxlength="150" required value="{{ $e?->name }}" placeholder="WTM Africa 2026, Cape Town / Lagos corporate roadshow"></div>
    <div class="field"><label>Type</label><select name="type">@foreach($M::TYPES as $k => [$l])<option value="{{ $k }}" @selected($e?->type === $k)>{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label>Owner</label><select name="owner_id">@foreach($owners as $u)<option value="{{ $u->id }}" @selected(($e?->owner_id ?? auth()->id()) === $u->id)>{{ $u->name }}</option>@endforeach</select></div>
    <div class="field"><label>Target segment</label><select name="segment"><option value="">—</option>@foreach($M::SEGMENTS as $k => $l)<option value="{{ $k }}" @selected($e?->segment === $k)>{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label>Source market</label><select name="market"><option value="">—</option>@foreach($M::MARKETS as $k => $l)<option value="{{ $k }}" @selected($e?->market === $k)>{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label>Starts</label><input type="date" name="start_date" required value="{{ $e?->start_date?->toDateString() ?? bdate()->copy()->addDays(14)->toDateString() }}"></div>
    <div class="field"><label>Ends</label><input type="date" name="end_date" value="{{ $e?->end_date?->toDateString() }}"></div>
    <div class="field"><label>Location</label><input name="location" maxlength="150" value="{{ $e?->location }}" placeholder="Accra International Conference Centre"></div>
    <div class="field"><label>Budget (GH₵)</label><input type="number" name="budget" min="0" step="0.01" value="{{ $e?->budget ?? 0 }}"></div>
    <div class="field"><label>Target leads</label><input type="number" name="target_leads" min="0" value="{{ $e?->target_leads ?? 0 }}"></div>
    <div class="field span-all"><label>Objectives</label><textarea name="objectives" rows="3" placeholder="Meet 30 buyers, sign 5 agency contracts…">{{ $e?->objectives }}</textarea></div>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Save event</button></div>
</form></div></div>
