<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login · Nowills PMS</title>
<link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head><body>
@include('partials.icons')
<div class="auth">
  <section class="auth-art" aria-label="Nowills hospitality suite">
    <div class="auth-art-head">
      <div class="brand" style="padding:0;border:0;height:auto"><div class="brand-mark">N</div><div>Nowills PMS<small>CLOUD HOSPITALITY SUITE</small></div></div>
      <span class="auth-live"><i></i> Built for hospitality</span>
    </div>

    <div class="auth-intro">
      <p class="auth-eyebrow">One calm view of the whole property</p>
      <h1>Run every room, table and event from one screen.</h1>
      <p>Reservations, front desk, housekeeping, POS, banquets, inventory, accounts and night audit — unified for hotels, resorts, guest houses, restaurants and cafés.</p>
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
  <main class="auth-form">
    <form class="auth-card" method="post" action="{{ route('login.attempt') }}" autocomplete="off">
      @csrf
      <div class="auth-form-kicker">Welcome back</div>
      <h2>Login to your account</h2>
      <p class="text-2 mb-2">Enter your property credentials to continue.</p>
      @if($errors->any())<div class="alert alert-error"><x-icon name="alert"/><div>{{ $errors->first() }}</div></div>@endif
      <div class="field"><label for="hotel_code" id="codeLabel">Hotel ID / Group ID</label><input class="input" id="hotel_code" name="hotel_code" value="{{ old('hotel_code') }}" required autofocus></div>
      <div class="field"><label for="username">Username</label><input class="input" id="username" name="username" value="{{ old('username') }}" required></div>
      <div class="field"><label for="password">Password</label><input class="input" id="password" type="password" name="password" required autocomplete="current-password"></div>
      <div class="flex justify-between items-center mb-2">
        <label class="check"><input type="checkbox" name="group_login" value="1" @checked(old('group_login')) onchange="document.getElementById('codeLabel').textContent=this.checked?'Group ID':'Hotel ID'"> Group Login</label>
        <label class="check"><input type="checkbox" name="remember" value="1"> Remember me</label>
      </div>
      <button class="btn btn-primary btn-lg btn-block" type="submit"><x-icon name="login"/> Sign In</button>
      <p class="small muted mt-2 center">By signing in you accept the Terms of Service.</p>
      @if(app()->environment('local'))
      <div class="alert alert-info small mt-2"><div><b>Demo:</b> Hotel ID <code>NOWILLS</code> · user <code>admin</code> · pass <code>Admin@123</code><br>Group: tick <i>Group Login</i>, ID <code>NWGROUP</code> · <code>groupadmin</code> · <code>Admin@123</code></div></div>
      @endif
    </form>
  </main>
</div>
<script>
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
