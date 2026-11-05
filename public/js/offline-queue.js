/*
 * The outbox screen.
 *
 * Renders what /js/offline-core.js is holding on this device, groups it by what it
 * needs from a person, and lets a person put one change back in the queue or throw
 * it away. Everything here comes from IndexedDB, so the screen works with no
 * connection — which is exactly when it matters.
 */
(function () {
  'use strict';

  var root = document.getElementById('offlineQueue');
  if (!root || !window.PmsOffline) return;

  var core = window.PmsOffline;
  var WAITING = ['queued', 'retry'];

  var el = {
    state: root.querySelector('[data-queue-state]'),
    summary: root.querySelector('[data-queue-summary]'),
    waiting: root.querySelector('[data-queue-waiting]'),
    waitingEmpty: root.querySelector('[data-queue-waiting-empty]'),
    waitingCount: root.querySelector('[data-queue-waiting-count]'),
    problems: root.querySelector('[data-queue-problems]'),
    problemsEmpty: root.querySelector('[data-queue-problems-empty]'),
    problemCount: root.querySelector('[data-queue-problem-count]')
  };

  function esc(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (ch) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
    });
  }

  function when(value) {
    if (!value) return '—';
    var at = new Date(value);
    if (isNaN(at.getTime())) return '—';
    return at.toLocaleString('en-GB', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
  }

  function human(operation) {
    var names = {
      'pos.order.create': 'Opened a bill',
      'pos.order.kot': 'Sent dishes to the kitchen',
      'frontdesk.checkin': 'Checked a guest in',
      'frontdesk.charge': 'Posted a folio charge',
      'housekeeping.room_status': 'Changed a room status',
      'housekeeping.task': 'Worked a housekeeping task',
      'housekeeping.maintenance': 'Raised a maintenance issue',
      'guest_request.create': 'Logged a guest request'
    };
    return names[operation] || operation;
  }

  function payload(row) {
    try {
      return JSON.stringify(row.payload || {}, null, 1);
    } catch (_) {
      return '{}';
    }
  }

  function rowHtml(row, actionable) {
    var status = String(row.status || 'queued');
    return '<div class="offline-queue-row offline-queue-row-' + esc(status) + '" data-row="' + esc(row.id) + '">' +
      '<div class="offline-queue-row-main">' +
        '<b>' + esc(row.label || human(row.operation)) + '</b>' +
        '<span class="tiny muted">' + esc(human(row.operation)) + ' · ' + esc(row.operation) +
          ' · ' + esc(when(row.created_at)) +
          (row.attempts ? ' · ' + row.attempts + ' attempt' + (row.attempts === 1 ? '' : 's') : '') +
          ' · ' + esc(status) + '</span>' +
        (row.error ? '<p class="offline-queue-error tiny">' + esc(row.error) + '</p>' : '') +
        '<details class="offline-queue-detail"><summary class="tiny muted">What was sent</summary><pre>' +
          esc(payload(row)) + '</pre></details>' +
      '</div>' +
      (actionable
        ? '<div class="offline-queue-row-actions">' +
            '<button type="button" class="btn btn-sm" data-retry="' + esc(row.id) + '">Try again</button>' +
            '<button type="button" class="btn btn-sm" data-discard="' + esc(row.id) + '">Discard</button>' +
          '</div>'
        : '') +
    '</div>';
  }

  function paintState(status) {
    var parts = [status.online ? 'Online' : 'Offline — this device is holding the work'];
    if (status.syncing) parts.push('sending now');
    if (status.lastSync) parts.push('last sent ' + when(status.lastSync));
    el.state.textContent = parts.join(' · ');
    el.state.classList.toggle('is-offline', !status.online);
  }

  function paint(rows) {
    rows = (rows || []).slice().sort(function (a, b) {
      var aProblem = !WAITING.includes(a.status) ? 0 : 1;
      var bProblem = !WAITING.includes(b.status) ? 0 : 1;
      if (aProblem !== bProblem) return aProblem - bProblem;
      return (a.created_at || 0) - (b.created_at || 0);
    });

    var waiting = rows.filter(function (row) { return WAITING.includes(row.status); });
    var problems = rows.filter(function (row) { return !WAITING.includes(row.status); });
    var status = core.status();

    el.summary.innerHTML = [
      ['Waiting to send', waiting.length],
      ['Needs a person', problems.length],
      ['Connection', status.online ? 'Online' : 'Offline'],
      ['Last sent', status.lastSync ? when(status.lastSync) : '—']
    ].map(function (pair) {
      return '<div class="offline-queue-stat"><span class="tiny muted">' + esc(pair[0]) + '</span><b>' + esc(pair[1]) + '</b></div>';
    }).join('');

    el.waiting.innerHTML = waiting.map(function (row) { return rowHtml(row, false); }).join('');
    el.waitingEmpty.hidden = waiting.length > 0;
    el.waitingCount.textContent = waiting.length ? waiting.length + ' queued' : '';

    el.problems.innerHTML = problems.map(function (row) { return rowHtml(row, true); }).join('');
    el.problemsEmpty.hidden = problems.length > 0;
    el.problemCount.textContent = problems.length ? problems.length + ' to look at' : '';

    paintState(status);
  }

  function reload() {
    return core.pending().then(paint).catch(function (error) {
      el.state.textContent = error.message;
    });
  }

  root.addEventListener('click', function (event) {
    var retry = event.target.closest('[data-retry]');
    var discard = event.target.closest('[data-discard]');

    if (retry) {
      retry.disabled = true;
      core.retry(retry.getAttribute('data-retry')).then(reload).catch(reload);
      return;
    }

    if (discard) {
      var row = discard.closest('[data-row]');
      var label = row && row.querySelector('b') ? row.querySelector('b').textContent : 'this change';
      if (!window.confirm('Discard "' + label + '"? The server never received it, and it cannot be recovered.')) return;
      discard.disabled = true;
      core.discard(discard.getAttribute('data-discard')).then(reload).catch(reload);
    }
  });

  document.querySelectorAll('[data-offline-sync-now]').forEach(function (button) {
    button.addEventListener('click', function () {
      core.flush({ force: true }).then(reload).catch(reload);
    });
  });

  // One listener: every queue change (a flush, a retry, a discard, another tab)
  // emits a new status, and the list is read again from the device.
  core.onChange(function (status) {
    paintState(status);
    reload();
  });

  core.watch();
})();
