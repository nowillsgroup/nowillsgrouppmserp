{{-- v4.1 upsell offer form – $o = UpsellOffer|null; fields shown per kind by the script in upsell/index --}}
@php
  $kind = old('kind', $o?->kind ?? 'addon');
  $chan = old('channels', $o?->channels ?? ['pre_arrival', 'desk', 'portal']);
  $rts = array_map('intval', old('room_type_ids', $o?->room_type_ids ?? []));
  $srcs = array_map('intval', old('source_ids', $o?->source_ids ?? []));
@endphp
<x-field name="name" label="Offer name (guest-facing)" required :value="$o?->name" maxlength="80" placeholder="Breakfast buffet"/>
<x-field name="code" label="Code" required :value="$o?->code" maxlength="20" placeholder="BKFST"/>
<x-field name="kind" label="Kind" type="select" :options="collect(\App\Models\UpsellOffer::KINDS)->map(fn ($k) => $k[0])" :value="$kind" :placeholder="false" required data-kind-select/>
<x-field name="icon" label="Icon" type="select" :options="collect(\App\Http\Controllers\UpsellController::ICONS)->mapWithKeys(fn ($i) => [$i => ucfirst($i)])" :value="$o?->icon" placeholder="(default for the kind)"/>
<x-field name="description" label="Description shown to the guest" type="textarea" :value="$o?->description" maxlength="1000" class="span-2" rows="2" placeholder="What is included, when and where"/>

<x-field name="pricing" label="Pricing" type="select" :options="collect(\App\Models\UpsellOffer::PRICING)->map(fn ($p) => $p[0])" :value="$o?->pricing ?? 'per_stay'" :placeholder="false" required/>
<x-field name="price" label="Price (before tax)" type="number" step="0.01" min="0" :value="$o?->price" hint="Not used with “% of the rate difference”"/>
<div data-kinds="upgrade" class="span-2 form-grid" style="padding:0">
  <x-field name="to_room_type_id" label="Upgrade to" type="select" :options="$roomTypes->mapWithKeys(fn ($t) => [$t->id => $t->name.' ('.money($t->base_rate).')'])" :value="$o?->to_room_type_id"/>
  <div class="form-grid" style="padding:0;grid-template-columns:1fr 1fr">
    <x-field name="diff_pct" label="% of rate difference" type="number" step="0.5" min="1" max="100" :value="$o?->diff_pct" placeholder="60"/>
    <x-field name="min_price" label="Min. per night" type="number" step="1" min="0" :value="$o?->min_price" placeholder="900"/>
  </div>
</div>
<div data-kinds="early_checkin,late_checkout" class="span-2 form-grid" style="padding:0">
  <x-field name="service_time" label="Time (early check-in from / late check-out until)" type="time" :value="$o?->service_time ? substr($o->service_time, 0, 5) : null"/>
  <label class="check" style="align-self:end;padding-bottom:.55rem"><input type="hidden" name="auto_confirm" value="0"><input type="checkbox" name="auto_confirm" value="1" @checked(old('auto_confirm', $o ? $o->auto_confirm : false))> Confirm automatically <span class="tiny muted">(otherwise the desk confirms each request)</span></label>
</div>
<x-field name="category" label="Folio charge category" type="select" :options="config('pms.charge_categories')" :value="$o?->category ?? 'misc'" :placeholder="false" required/>
<label class="check" style="align-self:end;padding-bottom:.55rem"><input type="hidden" name="apply_tax" value="0"><input type="checkbox" name="apply_tax" value="1" @checked(old('apply_tax', $o ? $o->apply_tax : true))> Apply tax to the charge</label>

<div class="field span-2"><label>Shown on <span class="req">*</span></label>
  <div class="flex gap-2 wrap">@foreach(\App\Models\UpsellOffer::CHANNELS as $ck => $cl)<label class="check"><input type="checkbox" name="channels[]" value="{{ $ck }}" @checked(in_array($ck, $chan, true))> {{ $cl }}</label>@endforeach</div>
</div>
<div class="field span-2"><label>Eligible booked room types <span class="tiny muted">(none ticked = all; for upgrades: every cheaper type)</span></label>
  <div class="flex gap-2 wrap">@foreach($roomTypes as $t)<label class="check"><input type="checkbox" name="room_type_ids[]" value="{{ $t->id }}" @checked(in_array($t->id, $rts, true))> {{ $t->name }}</label>@endforeach</div>
</div>
<x-field name="min_nights" label="Minimum nights" type="number" min="1" max="30" required :value="$o?->min_nights ?? 1"/>
<x-field name="lead_hours" label="Guest self-service closes (hours before arrival)" type="number" min="0" max="720" required :value="$o?->lead_hours ?? 0"/>
<x-field name="daily_cap" label="Daily limit (units per day)" type="number" min="1" max="999" :value="$o?->daily_cap" placeholder="unlimited"/>
<x-field name="sort" label="Display order" type="number" min="0" max="999" :value="$o?->sort ?? 0"/>
<x-field name="valid_from" label="Valid for arrivals from" type="date" :value="$o?->valid_from?->format('Y-m-d')"/>
<x-field name="valid_to" label="Valid for arrivals until" type="date" :value="$o?->valid_to?->format('Y-m-d')"/>
<x-field name="incentive_type" label="Staff incentive (desk sales)" type="select" :options="\App\Models\UpsellOffer::INCENTIVES" :value="$o?->incentive_type ?? 'none'" :placeholder="false" required/>
<x-field name="incentive_value" label="Incentive (% or amount)" type="number" step="0.01" min="0" :value="$o?->incentive_value"/>
<details class="span-2"><summary class="small">Limit to booking sources ({{ count($srcs) ?: 'all' }})</summary>
  <div class="flex gap-2 wrap mt-1">@foreach($sources as $sid => $sn)<label class="check small"><input type="checkbox" name="source_ids[]" value="{{ $sid }}" @checked(in_array((int) $sid, $srcs, true))> {{ $sn }}</label>@endforeach</div>
</details>
<label class="check span-2"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $o ? $o->is_active : true))> On sale</label>
