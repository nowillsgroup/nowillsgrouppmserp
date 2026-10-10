@php $E = \App\Models\CroEnquiry::class; $q = $q ?? []; @endphp
<div class="modal" id="croLogModal"><div class="modal-box" style="max-width:48rem"><form method="post" action="{{ route('cro.enquiries.store') }}">@csrf
  <input type="hidden" name="asked_hotel_id" value="{{ hotel()->id }}">
  <div class="modal-head"><h3>Log reservations enquiry</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field"><label>Caller</label><input name="caller_name" required maxlength="120" value="{{ old('caller_name') }}" placeholder="Name of the guest / booker"></div>
    <div class="field"><label>Phone</label><input name="phone" maxlength="30" value="{{ old('phone') }}"></div>
    <div class="field"><label>E-mail</label><input type="email" name="email" maxlength="255" value="{{ old('email') }}"></div>
    <div class="field"><label>Channel</label><select name="channel">@foreach($E::CHANNELS as $k => $l)@continue($k === 'referral')<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label>Arrival</label><input type="date" name="arrival" required value="{{ old('arrival', $q['arrival'] ?? bdate()->toDateString()) }}"></div>
    <div class="field"><label>Departure</label><input type="date" name="departure" required value="{{ old('departure', $q['departure'] ?? bdate()->copy()->addDay()->toDateString()) }}"></div>
    <div class="field"><label>Rooms</label><input type="number" name="rooms" min="1" max="20" value="{{ old('rooms', $q['rooms'] ?? 1) }}"></div>
    <div class="field"><label>Adults / children</label><div class="flex gap-1"><input type="number" name="adults" min="1" max="80" value="{{ old('adults', $q['adults'] ?? 2) }}" aria-label="Adults"><input type="number" name="children" min="0" max="40" value="{{ old('children', $q['children'] ?? 0) }}" aria-label="Children"></div></div>
    <div class="field"><label>Room type asked</label><select name="room_type_id"><option value="">Any</option>@foreach($types as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach</select><span class="tiny muted">for this hotel</span></div>
    <div class="field"><label>Rate quoted / night</label><input type="number" name="quoted_rate" min="0" step="0.01" placeholder="hotel rate"></div>
    <div class="field"><label>Company</label><select name="company_id"><option value="">—</option>@foreach($companies as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></div>
    <div class="field"><label>Call length (min)</label><input type="number" name="duration_min" min="0" max="600" step="0.5"></div>
    <div class="field span-all"><label>Outcome</label><select name="outcome"><option value="open">Open – follow up</option><option value="lost">Lost – denial or regret</option></select></div>
    <div class="field"><label>Follow up by</label><input type="datetime-local" name="follow_up_at"><span class="tiny muted">blank = default</span></div>
    <div class="field"><label>Lost because</label><select name="loss_reason"><option value="">—</option><optgroup label="Denial – we could not offer">@foreach($E::DENIALS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</optgroup><optgroup label="Regret – the guest declined">@foreach($E::REGRETS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</optgroup></select></div>
    <div class="field"><label>Booked with (competitor)</label><select name="competitor_id"><option value="">—</option>@foreach($competitors as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></div>
    <div class="field span-all"><label>Loss note</label><input name="loss_note" maxlength="255" placeholder="e.g. found GH₵ 2,300 at the competitor"></div>
    <div class="field span-all"><label>Notes</label><textarea name="notes" rows="2"></textarea></div>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Log enquiry</button></div>
</form></div></div>
