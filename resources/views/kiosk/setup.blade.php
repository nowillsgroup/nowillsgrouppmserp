<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
<title>{{ $hotel->name }} – kiosk setup</title><link rel="stylesheet" href="{{ asset('css/kiosk.css') }}?v=7.2.0"></head>
<body class="ksk">
<div class="ksk-setup" id="kskSetup" data-start="{{ route('kiosk.pair.start', $code) }}" data-poll="{{ url('/kiosk/pair') }}" data-hotel="{{ $code }}">
  <div class="ksk-brand">{{ $hotel->name }}</div>
  @if($enabled)
  <h1>Kiosk setup</h1>
  <p class="ksk-lead">Ask the front office manager to enter this code in <b>Self-Service Kiosk › Kiosks › Pair a new kiosk</b>.</p>
  <div class="ksk-pair-code" id="kskPairCode">······</div>
  <p class="ksk-muted" id="kskPairMsg">Requesting a code…</p>
  @else
  <h1>Self-service kiosks are switched off</h1><p class="ksk-lead">Turn them on in kiosk settings, then reload this page.</p>
  @endif
</div>
@if($enabled)<script src="{{ asset('js/kiosk.js') }}?v=7.2.0"></script>@endif
</body></html>
