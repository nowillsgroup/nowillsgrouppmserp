<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no"><meta name="robots" content="noindex, nofollow">
<title>{{ $hotel->name }} – self-service</title><link rel="stylesheet" href="{{ asset('css/kiosk.css') }}?v=7.2.0"></head>
<body class="ksk">
<div id="ksk" data-base="{{ $base }}" data-idle="{{ $cfg['idle'] }}" data-hotel="{{ $hotel->name }}" data-currency="GH₵">
  <header class="ksk-top">
    <div class="ksk-logo"><span class="ksk-mark">{{ mb_substr($hotel->name, 0, 1) }}</span><div><b>{{ $hotel->name }}</b><small id="kskWhere">{{ $kiosk->name }}</small></div></div>
    <div class="ksk-steps" id="kskSteps"></div>
    <div class="ksk-top-r"><button type="button" class="ksk-ghost" id="kskHelp" hidden>Call for help</button><button type="button" class="ksk-ghost" id="kskRestart" hidden>Start over</button><div class="ksk-clock" id="kskClock"></div></div>
  </header>
  <main class="ksk-main" id="kskMain" aria-live="polite"><div class="ksk-center"><div class="ksk-spin"></div></div></main>
  <div class="ksk-toast" id="kskToast" role="alert" hidden></div>
  <div class="ksk-idle" id="kskIdle" hidden><div class="ksk-card"><h2>Are you still there?</h2><p>This screen will start over in <b id="kskIdleN">15</b> seconds.</p><button type="button" class="ksk-btn" id="kskIdleStay">I'm still here</button></div></div>
  <div class="ksk-kb" id="kskKb" hidden></div>
</div>
<script src="{{ asset('js/kiosk.js') }}?v=7.2.0"></script>
</body></html>
