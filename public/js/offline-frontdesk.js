/*
 * Offline front desk.
 *
 * Draws arrivals, in-house guests and the room list from the last snapshot, and
 * writes two kinds of intent into the shared outbox (offline-core.js):
 *
 *   frontdesk.checkin  — put a reserved stay into a vacant room
 *   frontdesk.charge   — post a charge on the open folio of an in-house guest
 *
 * Payments, settlement and check-out are not here on purpose: they belong to the
 * cashier shift and the folio's payment trail, so they stay on the online screens.
 */
(function () {
  'use strict';

  var root = document.getElementById('offlineDesk');
  if (!root || !window.PmsOffline) return;

  var core = window.PmsOffline;
  var SNAPSHOT = 'frontdesk';

  var el = {
    state: root.querySelector('[data-desk-state]'),
    arrivals: root.querySelector('[data-desk-arrivals]'),
    arrivalsNote: root.querySelector('[data-desk-arrivals-note]'),
    arrivalsEmpty: root.querySelector('[data-desk-arrivals-empty]'),
    inhouse: root.querySelector('[data-desk-inhouse]'),
    inhouseNote: root.querySelector('[data-desk-inhouse-note]'),
    inhouseEmpty: root.querySelector('[data-desk-inhouse-empty]'),
    stay: root.querySelector('[data-charge-stay]'),
    category: root.querySelector('[data-charge-category]'),
    description: root.querySelector('[data-charge-description]'),
    amount: root.querySelector('[data-charge-amount]'),
    tax: root.querySelector('[data-charge-tax]'),
    post: root.querySelector('[data-charge-post]'),
    chargeNote: root.querySelector('[data-desk-charge-note]'),
    queue: root.querySelector('[data-desk-queue]'),
    queueEmpty: root.querySelector('[data-desk-queue-empty]'),
    queueCount: root.querySelector('[data-desk-queue-count]')
  };

  var state = { snapshot: null, checkinPending: {} };

  function esc(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (ch) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
    });
  }

  function money(value) {
    return 'GH₵ ' + (Number(value) || 0).toFixed(2);
  }

  function day(value) {
    if (!value) return '—';
    var date = new Date(value + 'T00:00:00');
    return date.toLocaleDateString('en-GB', { day: '2-digit', month: 'short' });
  }

  function emptyRooms(roomTypeId) {
    var rooms = ((state.snapshot && state.snapshot.rooms) || []).filter(function (room) { return room.status === 'vacant'; });
    // Rooms of the booked type first, then anything else the desk may upgrade into.
    return rooms.sort(function (a, b) {
      var aMatch = Number(a.room_type_id) === Number(roomTypeId) ? 0 : 1;
      var bMatch = Number(b.room_type_id) === Number(roomTypeId) ? 0 : 1;
      if (aMatch !== bMatch) return aMatch - bMatch;
      return String(a.room_no).localeCompare(String(b.room_no));
    });
  }

  function paintStatus(status) {
    if (!state.snapshot) { el.state.textContent = 'Preparing the local desk…'; return; }
    var parts = [status.online ? 'Online' : 'Offline — working from this device'];
    if (status.queued) parts.push(status.queued + ' waiting to send');
    if (status.conflicts) parts.push(status.conflicts + ' need attention');
    el.state.textContent = parts.join(' · ');
    el.state.classList.toggle('is-offline', !status.online);
  }

  function paintArrivals() {
    var rows = (state.snapshot && state.snapshot.arrivals) || [];
    el.arrivalsNote.textContent = rows.length ? rows.length + ' to check in' : '';
    el.arrivals.innerHTML = rows.map(function (stay) {
      var rooms = emptyRooms(stay.room_type_id);
      var pending = state.checkinPending[stay.stay_id];
      return '<div class="offline-desk-row' + (pending ? ' is-pending' : '') + '" data-arrival="' + stay.stay_id + '">' +
        '<div class="offline-desk-row-main">' +
          '<b>' + esc(stay.guest || 'Guest') + '</b>' +
          '<span class="tiny muted">' + esc(stay.reservation_no || '') + ' · ' + esc(stay.room_type || 'Room') +
            ' · ' + (stay.nights || 0) + ' night' + (stay.nights === 1 ? '' : 's') + ' · ' + money(stay.rate) + '</span>' +
        '</div>' +
        (pending
          ? '<span class="badge badge-amber">queued</span>'
          : '<div class="offline-desk-row-actions">' +
              '<select data-checkin-room="' + stay.stay_id + '" aria-label="Room for ' + esc(stay.guest || 'guest') + '">' +
                rooms.map(function (room) {
                  return '<option value="' + room.id + '">' + esc(room.room_no) + '</option>';
                }).join('') +
              '</select>' +
              '<button type="button" class="btn btn-sm btn-primary" data-checkin="' + stay.stay_id + '"' +
                (rooms.length ? '' : ' disabled title="No vacant room on this device"') + '>Check in</button>' +
            '</div>') +
      '</div>';
    }).join('');
    el.arrivalsEmpty.hidden = rows.length > 0;
  }

  function paintInHouse() {
    var rows = (state.snapshot && state.snapshot.in_house) || [];
    el.inhouseNote.textContent = rows.length ? rows.length + ' in house' : '';
    el.inhouse.innerHTML = rows.map(function (stay) {
      return '<div class="offline-desk-row" data-inhouse="' + stay.stay_id + '">' +
        '<div class="offline-desk-row-main">' +
          '<b>Room ' + esc(stay.room_no) + ' · ' + esc(stay.guest || 'Guest') + '</b>' +
          '<span class="tiny muted">Departs ' + esc(day(stay.departure_date)) +
            (stay.balance === null ? '' : ' · balance ' + money(stay.balance)) + '</span>' +
        '</div>' +
        '<button type="button" class="btn btn-sm" data-charge-for="' + stay.stay_id + '">Charge</button>' +
      '</div>';
    }).join('');
    el.inhouseEmpty.hidden = rows.length > 0;

    var chosen = el.stay.value;
    el.stay.innerHTML = rows.map(function (stay) {
      return '<option value="' + stay.stay_id + '">Room ' + esc(stay.room_no) + ' · ' + esc(stay.guest || 'Guest') + '</option>';
    }).join('');
    if (chosen && rows.some(function (stay) { return String(stay.stay_id) === String(chosen); })) el.stay.value = chosen;
  }

  function paintCategories() {
    var categories = (state.snapshot && state.snapshot.categories) || {};
    if (el.category.options.length) return;
    el.category.innerHTML = Object.keys(categories).map(function (key) {
      return '<option value="' + esc(key) + '">' + esc(categories[key]) + '</option>';
    }).join('');
  }

  function paintQueue() {
    core.pending().then(function (rows) {
      var waiting = rows.filter(function (row) { return row.status === 'queued' || row.status === 'retry'; });
      var problems = rows.filter(function (row) { return row.status === 'conflict' || row.status === 'rejected' || row.status === 'stalled'; });
      el.queueCount.textContent = (waiting.length ? waiting.length + ' waiting' : '') +
        (problems.length ? (waiting.length ? ' · ' : '') + problems.length + ' need attention' : '');
      el.queue.innerHTML = rows.map(function (row) {
        return '<div class="offline-pos-queue-row offline-pos-queue-' + esc(row.status) + '">' +
          '<div><b>' + esc(row.label || row.operation) + '</b><div class="tiny muted">' + esc(row.operation) + '</div></div>' +
          '<div class="tiny">' + esc(row.status) + (row.error ? ' · ' + esc(row.error) : '') + '</div>' +
        '</div>';
      }).join('');
      el.queueEmpty.hidden = rows.length > 0;
    });
  }

  function paintAll(status) {
    if (state.snapshot) {
      paintStatus(status || core.status());
      paintArrivals();
      paintInHouse();
      paintCategories();
      paintQueue();
    }
  }

  // ── intents ────────────────────────────────────────────────────────────────

  function checkIn(stayId) {
    if (root.dataset.canCheckin !== '1') {
      el.state.textContent = 'Your login cannot check guests in.';
      return;
    }
    var select = root.querySelector('[data-checkin-room="' + stayId + '"]');
    var roomId = select ? Number(select.value) : 0;
    if (!roomId) {
      el.state.textContent = 'No vacant room is held on this device for that arrival.';
      return;
    }
    var stay = ((state.snapshot && state.snapshot.arrivals) || []).find(function (row) { return String(row.stay_id) === String(stayId); });
    state.checkinPending[stayId] = true;
    paintArrivals();

    core.enqueue('frontdesk.checkin', {
      stay_id: Number(stayId),
      room_id: roomId,
      business_date: root.dataset.businessDate
    }, {
      label: 'Check in ' + ((stay && stay.guest) || 'guest') + ' → room ' + (select.options[select.selectedIndex] ? select.options[select.selectedIndex].text : '')
    }).then(function () {
      el.state.textContent = navigator.onLine ? 'Check-in saved and sending…' : 'Check-in saved on this device. It will sync when the line returns.';
      return core.flush();
    }).then(function () {
      paintQueue();
      // The arrival leaves the list once the server has it; anything it refused
      // comes back with a reason instead of sitting here looking sent.
      return core.snapshot(SNAPSHOT, root.dataset.snapshotUrl).then(applySnapshot).catch(function () {});
    }).catch(function (error) {
      delete state.checkinPending[stayId];
      paintArrivals();
      el.state.textContent = 'Could not save the check-in: ' + error.message;
    });
  }

  function postCharge() {
    if (root.dataset.canCharge !== '1') {
      el.state.textContent = 'Your login cannot post folio charges.';
      return;
    }
    var stayId = Number(el.stay.value);
    var amount = Number(el.amount.value);
    var description = el.description.value.trim();
    if (!stayId) { el.state.textContent = 'Choose the guest this charge belongs to.'; return; }
    if (!description) { el.state.textContent = 'Describe the charge before posting it.'; return; }
    if (!(amount > 0)) { el.state.textContent = 'Enter the amount to post.'; return; }

    var guest = el.stay.options[el.stay.selectedIndex] ? el.stay.options[el.stay.selectedIndex].text : '';
    core.enqueue('frontdesk.charge', {
      stay_id: stayId,
      category: el.category.value,
      description: description,
      amount: amount,
      apply_tax: el.tax.checked,
      business_date: root.dataset.businessDate
    }, { label: 'Charge ' + money(amount) + ' · ' + guest }).then(function () {
      el.description.value = '';
      el.amount.value = '';
      el.chargeNote.textContent = navigator.onLine
        ? 'Charge saved and sending to the folio…'
        : 'Charge saved on this device. It posts to the folio when the line returns.';
      return core.flush();
    }).then(paintQueue).catch(function (error) {
      el.state.textContent = 'Could not save the charge: ' + error.message;
    });
  }

  // ── events ─────────────────────────────────────────────────────────────────

  el.arrivals.addEventListener('click', function (event) {
    var button = event.target.closest('[data-checkin]');
    if (button) checkIn(button.getAttribute('data-checkin'));
  });

  el.inhouse.addEventListener('click', function (event) {
    var button = event.target.closest('[data-charge-for]');
    if (!button) return;
    el.stay.value = button.getAttribute('data-charge-for');
    el.description.focus();
  });

  el.post.addEventListener('click', postCharge);

  document.querySelectorAll('[data-offline-sync-now]').forEach(function (button) {
    button.addEventListener('click', function () { core.flush({ force: true }).then(paintQueue); });
  });

  core.onChange(function (status) { paintStatus(status); });

  window.addEventListener('pms-offline:applied', function (event) {
    var detail = event.detail || {};
    (detail.applied || []).forEach(function (entry) {
      if (entry.operation === 'frontdesk.checkin' && entry.result && entry.result.stay) {
        delete state.checkinPending[entry.payload && entry.payload.stay_id];
        var stay = entry.result.stay;
        el.state.textContent = 'Checked in · room ' + (stay.room_no || '') + ' · ' + (stay.guest || 'guest');
      }
    });
    core.snapshot(SNAPSHOT, root.dataset.snapshotUrl).then(applySnapshot).catch(function () {});
  });

  // ── boot ───────────────────────────────────────────────────────────────────

  function applySnapshot(data) {
    state.snapshot = data;
    // A queued arrival that is no longer on the list has been checked in; one the
    // server refused must be tappable again.
    Object.keys(state.checkinPending).forEach(function (id) {
      var still = (data.arrivals || []).some(function (row) { return String(row.stay_id) === String(id); });
      if (!still) delete state.checkinPending[id];
    });
    paintAll();
  }

  core.snapshot(SNAPSHOT, root.dataset.snapshotUrl).then(applySnapshot).catch(function (error) {
    el.state.textContent = error.message + ' Connect once to download the desk list for this device.';
  });

  core.watch();
  paintQueue();
})();
