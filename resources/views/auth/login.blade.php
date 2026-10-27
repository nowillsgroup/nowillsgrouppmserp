<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login · Nowills PMS</title>
<link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
<link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}?v={{ filemtime(public_path('favicon.svg')) }}">
</head><body class="auth-login-page">
@include('partials.icons')
<div class="auth auth-login">
  <section class="auth-art auth-login-art" aria-label="Nowills hospitality suite">
    <div class="auth-art-head">
      <div class="brand" style="padding:0;border:0;height:auto"><img class="brand-logo-auth" src="{{ asset('images/brand/nowills-pms-logo-white.png') }}?v=2" alt="Nowills PMS — Cloud Hospitality Suite"></div>
      <span class="auth-live"><i></i> Built for hospitality</span>
    </div>

    <div class="auth-intro">
      <p class="auth-eyebrow">One calm view of the whole property</p>
      <h1>Hospitality operations,<br>beautifully connected.</h1>
      <p>Bring reservations, front desk, housekeeping, dining, events and finance together in one clear view—built for the way hospitality works.</p>
    </div>

    <div class="auth-slider" data-auth-slider aria-roledescription="carousel" aria-label="Hospitality spaces">
      <div class="auth-slides">
        <figure class="auth-slide is-active" data-slide="0" aria-hidden="false">
          <img src="{{ asset('images/login/g1.jpg') }}" alt="Aerial view of The Grove Essipong Beach Resort, Takoradi">
          <figcaption><strong>Resort & recreation</strong><span>See the whole property from one connected view.</span></figcaption>
        </figure>
        <figure class="auth-slide" data-slide="1" aria-hidden="true">
          <img src="{{ asset('images/login/g3.jpg') }}" alt="The Grove Essipong Beach Resort exterior">
          <figcaption><strong>Hospitality, rooted here</strong><span>One connected system for every part of the resort.</span></figcaption>
        </figure>
        <figure class="auth-slide" data-slide="2" aria-hidden="true">
          <img src="{{ asset('images/login/g4.jpg') }}" alt="Conference hall at The Grove Essipong Beach Resort">
          <figcaption><strong>Conference & events</strong><span>Make every moment count at The Grove.</span></figcaption>
        </figure>
      </div>
      <div class="auth-slider-bar">
        <div class="auth-slider-buttons">
          <button type="button" class="auth-slider-btn" data-auth-prev aria-label="Previous image">‹</button>
          <button type="button" class="auth-slider-btn" data-auth-next aria-label="Next image">›</button>
        </div>
        <div class="auth-dots" role="tablist" aria-label="Choose a hospitality image">
          <button type="button" class="auth-dot is-active" data-auth-dot="0" role="tab" aria-label="Show image 1" aria-selected="true"></button>
          <button type="button" class="auth-dot" data-auth-dot="1" role="tab" aria-label="Show image 2" aria-selected="false"></button>
          <button type="button" class="auth-dot" data-auth-dot="2" role="tab" aria-label="Show image 3" aria-selected="false"></button>
        </div>
        <span class="auth-slider-count" data-auth-count>01 / 03</span>
      </div>
    </div>

    <div class="auth-tiles">
      <div><x-icon name="hotel"/><br>Hotels</div><div><x-icon name="cup"/><br>Restaurants</div><div><x-icon name="tree"/><br>Resorts</div><div><x-icon name="house"/><br>Guest Houses</div><div><x-icon name="coffee"/><br>Cafés</div>
    </div>
  </section>
  <main class="auth-form auth-login-form">
    <form class="auth-card auth-login-card" method="post" action="{{ route('login.attempt') }}" autocomplete="on">
      @csrf
      <div class="auth-form-kicker"><span class="auth-kicker-dot" aria-hidden="true"></span> PROPERTY TEAM ACCESS</div>
      <h2>Welcome back</h2>
      <p class="auth-login-subtitle">Sign in with your hotel or group credentials.</p>
      @if($errors->any())<div class="alert alert-error auth-login-error" role="alert"><x-icon name="alert"/><div>{{ $errors->first() }}</div></div>@endif

      <div class="field auth-login-field">
        <label for="hotel_code" id="codeLabel">{{ old('group_login') ? 'Group ID' : 'Hotel ID' }}</label>
        <input class="input" id="hotel_code" name="hotel_code" value="{{ old('hotel_code') }}" placeholder="e.g. NOWILLS" required autofocus autocomplete="organization" autocapitalize="characters" spellcheck="false" aria-describedby="hotelCodeHint">
        <small class="auth-field-hint" id="hotelCodeHint">Use the ID provided by your property administrator.</small>
      </div>
      <div class="field auth-login-field">
        <label for="username">Username</label>
        <input class="input" id="username" name="username" value="{{ old('username') }}" placeholder="Your username" required autocomplete="username" autocapitalize="none" spellcheck="false">
      </div>
      <div class="field auth-login-field">
        <label for="password">Password</label>
        <div class="auth-password-wrap">
          <input class="input" id="password" type="password" name="password" placeholder="Your password" required autocomplete="current-password">
          <button class="auth-password-toggle" type="button" data-toggle-password aria-label="Show password" aria-pressed="false">Show</button>
        </div>
      </div>
      <div class="auth-login-options">
        <label class="check" for="group_login"><input id="group_login" type="checkbox" name="group_login" value="1" @checked(old('group_login'))> Group login</label>
        <label class="check" for="remember"><input id="remember" type="checkbox" name="remember" value="1" @checked(old('remember'))> Remember me</label>
      </div>
      <button class="btn btn-primary btn-lg btn-block auth-login-submit" type="submit"><x-icon name="login"/> Sign in</button>
      <div class="auth-login-help"><span class="auth-lock" aria-hidden="true">✓</span><span>Access is managed by your property administrator.</span></div>
      @if(app()->environment('local'))
      <div class="alert alert-info small mt-2"><div><b>Demo:</b> Hotel ID <code>NOWILLS</code> · user <code>admin</code> · pass <code>Admin@123</code><br>Group: tick <i>Group login</i>, ID <code>NWGROUP</code> · <code>groupadmin</code> · <code>Admin@123</code></div></div>
      @endif
    </form>
  </main>
