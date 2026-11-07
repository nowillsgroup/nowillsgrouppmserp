/* Nowills PMS v7.1 – in-room TV portal & lobby signage.
 * Runs on hotel TVs (Samsung Tizen hotel mode, LG webOS Pro:Centric, Philips, Android launchers) and kiosk browsers.
 * ES5 + XHR on purpose: hotel TV browsers can be several years old. D-pad spatial navigation; Back = Esc / Backspace / 10009 (Tizen) / 461 (webOS).
 * All data comes from /tv/{token}/… (stateless, the device token in the URL). Text is always set with textContent – never innerHTML. */
(function () {
  'use strict';

  // ───────────────────────── helpers ─────────────────────────
  function $(id) { return document.getElementById(id); }
  function el(tag, attrs, kids) {
    var e = (tag === 'svg' || tag === 'use') ? document.createElementNS('http://www.w3.org/2000/svg', tag) : document.createElement(tag), k;
    attrs = attrs || {};
    for (k in attrs) {
      if (!attrs.hasOwnProperty(k) || attrs[k] === null || attrs[k] === undefined || attrs[k] === false) continue;
      if (k === 'text') e.textContent = attrs[k];
      else if (k === 'cls') e.className = attrs[k];
      else if (k === 'on') e.addEventListener('click', attrs[k]);
      else if (k === 'style') e.setAttribute('style', attrs[k]);
      else if (k === 'xlink:href') e.setAttributeNS('http://www.w3.org/1999/xlink', k, attrs[k]);
      else e.setAttribute(k, attrs[k] === true ? '' : attrs[k]);
    }
    (kids || []).forEach(function (c) { if (c !== null && c !== undefined && c !== false) e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return e;
  }
  function btn(label, fn, cls, extra) {
    var a = { cls: 'tv-btn ' + (cls || ''), 'data-f': '1', tabindex: '0', on: fn, text: label };
    for (var k in (extra || {})) a[k] = extra[k];
    return el('button', a);
  }
  function clear(n) { while (n.firstChild) n.removeChild(n.firstChild); return n; }
  function req(method, url, body, cb) {
    var x = new XMLHttpRequest();
    x.open(method, url, true);
    x.setRequestHeader('Accept', 'application/json');
    if (body) x.setRequestHeader('Content-Type', 'application/json');
    x.timeout = 15000;
    x.onreadystatechange = function () {
      if (x.readyState !== 4) return;
      var d = null;
      try { d = JSON.parse(x.responseText); } catch (e) { d = null; }
      cb(x.status, d);
    };
    x.send(body ? JSON.stringify(body) : null);
  }
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function tvIcon(name, cls) {
    return el('svg', { cls: cls || 'tv-tile-svg', viewBox: '0 0 24 24', 'xmlns:xlink': 'http://www.w3.org/1999/xlink', fill: 'none', stroke: 'currentColor', 'stroke-width': '1.55', 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'aria-hidden': 'true', focusable: 'false' }, [
      el('use', { href: '#tv-glyph-' + name, 'xlink:href': '#tv-glyph-' + name }),
    ]);
  }

  // ───────────────────────── setup / pairing page ─────────────────────────
  var setup = $('tvSetup');
  if (setup) {
    var codeEl = $('tvPairCode'), msg = $('tvPairMsg'), poll = setup.getAttribute('data-poll'), key = 'tvPair:' + setup.getAttribute('data-hotel');
    var start = function () {
      req('POST', setup.getAttribute('data-start'), {}, function (s, d) {
        if (s !== 200 || !d || !d.token) { msg.textContent = (d && d.error) || 'Could not get a code – retrying…'; setTimeout(start, 15000); return; }
        try { localStorage.setItem(key, d.token); } catch (e) { /* private mode */ }
        codeEl.textContent = d.code;
        msg.textContent = 'Waiting for the code to be entered…';
        wait(d.token);
      });
    };
    var wait = function (token) {
      req('GET', poll + '/' + token, null, function (s, d) {
        if (d && d.state === 'paired') {
          var appUrl = d.url;
          if (/[?&]launch=welcome(?:&|$)/.test(location.search || '')) appUrl += '?launch=welcome';
          try { localStorage.removeItem(key); localStorage.setItem('tvApp', appUrl); } catch (e) { /* ignore */ }
          msg.textContent = 'Paired – starting…';
          location.replace(appUrl);
        } else if (d && d.state === 'waiting') {
          codeEl.textContent = d.code;
          setTimeout(function () { wait(token); }, 4000);
        } else if (s === 429 || s === 0) {
          setTimeout(function () { wait(token); }, 10000);
        } else {
          start();
        }
      });
    };
    var saved = null;
    try { saved = localStorage.getItem(key); } catch (e) { saved = null; }
    if (saved) wait(saved); else start();
    return;
  }

  // ───────────────────────── the portal ─────────────────────────
  var root = $('tvApp');
  if (!root) return;
  var preboot = $('tvPreboot');
  var BASE = root.getAttribute('data-base');
  var S = JSON.parse($('tvBoot').textContent);
  var T = S.t || {};
  var launchWelcomeOnResume = /[?&]launch=welcome(?:&|$)/.test(location.search || '');
  var stack = [];            // navigation history of screen names
  var screen = null;         // current screen name
  var welcomeIntegrated = false; // keep the first guest screen integrated with the home dashboard for this stay
  var welcomeMarked = false;
  var welcomeMarkPending = false;
  var portalQrPending = false;
  var modal = null;          // open modal element
  var cache = {};            // section data
  var cart = {};             // dining cart id → {id, name, raw, qty}
  var weather = null;
  var lastHome = 0;
  var standby = false;
  var hbTimer = null;
  var shownMsg = {};         // high-priority messages already popped up
  try { localStorage.setItem('tvApp', location.href); } catch (e) { /* ignore */ }

  function api(method, path, body, cb) {
    req(method, BASE + path, body, function (s, d) {
      if (s >= 200 && s < 300) { cb && cb(null, d); return; }
      cb && cb((d && d.error) || T.error || 'Error', d);
    });
  }
  function logEvent(ev, target) { api('POST', '/event', { event: ev, target: target || null }); }

  // base layout: top bar + stage + overlays
  var top = el('div', { cls: 'tv-top' });
  var stage = el('div', { cls: 'tv-stage' });
  var ticker = el('div', { cls: 'tv-ticker', style: 'display:none' });
  var toastEl = el('div', { cls: 'tv-toast' });
  var emEl = el('div', { cls: 'tv-emergency', style: 'display:none' });
  var sbEl = el('div', { cls: 'tv-standby', style: 'display:none' }, [el('div', { text: '⏻' })]);
  root.appendChild(top); root.appendChild(stage); root.appendChild(ticker); root.appendChild(toastEl); root.appendChild(emEl); root.appendChild(sbEl);

  function toast(text, bad) {
    toastEl.textContent = text;
    toastEl.className = 'tv-toast show' + (bad ? ' bad' : '');
    clearTimeout(toastEl._t);
    toastEl._t = setTimeout(function () { toastEl.className = 'tv-toast'; }, 4500);
  }

  // ───────────── top bar: hotel · room · clock · weather ─────────────
  var WX = { 0: ['☀', 'Clear'], 1: ['🌤', 'Mostly clear'], 2: ['⛅', 'Partly cloudy'], 3: ['☁', 'Cloudy'], 45: ['🌫', 'Fog'], 48: ['🌫', 'Fog'], 51: ['🌦', 'Drizzle'], 53: ['🌦', 'Drizzle'], 55: ['🌦', 'Drizzle'],
    61: ['🌧', 'Rain'], 63: ['🌧', 'Rain'], 65: ['🌧', 'Heavy rain'], 80: ['🌦', 'Showers'], 81: ['🌧', 'Showers'], 82: ['⛈', 'Heavy showers'], 95: ['⛈', 'Thunderstorm'], 96: ['⛈', 'Thunderstorm'], 99: ['⛈', 'Thunderstorm'] };
  function renderTop() {
    clear(top);
    var d = new Date();
    var left = el('div', { cls: 'tv-top-l' }, [el('b', { text: (S.hotel && S.hotel.name) || '' }),
      S.guest ? el('span', { text: (T.room || 'Room') + ' ' + S.guest.room }) : (S.device ? el('span', { text: S.device.place }) : null)]);
    var right = el('div', { cls: 'tv-top-r' });
    if (weather) right.appendChild(el('span', { cls: 'tv-wx', title: weather.label, text: weather.icon + ' ' + weather.temp + '°C' }));
    if (S.mode === 'room' && S.unread) right.appendChild(el('span', { cls: 'tv-badge' }, [tvIcon('messages', 'tv-badge-icon'), el('span', { text: String(S.unread) })]));
    right.appendChild(el('span', { cls: 'tv-clock', text: pad(d.getHours()) + ':' + pad(d.getMinutes()) }));
    right.appendChild(el('span', { cls: 'tv-date', text: d.toLocaleDateString(S.lang || 'en', { weekday: 'short', day: 'numeric', month: 'short' }) }));
    top.appendChild(left); top.appendChild(right);
  }
  function loadWeather() {
    if (!S.weather) return;
    req('GET', 'https://api.open-meteo.com/v1/forecast?latitude=' + S.weather.lat + '&longitude=' + S.weather.lon + '&current=temperature_2m,weather_code&timezone=auto', null, function (s, d) {
      if (s === 200 && d && d.current) {
        var w = WX[d.current.weather_code] || ['🌡', ''];
        weather = { temp: Math.round(d.current.temperature_2m), icon: w[0], label: w[1] };
        renderTop();
      }
    });
  }

  // ───────────── focus & spatial navigation ─────────────
  function scope() { return modal || stage; }
  function focusables() {
    var list = scope().querySelectorAll('[data-f]'), out = [];
    for (var i = 0; i < list.length; i++) if (list[i].offsetParent !== null && !list[i].disabled) out.push(list[i]);
    return out;
  }
  function focusFirst(pref) {
    var f = (pref && scope().querySelector(pref)) || focusables()[0];
    if (f) f.focus();
  }
  function move(dir) {
    var cur = document.activeElement, all = focusables();
    if (!cur || all.indexOf(cur) < 0) { focusFirst(); return; }
    var r = cur.getBoundingClientRect(), cx = r.left + r.width / 2, cy = r.top + r.height / 2, best = null, bd = Infinity;
    all.forEach(function (e) {
      if (e === cur) return;
      var q = e.getBoundingClientRect(), x = q.left + q.width / 2, y = q.top + q.height / 2, dx = x - cx, dy = y - cy, main, cross;
      if (dir === 'left') { if (q.right > r.left + 2) return; main = -dx; cross = Math.abs(dy); }
      else if (dir === 'right') { if (q.left < r.right - 2) return; main = dx; cross = Math.abs(dy); }
      else if (dir === 'up') { if (q.bottom > r.top + 2) return; main = -dy; cross = Math.abs(dx); }
      else { if (q.top < r.bottom - 2) return; main = dy; cross = Math.abs(dx); }
      var d = main + cross * 2.2;
      if (d < bd) { bd = d; best = e; }
    });
    if (best) { best.focus(); if (best.scrollIntoView) best.scrollIntoView({ block: 'nearest', inline: 'nearest' }); }
  }
  var numBuf = '', numTimer = null;
  document.addEventListener('keydown', function (e) {
    var k = e.keyCode;
    if (standby) { if ([13, 27, 8, 10009, 461, 36].indexOf(k) >= 0) { standby = false; sbEl.style.display = 'none'; } e.preventDefault(); return; }
    if (S.emergency) { e.preventDefault(); return; }             // nothing else while an emergency alert is up
    var tag = (document.activeElement && document.activeElement.tagName) || '';
    var typing = tag === 'INPUT' && document.activeElement.type !== 'checkbox';
    if (k === 37 && !typing) { move('left'); e.preventDefault(); }
    else if (k === 38) { move('up'); e.preventDefault(); }
    else if (k === 39 && !typing) { move('right'); e.preventDefault(); }
    else if (k === 40) { move('down'); e.preventDefault(); }
    else if (k === 27 || k === 10009 || k === 461 || (k === 8 && !typing)) { back(); e.preventDefault(); }
    else if (k === 36 || k === 10073) { welcomeIntegrated = false; go('home', true); e.preventDefault(); }
    else if (k === 13 && document.activeElement && document.activeElement.hasAttribute('data-f') && tag !== 'BUTTON') { document.activeElement.click(); e.preventDefault(); }
    else if (k >= 48 && k <= 57 && !typing && screen === 'tv') {   // number keys: direct channel entry
      numBuf += String(k - 48);
      toast(numBuf);
      clearTimeout(numTimer);
      numTimer = setTimeout(function () { var n = parseInt(numBuf, 10); numBuf = ''; tuneNumber(n); }, 1200);
    } else if ((k === 427 || k === 33) && screen === 'tv') { zap(1); e.preventDefault(); }       // CH+
    else if ((k === 428 || k === 34) && screen === 'tv') { zap(-1); e.preventDefault(); }        // CH-
  });
  // register media keys on Tizen (hotel TVs route them to the app only when asked)
  try { if (window.tizen && tizen.tvinputdevice) ['ChannelUp', 'ChannelDown', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'].forEach(function (n) { try { tizen.tvinputdevice.registerKey(n); } catch (e) { /* ignore */ } }); } catch (e) { /* ignore */ }

  // ───────────── screens & history ─────────────
  function go(name, reset) {
    closeModal();
    if (reset) stack = [];
    else if (screen && screen !== name) stack.push(screen);
    screen = name;
    render();
  }
  function back() {
    if (modal) { closeModal(); return; }
    if (stack.length) { screen = stack.pop(); render(); return; }
    if (S.mode === 'room' && screen !== 'home') { welcomeIntegrated = false; go('home', true); }
  }
  function openModal(node, pref) {
    closeModal();
    modal = el('div', { cls: 'tv-modal' }, [el('div', { cls: 'tv-modal-box' }, [node])]);
    root.appendChild(modal);
    focusFirst(pref);
  }
  function closeModal() {
    if (!modal) return;
    root.removeChild(modal);
    modal = null;
    focusFirst();
    setTimeout(function () { if (!modal) popHighPriority(); }, 400);   // queued high-priority messages
  }
  function header(title, extra) { return el('div', { cls: 'tv-h' }, [btn('‹ ' + (T.back || 'Back'), back, 'tv-back'), el('h2', { text: title }), extra || null]); }
  function need(section, cb) {
    if (cache[section]) { cb(cache[section]); return; }
    stage.appendChild(el('div', { cls: 'tv-loading', text: '…' }));
    api('GET', '/data/' + section, null, function (err, d) {
      if (err) { toast(err, true); return; }
      cache[section] = d;
      if (screen) cb(d);
    });
  }

  function render() {
    document.body.className = 'tv tv-th-' + ((S.welcome && S.welcome.theme) || 'night') + (S.mode === 'signage' ? ' tv-signage-mode' : '') + ((screen === 'welcome' || screen === 'vacant') ? ' tv-welcome-mode' : '');
    renderTop();
    clear(stage);
    ticker.style.display = 'none';
    var fn = SCREENS[screen] || SCREENS.home;
    fn();
    if (!modal) focusFirst('[data-autofocus]');
  }

  var TILES = [['tv', 'tv'], ['movies', 'movies'], ['dining', 'dining'], ['services', 'services'], ['messages', 'messages'], ['bill', 'bill'], ['info', 'info'], ['explore', 'explore'], ['checkout', 'checkout'], ['feedback', 'feedback']];

  function homeTiles() {
    var grid = el('div', { cls: 'tv-tiles' });
    var count = 0;
    TILES.forEach(function (t) {
      if (!S.features || !S.features[t[0]]) return;
      var label = T[t[0]] || t[0];
      var badge = t[0] === 'messages' && (S.unread || S.desk_messages) ? el('span', { cls: 'tv-tile-badge', text: String((S.unread || 0) + (S.desk_messages || 0)) }) : null;
      grid.appendChild(el('button', { cls: 'tv-tile', 'data-f': '1', tabindex: '0', 'data-autofocus': count === 0 ? '1' : null, on: function () { go(t[0]); } },
        [el('span', { cls: 'tv-tile-i' }, [tvIcon(t[1])]), el('span', { text: label }), badge]));
      count++;
    });
    return grid;
  }

  function welcomeBackdrop() {
    var layer = el('div', { cls: 'tv-welcome-backdrop', 'aria-hidden': 'true' });
    var src = root.getAttribute('data-welcome-image') || (S.hotel && S.hotel.welcome_image) || '';
    if (src && src.charAt(0) !== '/' && !/^[a-z][a-z0-9+.-]*:/i.test(src)) src = '/' + src;
    if (src) {
      var photo = el('img', { cls: 'tv-welcome-photo', src: src, alt: '' });
      photo.onerror = function () { this.style.display = 'none'; };
      layer.appendChild(photo);
    }
    layer.appendChild(el('div', { cls: 'tv-welcome-shade' }));
    layer.appendChild(el('div', { cls: 'tv-welcome-frame' }));
    return layer;
  }

  function welcomeBrand() {
    var brand = el('div', { cls: 'tv-welcome-brand' });
    var logoSrc = root.getAttribute('data-hotel-logo') || '';
    if (logoSrc) {
      var logo = el('img', { cls: 'tv-welcome-logo', src: logoSrc, alt: '' });
      logo.onerror = function () { this.style.display = 'none'; };
      brand.appendChild(el('span', { cls: 'tv-welcome-logo-frame', 'aria-hidden': 'true' }, [logo]));
    } else {
      brand.appendChild(el('span', { cls: 'tv-welcome-monogram', 'aria-hidden': 'true', text: '✦' }));
    }
    brand.appendChild(el('span', { cls: 'tv-welcome-brand-text' }, [
      el('small', { text: T.welcome || 'Welcome' }),
      el('strong', { text: (S.hotel && S.hotel.name) || '' }),
    ]));
    return brand;
  }

  function welcomeStayCard() {
    var guest = S.guest || {};
    var card = el('div', { cls: 'tv-welcome-stay-card' });
    card.appendChild(el('div', { cls: 'tv-welcome-card-eyebrow', text: 'YOUR STAY' }));
    var details = el('div', { cls: 'tv-welcome-stay-details' });
    if (guest.room) details.appendChild(el('div', { cls: 'tv-welcome-stay-detail' }, [el('small', { text: T.room || 'Room' }), el('strong', { text: guest.room })]));
    if (guest.departure) details.appendChild(el('div', { cls: 'tv-welcome-stay-detail' }, [el('small', { text: T.departure || 'Departure' }), el('strong', { text: guest.departure })]));
    if (details.childNodes.length) card.appendChild(details);
    if ((S.rentals || []).length) {
      card.appendChild(el('div', { cls: 'tv-welcome-extra' }, [
        el('small', { text: T.rented || 'Rented' }),
        el('strong', { text: S.rentals.map(function (r) { return r.title + ' (' + r.left + (T.hours || 'h') + ')'; }).join(', ') }),
      ]));
    }
    if (S.desk_ext) card.appendChild(el('div', { cls: 'tv-welcome-extra' }, [
      el('small', { text: T.front_desk || 'Front desk' }),
      el('strong', { text: (T.dial || 'Dial') + ' ' + S.desk_ext }),
    ]));
    if (S.wifi && S.wifi.name) {
      card.appendChild(el('div', { cls: 'tv-welcome-wifi' }, [
        el('div', { cls: 'tv-welcome-wifi-label' }, [tvIcon('wifi', 'tv-welcome-wifi-icon'), el('span', { text: T.wifi || 'Wi-Fi' })]),
        el('strong', { cls: 'tv-welcome-wifi-name', text: S.wifi.name }),
        S.wifi.password ? el('span', { cls: 'tv-welcome-wifi-password', text: (T.password || 'Password') + ' · ' + S.wifi.password }) : null,
      ]));
    }
    if (S.portal && S.portal.qr) {
      card.appendChild(el('div', { cls: 'tv-welcome-portal' }, [
        el('img', { cls: 'tv-welcome-portal-qr', src: 'data:image/svg+xml;base64,' + S.portal.qr, alt: T.guest_portal || 'Guest portal QR code' }),
        el('div', { cls: 'tv-welcome-portal-copy' }, [
          el('strong', { text: T.guest_portal || 'Guest portal' }),
          el('span', { text: T.scan_portal || 'Scan to open on your phone' }),
        ]),
      ]));
    }
    return details.childNodes.length || (S.wifi && S.wifi.name) || (S.rentals || []).length || S.desk_ext || (S.portal && S.portal.qr) ? card : null;
  }

  var SCREENS = {
    off: function () {
      stage.appendChild(el('div', { cls: 'tv-center' }, [el('h1', { text: (T.welcome || 'Welcome') }), el('p', { cls: 'tv-lead', text: (S.hotel && S.hotel.name) || '' })]));
    },
    unregistered: function () {
      stage.appendChild(el('div', { cls: 'tv-center' }, [el('h1', { text: (S.hotel && S.hotel.name) || '' }), el('p', { cls: 'tv-lead', text: 'This TV is not active. Please contact the front desk.' })]));
    },
    vacant: function () {
      var city = S.hotel && S.hotel.city ? S.hotel.city : '';
      var hotelName = (S.hotel && S.hotel.name) || 'Welcome';
      var welcomeTo = T.vacant || 'Welcome to';
      var copy = el('div', { cls: 'tv-welcome-main tv-welcome-main-vacant' }, [
        el('p', { cls: 'tv-welcome-kicker', text: city ? welcomeTo + ' ' + city : welcomeTo }),
        el('span', { cls: 'tv-welcome-rule', 'aria-hidden': 'true' }),
        el('h1', { cls: 'tv-welcome-h', text: hotelName }),
        el('div', { cls: 'tv-welcome-actions' }, [el('button', { cls: 'tv-btn tv-big tv-primary tv-vacant-cta', 'data-f': '1', tabindex: '0', 'data-autofocus': '1', on: function () { go('tv'); } }, [tvIcon('tv', 'tv-action-icon'), el('span', { text: T.tv || 'Live TV' })])]),
      ]);
      var layout = el('div', { cls: 'tv-welcome-layout tv-welcome-layout-vacant' }, [welcomeBrand(), copy,
        el('div', { cls: 'tv-welcome-footer' }, [el('span', { cls: 'tv-welcome-footer-line' }), el('span', { text: city ? city.toUpperCase() + ' · GHANA' : 'A PLACE TO UNWIND' })])]);
      stage.appendChild(el('section', { cls: 'tv-welcome-screen tv-vacant-screen' }, [welcomeBackdrop(), layout]));
    },
    welcome: function () {
      var w = S.welcome || {};
      var city = S.hotel && S.hotel.city ? S.hotel.city : '';
      var langs = el('div', { cls: 'tv-langs tv-welcome-language-list' });
      Object.keys(S.langs || {}).forEach(function (l) {
        langs.appendChild(btn(S.langs[l], function () { setLang(l); }, l === S.lang ? 'on' : ''));
      });
      var body = el('div', { cls: 'tv-welcome-body' });
      String(w.body || '').split('\n').forEach(function (line) { if (line.trim()) body.appendChild(el('p', { text: line })); });
      var copy = el('div', { cls: 'tv-welcome-main' }, [
        el('p', { cls: 'tv-welcome-kicker', text: city ? city.toUpperCase() + ' · AKWAABA / BIENVENUE' : 'AKWAABA / BIENVENUE' }),
        el('span', { cls: 'tv-welcome-rule', 'aria-hidden': 'true' }),
        el('h1', { cls: 'tv-welcome-h', text: w.headline || (T.welcome || 'Welcome') }),
        S.guest && S.guest.name ? el('p', { cls: 'tv-welcome-guest-greeting', text: (T.welcome || 'Welcome') + ', ' + S.guest.name }) : null,
        body,
      ]);
      if (!welcomeMarked && !welcomeMarkPending) {
        welcomeMarkPending = true;
        api('POST', '/welcome', {}, function (err) {
          welcomeMarkPending = false;
          if (!err) welcomeMarked = true;
        });
      }
      var actions = el('div', { cls: 'tv-welcome-actions' }, [
        el('div', { cls: 'tv-welcome-language' }, [el('span', { cls: 'tv-welcome-language-label', text: T.language || 'Language' }), langs]),
      ]);
      var quickAccess = el('section', { cls: 'tv-welcome-quick-access', 'aria-label': 'Hotel services' }, [
        el('div', { cls: 'tv-welcome-quick-heading' }, [
          el('strong', { text: 'YOUR HOTEL, AT A GLANCE' }),
          el('span', { text: 'Choose a service to get started' }),
        ]),
        homeTiles(),
      ]);
      var footer = el('div', { cls: 'tv-welcome-footer' }, [
        el('span', { cls: 'tv-welcome-footer-line' }),
        el('span', { text: city ? city.toUpperCase() + ' · GHANA' : 'A PLACE TO UNWIND' }),
      ]);
      var layout = el('div', { cls: 'tv-welcome-layout tv-welcome-layout-integrated' }, [welcomeBrand(), copy, welcomeStayCard(), quickAccess, actions, footer]);
      stage.appendChild(el('section', { cls: 'tv-welcome-screen' }, [welcomeBackdrop(), layout]));
    },
    home: function () {
      if (Date.now() - lastHome > 600000) { logEvent('home'); lastHome = Date.now(); }
      var g = S.guest || {};
      var grid = homeTiles();
      var side = el('div', { cls: 'tv-home-side' }, [
        el('div', { cls: 'tv-greet' }, [el('small', { text: T.welcome || 'Welcome' }), el('h2', { text: g.name || '' })]),
        el('div', { cls: 'tv-kv' }, [el('span', { text: T.room || 'Room' }), el('b', { text: g.room || '' }), el('span', { text: T.departure || 'Departure' }), el('b', { text: g.departure || '' })]),
        S.wifi && S.wifi.name ? el('div', { cls: 'tv-kv' }, [el('span', { text: T.wifi || 'Wi-Fi' }), el('b', { text: S.wifi.name }), el('span', { text: T.password || 'Password' }), el('b', { text: S.wifi.password })]) : null,
        (S.rentals || []).length ? el('div', { cls: 'tv-kv' }, [el('span', { text: T.rented || 'Rented' }), el('b', { text: S.rentals.map(function (r) { return r.title + ' (' + r.left + (T.hours || 'h') + ')'; }).join(', ') })]) : null,
        S.desk_ext ? el('p', { cls: 'tv-muted', text: (T.dial || 'Dial') + ' ' + S.desk_ext + ' ' + (T.front_desk || '') }) : null,
        el('div', { cls: 'tv-row' }, [btn('🌐 ' + (T.language || 'Language'), langModal, 'tv-small')]),
      ]);
      stage.appendChild(el('div', { cls: 'tv-home' }, [side, grid]));
      var live = (S.messages || []).filter(function (m) { return !m.read; });
      if (live.length) { clear(ticker); ticker.appendChild(tvIcon('messages', 'tv-ticker-icon')); ticker.appendChild(document.createTextNode(' ' + live.map(function (m) { return m.title; }).join('   •   '))); ticker.style.display = ''; }
    },
    tv: function () {
      logEvent('tv');
      need('channels', function (d) {
        clear(stage);
        var list = d.channels || [];
        if (!list.length) { stage.appendChild(header(T.tv)); stage.appendChild(el('p', { cls: 'tv-lead', text: T.unavailable })); focusFirst(); return; }
        var player = el('div', { cls: 'tv-player' });
        var col = el('div', { cls: 'tv-chlist' });
        list.forEach(function (c, i) {
          col.appendChild(el('button', { cls: 'tv-ch', 'data-f': '1', tabindex: '0', 'data-no': c.no, 'data-autofocus': i === 0 ? '1' : null, on: function () { tune(c, player); } }, [
            el('b', { text: String(c.no) }), el('span', { cls: 'tv-ch-n', text: c.name }), el('small', { text: c.now ? c.now.title : c.cat_label })]));
        });
        stage.appendChild(header(T.tv));
        stage.appendChild(el('div', { cls: 'tv-tvwrap' }, [col, player]));
        tune(currentCh || S.power_channel && list.filter(function (c) { return c.no === S.power_channel; })[0] || list[0], player, true);
        focusFirst('[data-no="' + (currentCh ? currentCh.no : list[0].no) + '"]');
      });
    },
    movies: function () {
      need('movies', function (d) {
        clear(stage);
        stage.appendChild(header(T.movies));
        var grid = el('div', { cls: 'tv-posters' });
        (d.movies || []).forEach(function (m, i) {
          grid.appendChild(el('button', { cls: 'tv-poster', 'data-f': '1', tabindex: '0', 'data-autofocus': i === 0 ? '1' : null, style: 'background:linear-gradient(160deg,hsl(' + m.hue + ',55%,38%),hsl(' + ((m.hue + 40) % 360) + ',60%,16%))', on: function () { movieModal(m); } }, [
            el('span', { cls: 'tv-poster-t', text: m.title }), el('small', { text: m.genre + ' · ' + m.rating + ' · ' + m.mins + ' min' }),
            m.rented ? el('span', { cls: 'tv-poster-b', text: '▶ ' + m.left + (T.hours || 'h') + ' ' + (T.left || '') }) : el('span', { cls: 'tv-poster-p', text: m.price })]));
        });
        if (!(d.movies || []).length) grid.appendChild(el('p', { cls: 'tv-lead', text: T.unavailable }));
        stage.appendChild(grid);
        focusFirst('[data-autofocus]');
      });
    },
    dining: function () {
      need('dining', function (d) {
        clear(stage);
        stage.appendChild(header((T.dining || 'Dining') + (d.outlet ? ' · ' + d.outlet : '')));
        if (!(d.cats || []).length) { stage.appendChild(el('p', { cls: 'tv-lead', text: T.unavailable })); focusFirst(); return; }
        var items = el('div', { cls: 'tv-menu' }), cartBox = el('div', { cls: 'tv-cart' });
        var cats = el('div', { cls: 'tv-cats' });
        var showCat = function (c) {
          clear(items);
          c.items.forEach(function (it) {
            items.appendChild(el('button', { cls: 'tv-dish', 'data-f': '1', tabindex: '0', on: function () { pickItem(it, cartBox); } }, [
              it.image ? el('img', { cls: 'tv-dish-img', src: it.image, alt: '' }) : null,
              el('span', { cls: 'tv-dish-n', text: (it.veg ? '🟢 ' : '') + it.name }),
              (it.sides && it.sides.length) ? el('small', { text: (T.side || 'Side dish') + (it.side_required ? ' · ' + (T.required_side || 'required') : '') })
                : (it.modifiers && it.modifiers.length ? el('small', { text: 'Customise' }) : null), el('b', { text: it.price })]));
          });
        };
        d.cats.forEach(function (c, i) { cats.appendChild(btn(c.name, function () { showCat(c); }, 'tv-cat', { 'data-autofocus': i === 0 ? '1' : null })); });
        showCat(d.cats[0]);
        drawCart(cartBox);
        stage.appendChild(el('div', { cls: 'tv-dining' }, [cats, items, cartBox]));
        focusFirst('[data-autofocus]');
      });
    },
    services: function () {
      need('services', function (d) {
        clear(stage);
        stage.appendChild(header(T.services));
        var grid = el('div', { cls: 'tv-tiles tv-tiles-s' });
        (d.cats || []).forEach(function (c, i) {
          grid.appendChild(el('button', { cls: 'tv-tile', 'data-f': '1', tabindex: '0', 'data-autofocus': i === 0 ? '1' : null, on: function () { serviceModal(c); } }, [el('span', { cls: 'tv-tile-i' }, [tvIcon('services')]), el('span', { text: c.label })]));
        });
        grid.appendChild(el('button', { cls: 'tv-tile', 'data-f': '1', tabindex: '0', on: wakeupModal }, [el('span', { cls: 'tv-tile-i' }, [tvIcon('wakeup')]), el('span', { text: T.wakeup || 'Wake-up call' })]));
        var recent = el('div', { cls: 'tv-list' });
        (d.wakeups || []).forEach(function (w) { recent.appendChild(el('div', { cls: 'tv-li' }, [tvIcon('wakeup', 'tv-inline-icon'), el('span', { text: w.at + (w.daily ? ' · ' + (T.daily || '') : '') })])); });
        (d.open || []).forEach(function (r) { recent.appendChild(el('div', { cls: 'tv-li' }, [el('span', { text: r.item }), el('small', { text: r.status + ' · ' + r.at })])); });
        stage.appendChild(el('div', { cls: 'tv-split' }, [grid, recent]));
        focusFirst('[data-autofocus]');
      });
    },
    messages: function () {
      delete cache.messages;
      need('messages', function (d) {
        clear(stage);
        stage.appendChild(header(T.messages));
        var list = el('div', { cls: 'tv-list' });
        if (d.desk) list.appendChild(el('div', { cls: 'tv-li tv-li-hi' }, [tvIcon('messages', 'tv-inline-icon'), el('span', { text: (T.new_msg || 'New message') + ' – ' + (T.front_desk || 'front desk') + (S.desk_ext ? ' (' + (T.dial || '') + ' ' + S.desk_ext + ')' : '') })]));
        (d.messages || []).forEach(function (m, i) {
          list.appendChild(el('button', { cls: 'tv-li' + (m.read ? '' : ' tv-li-new'), 'data-f': '1', tabindex: '0', 'data-autofocus': i === 0 ? '1' : null, on: function () { msgModal(m); } }, [
            el('span', { text: (m.read ? '' : '● ') + m.title }), el('small', { text: m.at })]));
        });
        if (!d.desk && !(d.messages || []).length) list.appendChild(el('p', { cls: 'tv-lead', text: T.no_messages }));
        stage.appendChild(list);
        focusFirst('[data-autofocus]');
      });
    },
    bill: function () {
      stage.appendChild(header(T.bill));
      var box = el('div', { cls: 'tv-bill' });
      stage.appendChild(box);
      var load = function (pin) {
        api('POST', '/bill', { pin: pin || null }, function (err, d) {
          if (err) { if (S.pin && S.pin.bill && !pin) { pinModal(load); } else toast(err, true); return; }
          clear(box);
          var t = el('div', { cls: 'tv-list' });
          d.lines.forEach(function (l) { t.appendChild(el('div', { cls: 'tv-li' + (l.credit ? ' tv-credit' : '') }, [el('small', { text: l.date || '' }), el('span', { text: l.text }), el('b', { text: l.amount })])); });
          box.appendChild(t);
          box.appendChild(el('div', { cls: 'tv-total' }, [el('span', { text: T.balance || 'Balance' }), el('b', { text: d.balance })]));
          if (S.features.checkout) box.appendChild(el('div', { cls: 'tv-row' }, [btn((T.checkout || 'Express check-out'), function () { go('checkout'); }, 'tv-primary', { 'data-autofocus': '1' })]));
          focusFirst('[data-autofocus]');
        });
      };
      if (S.pin && S.pin.bill) pinModal(load); else load(null);
    },
    checkout: function () {
      stage.appendChild(header(T.checkout));
      stage.appendChild(el('div', { cls: 'tv-center' }, [el('p', { cls: 'tv-lead', text: T.checkout_q || '' }),
        el('div', { cls: 'tv-row' }, [
          btn(T.ok || 'OK', function () {
            api('POST', '/checkout', {}, function (err) {
              if (err) { toast(err, true); return; }
              clear(stage); stage.appendChild(header(T.checkout)); stage.appendChild(el('div', { cls: 'tv-center' }, [el('h2', { text: '✓' }), el('p', { cls: 'tv-lead', text: T.checkout_done })])); focusFirst();
            });
          }, 'tv-primary tv-big', { 'data-autofocus': '1' }),
          btn(T.cancel || 'Cancel', back, 'tv-big')])]));
    },
    info: function () {
      need('pages', function (d) {
        clear(stage);
        stage.appendChild(header(T.info));
        var grid = el('div', { cls: 'tv-cards' });
        (d.pages || []).forEach(function (p, i) {
          grid.appendChild(el('button', { cls: 'tv-card', 'data-f': '1', tabindex: '0', 'data-autofocus': i === 0 ? '1' : null, on: function () { pageModal(p); } }, [el('small', { text: p.category }), el('b', { text: p.title }), el('span', { text: String(p.body).split('\n')[0].slice(0, 110) })]));
        });
        stage.appendChild(grid);
        focusFirst('[data-autofocus]');
      });
    },
    explore: function () {
      need('explore', function (d) {
        clear(stage);
        stage.appendChild(header(T.explore));
        var grid = el('div', { cls: 'tv-cards' });
        (d.places || []).forEach(function (p, i) {
          grid.appendChild(el('button', { cls: 'tv-card', 'data-f': '1', tabindex: '0', 'data-autofocus': i === 0 ? '1' : null, on: function () {
            openModal(el('div', {}, [el('h2', { text: p.name }), el('p', { cls: 'tv-muted', text: [p.category, p.area, p.km !== null ? p.km + ' km' : null, p.hours].filter(Boolean).join(' · ') }),
              el('p', { text: p.text || '' }), p.tip ? el('p', { cls: 'tv-tip', text: '💡 ' + p.tip }) : null, el('div', { cls: 'tv-row' }, [btn(T.back || 'Back', closeModal, 'tv-primary')])]));
          } }, [el('small', { text: [p.category, p.km !== null ? p.km + ' km' : null].filter(Boolean).join(' · ') }), el('b', { text: p.name }), el('span', { text: (p.text || '').slice(0, 100) })]));
        });
        stage.appendChild(grid);
        focusFirst('[data-autofocus]');
      });
    },
    feedback: function () {
      stage.appendChild(header(T.feedback));
      var row = el('div', { cls: 'tv-stars' });
      [1, 2, 3, 4, 5].forEach(function (n) {
        row.appendChild(btn(new Array(n + 1).join('★'), function () {
          api('POST', '/feedback', { rating: n }, function (err) {
            if (err) { toast(err, true); return; }
            clear(stage); stage.appendChild(header(T.feedback)); stage.appendChild(el('div', { cls: 'tv-center' }, [el('h1', { text: T.thanks || 'Thank you!' })])); focusFirst();
          });
        }, 'tv-star', { 'data-autofocus': n === 5 ? '1' : null }));
      });
      stage.appendChild(el('div', { cls: 'tv-center' }, [row]));
    },
    signage: function () {
      var ev = el('div', { cls: 'tv-sg-events' }, [el('h2', { text: T.events_today || "Today's events" })]);
      (S.events || []).forEach(function (e) { ev.appendChild(el('div', { cls: 'tv-sg-ev' }, [el('b', { text: e.from + '–' + e.to }), el('span', { text: e.name }), el('small', { text: [e.function, e.hall].filter(Boolean).join(' · ') })])); });
      if (!(S.events || []).length) ev.appendChild(el('p', { cls: 'tv-muted', text: '—' }));
      var msgs = el('div', { cls: 'tv-sg-msgs' });
      (S.messages || []).slice(0, 3).forEach(function (m) { msgs.appendChild(el('div', { cls: 'tv-sg-msg' }, [el('b', { text: m.title }), el('p', { text: m.body })])); });
      if (!(S.messages || []).length) msgs.appendChild(el('div', { cls: 'tv-sg-msg tv-sg-idle' }, [el('b', { text: 'Akwaaba' }), el('p', { text: (T.welcome || 'Welcome') + ' – ' + ((S.hotel && S.hotel.name) || '') + ((S.hotel && S.hotel.city) ? ', ' + S.hotel.city : '') })])); // never a blank panel
      stage.appendChild(el('div', { cls: 'tv-signage' }, [el('h1', { text: (S.hotel && S.hotel.name) || '' }), el('div', { cls: 'tv-sg-grid' }, [ev, msgs])]));
      if (S.text) { ticker.textContent = S.text; ticker.style.display = ''; }
    },
  };

  // ───────────── live TV ─────────────
  var currentCh = null, hls = null;
  function tune(c, player, silent) {
    if (!c) return;
    currentCh = c;
    clear(player);
    var playable = /^https?:\/\//i.test(c.url);
    if (playable && !c.radio) {
      var v = el('video', { autoplay: true, playsinline: true, muted: false, src: c.url, cls: 'tv-video' });
      v.onerror = function () { v.style.display = 'none'; };
      player.appendChild(v);
    } else if (playable && c.radio) {
      player.appendChild(el('audio', { autoplay: true, src: c.url }));
      player.appendChild(el('div', { cls: 'tv-radio', text: '📻' }));
    } else {
      player.appendChild(el('div', { cls: 'tv-tuner' }, [el('div', { cls: 'tv-tuner-no', text: String(c.no) }), el('p', { text: T.no_signal || '' })]));
    }
    player.appendChild(el('div', { cls: 'tv-osd' }, [el('div', {}, [el('b', { text: c.no + '  ' + c.name }), el('small', { text: c.cat_label + (c.lang ? ' · ' + c.lang : '') })]),
      c.now ? el('div', {}, [el('small', { text: (T.now || 'Now') + ' ' + c.now.from + '–' + c.now.to }), el('b', { text: c.now.title }), c.now.desc ? el('p', { text: c.now.desc }) : null]) : null,
      c.next ? el('div', {}, [el('small', { text: (T.next || 'Next') + ' ' + c.next.from }), el('span', { text: c.next.title })]) : null]));
    if (!silent) logEvent('channel', c.no + ' ' + c.name);
  }
  function tuneNumber(n) {
    var list = (cache.channels && cache.channels.channels) || [], c = list.filter(function (x) { return x.no === n; })[0];
    var player = stage.querySelector('.tv-player');
    if (c && player) { tune(c, player); var b = stage.querySelector('[data-no="' + n + '"]'); if (b) b.focus(); } else toast(T.unavailable || '', true);
  }
  function zap(dir) {
    var list = (cache.channels && cache.channels.channels) || [];
    if (!list.length) return;
    var i = currentCh ? list.map(function (c) { return c.no; }).indexOf(currentCh.no) : -1;
    tuneNumber(list[(i + dir + list.length) % list.length].no);
  }

  // ───────────── modals ─────────────
  function pinModal(onPin) {
    var val = '', disp = el('div', { cls: 'tv-pin-d', text: '····' }), keys = el('div', { cls: 'tv-keypad' });
    var set = function () { disp.textContent = (val.replace(/./g, '●') + '····').slice(0, 4); if (val.length === 4) { var v = val; val = ''; closeModal(); onPin(v); } };
    ['1', '2', '3', '4', '5', '6', '7', '8', '9', '⌫', '0', '✕'].forEach(function (k) {
      keys.appendChild(btn(k, function () { if (k === '⌫') val = val.slice(0, -1); else if (k === '✕') { closeModal(); return; } else if (val.length < 4) val += k; set(); }, 'tv-key'));
    });
    openModal(el('div', {}, [el('p', { cls: 'tv-lead', text: T.enter_pin || 'PIN' }), disp, keys]));
    var onKey = function (e) { if (modal && e.keyCode >= 48 && e.keyCode <= 57 && val.length < 4) { val += String(e.keyCode - 48); set(); } };
    document.addEventListener('keydown', onKey);
    var obs = setInterval(function () { if (!modal) { document.removeEventListener('keydown', onKey); clearInterval(obs); } }, 500);
  }
  function movieModal(m) {
    var play = function (url, title) {
      closeModal();
      if (!url) { toast(title, false); return; }
      clear(stage);
      var v = el('video', { autoplay: true, controls: true, src: url, cls: 'tv-video tv-video-full' });
      stage.appendChild(header(title));
      stage.appendChild(v);
      focusFirst();
    };
    var rent = function (pin) {
      api('POST', '/rent/' + m.id, { pin: pin || null }, function (err, d) {
        if (err) { toast(err, true); return; }
        delete cache.movies;
        S.rentals = (S.rentals || []).concat([{ title: d.title, left: d.left, movie: m.id }]);
        toast('✓ ' + d.title + ' – ' + d.total + ' ' + (T.charged || ''));
        play(d.url, d.title);
      });
    };
    openModal(el('div', { cls: 'tv-movie' }, [el('h2', { text: m.title }), el('p', { cls: 'tv-muted', text: [m.genre, m.rating, m.mins + ' min', m.year, m.lang].filter(Boolean).join(' · ') }),
      el('p', { text: m.synopsis || '' }),
      m.rented ? el('div', { cls: 'tv-row' }, [btn('▶ ' + (T.watch || 'Watch'), function () { play(m.url, m.title); }, 'tv-primary tv-big'), btn(T.back || 'Back', closeModal, 'tv-big')])
        : el('div', {}, [el('p', { cls: 'tv-price', text: m.price + ' · ' + m.hours + (T.hours || 'h') + ' · ' + (T.incl_tax || '') + ' · ' + (T.charged || '') }),
          el('div', { cls: 'tv-row' }, [btn((T.rent || 'Rent') + ' ' + m.price, function () { if (S.pin && S.pin.purchase || m.adult) pinModal(rent); else rent(null); }, 'tv-primary tv-big'), btn(T.cancel || 'Cancel', closeModal, 'tv-big')])])]));
  }
  function money(n, sample) {
    var sym = String(sample || '').replace(/[\d.,\s-]/g, '') || '';
    var s = n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return sym + ' ' + s;
  }
  // a dish that comes with a side dish asks for it first, then for its options (v9.33)
  function pickItem(it, box) {
    if (it.sides && it.sides.length) { sideModal(it, box); return; }
    if (it.modifiers && it.modifiers.length) { modifierModal(it, box); return; }
    addCart(it);
    drawCart(box);
  }
  function sideModal(it, box, then) {
    var counts = {}, sides = it.sides || [], list = el('div', { cls: 'tv-list' }), note = el('p', { cls: 'tv-muted' });
    function picked() {
      var out = [];
      sides.forEach(function (s) {
        var n = counts[s.id] || 0;
        if (n > 0) out.push({ id: s.id, name: s.name, rate: Number(s.rate || 0), qty: n });
      });
      return out;
    }
    var confirm = btn(T.add || 'Add to order', function () {
      var chosen = picked();
      if (it.side_required && !chosen.length) { toast(T.choose_side || 'This dish comes with a side dish – choose one.', true); return; }
      closeModal();
      if (then) { then(chosen); return; }
      if (it.modifiers && it.modifiers.length) { modifierModal(it, box, chosen); return; }
      addCart(it, [], chosen);
      drawCart(box);
    }, 'tv-primary');
    function draw() {
      clear(list);
      sides.forEach(function (s) {
        var n = counts[s.id] || 0;
        list.appendChild(el('div', { cls: 'tv-side-row' }, [
          el('span', { cls: 'tv-side-n', text: s.name + (Number(s.rate) ? ' +' + Number(s.rate).toFixed(2) : ' · ' + (T.included || 'included')) }),
          btn('−', function () { if ((counts[s.id] || 0) > 0) { counts[s.id] = (counts[s.id] || 0) - 1; if (!counts[s.id]) delete counts[s.id]; draw(); } }, 'tv-q'),
          el('b', { cls: 'tv-side-q', text: '×' + n }),
          btn('+', function () { if ((counts[s.id] || 0) < 9) { counts[s.id] = (counts[s.id] || 0) + 1; draw(); } }, 'tv-q'),
        ]));
      });
      var n = picked().length;
      note.textContent = it.side_required && !n ? (T.choose_side || 'This dish comes with a side dish – choose one.') : '';
      confirm.textContent = n ? (T.add || 'Add to order') : (it.side_required ? (T.side || 'Side dish') : (T.no_side || 'No side dish'));
    }
    openModal(el('div', {}, [el('h2', { text: it.name + ' · ' + (T.side || 'Side dish') }), list, note,
      el('div', { cls: 'tv-row' }, [confirm, btn(T.cancel || 'Cancel', closeModal)])]));
    draw();
  }
  function modifierModal(it, box, sides) {
    var selected = {}, groups = it.modifiers || [], list = el('div', { cls: 'tv-list' });
    groups.forEach(function (g) {
      list.appendChild(el('h3', { text: g.name + ' · choose ' + g.min + '–' + g.max }));
      (g.options || []).forEach(function (o) {
        var option = { id: o.id, name: o.name, price_delta: o.price_delta, group_id: g.id };
        var b = btn(o.name + (Number(o.price_delta) ? ' ' + (Number(o.price_delta) > 0 ? '+' : '') + Number(o.price_delta).toFixed(2) : ''), function () {
          if (selected[o.id]) { delete selected[o.id]; b.className = 'tv-btn tv-li'; } else { selected[o.id] = option; b.className = 'tv-btn tv-li tv-primary'; }
        }, 'tv-li');
        list.appendChild(b);
      });
    });
    var confirm = btn(T.add || 'Add to order', function () {
      var picked = Object.keys(selected).map(function (id) { return selected[id]; });
      for (var i = 0; i < groups.length; i++) {
        var count = picked.filter(function (o) { return Number(o.group_id) === Number(groups[i].id); }).length;
        if (count < Number(groups[i].min) || count > Number(groups[i].max)) { toast('Choose between ' + groups[i].min + ' and ' + groups[i].max + ' for ' + groups[i].name + '.', true); return; }
      }
      addCart(it, picked, sides || []); closeModal(); drawCart(box);
    }, 'tv-primary');
    openModal(el('div', {}, [el('h2', { text: it.name }), list, el('div', { cls: 'tv-row' }, [confirm, btn(T.cancel || 'Cancel', closeModal)])]));
  }
  function addCart(it, modifiers, sides) {
    modifiers = modifiers || []; sides = sides || [];
    var ids = modifiers.map(function (m) { return Number(m.id); }).sort(function (a, b) { return a - b; });
    // a double portion is the same side dish named twice, so the line carries the units and the count (v9.33)
    var units = [];
    sides.forEach(function (s) { for (var i = 0, n = Math.max(1, Number(s.qty) || 1); i < n; i++) units.push(Number(s.id)); });
    units.sort(function (a, b) { return a - b; });
    var key = it.id + ':' + ids.join(',') + '|s:' + units.join(',');
    var delta = modifiers.reduce(function (sum, m) { return sum + Number(m.price_delta || 0); }, 0)
      + sides.reduce(function (sum, s) { return sum + Number(s.rate || 0) * Math.max(1, Number(s.qty) || 1); }, 0);
    if (!cart[key]) cart[key] = { id: it.id, name: it.name, raw: Number(it.raw) + delta, price: money(Number(it.raw) + delta, it.price), qty: 0, modifiers: modifiers, sides: sides, units: units };
    if (cart[key].qty < 20) cart[key].qty++;
    toast('+1 ' + it.name);
  }
  function drawCart(box) {
    clear(box);
    box.appendChild(el('h3', { text: T.cart || 'Your order' }));
    var ids = Object.keys(cart).filter(function (k) { return cart[k].qty > 0; }), total = 0, sample = '';
    ids.forEach(function (k) {
      var c = cart[k];
      total += c.raw * c.qty; sample = c.price;
      var label = c.qty + ' × ' + c.name + (c.modifiers && c.modifiers.length ? ' · ' + c.modifiers.map(function (m) { return m.name; }).join(', ') : '')
        + (c.sides && c.sides.length ? ' · ' + c.sides.map(function (x) { return x.name + (Number(x.qty) > 1 ? ' ×' + Number(x.qty) : ''); }).join(', ') : '');
      box.appendChild(el('div', { cls: 'tv-cart-l' }, [btn('−', function () { c.qty--; drawCart(box); }, 'tv-q'), el('span', { text: label }), btn('+', function () { if (c.qty < 20) c.qty++; drawCart(box); }, 'tv-q')]));
    });
    if (!ids.length) { box.appendChild(el('p', { cls: 'tv-muted', text: '—' })); return; }
    box.appendChild(el('div', { cls: 'tv-total' }, [el('span', { text: T.total || 'Total' }), el('b', { text: money(total, sample) })]));
    box.appendChild(btn(T.order || 'Place order', function () {
      var lines = ids.map(function (k) { return { id: cart[k].id, qty: cart[k].qty, modifiers: (cart[k].modifiers || []).map(function (m) { return m.id; }), variants: cart[k].units || [] }; });
      api('POST', '/order', { items: lines }, function (err, d) {
        if (err) { toast(err, true); return; }
        cart = {};
        drawCart(box);
        toast('✓ ' + (T.sent || '') + ' ' + d.number);
      });
    }, 'tv-primary'));
  }
  function serviceModal(c) {
    var list = el('div', { cls: 'tv-list' });
    c.items.forEach(function (it, i) {
      list.appendChild(btn(it, function () {
        api('POST', '/request', { category: c.key, item: it, quantity: 1 }, function (err, d) {
          if (err) { toast(err, true); return; }
          closeModal(); delete cache.services; toast('✓ ' + (T.sent || '') + ' ' + d.number); render();
        });
      }, 'tv-li'));
    });
    openModal(el('div', {}, [el('h2', { text: c.label }), list, el('div', { cls: 'tv-row' }, [btn(T.cancel || 'Cancel', closeModal)])]));
  }
  function wakeupModal() {
    var h = 6, m = 30, daily = false;
    var disp = el('div', { cls: 'tv-time' });
    var draw = function () { disp.textContent = pad(h) + ':' + pad(m); };
    draw();
    openModal(el('div', {}, [el('h2', { text: T.wakeup || 'Wake-up call' }),
      el('div', { cls: 'tv-row' }, [btn('▲ h', function () { h = (h + 1) % 24; draw(); }), btn('▼ h', function () { h = (h + 23) % 24; draw(); }), disp, btn('▲ m', function () { m = (m + 5) % 60; draw(); }), btn('▼ m', function () { m = (m + 55) % 60; draw(); })]),
      el('div', { cls: 'tv-row' }, [btn('☐ ' + (T.daily || 'Every day'), function () { daily = !daily; this.textContent = (daily ? '☑ ' : '☐ ') + (T.daily || 'Every day'); })]),
      el('div', { cls: 'tv-row' }, [btn(T.book || 'Book', function () {
        api('POST', '/wakeup', { time: pad(h) + ':' + pad(m), daily: daily }, function (err, d) {
          if (err) { toast(err, true); return; }
          closeModal(); delete cache.services; toast((T.wakeup || 'Wake-up call') + ' ' + d.at); render();
        });
      }, 'tv-primary'), btn(T.cancel || 'Cancel', closeModal)])]));
  }
  function msgModal(m) {
    if (!m.read) { api('POST', '/read/' + m.id, {}, function () {}); m.read = true; S.unread = Math.max(0, (S.unread || 1) - 1); }
    openModal(el('div', {}, [el('small', { cls: 'tv-muted', text: m.at }), el('h2', { text: m.title }), el('p', { cls: 'tv-lead', text: m.body }), el('div', { cls: 'tv-row' }, [btn(T.ok || 'OK', function () { closeModal(); if (screen === 'messages') render(); }, 'tv-primary')])]));
  }
  function pageModal(p) {
    logEvent('page', p.title);
    var body = el('div', { cls: 'tv-page' });
    String(p.body).split('\n').forEach(function (l) { if (l.trim()) body.appendChild(el('p', { text: l })); });
    openModal(el('div', {}, [el('h2', { text: p.title }), body, el('div', { cls: 'tv-row' }, [btn(T.back || 'Back', closeModal, 'tv-primary')])]));
  }
  function langModal() {
    var list = el('div', { cls: 'tv-langs' });
    Object.keys(S.langs || {}).forEach(function (l) { list.appendChild(btn(S.langs[l], function () { closeModal(); setLang(l); }, l === S.lang ? 'on' : '')); });
    openModal(el('div', {}, [el('h2', { text: T.language || 'Language' }), list]));
  }
  function setLang(l) {
    api('POST', '/language', { lang: l }, function (err) {
      if (err) { toast(err, true); return; }
      cache = {};
      refresh(true);
    });
  }

  // ───────────── heartbeat: state, commands, emergency, messages ─────────────
  function runCommands(list, boot) {
    (list || []).forEach(function (c) {
      var ok = true, res = null;
      if (c.command === 'reload') { api('POST', '/ack/' + c.id, { ok: true }, function () { if (!boot) location.reload(); }); return; }
      if (c.command === 'reset') { cache = {}; cart = {}; stack = []; currentCh = null; if (!boot) setTimeout(function () { refresh(true); }, 50); }
      else if (c.command === 'message') { openModal(el('div', {}, [el('h2', {}, [tvIcon('messages', 'tv-heading-icon')]), el('p', { cls: 'tv-lead', text: c.payload.text || '' }), el('div', { cls: 'tv-row' }, [btn(T.ok || 'OK', closeModal, 'tv-primary')])])); }
      else if (c.command === 'volume') { S.volume_max = c.payload.level; res = 'Volume limit ' + c.payload.level; try { if (window.webapis && webapis.avinfo) res += ' (applied)'; } catch (e) { /* ignore */ } }
      else if (c.command === 'power_off') { standby = true; sbEl.style.display = ''; }
      else if (c.command === 'power_on') { standby = false; sbEl.style.display = 'none'; }
      else if (c.command === 'tune') { if (S.mode === 'room' || S.mode === 'vacant') { go('tv'); setTimeout(function () { tuneNumber(c.payload.channel); }, 600); } else { ok = false; res = 'Not a room TV'; } }
      api('POST', '/ack/' + c.id, { ok: ok, result: res });
    });
  }
  function showEmergency() {
    if (S.emergency) {
      clear(emEl);
      emEl.appendChild(el('div', { cls: 'tv-em-in' }, [el('div', { cls: 'tv-em-icon', text: '⚠' }), el('h1', { text: (T.emergency || 'EMERGENCY') }), el('h2', { text: S.emergency.title }), el('p', { text: S.emergency.body }), el('small', { text: S.emergency.at })]));
      emEl.style.display = '';
      Array.prototype.forEach.call(document.querySelectorAll('video,audio'), function (v) { try { v.pause(); } catch (e) { /* ignore */ } });
      standby = false; sbEl.style.display = 'none';
    } else emEl.style.display = 'none';
  }
  // one high-priority message at a time, oldest first, never over another dialog (PIN pad, order…) – the next one follows when it closes
  function popHighPriority() {
    if (S.mode !== 'room' || modal || (S.welcome && S.welcome.show) || S.emergency) return;
    var q = (S.messages || []).filter(function (m) { return m.priority === 'high' && !m.read && !shownMsg[m.id]; });
    if (!q.length) return;
    var m = q[q.length - 1];   // the list is newest first
    shownMsg[m.id] = true;
    msgModal(m);
  }
  function apply(d, force) {
    var prevMode = S.mode, prevLang = S.lang, prevGuest = S.guest && S.guest.name, prevWelcome = S.welcome && S.welcome.show, prevPortal = S.portal, prevSig = JSON.stringify([S.events, S.messages, S.text]);
    if (d.portal && prevPortal && d.portal.url === prevPortal.url && prevPortal.qr) d.portal.qr = prevPortal.qr;
    S = d; T = d.t || T;
    document.documentElement.lang = S.lang || 'en';
    showEmergency();
    runCommands(d.commands);
    var guestChanged = (S.guest && S.guest.name) !== prevGuest;
    if (S.mode !== prevMode || guestChanged) {
      welcomeIntegrated = false;
      welcomeMarked = false;
      welcomeMarkPending = false;
    }
    if (force || S.mode !== prevMode || S.lang !== prevLang || guestChanged || (S.welcome && S.welcome.show && !prevWelcome)) {
      if (S.mode !== prevMode || guestChanged) { cache = {}; cart = {}; }
      startScreen();
    } else if (S.mode === 'signage' && JSON.stringify([S.events, S.messages, S.text]) !== prevSig) render();
    else renderTop();
    popHighPriority();
    if (screen === 'welcome' && S.portal && S.portal.url && !S.portal.qr) loadPortalQr(S.portal.url);
  }
  function startScreen() {
    stack = [];
    if (S.mode === 'room') {
      if (S.welcome && S.welcome.show) welcomeIntegrated = true;
      screen = welcomeIntegrated ? 'welcome' : 'home';
    } else {
      welcomeIntegrated = false;
      screen = S.mode;   // vacant / signage / off / unregistered
    }
    render();
    // Remove the server-rendered welcome only after the interactive TV screen is ready.
    if (preboot && preboot.parentNode) preboot.parentNode.removeChild(preboot);
    preboot = null;
  }
  function loadPortalQr(url) {
    if (!url || portalQrPending) return;
    portalQrPending = true;
    api('GET', '/portal-qr', null, function (err, d) {
      portalQrPending = false;
      if (!err && d && d.url === url && S.portal && S.portal.url === url) {
        S.portal = d;
        if (screen === 'welcome') render();
      }
    });
  }
  function refresh(force) {
    api('GET', '/state', null, function (err, d) { if (!err && d) apply(d, force); });
  }
  var lastResumeRefresh = 0;
  function resumeWelcome(force) {
    if (!launchWelcomeOnResume) return;
    var nowMs = Date.now();
    if (!force && nowMs - lastResumeRefresh < 2500) return;
    lastResumeRefresh = nowMs;
    api('GET', '/state', null, function (err, d) {
      if (err || !d) return;
      if (d.mode === 'room' && d.welcome && d.welcome.enabled) d.welcome.show = true;
      apply(d, true);
    });
  }
  function beat() {
    clearTimeout(hbTimer);
    refresh(false);
    hbTimer = setTimeout(beat, Math.max(10, S.heartbeat || 20) * 1000);
  }

  var wasPageHidden = false;
  document.addEventListener('visibilitychange', function () {
    var pageHidden = document.hidden === true || document.visibilityState === 'hidden';
    if (pageHidden) { wasPageHidden = true; return; }
    if (wasPageHidden) { wasPageHidden = false; resumeWelcome(true); }
  });
  window.addEventListener('focus', function () { resumeWelcome(false); });
  window.addEventListener('pageshow', function () { resumeWelcome(false); });

  // boot
  showEmergency();
  runCommands(S.commands, true);
  startScreen();
  popHighPriority();
  loadWeather();
  setInterval(loadWeather, 1800000);
  setInterval(renderTop, 30000);
  hbTimer = setTimeout(beat, Math.max(10, S.heartbeat || 20) * 1000);
})();
