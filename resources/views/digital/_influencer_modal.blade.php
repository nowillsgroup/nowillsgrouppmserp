@php $CH = \App\Models\DmChannel::class; $mid = $i ? 'infModal' : 'infModalNew'; @endphp
<div class="modal" id="{{ $mid }}"><div class="modal-box" style="max-width:48rem"><form method="post" action="{{ $i ? route('digital.influencer.update', $i) : route('digital.influencers.store') }}">@csrf @if($i) @method('PUT') @endif
  <div class="modal-head"><h3>{{ $i ? 'Edit '.$i->number : 'New creator collaboration' }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field"><label>Creator name</label><input name="name" maxlength="120" required value="{{ $i?->name }}" placeholder="Adwoa Travels"></div>
    <div class="field"><label>Main platform</label><select name="platform">@foreach($CH::PLATFORMS as $k => [$l, $kind])@if($kind === 'social' && $k !== 'other')<option value="{{ $k }}" @selected(($i?->platform ?? 'instagram') === $k)>{{ $l }}</option>@endif @endforeach</select></div>
    <div class="field"><label>Handle</label><input name="handle" maxlength="100" required value="{{ $i?->handle }}" placeholder="@adwoatravels"></div>
    <div class="field"><label>Followers</label><input type="number" min="0" name="followers" value="{{ $i?->followers ?? 0 }}"></div>
    <div class="field"><label>Niche</label><input name="niche" maxlength="60" value="{{ $i?->niche }}" placeholder="Travel, food, diaspora"></div>
    <div class="field"><label>E-mail</label><input type="email" name="email" maxlength="150" value="{{ $i?->email }}"></div>
    <div class="field"><label>Phone</label><input name="phone" maxlength="30" value="{{ $i?->phone }}"></div>
    <div class="field"><label>Owner</label><select name="owner_id">@foreach($people as $u)<option value="{{ $u->id }}" @selected(($i?->owner_id ?? auth()->id()) === $u->id)>{{ $u->name }}</option>@endforeach</select></div>
    <div class="field"><label>Stay from</label><input type="date" name="stay_from" value="{{ $i?->stay_from?->toDateString() }}"></div>
    <div class="field"><label>Stay to</label><input type="date" name="stay_to" value="{{ $i?->stay_to?->toDateString() }}"></div>
    <div class="field"><label>Content due</label><input type="date" name="due_date" value="{{ $i?->due_date?->toDateString() }}"></div>
    <div class="field"><label>Tracked link</label><select name="link_id"><option value="">—</option>@foreach($linkList as $l)<option value="{{ $l->id }}" @selected($i?->link_id === $l->id)>/go/{{ $l->code }}</option>@endforeach</select></div>
    <div class="field"><label>Cash fee (GH₵)</label><input type="number" min="0" step="0.01" name="fee" value="{{ $i?->fee ?? 0 }}"></div>
    <div class="field"><label>Complimentary value (GH₵)</label><input type="number" min="0" step="0.01" name="comp_value" value="{{ $i?->comp_value ?? 0 }}"></div>
    <div class="field"><label>Reservation no. (ID)</label><input type="number" min="1" name="reservation_id" value="{{ $i?->reservation_id }}" placeholder="optional"></div>
    <div class="field span-all"><label>Deliverables</label><textarea name="deliverables" rows="3" placeholder="3 Instagram reels + 8 stories with link sticker, 1 blog post; usage rights 12 months">{{ $i?->deliverables }}</textarea></div>
    <div class="field span-all"><label>Notes</label><textarea name="notes" rows="2">{{ $i?->notes }}</textarea></div>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Save</button></div>
</form></div></div>
