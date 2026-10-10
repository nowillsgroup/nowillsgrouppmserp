@php $CH = \App\Models\DmChannel::class; $mid = $c ? 'channelModal' : 'channelModalNew'; @endphp
<div class="modal" id="{{ $mid }}"><div class="modal-box" style="max-width:42rem"><form method="post" action="{{ $c ? route('digital.channel.update', $c) : route('digital.channels.store') }}">@csrf @if($c) @method('PUT') @endif
  <div class="modal-head"><h3>{{ $c ? 'Edit '.$c->name : 'Add a channel' }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field"><label>Name</label><input name="name" maxlength="100" required value="{{ $c?->name }}" placeholder="Instagram @nowillsgrand"></div>
    <div class="field"><label>Platform</label><select name="platform">@foreach($CH::KINDS as $kk => $kl)<optgroup label="{{ $kl }}">@foreach($CH::PLATFORMS as $k => [$l, $kind])@if($kind === $kk)<option value="{{ $k }}" @selected($c?->platform === $k)>{{ $l }}</option>@endif @endforeach</optgroup>@endforeach</select></div>
    <div class="field"><label>Handle / account ID</label><input name="handle" maxlength="100" value="{{ $c?->handle }}" placeholder="@nowillsgrand"></div>
    <div class="field"><label>Profile / page address</label><input type="url" name="url" maxlength="255" value="{{ $c?->url }}" placeholder="https://…"></div>
    <div class="field"><label>Owner</label><select name="owner_id"><option value="">—</option>@foreach($people as $u)<option value="{{ $u->id }}" @selected($c?->owner_id === $u->id)>{{ $u->name }}</option>@endforeach</select></div>
    <div class="field"><label>Followers (current)</label><input type="number" min="0" name="followers" value="{{ $c?->followers }}"></div>
    <div class="field"><label>Rating <span class="tiny muted">(listings)</span></label><input type="number" min="0" max="10" step="0.01" name="rating" value="{{ $c?->rating }}"></div>
    <div class="field"><label>Reviews <span class="tiny muted">(listings)</span></label><input type="number" min="0" name="reviews" value="{{ $c?->reviews }}"></div>
    <div class="field"><label>Active</label><select name="is_active"><option value="1" @selected($c?->is_active ?? true)>Active</option><option value="0" @selected($c && ! $c->is_active)>Inactive</option></select></div>
    <div class="field span-all"><label>Notes (log-in holder, agency, posting guidelines)</label><textarea name="notes" rows="2">{{ $c?->notes }}</textarea></div>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Save channel</button></div>
</form></div></div>
