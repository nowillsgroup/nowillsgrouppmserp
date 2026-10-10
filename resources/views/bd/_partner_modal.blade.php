@php $PT = \App\Models\BdPartner::class; $mid = $p ? 'partnerModal' : 'partnerModalNew'; @endphp
<div class="modal" id="{{ $mid }}"><div class="modal-box" style="max-width:48rem"><form method="post" action="{{ $p ? route('bd.partner.update', $p) : route('bd.partners.store') }}">@csrf @if($p) @method('PUT') @endif
  <div class="modal-head"><h3>{{ $p ? 'Edit '.$p->number : 'New partnership' }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field span-all"><label>Partner</label><input name="name" maxlength="150" required value="{{ $p?->name }}" placeholder="Africa World Airlines"></div>
    <div class="field"><label>Type</label><select name="type" required>@foreach($PT::TYPES as $k => $l)<option value="{{ $k }}" @selected($p?->type === $k)>{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label>Agreement</label><select name="agreement" required>@foreach($PT::AGREEMENTS as $k => $l)<option value="{{ $k }}" @selected($p?->agreement === $k)>{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label>Commission %</label><input type="number" name="commission_pct" min="0" max="50" step="0.01" value="{{ $p?->commission_pct }}"></div>
    <div class="field"><label>Owner</label><select name="owner_id"><option value="">Me</option>@foreach($people as $u)<option value="{{ $u->id }}" @selected($p?->owner_id === $u->id)>{{ $u->name }}</option>@endforeach</select></div>
    <div class="field"><label>Contact</label><input name="contact_name" maxlength="100" value="{{ $p?->contact_name }}"></div>
    <div class="field"><label>E-mail</label><input type="email" name="email" maxlength="150" value="{{ $p?->email }}"></div>
    <div class="field"><label>Phone</label><input name="phone" maxlength="30" value="{{ $p?->phone }}"></div>
    <div class="field"><label>Review date</label><input type="date" name="review_date" value="{{ $p?->review_date?->toDateString() }}"></div>
    <div class="field"><label>Start</label><input type="date" name="start_date" value="{{ $p?->start_date?->toDateString() }}"></div>
    <div class="field"><label>End</label><input type="date" name="end_date" value="{{ $p?->end_date?->toDateString() }}"></div>
    <div class="field span-all tiny muted">Production is credited from any of these (use one that only this partner's bookings carry):</div>
    <div class="field"><label>Business source</label><select name="business_source_id"><option value="">—</option>@foreach($sources as $s)<option value="{{ $s->id }}" @selected($p?->business_source_id === $s->id)>{{ $s->name }}</option>@endforeach</select></div>
    <div class="field"><label>Promo code</label><select name="promo_code_id"><option value="">—</option>@foreach($promos as $s)<option value="{{ $s->id }}" @selected($p?->promo_code_id === $s->id)>{{ $s->code }}</option>@endforeach</select></div>
    <div class="field span-all"><label>Company (sales account)</label><select name="company_id"><option value="">—</option>@foreach($companies as $s)<option value="{{ $s->id }}" @selected($p?->company_id === $s->id)>{{ $s->name }}</option>@endforeach</select></div>
    <div class="field"><label>Target room nights / year</label><input type="number" name="target_nights" min="0" value="{{ $p?->target_nights }}"></div>
    <div class="field"><label>Target revenue / year (GH₵)</label><input type="number" name="target_revenue" min="0" step="0.01" value="{{ $p?->target_revenue }}"></div>
    <div class="field span-all"><label>Terms</label><textarea name="terms" rows="3" placeholder="What each side gives: rates, commission, marketing, crew rooms, lounge access …">{{ $p?->terms }}</textarea></div>
    <div class="field span-all"><label>Notes</label><textarea name="notes" rows="2">{{ $p?->notes }}</textarea></div>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Save partnership</button></div>
</form></div></div>
