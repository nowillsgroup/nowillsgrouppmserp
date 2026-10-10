@extends('public.layout')
@section('title', 'Book a table – '.$outlet->name)
@section('content')
@php
  $groups = collect($slots)->map(fn ($s, $t) => $s + ['time' => $t])->groupBy('period');
  $anyOk = collect($slots)->contains('ok', true);
  $why = ['past' => 'passed', 'full' => 'full', 'notice' => 'call us', 'pacing' => 'full'];
@endphp
<div class="card" style="max-width:760px;margin:0 auto">
  <div class="card-head"><h3><x-icon name="cup"/> Book a table</h3>@if($outlets->count() === 1)<span class="muted">{{ $outlet->name }}</span>@endif</div>
  <div class="card-body">
    <form method="get" action="{{ route('dine.show', $hotel->code) }}" class="form-grid" id="dineSearch">
      @if($outlets->count() > 1)<div class="field"><label for="outlet">Restaurant</label><select name="outlet" id="outlet" onchange="this.form.submit()">@foreach($outlets as $o)<option value="{{ $o->id }}" @selected($o->id === $outlet->id)>{{ $o->name }}</option>@endforeach</select></div>@endif
      <div class="field"><label for="date">Date</label><input type="date" name="date" id="date" value="{{ $date->toDateString() }}" min="{{ $min->toDateString() }}" max="{{ $max->toDateString() }}" onchange="this.form.submit()"></div>
      <div class="field"><label for="party">Guests</label><select name="party" id="party" onchange="this.form.submit()">@for($i = 1; $i <= $cfg['max_online_party']; $i++)<option value="{{ $i }}" @selected($i === $party)>{{ $i }} {{ $i === 1 ? 'guest' : 'guests' }}</option>@endfor</select></div>
      <noscript><button class="btn">Show times</button></noscript>
    </form>
    <p class="small muted">Larger group? Please call us{{ $hotel->phone ? ' on '.$hotel->phone : '' }}. @if($cfg['online_note']){{ $cfg['online_note'] }}@endif</p>

    <form method="post" action="{{ route('dine.store', $hotel->code) }}" id="dineForm">@csrf
      <input type="hidden" name="outlet_id" value="{{ $outlet->id }}"><input type="hidden" name="date" value="{{ $date->toDateString() }}"><input type="hidden" name="party_size" value="{{ $party }}">
      <div style="position:absolute;left:-9999px" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></div>
      <h4 class="mt-2">{{ $date->format('l, d F') }} · {{ $party }} {{ $party === 1 ? 'guest' : 'guests' }}</h4>
      @if(!$slots)
        <div class="alert alert-warn"><x-icon name="alert"/><div>{{ $outlet->name }} is closed on this day. Please choose another date.</div></div>
      @elseif(!$anyOk)
        <div class="alert alert-warn" id="fullyBooked"><x-icon name="alert"/><div>Sorry, we are fully booked for {{ $party }} on this day. Please try another date{{ $hotel->phone ? ' or call '.$hotel->phone : '' }}.</div></div>
      @endif
      <div id="slotList">
      @foreach($groups as $period => $list)
        <div class="slot-group"><h4>{{ $period }}</h4><div class="slot-pills">
          @foreach($list as $s)
            @if($s['ok'])<input type="radio" name="time" id="t_{{ str_replace(':', '', $s['time']) }}" value="{{ $s['time'] }}" @checked(old('time') === $s['time']) required><label for="t_{{ str_replace(':', '', $s['time']) }}">{{ $s['time'] }}</label>
            @else<label class="off" title="{{ $why[$s['reason']] ?? '' }}">{{ $s['time'] }}</label>@endif
          @endforeach
        </div></div>
      @endforeach
      </div>
      @if($anyOk)
      <div class="form-grid mt-2">
        <div class="field"><label for="name">Your name *</label><input name="name" id="name" value="{{ old('name') }}" required maxlength="120" autocomplete="name"></div>
        <div class="field"><label for="phone">Mobile *</label><input name="phone" id="phone" value="{{ old('phone') }}" required maxlength="30" autocomplete="tel" inputmode="tel"></div>
        <div class="field"><label for="email">E-mail</label><input type="email" name="email" id="email" value="{{ old('email') }}" maxlength="150" autocomplete="email"><div class="hint">for your confirmation</div></div>
        <div class="field"><label for="occasion">Occasion</label><select name="occasion" id="occasion"><option value="">—</option>@foreach(\App\Models\TableReservation::OCCASIONS as $k => $l)<option value="{{ $k }}" @selected(old('occasion') === $k)>{{ $l }}</option>@endforeach</select></div>
      </div>
      <div class="field mt-2"><label>Anything we should know?</label><div class="tbl-picks">@foreach(['high_chair', 'wheelchair', 'window', 'quiet', 'vegetarian', 'allergy'] as $k)<label><input type="checkbox" name="tags[]" value="{{ $k }}" @checked(in_array($k, (array) old('tags', [])))><span>{{ \App\Models\TableReservation::TAGS[$k] }}</span></label>@endforeach</div></div>
      <div class="field mt-1"><label for="notes">Notes</label><textarea name="notes" id="notes" rows="2" maxlength="500" placeholder="Allergies, celebrations, seating wishes…">{{ old('notes') }}</textarea></div>
      <p class="tiny muted">We use your details only to manage this booking (confirmation and a reminder). The table is kept for {{ $cfg['hold_minutes'] }} minutes after the booking time; you'll have about {{ $duration }} minutes at the table.</p>
      <button class="btn btn-primary btn-block" id="dineSubmit"><x-icon name="check"/> Book my table</button>
      @endif
    </form>
  </div>
</div>
@endsection
