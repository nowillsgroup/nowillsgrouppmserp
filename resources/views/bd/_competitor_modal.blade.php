@php $P = \App\Models\BdCompetitor::class; $mid = $c ? 'competitorModal'.$c->id : 'competitorModalNew'; @endphp
<div class="modal" id="{{ $mid }}"><div class="modal-box" style="max-width:40rem"><form method="post" action="{{ $c ? route('bd.competitor.update', $c) : route('bd.competitors.store') }}">@csrf @if($c) @method('PUT') @endif
  <div class="modal-head"><h3>{{ $c ? 'Edit '.$c->name : 'Add a hotel to the competitive set' }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field span-all"><label>Hotel</label><input name="name" maxlength="120" required value="{{ $c?->name }}" placeholder="Kempinski Hotel Gold Coast City"></div>
    <div class="field"><label>Stars</label><select name="stars"><option value="">—</option>@foreach([5, 4, 3, 2, 1] as $n)<option value="{{ $n }}" @selected($c?->stars === $n)>{{ $n }} ★</option>@endforeach</select></div>
    <div class="field"><label>Positioning</label><select name="positioning"><option value="">—</option>@foreach($P::POSITIONING as $k => $l)<option value="{{ $k }}" @selected($c?->positioning === $k)>{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label>Rooms</label><input type="number" name="rooms" min="1" max="5000" value="{{ $c?->rooms }}"></div>
    <div class="field"><label>Distance (km)</label><input type="number" name="distance_km" min="0" max="500" step="0.1" value="{{ $c?->distance_km }}"></div>
    <div class="field"><label>Location</label><input name="location" maxlength="120" value="{{ $c?->location }}" placeholder="Ridge, Accra"></div>
    <div class="field"><label>Website</label><input type="url" name="website" maxlength="255" value="{{ $c?->website }}" placeholder="https://"></div>
    <div class="field span-all"><label>Strengths</label><textarea name="strengths" rows="2">{{ $c?->strengths }}</textarea></div>
    <div class="field span-all"><label>Weaknesses</label><textarea name="weaknesses" rows="2">{{ $c?->weaknesses }}</textarea></div>
    @if($c)<div class="field span-all"><label class="dm-check"><input type="checkbox" name="is_active" value="1" @checked($c->is_active)> Active in the competitive set (rate shops and fair share)</label></div>@endif
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Save hotel</button></div>
</form></div></div>
