<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
<title>{{ $hotel->name }} – TV setup</title><link rel="stylesheet" href="{{ asset('css/tv.css') }}?v=7.1.0"></head>
<body class="tv tv-th-night">
<div class="tv-setup" id="tvSetup" data-start="{{ route('tv.pair.start', $code) }}" data-poll="{{ url('/tv/pair') }}" data-hotel="{{ $code }}">
  <div class="tv-brand">{{ $hotel->name }}</div>
  @if($enabled)
  <h1>TV setup</h1>
  <p class="tv-lead">Ask the hotel IT / engineering team to enter this pairing code in <b>IPTV › TVs &amp; Screens › Pair a TV</b>.</p>
  <div class="tv-pair-code" id="tvPairCode">······</div>
  <p class="tv-muted" id="tvPairMsg">Requesting a code…</p>
  @else
  <h1>The TV portal is switched off</h1><p class="tv-lead">Turn it on in IPTV settings, then reload this page.</p>
  @endif
</div>
@if($enabled)<script src="{{ asset('js/tv.js') }}?v=7.1.0"></script>@endif
</body></html>
