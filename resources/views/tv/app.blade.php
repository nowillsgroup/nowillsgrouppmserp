<!doctype html>
<html lang="{{ $boot['lang'] }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
<title>{{ $hotel->name }}</title><link rel="stylesheet" href="{{ asset('css/tv.css') }}?v=7.1.0"></head>
<body class="tv tv-th-{{ $boot['welcome']['theme'] ?? 'night' }}">
<div id="tvApp" data-base="{{ url('/tv/'.$token) }}"></div>
<script type="application/json" id="tvBoot">{!! json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) !!}</script>
<noscript><div class="tv-setup"><h1>{{ $hotel->name }}</h1><p class="tv-lead">This TV needs JavaScript switched on.</p></div></noscript>
<script src="{{ asset('js/tv.js') }}?v=7.1.0"></script>
</body></html>
