/* v7.2 Nowills PMS – self-service lobby kiosk. Vanilla JS, no dependencies. Every step answers with the whole next screen. */
(function () {
  'use strict';
  var $ = function (id) { return document.getElementById(id); };
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function req(method, url, body, cb, isForm) {
    var x = new XMLHttpRequest();
    x.open(method, url, true);
    x.setRequestHeader('Accept', 'application/json');
    if (body && !isForm) { x.setRequestHeader('Content-Type', 'application/json'); }
    x.timeout = 30000;
    x.onreadystatechange = function () {
      if (x.readyState !== 4) { return; }
      var d = null;
      try { d = JSON.parse(x.responseText); } catch (e) { d = null; }
      cb(x.status, d);
    };
    x.send(body ? (isForm ? body : JSON.stringify(body)) : null);
  }

  // ───────────────────────── setup / pairing page ─────────────────────────
  var setup = $('kskSetup');
  if (setup) {
    var codeEl = $('kskPairCode'), msg = $('kskPairMsg'), poll = setup.getAttribute('data-poll'), key = 'kskPair:' + setup.getAttribute('data-hotel');
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
          try { localStorage.removeItem(key); localStorage.setItem('kskApp', d.url); } catch (e) { /* ignore */ }
          msg.textContent = 'Paired – starting…';
          location.replace(d.url);
        } else if (d && d.state === 'waiting') {
          codeEl.textContent = d.code;
          setTimeout(function () { wait(token); }, 4000);
        } else if (s === 429 || s === 0) {
          setTimeout(function () { wait(token); }, 10000);
        } else { start(); }
      });
    };
    var saved = null;
    try { saved = localStorage.getItem(key); } catch (e) { saved = null; }
    if (saved) { wait(saved); } else { start(); }
    return;
  }

  var root = $('ksk');
  if (!root) { return; }
  var BASE = root.getAttribute('data-base'), IDLE = parseInt(root.getAttribute('data-idle'), 10) || 90;
  var main = $('kskMain'), toastEl = $('kskToast'), helpBtn = $('kskHelp'), restartBtn = $('kskRestart'), stepsEl = $('kskSteps');
  var S = null, H = null, local = {}, lastAct = Date.now(), timers = {}, stream = null, busy = false;

  var I = {
    key: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="7.5" cy="15.5" r="4.5"/><path d="M10.7 12.3 21 2M16 7l3 3M18.5 4.5l2 2"/></svg>',
    out: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/></svg>',
    bed: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 20V8M2 16h20M22 20v-8a3 3 0 0 0-3-3h-8v7"/><circle cx="7" cy="11.5" r="2.5"/></svg>',
    check: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>',
    bell: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>',
    info: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>'
  };
  var FLOW = {
    checkin: [['find', 'Find'], ['details', 'Details'], ['document', 'ID'], ['sign', 'Sign'], ['pay', 'Pay'], ['room', 'Room'], ['keys', 'Keys'], ['done', 'Done']],
    checkout: [['find', 'Find'], ['bill', 'Bill'], ['feedback', 'Rate'], ['done', 'Done']],
    walkin: [['rooms', 'Room type'], ['details', 'Details'], ['document', 'ID'], ['sign', 'Sign'], ['pay', 'Pay'], ['room', 'Room'], ['keys', 'Keys'], ['done', 'Done']]
  };

  // ───────── helpers ─────────
  function clock() { var d = new Date(); $('kskClock').textContent = (d.getHours() < 10 ? '0' : '') + d.getHours() + ':' + (d.getMinutes() < 10 ? '0' : '') + d.getMinutes(); }
  clock(); setInterval(clock, 10000);
  function toast(m, ok) {
    toastEl.textContent = m; toastEl.style.background = ok ? '#1f8a5b' : ''; toastEl.hidden = false;
    clearTimeout(timers.toast); timers.toast = setTimeout(function () { toastEl.hidden = true; }, ok ? 3000 : 6000);
  }
  function stopTimers() { ['poll', 'back', 'home'].forEach(function (k) { clearTimeout(timers[k]); clearInterval(timers[k]); timers[k] = null; }); }
  function stopCam() { if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; } }
  function api(method, path, body, cb, isForm) {
    req(method, BASE + path, body, function (st, d) {
      busy = false; setBusy(false);
      if (st === 404 && path.indexOf('/v/') === 0) { toast('This visit has ended. Please start again.'); goHome(); return; }
      if (!d) { toast(st === 429 ? 'One moment please – too many taps. Try again in a few seconds.' : 'Connection problem – please try again.'); return; }
      cb(d, st);
    }, isForm);
  }
  function setBusy(on) { var b = main.querySelectorAll('.ksk-btn'); for (var i = 0; i < b.length; i++) { if (on) { b[i].setAttribute('data-busy', '1'); b[i].disabled = true; } else if (b[i].getAttribute('data-busy')) { b[i].removeAttribute('data-busy'); b[i].disabled = false; } } }
  function act(action, body, isForm) {
    if (!S || busy) { return; }
    busy = true; setBusy(true);
    api('POST', '/v/' + S.session + '/' + action, body || {}, handle, isForm);
  }
  function handle(d) {
    if (d.end) { endScreen(d.end, d.message, d); return; }
    if (d.error) { toast(d.error); return; }
    render(d);
  }
  function bk(b) {
    if (!b) { return ''; }
    return '<div class="ksk-bk"><div><b>' + esc(b.name) + '</b><br><span>' + esc(b.no) + ' · ' + esc(b.type || '') + '</span></div><div><span>' + esc(b.arrival) + ' → ' + esc(b.departure) +
      '</span><br><span>' + b.nights + ' night' + (b.nights > 1 ? 's' : '') + ' · ' + b.adults + ' adult' + (b.adults > 1 ? 's' : '') + (b.children ? ' · ' + b.children + ' child' : '') + '</span></div>' +
      (b.room ? '<span class="pill">Room ' + esc(b.room) + '</span>' : '') + '</div>';
  }
  function field(name, label, opts) {
    opts = opts || {};
    return '<div class="ksk-field' + (opts.all ? ' all' : '') + '"><label for="kf_' + name + '">' + esc(label) + '</label><input class="ksk-in" id="kf_' + name + '" name="' + name + '" type="' + (opts.type || 'text') + '"' +
      (opts.value != null ? ' value="' + esc(opts.value) + '"' : '') + (opts.ph ? ' placeholder="' + esc(opts.ph) + '"' : '') + (opts.mode ? ' inputmode="' + opts.mode + '"' : '') +
      (opts.max ? ' maxlength="' + opts.max + '"' : '') + ' autocomplete="off" autocapitalize="' + (opts.caps || 'words') + '" spellcheck="false"' + (opts.nokb ? ' data-nokb="1"' : '') + '>' +
      (opts.hint ? '<div class="ksk-hint">' + esc(opts.hint) + '</div>' : '') + '</div>';
  }
  function val(name) { var e = main.querySelector('[name="' + name + '"]'); return e ? (e.type === 'checkbox' ? (e.checked ? 1 : 0) : e.value.trim()) : ''; }
  function on(sel, fn) { var e = main.querySelector(sel); if (e) { e.addEventListener('click', function (ev) { ev.preventDefault(); fn(ev, e); }); } return e; }
  function steps(kind, step) {
    var f = FLOW[kind] || [], idx = -1;
    f.forEach(function (s, i) { if (s[0] === step) { idx = i; } });
    stepsEl.innerHTML = f.map(function (s, i) { return '<span class="' + (i === idx ? 'on' : (i < idx ? 'ok' : '')) + '">' + s[1] + '</span>'; }).join('');
  }
  function chrome(visit) { helpBtn.hidden = !visit; restartBtn.hidden = !visit; if (!visit) { stepsEl.innerHTML = ''; } }
  function paint(html) { stopCam(); kbHide(); main.innerHTML = html; main.scrollTop = 0; var f = main.querySelector('input.ksk-in:not([type=date]):not([data-nofocus])'); if (f && !('ontouchstart' in window)) { try { f.focus(); } catch (e) { /* ignore */ } } }

  // ───────── home ─────────
  function goHome() {
    stopTimers(); stopCam(); kbHide();
    S = null; local = {}; chrome(false); $('kskIdle').hidden = true;
    req('GET', BASE + '/home', null, function (st, d) {
      if (!d) { main.innerHTML = '<div class="ksk-center"><div><div class="ksk-spin" style="margin:0 auto 1rem"></div><p class="ksk-lead">Connecting…</p></div></div>'; timers.home = setTimeout(goHome, 10000); return; }
      H = d; renderHome();
      timers.home = setInterval(function () { req('GET', BASE + '/home', null, function (s2, d2) { if (d2 && !S) { var changed = JSON.stringify(d2.offer) !== JSON.stringify(H.offer) || d2.active !== H.active; H = d2; if (changed) { renderHome(); } } }); }, 60000);
    });
  }
  function renderHome() {
    if (!H.active) {
      main.innerHTML = '<div class="ksk-center"><div><div class="ksk-tick info">' + I.info + '</div><h1 class="ksk-h">Please see the front desk</h1><p class="ksk-lead">This kiosk is not in service right now. Our team will be happy to help you.</p></div></div>';
      return;
    }
    var o = H.offer, any = o.checkin || o.checkout || o.walkin;
    var h = new Date().getHours(), greet = h < 12 ? 'Good morning' : (h < 17 ? 'Good afternoon' : 'Good evening');
    main.innerHTML = '<div class="ksk-home"><div class="ksk-hello"><h1>' + greet + '. <em>Akwaaba!</em></h1><p>' + esc(H.welcome) + '</p></div>' +
      '<div class="ksk-tiles">' +
      (o.checkin ? '<button class="ksk-tile t-in" data-kind="checkin" id="kskGoCheckin"><i>' + I.key + '</i><b>Check in</b><span>Have a booking? Get your room key here.</span><em class="go">→</em></button>' : '') +
      (o.checkout ? '<button class="ksk-tile t-out" data-kind="checkout" id="kskGoCheckout"><i>' + I.out + '</i><b>Check out</b><span>Review your bill, pay and you\'re done.</span><em class="go">→</em></button>' : '') +
      (o.walkin ? '<button class="ksk-tile t-walk" data-kind="walkin" id="kskGoWalkin"><i>' + I.bed + '</i><b>Book a room</b><span>No booking? See tonight\'s rooms and rates.</span><em class="go">→</em></button>' : '') +
      (!any ? '<div class="ksk-card"><h2 class="ksk-h">Please see the front desk</h2><p class="ksk-sub">Self-service is not available at the moment.</p></div>' : '') +
      '</div><div class="ksk-foot"><span>' + esc(H.date) + ' · Need help? Our front desk team is right here.</span><span class="ksk-pay-logos"><span>Mobile Money</span><span>Visa</span><span>Mastercard</span></span></div></div>';
    Array.prototype.forEach.call(main.querySelectorAll('.ksk-tile'), function (b) {
      b.addEventListener('click', function () {
        if (busy) { return; }
        busy = true;
        api('POST', '/start', { kind: b.getAttribute('data-kind') }, handle);
      });
    });
  }

  // ───────── screens ─────────
  function render(d) {
    stopTimers();
    S = d; chrome(d.status === 'active'); steps(d.kind, d.step);
    ({ find: sFind, details: sDetails, document: sDocument, sign: sSign, pay: sPay, room: sRoom, keys: sKeys, bill: sBill, feedback: sFeedback, rooms: sWalkin, done: sDone }[d.step] || sUnknown)(d);
  }
  function sUnknown() { paint('<div class="ksk-center"><div class="ksk-card"><h2 class="ksk-h">One moment…</h2><button class="ksk-btn" id="kskHome">Back to start</button></div></div>'); on('#kskHome', goHome); }

  function sFind(d) {
    var out = d.kind === 'checkout', tab = local.findTab || 'ref';
    paint('<div class="ksk-wrap ksk-narrow"><div class="ksk-card"><h2 class="ksk-h">' + (out ? 'Checking out?' : 'Let\'s find your booking') + '</h2>' +
      '<p class="ksk-sub">' + (out ? 'Enter your room number (or booking number) and your surname.' : 'Enter the booking number from your confirmation and the surname on the booking.') + '</p>' +
      (!out ? '<div class="ksk-tabs"><button class="' + (tab === 'ref' ? 'on' : '') + '" data-tab="ref">Booking number</button><button class="' + (tab === 'qr' ? 'on' : '') + '" data-tab="qr" id="kskTabQr">Scan my QR code</button></div>' : '') +
      (tab === 'qr' && !out
        ? '<div class="ksk-form"><div class="ksk-field all"><label for="kf_qr">QR code</label><input class="ksk-in" id="kf_qr" name="qr" data-nokb="1" autocomplete="off" placeholder="Hold the QR code under the scanner…"><div class="ksk-hint">Use the QR code from your pre-arrival e-mail (online check-in).</div></div></div>'
        : '<div class="ksk-form">' + field('ref', out ? 'Room or booking number' : 'Booking number', { ph: out ? 'e.g. 204' : 'e.g. RES-00073', caps: 'characters', max: 40 }) + field('last_name', 'Surname', { ph: 'as on the booking', max: 80 }) + '</div>') +
      '<div class="ksk-actions"><button class="ksk-link" id="kskNoBooking">' + (out ? '' : 'I can\'t find my booking number') + '</button><button class="ksk-btn" id="kskFind">Continue</button></div></div></div>');
    Array.prototype.forEach.call(main.querySelectorAll('[data-tab]'), function (b) { b.addEventListener('click', function () { local.findTab = b.getAttribute('data-tab'); sFind(d); }); });
    var go = function () { act('find', tab === 'qr' && !out ? { qr: val('qr') } : { ref: val('ref'), last_name: val('last_name') }); };
    on('#kskFind', go);
    if (!out) { on('#kskNoBooking', function () { act('assist', { note: 'Guest could not find the booking number' }); }); }
    var qr = main.querySelector('[name=qr]');
    if (qr) { qr.focus(); qr.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); go(); } }); }
    main.querySelectorAll('input').forEach(function (i) { i.addEventListener('keydown', function (e) { if (e.key === 'Enter' && i.name !== 'qr') { e.preventDefault(); go(); } }); });
  }

  function sDetails(d) {
    var x = d.details || {}, types = x.id_types || {}, home = x.home || [], purposes = x.purposes || {};
    var opt = function (o, cur) { return Object.keys(o).map(function (k) { return '<option value="' + esc(k) + '"' + (k === cur ? ' selected' : '') + '>' + esc(o[k]) + '</option>'; }).join(''); };
    paint('<div class="ksk-wrap"><div class="ksk-card"><h2 class="ksk-h">Welcome, ' + esc(d.booking.first_name || 'guest') + '!</h2><p class="ksk-sub">Please check your details. Leave a field blank to keep what we have on file.</p>' + bk(d.booking) +
      '<div class="ksk-form">' +
      field('phone', 'Mobile number', { type: 'tel', mode: 'tel', ph: x.phone ? 'on file: ' + x.phone : '+233 …', max: 30 }) +
      field('email', 'E-mail', { type: 'email', mode: 'email', ph: x.email ? 'on file: ' + x.email : 'for your receipt', caps: 'none', max: 150 }) +
      field('nationality', 'Nationality', { value: x.nationality || '', ph: 'e.g. Ghana', max: 60 }) +
      '<div class="ksk-field"><label for="kf_id_type">ID document</label><select class="ksk-in" id="kf_id_type" name="id_type">' + opt(types, x.id_type || 'ghana_card') + '</select></div>' +
      field('id_number', 'ID number', { ph: x.id_number ? 'on file: ' + x.id_number : 'e.g. GHA-123456789-0', caps: 'characters', max: 60, all: true }) +
      '<div class="all" id="kskForeign"' + (x.foreign ? '' : ' hidden') + '><div class="ksk-form"><hr class="ksk-sep"><div class="all ksk-small">Visitors to Ghana: we must record your passport and visa for the immigration register.</div>' +
      field('passport_no', 'Passport number', { ph: x.passport ? 'on file: ' + x.passport : '', caps: 'characters', max: 40 }) +
      '<div class="ksk-field"><label for="kf_pe">Passport expiry date</label><input class="ksk-in" type="date" id="kf_pe" name="passport_expires_on"></div>' +
      field('visa_no', 'Visa number (if any)', { caps: 'characters', max: 30 }) +
      '<div class="ksk-field"><label for="kf_purpose">Purpose of visit</label><select class="ksk-in" id="kf_purpose" name="purpose">' + opt(purposes, 'tourism') + '</select></div>' +
      field('arrived_from', 'Arriving from', { ph: 'city / country', max: 120 }) + field('next_destination', 'Next destination', { ph: 'city / country', max: 120 }) +
      '</div></div></div><div class="ksk-actions"><span class="ksk-small">Your details are protected under Ghana\'s Data Protection Act.</span><button class="ksk-btn" id="kskDetails">Continue</button></div></div></div>');
    var nat = main.querySelector('[name=nationality]');
    nat.addEventListener('input', function () { var v = nat.value.trim().toLowerCase(); $('kskForeign').hidden = !home.length || !v || home.indexOf(v) >= 0; });
    on('#kskDetails', function () {
      var b = {};
      ['phone', 'email', 'nationality', 'id_type', 'id_number', 'passport_no', 'passport_expires_on', 'visa_no', 'purpose', 'arrived_from', 'next_destination'].forEach(function (k) { var v = val(k); if (v !== '') { b[k] = v; } });
      if ($('kskForeign').hidden) { ['passport_expires_on', 'visa_no', 'purpose', 'arrived_from', 'next_destination'].forEach(function (k) { delete b[k]; }); }
      act('details', b);
    });
  }

  function sDocument(d) {
    paint('<div class="ksk-wrap ksk-narrow"><div class="ksk-card"><h2 class="ksk-h">Photo of your ID</h2><p class="ksk-sub">Hold your Ghana Card, passport or driving licence flat inside the frame, photo side up.</p>' +
      '<div class="ksk-cam" id="kskCam"><div class="nocam" id="kskNoCam">Starting the camera…</div><video id="kskVideo" autoplay playsinline muted hidden></video><img id="kskShot" alt="" hidden><div class="frame" id="kskFrame"></div></div>' +
      '<input type="file" accept="image/*" capture="environment" class="ksk-file" id="kskFile">' +
      '<div class="ksk-actions"><button class="ksk-link" id="kskUpload">Use the document scanner instead</button><span><button class="ksk-btn light" id="kskRetake" hidden>Retake</button> <button class="ksk-btn" id="kskSnap" disabled>Take photo</button> <button class="ksk-btn" id="kskUse" hidden>Use this photo</button></span></div></div></div>');
    var video = $('kskVideo'), shot = $('kskShot'), snap = $('kskSnap'), use = $('kskUse'), retake = $('kskRetake'), noCam = $('kskNoCam'), file = $('kskFile'), data = null;
    var live = function () { shot.hidden = true; video.hidden = false; $('kskFrame').hidden = false; snap.hidden = false; use.hidden = true; retake.hidden = true; data = null; };
    if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
      navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment', width: { ideal: 1920 }, height: { ideal: 1080 } }, audio: false }).then(function (st) {
        stream = st; video.srcObject = st; noCam.hidden = true; video.hidden = false; snap.disabled = false;
      }).catch(function () { noCam.textContent = 'The camera is not available – use the document scanner button below, or call for help.'; });
    } else { noCam.textContent = 'The camera is not available – use the document scanner button below, or call for help.'; }
    on('#kskSnap', function () {
      if (!video.videoWidth) { return; }
      var c = document.createElement('canvas'), w = Math.min(1600, video.videoWidth), h = Math.round(video.videoHeight * w / video.videoWidth);
      c.width = w; c.height = h; c.getContext('2d').drawImage(video, 0, 0, w, h);
      data = c.toDataURL('image/jpeg', 0.85);
      shot.src = data; shot.hidden = false; video.hidden = true; $('kskFrame').hidden = true; snap.hidden = true; use.hidden = false; retake.hidden = false;
    });
    on('#kskRetake', live);
    on('#kskUse', function () { if (data) { act('document', { image: data }); } });
    on('#kskUpload', function () { file.click(); });
    file.addEventListener('change', function () {
      if (!file.files || !file.files[0]) { return; }
      var fd = new FormData(); fd.append('photo', file.files[0]);
      busy = true; setBusy(true);
      api('POST', '/v/' + S.session + '/document', fd, handle, true);
    });
  }

  function sSign(d) {
    var g = d.grc || {}, b = d.booking;
    paint('<div class="ksk-wrap"><div class="ksk-card"><h2 class="ksk-h">Registration card</h2><p class="ksk-sub">Please read and sign with your finger.</p>' +
      '<div class="ksk-grc"><div><small>Guest</small><b>' + esc(b.name) + '</b></div><div><small>Booking</small><b>' + esc(b.no) + '</b></div><div><small>Room type</small><b>' + esc(b.type) + '</b></div>' +
      '<div><small>Arrival</small><b>' + esc(b.arrival) + '</b></div><div><small>Departure</small><b>' + esc(b.departure) + ' · by ' + esc(g.checkout_time) + '</b></div><div><small>Nightly rate incl. taxes</small><b>' + esc(g.rate) + '</b></div></div>' +
      '<p class="ksk-terms">' + esc(g.terms) + '</p>' +
      '<div class="ksk-sig" id="kskSig"><canvas id="kskPad"></canvas><div class="line"></div><div class="x">✕</div><button class="ksk-btn light" id="kskClear" style="min-height:0;padding:.45rem 1rem;font-size:.9rem">Clear</button></div>' +
      '<div class="ksk-actions"><label class="ksk-check"><input type="checkbox" name="accept" id="kskAccept"> <span>I have read and accept the terms above.</span></label><button class="ksk-btn" id="kskSign">Sign &amp; continue</button></div></div></div>');
    var cv = $('kskPad'), box = $('kskSig'), ctx, drawing = false, ink = 0, lx = 0, ly = 0;
    var size = function () { var r = box.getBoundingClientRect(), k = Math.min(2, window.devicePixelRatio || 1); cv.width = Math.min(1900, Math.round(r.width * k)); cv.height = Math.min(900, Math.round(r.height * k)); ctx = cv.getContext('2d'); ctx.lineWidth = 2.6 * k; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#10231e'; ink = 0; };
    size();
    var pt = function (e) { var r = cv.getBoundingClientRect(); return [(e.clientX - r.left) * cv.width / r.width, (e.clientY - r.top) * cv.height / r.height]; };
    cv.addEventListener('pointerdown', function (e) { e.preventDefault(); drawing = true; var p = pt(e); lx = p[0]; ly = p[1]; try { cv.setPointerCapture(e.pointerId); } catch (x) { /* ignore */ } });
    cv.addEventListener('pointermove', function (e) { if (!drawing) { return; } var p = pt(e); ctx.beginPath(); ctx.moveTo(lx, ly); ctx.lineTo(p[0], p[1]); ctx.stroke(); ink += Math.abs(p[0] - lx) + Math.abs(p[1] - ly); lx = p[0]; ly = p[1]; });
    var end = function () { drawing = false; };
    cv.addEventListener('pointerup', end); cv.addEventListener('pointercancel', end); cv.addEventListener('pointerleave', end);
    on('#kskClear', function () { ctx.clearRect(0, 0, cv.width, cv.height); ink = 0; });
    on('#kskSign', function () {
      if (ink < 60) { toast('Please sign in the box.'); return; }
      if (!$('kskAccept').checked) { toast('Please tick to accept the registration terms.'); return; }
      act('sign', { signature: cv.toDataURL('image/png'), accept: 1 });
    });
  }

  function lines(rows, total, totalLabel) {
    return '<table class="ksk-lines">' + rows.map(function (l) { return '<tr><td>' + esc(l[0]) + '</td><td class="' + (String(l[1]).indexOf('-') === 0 ? 'neg' : '') + '">' + esc(l[1]) + '</td></tr>'; }).join('') +
      '<tr class="tot"><td>' + esc(totalLabel) + '</td><td>' + esc(total) + '</td></tr></table>';
  }
  function qrPanel(svg, payNo) {
    return '<div class="ksk-qr" id="kskQr">' + svg + '<p><b>Scan with your phone camera</b><br>Pay by mobile money (MTN MoMo, Telecel Cash, AirtelTigo) or card. Ref ' + esc(payNo) + '</p><div class="ksk-wait"><i class="ksk-dot"></i> Waiting for your payment…</div></div>';
  }
  function pollPay() {
    clearTimeout(timers.poll);
    timers.poll = setTimeout(function () {
      if (!S) { return; }
      req('GET', BASE + '/v/' + S.session + '/pay-status', null, function (st, d) {
        if (!S) { return; }
        if (!d || st >= 500 || st === 429) { pollPay(); return; }
        if (d.end || d.error) { handle(d); return; }
        var p = d.pay || {};
        if (p.state === 'paid') { toast('Payment received – thank you!', true); lastAct = Date.now(); render(d); return; }
        if (p.state === 'failed' || p.state === 'review') {
          toast(p.state === 'review' ? 'Your payment is being checked – please see the front desk.' : ((p.message || 'The payment did not go through.') + ' You can try again.'));
          render(d); return;
        }
        pollPay();
      });
    }, 3000);
  }
  function sPay(d) {
    var p = d.pay || {};
    paint('<div class="ksk-wrap"><div class="ksk-card"><h2 class="ksk-h">Payment</h2><p class="ksk-sub">' + (d.kind === 'walkin' ? 'Your stay is paid in advance.' : 'To finish your check-in, please settle the amount below.') + '</p>' + bk(d.booking) +
      '<div class="ksk-pay"><div>' + lines(p.lines || [], p.due, 'To pay now') + '</div><div>' +
      (p.qr ? qrPanel(p.qr, p.pay_no) : '<button class="ksk-btn gold wide" id="kskPay">Pay ' + esc(p.due) + '</button><p class="ksk-small" style="text-align:center">You\'ll get a QR code to pay safely on your own phone – mobile money or card.</p>') +
      '</div></div><div class="ksk-actions"><button class="ksk-link" id="kskPayDesk">I\'d rather pay at the front desk</button><span></span></div></div></div>');
    on('#kskPay', function () { act('pay'); });
    on('#kskPayDesk', function () { act('assist', { reason: 'pay_desk', note: 'Guest chose to pay ' + p.due + ' at the desk' }); });
    if (p.qr) { pollPay(); }
  }

  function sRoom(d) {
    var rooms = d.rooms || [], choice = d.choice && rooms.length > 1;
    local.room = local.room && rooms.some(function (r) { return r.id === local.room; }) ? local.room : (rooms[0] && rooms[0].id);
    var sel = function () { var r = rooms.filter(function (x) { return x.id === local.room; })[0]; return r ? r.no : ''; };
    paint('<div class="ksk-wrap"><div class="ksk-card"><h2 class="ksk-h">' + (choice ? 'Choose your room' : 'Your room is ready') + '</h2><p class="ksk-sub">' + (choice ? 'These ' + esc(d.booking.type) + ' rooms are ready for you now.' : 'We have prepared a ' + esc(d.booking.type) + ' for you.') + '</p>' +
      (choice ? '<div class="ksk-rooms" id="kskRooms">' + rooms.map(function (r) { return '<button class="ksk-room' + (r.id === local.room ? ' on' : '') + '" data-room="' + r.id + '"><b>' + esc(r.no) + '</b><span>' + esc(r.floor || '') + (r.smoking ? ' · smoking' : '') + '</span></button>'; }).join('') + '</div>' : '') +
      '<div class="ksk-actions"><span class="ksk-small">' + (rooms.length ? '' : 'Checking room availability…') + '</span><button class="ksk-btn gold" id="kskRoom">' + (choice ? 'Check in to room <span id="kskRoomNo">' + esc(sel()) + '</span>' : 'Check me in') + '</button></div></div></div>');
    Array.prototype.forEach.call(main.querySelectorAll('[data-room]'), function (b) {
      b.addEventListener('click', function () { local.room = parseInt(b.getAttribute('data-room'), 10); main.querySelectorAll('.ksk-room').forEach(function (x) { x.classList.toggle('on', x === b); }); $('kskRoomNo').textContent = sel(); });
    });
    on('#kskRoom', function () { act('room', { room_id: local.room || null }); });
  }

  function sKeys(d) {
    var k = d.keys || { max: 2 }, n = local.keys || Math.min(k.max, Math.max(1, Math.min(2, (d.booking && d.booking.adults) || 1)));
    local.keys = n;
    var btns = ''; for (var i = 1; i <= k.max; i++) { btns += '<button data-n="' + i + '" class="' + (i === n ? 'on' : '') + '">' + i + '<small>card' + (i > 1 ? 's' : '') + '</small></button>'; }
    paint('<div class="ksk-wrap ksk-narrow"><div class="ksk-card"><h2 class="ksk-h">You\'re checked in to room ' + esc(k.room) + '!</h2><p class="ksk-sub">How many key cards would you like?</p>' +
      '<div class="ksk-keys" id="kskKeys">' + btns + '</div>' +
      '<div class="ksk-enc"><div class="card"></div><div><b>Place a blank card on the encoder</b><br><span class="ksk-small">Take the top card from the tray, lay it on the reader below the screen and keep it there until the tick appears.</span></div></div>' +
      '<div class="ksk-actions"><span></span><button class="ksk-btn gold" id="kskEncode">Encode ' + n + ' key card' + (n > 1 ? 's' : '') + '</button></div></div></div>');
    Array.prototype.forEach.call(main.querySelectorAll('[data-n]'), function (b) { b.addEventListener('click', function () { local.keys = parseInt(b.getAttribute('data-n'), 10); sKeys(d); }); });
    on('#kskEncode', function () { act('keys', { count: local.keys }); });
  }

  function sBill(d) {
    var b = d.bill || {}, owing = b.balance > 0.009;
    var rows = (b.lines || []).map(function (l) { return '<tr><td>' + esc(l.date) + '</td><td>' + esc(l.text) + '</td><td class="' + (l.amount < 0 ? 'neg' : '') + '">' + esc(l.label) + '</td></tr>'; }).join('');
    paint('<div class="ksk-wrap"><div class="ksk-card"><h2 class="ksk-h">Your bill</h2><p class="ksk-sub">' + esc(b.name) + ' · room ' + esc(b.room) + ' · ' + esc(b.arrival) + ' – ' + esc(b.departure) + '</p>' +
      '<div class="ksk-pay"><div><div class="ksk-bill"><table class="ksk-lines">' + (rows || '<tr><td colspan="3">No charges.</td></tr>') + '</table></div>' +
      '<table class="ksk-lines"><tr class="tot"><td>' + (owing ? 'Balance to pay' : (b.credit ? 'Refund due to you' : 'Balance')) + '</td><td>' + esc(b.balance_label) + '</td></tr></table><p class="ksk-small">All prices include VAT, NHIL, GETFund and the tourism levy where they apply.</p></div><div>' +
      (owing ? (b.qr ? qrPanel(b.qr, b.pay_no) : '<button class="ksk-btn gold wide" id="kskPay">Pay ' + esc(b.balance_label) + '</button><p class="ksk-small" style="text-align:center">Pay safely on your own phone – mobile money or card.</p>')
        : '<div style="text-align:center"><div class="ksk-tick">' + I.check + '</div><p><b>' + (b.credit ? 'Our team will return your refund.' : 'Your bill is settled.') + '</b></p><button class="ksk-btn gold wide" id="kskCheckout">Check out now</button></div>') +
      '</div></div><div class="ksk-actions"><button class="ksk-link" id="kskQuery">I have a question about my bill</button><span></span></div></div></div>');
    on('#kskPay', function () { act('pay'); });
    on('#kskCheckout', function () { act('checkout'); });
    on('#kskQuery', function () { act('assist', { note: 'Guest has a question about the bill' }); });
    if (owing && b.qr) { pollPay(); }
  }

  function sFeedback() {
    paint('<div class="ksk-wrap ksk-narrow"><div class="ksk-card ksk-done"><div class="ksk-tick">' + I.check + '</div><h2 class="ksk-h">You\'re checked out</h2><p class="ksk-sub">How was your stay with us?</p>' +
      '<div class="ksk-stars" id="kskStars">' + [1, 2, 3, 4, 5].map(function (n) { return '<button data-r="' + n + '" aria-label="' + n + ' stars">★</button>'; }).join('') + '</div>' +
      '<button class="ksk-link" id="kskSkip">Skip</button></div></div>');
    var stars = main.querySelectorAll('[data-r]');
    Array.prototype.forEach.call(stars, function (b) {
      b.addEventListener('click', function () { var r = parseInt(b.getAttribute('data-r'), 10); Array.prototype.forEach.call(stars, function (x) { x.classList.toggle('on', parseInt(x.getAttribute('data-r'), 10) <= r); }); setTimeout(function () { act('feedback', { rating: r }); }, 350); });
    });
    on('#kskSkip', function () { act('feedback', {}); });
  }

  function backHome(sec) {
    var n = sec, el = main.querySelector('.ksk-count');
    clearInterval(timers.back);
    timers.back = setInterval(function () { n--; if (el) { el.textContent = 'This screen closes in ' + n + ' s'; } if (n <= 0) { goHome(); } }, 1000);
  }
  function sDone(d) {
    chrome(false); S = { session: d.session, kind: d.kind, done: true };
    if (d.farewell) {
      var f = d.farewell;
      paint('<div class="ksk-wrap ksk-narrow"><div class="ksk-card ksk-done"><div class="ksk-tick">' + I.check + '</div><h2 class="ksk-h">Thank you, ' + esc(f.name) + '!</h2><p class="ksk-sub">You are checked out. Please drop your key cards in the box at the front desk. Safe travels – <b>nante yie</b>!</p>' +
        (f.invoice && f.email ? '<button class="ksk-btn gold" id="kskInvoice">E-mail my invoice to ' + esc(f.email) + '</button>' : '') +
        '<div class="ksk-actions" style="justify-content:center"><button class="ksk-btn light" id="kskFinish">Finish</button></div><div class="ksk-count">This screen closes in 45 s</div></div></div>');
      on('#kskInvoice', function (e, btn) {
        btn.disabled = true;
        req('POST', BASE + '/v/' + d.session + '/invoice', {}, function (st, r) {
          if (r && r.sent) { toast('Invoice sent to ' + r.sent, true); btn.textContent = 'Invoice sent ✓'; } else { btn.disabled = false; toast((r && r.error) || 'The invoice could not be sent.'); }
        });
      });
      on('#kskFinish', goHome);
      backHome(45);
      return;
    }
    var w = d.welcome || {};
    paint('<div class="ksk-wrap"><div class="ksk-card ksk-done" id="kskWelcome"><p class="big">Welcome, ' + esc(w.name) + '! Your room is</p><div class="ksk-roomno" id="kskRoomDone">' + esc(w.room) + '</div><p class="ksk-sub">' + esc(w.floor || '') + ' · ' + esc(w.type || '') + '</p>' +
      '<div class="ksk-facts">' + (w.wifi ? '<div><small>Wi-Fi</small><b>' + esc(w.wifi) + '</b></div><div><small>Wi-Fi password</small><b>' + esc(w.wifi_password) + '</b></div>' : '') +
      (w.tv_pin ? '<div><small>TV PIN (movies)</small><b id="kskPin">' + esc(w.tv_pin) + '</b></div>' : '') + '<div><small>Check-out</small><b>' + esc(w.departure) + ' by ' + esc(w.checkout_time) + '</b></div>' +
      '<div><small>Key cards</small><b>' + (w.keys ? w.keys + ' encoded' : 'at the front desk') + '</b></div></div>' +
      '<p class="ksk-small">' + esc(w.breakfast) + ' Your registration card and receipt are on file – ask the desk any time for a copy.</p>' +
      '<div class="ksk-actions" style="justify-content:center"><button class="ksk-btn gold" id="kskFinish">Enjoy your stay</button></div><div class="ksk-count">This screen closes in 60 s</div></div></div>');
    on('#kskFinish', goHome);
    backHome(60);
  }

  function sWalkin(d) {
    var w = d.walkin || { max_nights: 7, max_adults: 3 };
    local.nights = local.nights || 1; local.adults = local.adults || 1;
    if (local.stage === 'guest' && local.type) { return walkinGuest(d, w); }
    paint('<div class="ksk-wrap"><div class="ksk-card"><h2 class="ksk-h">Book a room for tonight</h2><p class="ksk-sub">Choose your stay – we\'ll show the rooms ready right now, with taxes included.</p>' +
      '<div class="ksk-form"><div class="ksk-field"><label>Nights</label><div class="ksk-stepper"><button data-s="nights:-1">−</button><output id="kskNights">' + local.nights + '</output><button data-s="nights:1">+</button></div></div>' +
      '<div class="ksk-field"><label>Adults</label><div class="ksk-stepper"><button data-s="adults:-1">−</button><output id="kskAdults">' + local.adults + '</output><button data-s="adults:1">+</button></div></div></div>' +
      '<div id="kskTypes" style="margin-top:1.3rem">' + (local.types ? typesHtml() : '') + '</div>' +
      '<div class="ksk-actions"><span class="ksk-small">Longer stays or groups? Our front desk will be glad to help.</span><button class="ksk-btn" id="kskShow">' + (local.types ? 'Continue' : 'Show available rooms') + '</button></div></div></div>');
    Array.prototype.forEach.call(main.querySelectorAll('[data-s]'), function (b) {
      b.addEventListener('click', function () {
        var p = b.getAttribute('data-s').split(':'), k = p[0], lim = k === 'nights' ? w.max_nights : w.max_adults;
        local[k] = Math.max(1, Math.min(lim, local[k] + parseInt(p[1], 10))); local.types = null; local.type = null; sWalkin(d);
      });
    });
    bindTypes();
    on('#kskShow', function () {
      if (local.types && local.type) { local.stage = 'guest'; sWalkin(d); return; }
      if (local.types) { toast('Please choose a room type.'); return; }
      if (busy) { return; }
      busy = true; setBusy(true);
      api('GET', '/v/' + d.session + '/walkin-offer?nights=' + local.nights + '&adults=' + local.adults, null, function (r) {
        if (r.error) { toast(r.error); return; }
        local.types = r.types || [];
        if (!local.types.length) { toast('Sorry – no rooms are ready for those dates right now. Please see the front desk.'); local.types = null; return; }
        local.type = local.types[0].id; sWalkin(d);
      });
    });
  }
  function typesHtml() {
    return '<div class="ksk-types">' + local.types.map(function (t) {
      return '<button class="ksk-type' + (t.id === local.type ? ' on' : '') + '" data-type="' + t.id + '"><div><b>' + esc(t.name) + '</b><small>' + esc(t.description || ('Up to ' + t.max_adults + ' adults')) + '</small></div><strong>' + esc(t.total_label) + '<small><br>' + esc(t.nightly_label) + ' / night</small></strong></button>';
    }).join('') + '</div>';
  }
  function bindTypes() {
    Array.prototype.forEach.call(main.querySelectorAll('[data-type]'), function (b) {
      b.addEventListener('click', function () { local.type = parseInt(b.getAttribute('data-type'), 10); main.querySelectorAll('.ksk-type').forEach(function (x) { x.classList.toggle('on', x === b); }); });
    });
  }
  function walkinGuest(d, w) {
    var t = local.types.filter(function (x) { return x.id === local.type; })[0] || {};
    paint('<div class="ksk-wrap"><div class="ksk-card"><h2 class="ksk-h">Your details</h2><p class="ksk-sub">' + esc(t.name) + ' · ' + local.nights + ' night' + (local.nights > 1 ? 's' : '') + ' · ' + local.adults + ' adult' + (local.adults > 1 ? 's' : '') + ' · <b>' + esc(t.total_label) + '</b> incl. taxes</p>' +
      '<div class="ksk-form">' + field('first_name', 'First name', { max: 80 }) + field('last_name', 'Surname', { max: 80 }) + field('phone', 'Mobile number', { type: 'tel', mode: 'tel', ph: '+233 …', max: 30 }) +
      field('email', 'E-mail', { type: 'email', mode: 'email', caps: 'none', ph: 'for your receipt', max: 150 }) + field('nationality', 'Nationality', { ph: 'e.g. Ghana', max: 60, all: true }) +
      '<div class="all"><p class="ksk-terms">' + esc(w.terms || '') + ' The full stay is paid now; cancellation after check-in is not refundable.</p><label class="ksk-check"><input type="checkbox" name="accept"> <span>I accept the booking terms.</span></label></div></div>' +
      '<div class="ksk-actions"><button class="ksk-link" id="kskBack">← Change room</button><button class="ksk-btn gold" id="kskBook">Book &amp; continue</button></div></div></div>');
    on('#kskBack', function () { local.stage = null; sWalkin(d); });
    on('#kskBook', function () {
      act('walkin', { nights: local.nights, adults: local.adults, room_type_id: local.type, first_name: val('first_name'), last_name: val('last_name'), phone: val('phone'), email: val('email'), nationality: val('nationality'), accept: val('accept') });
    });
  }

  function endScreen(kind, message) {
    stopTimers(); stopCam(); kbHide();
    if (kind === 'cancel') { goHome(); return; }
    S = null; chrome(false);
    var icon = kind === 'assist' ? '<div class="ksk-tick warn">' + I.bell + '</div>' : (kind === 'keys' ? '<div class="ksk-tick">' + I.key + '</div>' : '<div class="ksk-tick info">' + I.info + '</div>');
    var title = kind === 'assist' ? 'Help is on the way' : (kind === 'keys' ? 'Almost there!' : 'Thank you');
    paint('<div class="ksk-wrap ksk-narrow"><div class="ksk-card ksk-done" id="kskEnd" data-end="' + esc(kind) + '">' + icon + '<h2 class="ksk-h">' + title + '</h2><p class="ksk-sub" id="kskEndMsg">' + esc(message) + '</p>' +
      (kind === 'assist' ? '<p class="ksk-small">A member of our front desk team has been notified and will be with you in a moment. Please stay close to the kiosk.</p>' : '') +
      '<div class="ksk-actions" style="justify-content:center"><button class="ksk-btn light" id="kskFinish">Back to start</button></div><div class="ksk-count">This screen closes in 45 s</div></div></div>');
    on('#kskFinish', goHome);
    backHome(45);
  }

  // ───────── chrome buttons & idle ─────────
  helpBtn.addEventListener('click', function () { if (S && !S.done) { act('assist', {}); } });
  restartBtn.addEventListener('click', function () { if (S && !S.done) { var sid = S.session; S = null; req('POST', BASE + '/v/' + sid + '/cancel', {}, function () { /* ignore */ }); } goHome(); });
  ['pointerdown', 'keydown', 'input', 'touchstart'].forEach(function (ev) { document.addEventListener(ev, function () { lastAct = Date.now(); }, true); });
  var idleBox = $('kskIdle'), idleN = $('kskIdleN'), idleLeft = 0;
  setInterval(function () {
    if (!S || S.done || S.status !== 'active') { idleBox.hidden = true; return; }
    var paying = !!main.querySelector('#kskQr'), limit = (paying ? Math.max(IDLE, 300) : IDLE) * 1000;
    if (idleBox.hidden) {
      if (Date.now() - lastAct > limit) { idleLeft = 15; idleN.textContent = idleLeft; idleBox.hidden = false; }
    } else {
      idleLeft--; idleN.textContent = Math.max(0, idleLeft);
      if (Date.now() - lastAct < 1500) { idleBox.hidden = true; return; }
      if (idleLeft <= 0) { idleBox.hidden = true; var sid = S.session; S = null; req('POST', BASE + '/v/' + sid + '/cancel', {}, function () { /* ignore */ }); goHome(); }
    }
  }, 1000);
  $('kskIdleStay').addEventListener('click', function () { lastAct = Date.now(); idleBox.hidden = true; });

  // ───────── on-screen keyboard (touch kiosks without a system keyboard) ─────────
  var kb = $('kskKb'), kbTarget = null, kbShift = false, kbAlt = false;
  var ROWS = [['1', '2', '3', '4', '5', '6', '7', '8', '9', '0'], ['q', 'w', 'e', 'r', 't', 'y', 'u', 'i', 'o', 'p'], ['a', 's', 'd', 'f', 'g', 'h', 'j', 'k', 'l'], ['⇧', 'z', 'x', 'c', 'v', 'b', 'n', 'm', '⌫']];
  var ALT = [['1', '2', '3', '4', '5', '6', '7', '8', '9', '0'], ['@', '.', '-', '_', '+', '/', '(', ')', "'", '#'], ['.com', '.com.gh', 'gmail.com', 'yahoo.com'], ['⇧', ',', ':', '&', '!', '?', '⌫']];
  function kbDraw() {
    var rows = kbAlt ? ALT : ROWS, email = kbTarget && kbTarget.type === 'email';
    kb.innerHTML = rows.map(function (r) {
      return '<div class="row">' + r.map(function (k) {
        var lbl = k.length === 1 && kbShift && !kbAlt ? k.toUpperCase() : k;
        return '<button type="button" data-k="' + esc(k) + '" class="' + (k === '⇧' || k === '⌫' ? 'dark w2' : (k.length > 2 ? 'w2' : '')) + '">' + esc(lbl) + '</button>';
      }).join('') + '</div>';
    }).join('') + '<div class="row"><button type="button" data-k="alt" class="dark w2">' + (kbAlt ? 'ABC' : '@ 123') + '</button>' + (email ? '<button type="button" data-k="@">@</button>' : '') +
      '<button type="button" data-k=" " class="w6">space</button>' + (email ? '<button type="button" data-k=".">.</button>' : '') + '<button type="button" data-k="hide" class="dark w2">Hide ⌄</button></div>';
  }
  function kbShow(t) { kbTarget = t; kbShift = t.value === '' && t.getAttribute('autocapitalize') !== 'none'; kbAlt = t.type === 'tel'; kbDraw(); kb.hidden = false; document.body.classList.add('kb-open'); setTimeout(function () { try { t.scrollIntoView({ block: 'center', behavior: 'smooth' }); } catch (e) { /* ignore */ } }, 50); }
  function kbHide() { kb.hidden = true; kbTarget = null; document.body.classList.remove('kb-open'); }
  kb.addEventListener('pointerdown', function (e) { e.preventDefault(); });
  kb.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('button') : null;
    if (!b || !kbTarget) { return; }
    var k = b.getAttribute('data-k'), t = kbTarget, v = t.value, s = typeof t.selectionStart === 'number' && t.type !== 'email' ? t.selectionStart : v.length, en = typeof t.selectionEnd === 'number' && t.type !== 'email' ? t.selectionEnd : v.length;
    if (k === 'hide') { t.blur(); kbHide(); return; }
    if (k === 'alt') { kbAlt = !kbAlt; kbDraw(); return; }
    if (k === '⇧') { kbShift = !kbShift; kbDraw(); return; }
    if (k === '⌫') { if (s === en && s > 0) { s--; } t.value = v.slice(0, s) + v.slice(en); } else {
      var ch = k.length === 1 && kbShift && !kbAlt ? k.toUpperCase() : k;
      var max = parseInt(t.getAttribute('maxlength'), 10) || 999;
      if (v.length - (en - s) + ch.length > max) { return; }
      t.value = v.slice(0, s) + ch + v.slice(en); s += ch.length;
      if (kbShift && !kbAlt) { kbShift = false; kbDraw(); }
    }
    try { if (t.type !== 'email') { t.setSelectionRange(s, s); } } catch (x) { /* ignore */ }
    t.dispatchEvent(new Event('input', { bubbles: true }));
    lastAct = Date.now();
  });
  main.addEventListener('focusin', function (e) {
    var t = e.target;
    if (t.tagName === 'INPUT' && /^(text|email|tel|search)$/.test(t.type) && !t.getAttribute('data-nokb') && (('ontouchstart' in window) || navigator.maxTouchPoints > 0 || localStorage.getItem('kskKb') === '1')) { kbShow(t); }
  });
  main.addEventListener('focusout', function () { setTimeout(function () { var a = document.activeElement; if (!a || a.tagName !== 'INPUT') { kbHide(); } }, 120); });

  goHome();
})();
