/* v7.3 Guest Messaging – staff inbox: live refresh, quick replies, note mode, guest search, broadcast reach */
(function () {
  'use strict';
  var box = document.getElementById('inbox');
  var csrf = (document.querySelector('meta[name=csrf-token]') || {}).content;

  if (box) {
    var thread = document.getElementById('inbThread');
    var conv = box.dataset.c, last = parseInt(box.dataset.last || '0', 10), latest = null, busy = false;
    var toBottom = function () { if (thread) thread.scrollTop = thread.scrollHeight; };
    toBottom();

    var poll = function () {
      if (busy || document.hidden) return;
      busy = true;
      var u = box.dataset.poll + '?' + (conv ? 'c=' + conv + '&after=' + last : '');
      fetch(u, { headers: { Accept: 'application/json' }, credentials: 'same-origin' }).then(function (r) { return r.ok ? r.json() : null; }).then(function (d) {
        if (!d) return;
        if (d.messages && d.messages.length && thread) {
          var near = thread.scrollHeight - thread.scrollTop - thread.clientHeight < 120;
          d.messages.forEach(function (m) {
            if (thread.querySelector('[data-id="' + m.id + '"]')) return;
            thread.insertAdjacentHTML('beforeend', m.html); last = Math.max(last, m.id);
          });
          if (near) toBottom();
        }
        if (latest !== null && d.latest !== latest) refreshList();
        latest = d.latest;
        document.querySelectorAll('.nav-badge[title^="Guest conversations"]').forEach(function (b) { b.textContent = d.attention || ''; b.style.display = d.attention ? '' : 'none'; });
      }).catch(function () {}).finally(function () { busy = false; });
    };
    var refreshList = function () {
      fetch(location.href, { credentials: 'same-origin' }).then(function (r) { return r.text(); }).then(function (h) {
        var doc = new DOMParser().parseFromString(h, 'text/html');
        var items = doc.getElementById('inbItems'), tabs = doc.getElementById('inbFolders');
        if (items) document.getElementById('inbItems').innerHTML = items.innerHTML;
        if (tabs) document.getElementById('inbFolders').innerHTML = tabs.innerHTML;
      }).catch(function () {});
    };
    setInterval(poll, 10000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });

    // composer: reply / note mode, quick replies, Enter to send
    var form = document.getElementById('inbCompose');
    if (form) {
      var body = document.getElementById('inbBody'), pick = document.getElementById('inbQrPick'), qr = document.getElementById('inbQr');
      var sendBtn = document.getElementById('inbSend'), waitBtn = document.getElementById('inbSendWait'), hint = document.getElementById('inbHint'), hintText = hint.textContent;
      var setMode = function (m) {
        form.classList.toggle('note', m === 'note');
        sendBtn.value = m === 'note' ? 'note' : 'reply';
        sendBtn.lastChild.textContent = m === 'note' ? ' Add note' : ' Send';
        waitBtn.style.display = m === 'note' ? 'none' : '';
        hint.textContent = m === 'note' ? 'Only staff see internal notes' : hintText;
        body.placeholder = m === 'note' ? 'Note for colleagues – the guest does not see this' : body.dataset.ph;
      };
      body.dataset.ph = body.placeholder;
      form.querySelectorAll('input[name=mode_tab]').forEach(function (r) { r.addEventListener('change', function () { setMode(r.value); }); });
      var insert = function (opt) {
        if (!opt || !opt.value) return;
        var v = body.value.replace(/(^|\s)\/[\w-]*$/, '$1');
        body.value = (v ? v.replace(/\s*$/, ' ') : '') + opt.dataset.body; qr.value = opt.value;
        body.focus(); body.setSelectionRange(body.value.length, body.value.length);
      };
      if (pick) pick.addEventListener('change', function () { insert(pick.selectedOptions[0]); pick.value = ''; });
      body.addEventListener('input', function () {
        var m = body.value.match(/(^|\s)\/([\w-]+)\s$/);
        if (!m || !pick) return;
        var opt = Array.prototype.find.call(pick.options, function (o) { return o.dataset.short && o.dataset.short.toLowerCase() === m[2].toLowerCase(); });
        if (opt) { body.value = body.value.replace(/\s$/, ''); insert(opt); }
      });
      body.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); if (body.value.trim()) sendBtn.click(); }
      });
      form.addEventListener('submit', function () { setTimeout(function () { form.querySelectorAll('button').forEach(function (b) { b.disabled = true; }); }, 0); });
    }

    // link an unknown sender to a guest profile
    var gq = document.getElementById('inbGuestQ'), gs = document.getElementById('inbGuestSel'), gt = null;
    if (gq && gs) gq.addEventListener('input', function () {
      clearTimeout(gt);
      if (gq.value.trim().length < 2) return;
      gt = setTimeout(function () {
        fetch(gq.dataset.src + '?q=' + encodeURIComponent(gq.value.trim()), { headers: { Accept: 'application/json' }, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (list) {
          gs.innerHTML = list.length ? '' : '<option value="">No guest found</option>';
          list.forEach(function (g) { var o = document.createElement('option'); o.value = g.id; o.textContent = g.label; gs.appendChild(o); });
        }).catch(function () {});
      }, 300);
    });
  }

  // broadcast form: floor field + live reach preview
  var bf = document.getElementById('inbBcForm');
  if (bf) {
    var aud = document.getElementById('inbBcAud'), fl = document.getElementById('inbBcFloor'), ch = document.getElementById('inbBcCh'), out = document.getElementById('inbBcReach');
    var names = { portal: 'portal chat', whatsapp: 'WhatsApp', sms: 'SMS' };
    var preview = function () {
      document.getElementById('inbBcFloorF').style.display = aud.value === 'floor' ? '' : 'none';
      fl.required = aud.value === 'floor';
      if (aud.value === 'floor' && !fl.value) { out.textContent = 'Choose the floor.'; return; }
      fetch(bf.dataset.preview + '?audience=' + aud.value + '&floor_id=' + (fl.value || '') + '&channel=' + ch.value, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.json(); }).then(function (d) {
          if (d.error) { out.textContent = d.error; return; }
          var parts = Object.keys(d.reach).map(function (k) { return d.reach[k] + ' by ' + (names[k] || k.replace(/^skip: /, 'not reached – ')); });
          out.innerHTML = '<b>' + d.count + '</b> guest(s)' + (parts.length ? ': ' + parts.join(', ').replace(/by not reached/g, 'not reached') : '');
        }).catch(function () {});
    };
    [aud, fl, ch].forEach(function (el) { el.addEventListener('change', preview); });
    preview();
  }
})();
