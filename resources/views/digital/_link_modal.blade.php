@php $L = \App\Models\DmLink::class; $mid = $l ? 'linkModal' : 'linkModalNew'; @endphp
<div class="modal" id="{{ $mid }}"><div class="modal-box" style="max-width:44rem"><form method="post" action="{{ $l ? route('digital.link.update', $l) : route('digital.links.store') }}">@csrf @if($l) @method('PUT') @endif
  <div class="modal-head"><h3>{{ $l ? 'Edit tracked link' : 'New tracked link' }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field span-all"><label>Name</label><input name="name" maxlength="120" required value="{{ $l?->name }}" placeholder="Instagram bio – book direct"></div>
    <div class="field span-all"><label>Destination <span class="tiny muted">(leave empty or type “booking” for the booking engine)</span></label><input name="destination" maxlength="500" value="{{ $l?->destination }}" placeholder="booking"></div>
    <div class="field"><label>utm_source</label><input name="utm_source" maxlength="60" required value="{{ $l?->utm_source }}" placeholder="instagram"></div>
    <div class="field"><label>utm_medium</label><input name="utm_medium" maxlength="60" required value="{{ $l?->utm_medium }}" list="dmMediums" placeholder="social"><datalist id="dmMediums">@foreach($L::MEDIUMS as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</datalist></div>
    <div class="field"><label>Campaign</label><select name="campaign_id"><option value="">—</option>@foreach($campaignList as $c)<option value="{{ $c->id }}" @selected($l?->campaign_id === $c->id)>{{ $c->number }} {{ $c->name }}</option>@endforeach</select></div>
    <div class="field"><label>utm_campaign <span class="tiny muted">(defaults to the campaign's tag)</span></label><input name="utm_campaign" maxlength="60" value="{{ $l?->utm_campaign }}" placeholder="always-on"></div>
    <div class="field"><label>utm_content <span class="tiny muted">(optional)</span></label><input name="utm_content" maxlength="60" value="{{ $l?->utm_content }}" placeholder="bio-link"></div>
    <div class="field"><label>Short code <span class="tiny muted">(optional)</span></label><input name="code" maxlength="40" value="{{ $l?->code }}" placeholder="auto" @if($l && $l->clicks) readonly @endif></div>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Save link</button></div>
</form></div></div>
