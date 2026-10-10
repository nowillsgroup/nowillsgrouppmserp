@php $A = \App\Models\Activity::class; $mid = $a ? 'actModal'.$a->id : 'actModalNew'; $wd = $a?->weekdays ?? [1, 2, 3, 4, 5, 6, 7]; @endphp
<div class="modal" id="{{ $mid }}"><div class="modal-box" style="max-width:48rem"><form method="post" action="{{ $a ? route('concierge.activity.update', $a) : route('concierge.activity.store') }}">@csrf @if($a) @method('PUT') @endif
  <div class="modal-head"><h3>{{ $a ? 'Edit '.$a->name : 'New activity' }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field"><label>Code</label><input name="code" maxlength="20" required value="{{ $a?->code }}" placeholder="CITY"></div>
    <div class="field"><label>Operator</label><select name="activity_vendor_id" required>@foreach($vendors as $v)<option value="{{ $v->id }}" @selected($a?->activity_vendor_id == $v->id)>{{ $v->name }}</option>@endforeach</select></div>
    <div class="field span-all"><label>Name</label><input name="name" maxlength="150" required value="{{ $a?->name }}"></div>
    <div class="field"><label>Category</label><select name="category">@foreach($A::CATEGORIES as $k => $l)<option value="{{ $k }}" @selected($a?->category === $k)>{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label>Duration (minutes)</label><input type="number" name="duration_min" min="15" max="1440" required value="{{ $a?->duration_min ?? 180 }}"></div>
    <div class="field span-all"><label>Highlights (one line)</label><input name="highlights" maxlength="255" value="{{ $a?->highlights }}"></div>
    <div class="field span-all"><label>Description</label><textarea name="description" rows="3" maxlength="3000">{{ $a?->description }}</textarea></div>
    <div class="field"><label>What to bring</label><input name="bring" maxlength="255" value="{{ $a?->bring }}"></div>
    <div class="field"><label>Meeting point</label><input name="meeting_point" maxlength="150" value="{{ $a?->meeting_point }}"></div>
    <div class="field"><label>Pricing</label><select name="pricing"><option value="person" @selected(($a?->pricing ?? 'person') === 'person')>Per person</option><option value="group" @selected($a?->pricing === 'group')>Private group (flat)</option></select></div>
    <div class="field"><label>Adult / group price (GH₵)</label><input type="number" step="0.01" min="0" name="price_adult" required value="{{ $a?->price_adult }}"></div>
    <div class="field"><label>Child price (GH₵)</label><input type="number" step="0.01" min="0" name="price_child" value="{{ $a?->price_child }}"></div>
    <div class="field"><label>Child up to age</label><input type="number" min="0" max="17" name="child_max_age" value="{{ $a?->child_max_age ?? 11 }}"></div>
    <div class="field"><label>Commission % <span class="tiny muted">(blank = operator's)</span></label><input type="number" step="0.01" min="0" max="60" name="commission_pct" value="{{ $a?->commission_pct }}"></div>
    <div class="field"><label>Payment <span class="tiny muted">(blank = operator's)</span></label><select name="collection"><option value="">Operator default</option>@foreach(\App\Models\ActivityVendor::COLLECTION as $k => $l)<option value="{{ $k }}" @selected($a?->collection === $k)>{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label>Minimum guests</label><input type="number" min="1" max="100" name="min_pax" required value="{{ $a?->min_pax ?? 1 }}"></div>
    <div class="field"><label>Seats per departure / max group</label><input type="number" min="1" max="500" name="capacity" value="{{ $a?->capacity }}"></div>
    <div class="field"><label>Schedule</label><select name="schedule"><option value="scheduled" @selected(($a?->schedule ?? 'scheduled') === 'scheduled')>Fixed departures</option><option value="request" @selected($a?->schedule === 'request')>On request (any time)</option></select></div>
    <div class="field"><label>Departure times</label><input name="times" maxlength="120" value="{{ implode(', ', $a?->times ?? []) }}" placeholder="09:00, 14:00"></div>
    <div class="field span-all"><label>Runs on</label><div class="flex gap-1 wrap">@foreach($A::DAYS as $n => $l)<label class="small"><input type="checkbox" name="weekdays[]" value="{{ $n }}" @checked(in_array($n, $wd))> {{ $l }}</label>@endforeach</div></div>
    <div class="field"><label>Book until (hours before)</label><input type="number" min="0" max="720" name="cutoff_hours" required value="{{ $a?->cutoff_hours ?? 12 }}"></div>
    <div class="field"><label>Free cancellation (hours before)</label><input type="number" min="0" max="720" name="free_cancel_hours" required value="{{ $a?->free_cancel_hours ?? 24 }}"></div>
    <div class="field"><label>Late cancellation fee %</label><input type="number" step="0.01" min="0" max="100" name="cancel_fee_pct" required value="{{ $a?->cancel_fee_pct ?? 50 }}"></div>
    <div class="field"><label>Sort order</label><input type="number" min="0" max="9999" name="sort_order" value="{{ $a?->sort_order ?? 0 }}"></div>
    <div class="span-all flex gap-1 wrap small">
      <label><input type="checkbox" name="pickup_included" value="1" @checked($a?->pickup_included)> Hotel pick-up included</label>
      <label><input type="checkbox" name="instant" value="1" @checked($a?->instant)> Instant confirmation (allotment)</label>
      <label><input type="checkbox" name="portal_visible" value="1" @checked($a?->portal_visible ?? true)> Bookable on the guest portal</label>
      <label><input type="checkbox" name="is_active" value="1" @checked($a?->is_active ?? true)> On sale</label>
    </div>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Save</button></div>
</form></div></div>