</div>
<script>
(() => {
  const group = document.getElementById('group_login');
  const codeLabel = document.getElementById('codeLabel');
  const password = document.getElementById('password');
  const passwordToggle = document.querySelector('[data-toggle-password]');
  const syncCodeLabel = () => { if (codeLabel) codeLabel.textContent = group?.checked ? 'Group ID' : 'Hotel ID'; };
  group?.addEventListener('change', syncCodeLabel);
  syncCodeLabel();
  passwordToggle?.addEventListener('click', () => {
    if (!password) return;
    const reveal = password.type === 'password';
    password.type = reveal ? 'text' : 'password';
    passwordToggle.textContent = reveal ? 'Hide' : 'Show';
    passwordToggle.setAttribute('aria-label', reveal ? 'Hide password' : 'Show password');
    passwordToggle.setAttribute('aria-pressed', String(reveal));
  });
})();

(() => {
  const root = document.querySelector('[data-auth-slider]');
  if (!root) return;
  const slides = [...root.querySelectorAll('.auth-slide')];
  const dots = [...root.querySelectorAll('[data-auth-dot]')];
  const count = root.querySelector('[data-auth-count]');
  let active = 0;
  let timer;

  const show = (next) => {
    active = (next + slides.length) % slides.length;
    slides.forEach((slide, index) => {
      const selected = index === active;
      slide.classList.toggle('is-active', selected);
      slide.setAttribute('aria-hidden', selected ? 'false' : 'true');
    });
    dots.forEach((dot, index) => {
      const selected = index === active;
      dot.classList.toggle('is-active', selected);
      dot.setAttribute('aria-selected', selected ? 'true' : 'false');
    });
    if (count) count.textContent = String(active + 1).padStart(2, '0') + ' / ' + String(slides.length).padStart(2, '0');
  };
  const stop = () => clearInterval(timer);
  const start = () => {
    stop();
    if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) timer = setInterval(() => show(active + 1), 6500);
  };

  root.querySelector('[data-auth-prev]')?.addEventListener('click', () => { show(active - 1); start(); });
  root.querySelector('[data-auth-next]')?.addEventListener('click', () => { show(active + 1); start(); });
  dots.forEach(dot => dot.addEventListener('click', () => { show(Number(dot.dataset.authDot)); start(); }));
  root.addEventListener('mouseenter', stop);
  root.addEventListener('mouseleave', start);
  root.addEventListener('focusin', stop);
  root.addEventListener('focusout', start);
  start();
})();
</script>
</body></html>
