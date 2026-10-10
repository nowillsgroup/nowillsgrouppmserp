@php $I = \App\Models\BdInitiative::class; $mid = $i ? 'initiativeModal' : 'initiativeModalNew'; $locked = $i && in_array($i->stage, ['approved', 'implementation', 'live'], true); @endphp
<div class="modal" id="{{ $mid }}"><div class="modal-box" style="max-width:48rem"><form method="post" action="{{ $i ? route('bd.initiative.update', $i) : route('bd.initiatives.store') }}">@csrf @if($i) @method('PUT') @endif
  <div class="modal-head"><h3>{{ $i ? 'Edit '.$i->number : 'New business development initiative' }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field span-all"><label>Initiative</label><input name="name" maxlength="150" required value="{{ $i?->name }}" placeholder="Airline crew accommodation contract"></div>
    <div class="field"><label>Category</label><select name="category" required>@foreach($I::CATEGORIES as $k => $l)<option value="{{ $k }}" @selected($i?->category === $k)>{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label>Target market / segment</label><input name="target_market" maxlength="120" value="{{ $i?->target_market }}" placeholder="Nigerian business travellers"></div>
    <div class="field"><label>Owner</label><select name="owner_id"><option value="">Me</option>@foreach($people as $u)<option value="{{ $u->id }}" @selected($i?->owner_id === $u->id)>{{ $u->name }}</option>@endforeach</select></div>
    <div class="field"><label>Partnership</label><select name="partner_id"><option value="">—</option>@foreach($partnerList as $p)<option value="{{ $p->id }}" @selected($i?->partner_id === $p->id)>{{ $p->number }} {{ $p->name }}</option>@endforeach</select></div>
    <div class="field span-all"><label>Opportunity & approach</label><textarea name="description" rows="4" placeholder="The market gap, why us, how we will win it, risks …">{{ $i?->description }}</textarea></div>
    <div class="field span-all tiny muted">Business case{{ $locked ? ' – approved, figures frozen' : '' }}</div>
    <div class="field"><label>Investment (GH₵)</label><input type="number" name="investment" min="0" step="0.01" value="{{ $i?->investment ?? 0 }}" @if($locked) readonly @endif></div>
    <div class="field"><label>Annual revenue (GH₵)</label><input type="number" name="annual_revenue" min="0" step="0.01" value="{{ $i?->annual_revenue ?? 0 }}" @if($locked) readonly @endif></div>
    <div class="field"><label>Annual running cost (GH₵)</label><input type="number" name="annual_cost" min="0" step="0.01" value="{{ $i?->annual_cost ?? 0 }}" @if($locked) readonly @endif></div>
    <div class="field"><label>Appraisal horizon (years)</label><input type="number" name="horizon_years" min="1" max="10" value="{{ $i?->horizon_years ?? 3 }}" @if($locked) readonly @endif></div>
    <div class="field"><label>Target launch</label><input type="date" name="launch_date" value="{{ $i?->launch_date?->toDateString() }}"></div>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Save initiative</button></div>
</form></div></div>
