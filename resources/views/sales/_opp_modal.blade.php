@php $O = \App\Models\SalesOpportunity::class; $mid = $o ? 'oppModal' : 'oppModalNew'; $preset = $preset ?? null; @endphp
<div class="modal" id="{{ $mid }}"><div class="modal-box" style="max-width:48rem"><form method="post" action="{{ $o ? route('sales.opportunity.update', $o) : route('sales.opportunities.store') }}">@csrf @if($o) @method('PUT') @endif
  <div class="modal-head"><h3>{{ $o ? 'Edit '.$o->number : 'New opportunity' }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field span-all"><label>Opportunity</label><input name="name" maxlength="150" required value="{{ $o?->name }}" placeholder="2027 corporate rate agreement / AGM conference, March"></div>
    <div class="field"><label>Account</label><select name="company_id" required><option value="">Choose…</option>@foreach($accounts as $a)<option value="{{ $a->id }}" @selected(($o?->company_id ?? $preset) == $a->id)>{{ $a->name }}</option>@endforeach</select></div>
    <div class="field"><label>Type</label><select name="type">@foreach($O::TYPES as $k => $l)<option value="{{ $k }}" @selected(($o?->type ?? 'corporate') === $k)>{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label>Source</label><select name="source">@foreach($O::SOURCES as $k => $l)<option value="{{ $k }}" @selected(($o?->source ?? 'prospecting') === $k)>{{ $l }}</option>@endforeach</select></div>
    @if($o)
    <div class="field"><label>Contact</label><select name="sales_contact_id"><option value="">—</option>@foreach($o->company->contacts->where('is_active', true) as $x)<option value="{{ $x->id }}" @selected($o->sales_contact_id === $x->id)>{{ $x->name }}{{ $x->title ? ' – '.$x->title : '' }}</option>@endforeach</select></div>
    @else
    <div class="field"><label>Starting stage</label><select name="stage">@foreach($O::OPEN as $s)<option value="{{ $s }}">{{ $O::STAGES[$s][0] }}</option>@endforeach</select></div>
    @endif
    <div class="field"><label>From marketing event <span class="tiny muted">for ROI</span></label><select name="marketing_event_id"><option value="">—</option>@foreach($mevents ?? [] as $m)<option value="{{ $m->id }}" @selected(($o?->marketing_event_id ?? ($presetEvent ?? request('mevent'))) == $m->id)>{{ $m->number }} {{ \Illuminate\Support\Str::limit($m->name, 34) }}</option>@endforeach</select></div>
    <div class="field"><label>Owner</label><select name="owner_id">@foreach($sellers as $u)<option value="{{ $u->id }}" @selected(($o?->owner_id ?? auth()->id()) === $u->id)>{{ $u->name }}</option>@endforeach</select></div>
    <div class="field"><label>Expected close</label><input type="date" name="expected_close" required value="{{ $o?->expected_close?->toDateString() ?? bdate()->copy()->addDays(30)->toDateString() }}"></div>
    <div class="field"><label>Probability % <span class="tiny muted">blank = stage default</span></label><input type="number" name="probability" min="1" max="99" value="{{ $o?->probability }}"></div>
    <div class="field"><label>Start / arrival <span class="tiny muted">validity for agreements</span></label><input type="date" name="arrival" value="{{ $o?->arrival?->toDateString() }}"></div>
    <div class="field"><label>End / departure</label><input type="date" name="departure" value="{{ $o?->departure?->toDateString() }}"></div>
    <div class="field"><label>Room nights</label><input type="number" name="room_nights" min="0" value="{{ $o?->room_nights ?? 0 }}"></div>
    <div class="field"><label>Rooms revenue (GH₵)</label><input type="number" name="rooms_revenue" min="0" step="0.01" value="{{ $o?->rooms_revenue ?? 0 }}"></div>
    <div class="field"><label>F&amp;B / banqueting (GH₵)</label><input type="number" name="fb_revenue" min="0" step="0.01" value="{{ $o?->fb_revenue ?? 0 }}"></div>
    <div class="field"><label>Other revenue (GH₵)</label><input type="number" name="other_revenue" min="0" step="0.01" value="{{ $o?->other_revenue ?? 0 }}"></div>
    <div class="field span-all"><label>Competing hotels</label><input name="competitors" maxlength="190" value="{{ $o?->competitors }}" placeholder="Kempinski, Mövenpick, Labadi Beach…"></div>
    <div class="field span-all"><label>Notes / requirements</label><textarea name="notes" rows="3">{{ $o?->notes }}</textarea></div>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Save opportunity</button></div>
</form></div></div>
