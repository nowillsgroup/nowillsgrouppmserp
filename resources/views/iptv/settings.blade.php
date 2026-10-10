@extends('layouts.app')
@section('title', 'IPTV Settings')
@section('crumbs') IPTV &amp; Guest Welcome / Settings @endsection
@php $IS = \App\Services\IptvService::class; $L = \App\Support\TvLang::LANGS; $cb = fn ($k, $label) => '<label class="small tv-cb"><input type="checkbox" name="'.$k.'" value="1" '.(($raw['tv_'.$k] ?? '0') === '1' ? 'checked' : '').'> '.e($label).'</label>'; @endphp
@section('content')
<form method="post" action="{{ route('iptv.settings.save') }}" id="tvSettingsForm">@csrf
<div class="g-2">
  <div>
    <div class="card"><div class="card-head"><h3>Guest portal on the TV</h3></div><div class="card-body form-grid">
      <div class="span-all">{!! $cb('enabled', 'TV portal switched on (off: TVs show a plain welcome only)') !!}{!! $cb('welcome_on', 'Show the personalised welcome screen once per stay') !!}{!! $cb('reset_on_checkout', 'Reset the TV at check-out (clears name, rentals, bill – recommended)') !!}</div>
      <div class="field"><label>Guest name on screen</label><select name="name_mode">@foreach($IS::NAME_MODES as $k => $l)<option value="{{ $k }}" @selected($cfg['name_mode'] === $k)>{{ $l }}</option>@endforeach</select></div>
      <div class="field"><label>Default language</label><select name="default_lang">@foreach($L as $k => $l)<option value="{{ $k }}" @selected($cfg['default_lang'] === $k)>{{ $l }}</option>@endforeach</select></div>
      <div class="field span-all"><label>Languages offered</label><div>@foreach($L as $k => $l)<label class="small tv-cb"><input type="checkbox" name="langs[]" value="{{ $k }}" @checked(in_array($k, $cfg['langs'], true))> {{ $l }}</label>@endforeach</div></div>
      <div class="span-all">{!! $cb('dining', 'In-room dining menu & ordering') !!}{!! $cb('requests', 'Service requests & wake-up calls') !!}{!! $cb('checkout', 'Express check-out request') !!}{!! $cb('feedback', 'Star-rating feedback') !!}{!! $cb('weather', 'Weather (open-meteo.com, no key needed)') !!}</div>
      <div class="field"><label>Front-desk extension shown</label><input name="desk_ext" maxlength="8" value="{{ $raw['tv_desk_ext'] }}"></div>
      <div class="field"><label>Latitude / longitude (weather)</label><div class="flex" style="gap:.3rem"><input name="lat" value="{{ $raw['tv_lat'] }}"><input name="lon" value="{{ $raw['tv_lon'] }}"></div></div>
    </div></div>
    <div class="card"><div class="card-head"><h3>Movies & privacy</h3></div><div class="card-body form-grid">
      <div class="span-all">{!! $cb('vod_enabled', 'Movie rentals (charged to the room folio)') !!}{!! $cb('pin_purchase', 'Rentals need the room PIN (given at check-in)') !!}{!! $cb('pin_bill', 'Viewing the bill needs the room PIN') !!}{!! $cb('adult', 'Offer adult titles (always PIN-protected)') !!}</div>
      <div class="field"><label>Max rentals per room per day</label><input type="number" name="max_rentals" min="0" max="20" value="{{ $raw['tv_max_rentals'] }}"></div>
      <div class="field"><label>Keep usage data (days)</label><input type="number" name="event_days" min="30" max="730" value="{{ $raw['tv_event_days'] }}"></div>
    </div></div>
  </div>
  <div>
    <div class="card"><div class="card-head"><h3>TVs</h3></div><div class="card-body form-grid">
      <div class="field"><label>Heartbeat (seconds)</label><input type="number" name="heartbeat" min="10" max="120" value="{{ $raw['tv_heartbeat'] }}"></div>
      <div class="field"><label>Offline after (minutes)</label><input type="number" name="offline_min" min="2" max="240" value="{{ $raw['tv_offline_min'] }}"></div>
      <div class="field"><label>Volume limit</label><input type="number" name="volume_max" min="10" max="100" value="{{ $raw['tv_volume_max'] }}"></div>
      <div class="field"><label>Power-on channel</label><select name="power_channel"><option value="">Home menu</option>@foreach($channels as $c)<option value="{{ $c->number }}" @selected((string) $raw['tv_power_channel'] === (string) $c->number)>{{ $c->number }} {{ $c->name }}</option>@endforeach</select></div>
      <div class="field span-all"><label>Setup address for new TVs</label><code class="tv-url">{{ $setupUrl }}</code></div>
    </div></div>
    <div class="card"><div class="card-head"><h3>Lobby & signage screens</h3></div><div class="card-body form-grid">
      <div class="span-all">{!! $cb('signage_events', 'Show today’s banquet & conference events') !!}</div>
      <div class="field span-all"><label>Scrolling text</label><textarea name="signage_text" rows="3" maxlength="300">{{ $raw['tv_signage_text'] }}</textarea></div>
    </div></div>
  </div>
</div>
<button class="btn btn-primary">Save settings</button>
</form>
@endsection
