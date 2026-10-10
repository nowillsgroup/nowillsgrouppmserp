@php $C = \App\Models\DmCampaign::class; $mid = $c ? 'campaignModal' : 'campaignModalNew'; @endphp
<div class="modal" id="{{ $mid }}"><div class="modal-box" style="max-width:46rem"><form method="post" action="{{ $c ? route('digital.campaign.update', $c) : route('digital.campaigns.store') }}">@csrf @if($c) @method('PUT') @endif
  <div class="modal-head"><h3>{{ $c ? 'Edit '.$c->number : 'New paid campaign' }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field span-all"><label>Name</label><input name="name" maxlength="150" required value="{{ $c?->name }}" placeholder="Detty December 2026 – Meta retargeting"></div>
    <div class="field"><label>Objective</label><select name="objective">@foreach($C::OBJECTIVES as $k => $l)<option value="{{ $k }}" @selected(($c?->objective ?? 'bookings') === $k)>{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label>Platform / ad account</label><select name="channel_id"><option value="">—</option>@foreach($adChannels as $ch)<option value="{{ $ch->id }}" @selected($c?->channel_id === $ch->id)>{{ $ch->name }}</option>@endforeach</select></div>
    <div class="field"><label>UTM campaign tag</label><input name="utm_campaign" maxlength="60" value="{{ $c?->utm_campaign }}" placeholder="auto from the name"></div>
    <div class="field"><label>CRM promo code</label><select name="promo_code_id"><option value="">—</option>@foreach($promos as $pc)<option value="{{ $pc->id }}" @selected($c?->promo_code_id === $pc->id)>{{ $pc->code }}</option>@endforeach</select></div>
    <div class="field"><label>Starts</label><input type="date" name="start_date" required value="{{ $c?->start_date?->toDateString() ?? bdate()->toDateString() }}"></div>
    <div class="field"><label>Ends</label><input type="date" name="end_date" value="{{ $c?->end_date?->toDateString() }}"></div>
    <div class="field"><label>Budget (GH₵)</label><input type="number" name="budget" min="0" step="0.01" value="{{ $c?->budget ?? 0 }}"></div>
    <div class="field"><label>Target ROAS (×)</label><input type="number" name="target_roas" min="0" step="0.1" value="{{ $c?->target_roas }}" placeholder="e.g. 5"></div>
    <div class="field"><label>Owner</label><select name="owner_id">@foreach($people as $u)<option value="{{ $u->id }}" @selected(($c?->owner_id ?? auth()->id()) === $u->id)>{{ $u->name }}</option>@endforeach</select></div>
    <div class="field span-all"><label>Notes (audience, creative, targeting)</label><textarea name="notes" rows="3">{{ $c?->notes }}</textarea></div>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Save campaign</button></div>
</form></div></div>
