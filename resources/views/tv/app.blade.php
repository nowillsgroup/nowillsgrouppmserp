<!doctype html>
<html lang="{{ $boot['lang'] ?? 'en' }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
<title>{{ $hotel->name }}</title><link rel="stylesheet" href="{{ asset('css/tv.css') }}?v={{ config('pms.version') }}-luxury-welcome-full-home-preview">
<style>
/* Server-rendered first-paint welcome: keeps the TV useful if a kiosk blocks or delays JavaScript. */
#tvPreboot{position:fixed;inset:0;z-index:9999;display:flex;overflow:hidden;background:#152321;color:#fff;font-family:"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif}
#tvPreboot,#tvPreboot *{box-sizing:border-box}
#tvPreboot .tv-welcome-backdrop{position:absolute;inset:0;background:#172522}
#tvPreboot .tv-welcome-photo{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;object-position:center 50%}
#tvPreboot .tv-welcome-shade{position:absolute;inset:0;background:linear-gradient(90deg,rgba(5,14,16,.76),rgba(6,15,17,.65) 37%,rgba(6,15,17,.4) 63%,rgba(4,12,13,.14)),linear-gradient(0deg,rgba(4,10,11,.56),transparent 38%),linear-gradient(180deg,rgba(4,10,11,.32),transparent 24%)}
#tvPreboot .tv-welcome-frame{position:absolute;inset:1rem;border:1px solid rgba(239,220,180,.3);border-radius:.35rem;pointer-events:none}
#tvPreboot .tv-welcome-layout{position:relative;z-index:1;display:grid;flex:1;min-height:0;width:100%;grid-template-columns:minmax(0,1fr) minmax(17rem,25rem);grid-template-rows:auto minmax(0,1fr) auto;column-gap:4vw;padding:2rem 5.2vw 1.9rem}
#tvPreboot .tv-welcome-brand{grid-column:1;grid-row:1;display:flex;align-items:center;gap:.75rem;align-self:start;max-width:70%;min-width:0}
#tvPreboot .tv-welcome-logo-frame,#tvPreboot .tv-welcome-monogram{display:flex;align-items:center;justify-content:center;flex:none;width:2.65rem;height:2.65rem;border:1px solid #ead4a0;border-radius:50%;background:rgba(8,18,19,.4);color:#ead4a0;font-family:Georgia,serif;font-size:1.35rem}
#tvPreboot .tv-welcome-logo{max-width:1.8rem;max-height:1.8rem;object-fit:contain}
#tvPreboot .tv-welcome-brand-text{display:flex;flex-direction:column;gap:.12rem;min-width:0}
#tvPreboot .tv-welcome-brand-text small{color:#ead4a0;font-size:.63rem;font-weight:700;letter-spacing:.2em;text-transform:uppercase}
#tvPreboot .tv-welcome-brand-text strong{overflow:hidden;color:#fff;font-size:1.02rem;font-weight:650;letter-spacing:.035em;text-overflow:ellipsis;white-space:nowrap}
#tvPreboot .tv-welcome-main{grid-column:1;grid-row:2;align-self:center;max-width:58rem;padding:.7rem 0 1rem}
#tvPreboot .tv-welcome-kicker{margin:0 0 .8rem;color:#ead4a0;font-size:.72rem;font-weight:750;letter-spacing:.21em;line-height:1.4;text-transform:uppercase}
#tvPreboot .tv-welcome-rule{display:block;width:3.15rem;height:.12rem;margin:.2rem 0 1.1rem;background:#ead4a0}
#tvPreboot .tv-welcome-h{max-width:58rem;margin:.15rem 0 .8rem;color:#fff8e9;font-family:Georgia,"Times New Roman",serif;font-size:4.4rem;font-weight:400;letter-spacing:-.035em;line-height:1.07;text-shadow:0 .2rem 1.25rem rgba(0,0,0,.44)}
#tvPreboot .tv-welcome-body{max-width:45rem}
#tvPreboot .tv-welcome-body p{margin:.35rem 0;color:rgba(255,255,255,.9);font-size:1.15rem;line-height:1.52;text-shadow:0 .1rem .6rem rgba(0,0,0,.3)}
#tvPreboot .tv-welcome-stay-card{grid-column:2;grid-row:2;align-self:end;width:100%;margin-bottom:1.1rem;padding:1.2rem 1.25rem;border:1px solid rgba(239,220,180,.38);border-radius:1rem;background:linear-gradient(145deg,rgba(14,27,27,.84),rgba(14,25,24,.66));box-shadow:0 1rem 2.8rem rgba(0,0,0,.24);backdrop-filter:blur(9px)}
#tvPreboot .tv-welcome-card-eyebrow{margin-bottom:.8rem;color:#ead4a0;font-size:.65rem;font-weight:800;letter-spacing:.19em;text-transform:uppercase}
#tvPreboot .tv-welcome-stay-details{display:grid;grid-template-columns:1fr 1fr;gap:.8rem;padding-bottom:.9rem}
#tvPreboot .tv-welcome-stay-detail{display:flex;flex-direction:column;gap:.22rem;min-width:0}
#tvPreboot .tv-welcome-stay-detail small{color:rgba(255,255,255,.64);font-size:.68rem;letter-spacing:.08em;text-transform:uppercase}
#tvPreboot .tv-welcome-stay-detail strong{overflow:hidden;color:#fff;font-size:1.1rem;font-weight:650;text-overflow:ellipsis;white-space:nowrap}
#tvPreboot .tv-welcome-wifi{padding-top:.85rem;border-top:1px solid rgba(255,255,255,.16)}
#tvPreboot .tv-welcome-wifi-label{display:flex;align-items:center;gap:.45rem;color:rgba(255,255,255,.76);font-size:.8rem}
#tvPreboot .tv-welcome-wifi-icon{color:#ead4a0;font-size:1.1rem}
#tvPreboot .tv-welcome-wifi-name{display:block;margin-top:.32rem;color:#fff;font-size:1rem}
#tvPreboot .tv-welcome-wifi-password{display:block;margin-top:.18rem;color:rgba(255,255,255,.7);font-size:.75rem}
#tvPreboot .tv-welcome-actions{grid-column:1;grid-row:3;display:flex;flex-direction:column;align-items:flex-start;gap:.65rem;align-self:end;min-width:0}
#tvPreboot .tv-welcome-continue{display:inline-flex;align-items:center;min-width:11rem;width:max-content;padding:.9rem 2rem;border:1px solid #ead4a0;border-radius:999px;background:#ead4a0;color:#17201e;font-size:1rem;font-weight:750}
#tvPreboot .tv-welcome-language{display:flex;flex-direction:column;gap:.28rem}
#tvPreboot .tv-welcome-language-label{color:rgba(255,255,255,.66);font-size:.62rem;letter-spacing:.16em;text-transform:uppercase}
#tvPreboot .tv-welcome-language-list{display:flex;gap:.35rem;flex-wrap:wrap}
#tvPreboot .tv-welcome-language-list .tv-btn{padding:.3rem .7rem;border:1px solid rgba(255,255,255,.23);border-radius:999px;background:rgba(7,15,16,.42);color:#fff;font-size:.7rem}
#tvPreboot .tv-welcome-language-list .tv-btn.on{border-color:#ead4a0;background:rgba(234,212,160,.18)}
#tvPreboot .tv-welcome-footer{grid-column:2;grid-row:3;display:flex;align-items:center;justify-content:flex-end;gap:.65rem;align-self:end;padding-bottom:.35rem;color:rgba(255,255,255,.76);font-size:.63rem;font-weight:650;letter-spacing:.16em;text-align:right;text-transform:uppercase}
#tvPreboot .tv-welcome-footer-line{display:block;width:1.8rem;height:1px;background:#ead4a0}
#tvPreboot.tv-welcome-layout-vacant .tv-welcome-layout{grid-template-columns:minmax(0,1fr);grid-template-rows:auto minmax(0,1fr) auto}
#tvPreboot .tv-welcome-main-vacant{grid-column:1;max-width:60rem}
#tvPreboot .tv-welcome-main-vacant .tv-welcome-h{font-size:4.7rem}
#tvPreboot .tv-welcome-main-vacant .tv-welcome-actions{margin-top:1.25rem}
#tvPreboot.tv-welcome-layout-vacant .tv-welcome-footer{grid-column:1;grid-row:3;justify-content:flex-start;text-align:left}
@media(max-width:1050px){#tvPreboot .tv-welcome-layout{grid-template-columns:minmax(0,1fr) minmax(14rem,21rem);column-gap:2.5vw;padding:1.45rem 4vw 1.35rem}#tvPreboot .tv-welcome-h{font-size:3.7rem}#tvPreboot .tv-welcome-stay-card{padding:1rem}}
@media(max-height:700px){#tvPreboot .tv-welcome-layout{padding-top:1.1rem;padding-bottom:1rem}#tvPreboot .tv-welcome-h{font-size:3.2rem;margin:.1rem 0 .45rem}#tvPreboot .tv-welcome-stay-card{margin-bottom:.4rem;padding:.8rem 1rem}}
@media(max-width:760px){#tvPreboot .tv-welcome-layout{grid-template-columns:minmax(0,1fr);grid-template-rows:auto minmax(0,1fr) auto auto;padding:1.4rem 5vw 1.25rem}#tvPreboot .tv-welcome-main{grid-column:1;grid-row:2}#tvPreboot .tv-welcome-h{font-size:3.2rem}#tvPreboot .tv-welcome-stay-card{grid-column:1;grid-row:3;display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin:.1rem 0 .6rem;padding:.7rem .85rem}#tvPreboot .tv-welcome-card-eyebrow{grid-column:1/-1;margin:0}#tvPreboot .tv-welcome-stay-details{padding:0}#tvPreboot .tv-welcome-wifi{padding:0 0 0 .75rem;border-top:0;border-left:1px solid rgba(255,255,255,.16)}#tvPreboot .tv-welcome-actions{grid-column:1;grid-row:4;gap:.45rem}#tvPreboot .tv-welcome-footer{display:none}#tvPreboot.tv-welcome-layout-vacant .tv-welcome-footer{display:flex;grid-row:3}}
</style></head>
@php
    $welcomeImagePath = $boot['hotel']['welcome_image'] ?? 'images/iptv/groove-esipong-beach-hotel.jpg';
    $welcomeImageUrl = parse_url(asset($welcomeImagePath), PHP_URL_PATH) ?: '/'.ltrim($welcomeImagePath, '/');
    $welcome = $boot['welcome'] ?? [];
    $guest = $boot['guest'] ?? [];
    $wifi = $boot['wifi'] ?? [];
    $prebootWelcome = ($boot['mode'] ?? '') === 'room' && (bool) ($welcome['show'] ?? false);
    $prebootVacant = ($boot['mode'] ?? '') === 'vacant';
    $prebootClass = ($prebootWelcome || $prebootVacant) ? ' tv-welcome-mode' : '';
@endphp
<body class="tv tv-th-{{ $welcome['theme'] ?? 'night' }}{{ $prebootClass }}">
<div id="tvApp" data-base="{{ url('/tv/'.$token) }}" data-welcome-image="{{ $welcomeImageUrl }}" data-hotel-logo="{{ $hotel->logo_path ? asset($hotel->logo_path) : '' }}">
@if($prebootWelcome || $prebootVacant)
  <section class="tv-welcome-screen{{ $prebootVacant ? ' tv-welcome-layout-vacant' : '' }}" id="tvPreboot">
    <div class="tv-welcome-backdrop" aria-hidden="true"><img class="tv-welcome-photo" src="{{ $welcomeImageUrl }}" alt=""><div class="tv-welcome-shade"></div><div class="tv-welcome-frame"></div></div>
    <div class="tv-welcome-layout{{ $prebootVacant ? ' tv-welcome-layout-vacant' : '' }}">
      <div class="tv-welcome-brand">
        @if($hotel->logo_path)<span class="tv-welcome-logo-frame"><img class="tv-welcome-logo" src="{{ asset($hotel->logo_path) }}" alt=""></span>@else<span class="tv-welcome-monogram" aria-hidden="true">✦</span>@endif
        <span class="tv-welcome-brand-text"><small>{{ $boot['t']['welcome'] ?? 'Welcome' }}</small><strong>{{ $hotel->name }}</strong></span>
      </div>
      @if($prebootWelcome)
        <div class="tv-welcome-main">
          <p class="tv-welcome-kicker">{{ !empty($boot['hotel']['city']) ? mb_strtoupper($boot['hotel']['city']).' · A WARM WELCOME' : 'A WARM WELCOME' }}</p><span class="tv-welcome-rule" aria-hidden="true"></span>
          <h1 class="tv-welcome-h">{{ $welcome['headline'] ?? ($boot['t']['welcome'] ?? 'Welcome') }}</h1>
          <div class="tv-welcome-body">@foreach(preg_split('/\r\n|\r|\n/', (string) ($welcome['body'] ?? '')) as $line)@if(trim($line) !== '')<p>{{ $line }}</p>@endif @endforeach</div>
        </div>
        <div class="tv-welcome-stay-card">
          <div class="tv-welcome-card-eyebrow">YOUR STAY</div>
          <div class="tv-welcome-stay-details">
            @if(!empty($guest['room']))<div class="tv-welcome-stay-detail"><small>{{ $boot['t']['room'] ?? 'Room' }}</small><strong>{{ $guest['room'] }}</strong></div>@endif
            @if(!empty($guest['departure']))<div class="tv-welcome-stay-detail"><small>{{ $boot['t']['departure'] ?? 'Departure' }}</small><strong>{{ $guest['departure'] }}</strong></div>@endif
          </div>
          @if(!empty($wifi['name']))<div class="tv-welcome-wifi"><div class="tv-welcome-wifi-label"><span class="tv-welcome-wifi-icon">◔</span><span>{{ $boot['t']['wifi'] ?? 'Wi-Fi' }}</span></div><strong class="tv-welcome-wifi-name">{{ $wifi['name'] }}</strong>@if(!empty($wifi['password']))<span class="tv-welcome-wifi-password">{{ $boot['t']['password'] ?? 'Password' }} · {{ $wifi['password'] }}</span>@endif</div>@endif
        </div>
        <div class="tv-welcome-actions"><span class="tv-btn tv-big tv-primary tv-welcome-continue">{{ $boot['t']['continue'] ?? 'Continue' }} ›</span><div class="tv-welcome-language"><span class="tv-welcome-language-label">{{ $boot['t']['language'] ?? 'Language' }}</span><div class="tv-welcome-language-list">@foreach(($boot['langs'] ?? []) as $code => $label)<span class="tv-btn{{ $code === ($boot['lang'] ?? '') ? ' on' : '' }}">{{ $label }}</span>@endforeach</div></div></div>
      @else
        <div class="tv-welcome-main tv-welcome-main-vacant"><p class="tv-welcome-kicker">{{ ($boot['t']['vacant'] ?? 'Welcome to').(!empty($boot['hotel']['city']) ? ' '.$boot['hotel']['city'] : '') }}</p><span class="tv-welcome-rule" aria-hidden="true"></span><h1 class="tv-welcome-h">{{ $hotel->name }}</h1><div class="tv-welcome-actions"><span class="tv-btn tv-big tv-primary tv-welcome-continue">📺 {{ $boot['t']['tv'] ?? 'Live TV' }}</span></div></div>
      @endif
      <div class="tv-welcome-footer"><span class="tv-welcome-footer-line"></span><span>{{ !empty($boot['hotel']['city']) ? mb_strtoupper($boot['hotel']['city']).' · GHANA' : 'A PLACE TO UNWIND' }}</span></div>
    </div>
  </section>
@endif
</div>
<script type="application/json" id="tvBoot">{!! json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) !!}</script>
<noscript><div class="tv-setup"><h1>{{ $hotel->name }}</h1><p class="tv-lead">This TV needs JavaScript switched on.</p></div></noscript>
<script src="{{ asset('js/tv.js') }}?v={{ config('pms.version') }}-luxury-welcome-full-home-preview"></script>
</body></html>
