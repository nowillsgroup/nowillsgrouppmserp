@php $C = \App\Models\Company::class; $mid = $c ? 'accountModal' : 'accountModalNew'; @endphp
<div class="modal" id="{{ $mid }}"><div class="modal-box" style="max-width:46rem"><form method="post" action="{{ $c ? route('sales.account.update', $c) : route('sales.accounts.store') }}">@csrf @if($c) @method('PUT') @endif
  <div class="modal-head"><h3>{{ $c ? 'Edit '.$c->name : 'New account / prospect' }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field span-all"><label>Account name</label><input name="name" maxlength="255" required value="{{ old('name', $c?->name) }}" placeholder="MTN Ghana"></div>
    <div class="field"><label>Type</label><select name="type">@foreach(['corporate' => 'Corporate', 'travel_agent' => 'Travel agent / tour operator'] + ($c?->type === 'ota' ? ['ota' => 'OTA'] : []) as $k => $l)<option value="{{ $k }}" @selected(($c?->type ?? 'corporate') === $k)>{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label>Industry</label><select name="industry"><option value="">—</option>@foreach($C::INDUSTRIES as $k => $l)<option value="{{ $k }}" @selected($c?->industry === $k)>{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label>Tier</label><select name="tier">@foreach($C::TIERS as $k => [$l])<option value="{{ $k }}" @selected(($c?->tier ?? 'standard') === $k)>{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label>Sales status</label><select name="sales_status">@foreach($C::SALES_STATUSES as $k => [$l])<option value="{{ $k }}" @selected(($c?->sales_status ?? 'prospect') === $k)>{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label>Account manager</label><select name="account_manager_id" @disabled(! can_do('sales.manage') && $c && $c->account_manager_id)>@foreach($sellers as $u)<option value="{{ $u->id }}" @selected(($c?->account_manager_id ?? auth()->id()) === $u->id)>{{ $u->name }}</option>@endforeach</select></div>
    <div class="field"><label>Potential (room nights / year)</label><input type="number" name="potential_nights" min="0" value="{{ $c?->potential_nights }}"></div>
    <div class="field"><label>Main contact</label><input name="contact_person" maxlength="120" value="{{ $c?->contact_person }}"></div>
    <div class="field"><label>Phone</label><input name="phone" maxlength="30" value="{{ $c?->phone }}" placeholder="+233 30 …"></div>
    <div class="field"><label>E-mail</label><input type="email" name="email" maxlength="150" value="{{ $c?->email }}"></div>
    <div class="field"><label>Website</label><input name="website" maxlength="150" value="{{ $c?->website }}"></div>
    <div class="field"><label>Address</label><input name="address" maxlength="255" value="{{ $c?->address }}"></div>
    <div class="field"><label>City</label><input name="city" maxlength="80" value="{{ $c?->city }}" placeholder="Accra"></div>
    <div class="field span-all"><label>Sales notes <span class="tiny muted">travel patterns, decision process, competitor hotels used…</span></label><textarea name="sales_notes" rows="3">{{ $c?->sales_notes }}</textarea></div>
    @unless($c)<p class="span-all tiny muted">New accounts are not approved for direct billing – credit control sets the credit limit and terms when the account is opened.</p>@endunless
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Save account</button></div>
</form></div></div>
