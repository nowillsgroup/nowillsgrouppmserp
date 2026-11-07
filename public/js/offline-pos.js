/*
 * Offline restaurant terminal.
 *
 * Draws itself from the last snapshot the server sent and writes intents into the
 * shared outbox (see offline-core.js). Two things are deliberate:
 *
 *   · a table the device opened before the connection dropped is remembered by a
 *     reference generated here, so every later ticket attaches to the same bill
 *     without the device ever needing the server's order id;
 *   · the screen says plainly what is on the device, what is queued and what the
 *     server has accepted. It never shows a total it has not actually recorded.
 */
(function () {
  'use strict';

  var root = document.getElementById('offlinePos');
  if (!root || !window.PmsOffline) return;

  var core = window.PmsOffline;
  var SNAPSHOT = 'pos';
  var LOCAL_KEY = 'pos-state';

  var el = {
    state: root.querySelector('[data-pos-state]'),
    snapshotNote: root.querySelector('[data-pos-snapshot-note]'),
    outlet: root.querySelector('[data-pos-outlet]'),
    orderType: root.querySelector('[data-pos-order-type]'),
    covers: root.querySelector('[data-pos-covers]'),
    stayField: root.querySelector('[data-pos-stay-field]'),
    stay: root.querySelector('[data-pos-stay]'),
    newOrder: root.querySelector('[data-pos-new]'),
    tables: root.querySelector('[data-pos-tables]'),
    tablesEmpty: root.querySelector('[data-pos-tables-empty]'),
    orders: root.querySelector('[data-pos-orders]'),
    ordersEmpty: root.querySelector('[data-pos-orders-empty]'),
    ordersCount: root.querySelector('[data-pos-orders-count]'),
    categories: root.querySelector('[data-pos-categories]'),
    items: root.querySelector('[data-pos-items]'),
    itemsEmpty: root.querySelector('[data-pos-items-empty]'),
    search: root.querySelector('[data-pos-search]'),
    draft: root.querySelector('[data-pos-draft]'),
    draftEmpty: root.querySelector('[data-pos-draft-empty]'),
    draftTitle: root.querySelector('[data-pos-draft-title]'),
    draftRef: root.querySelector('[data-pos-draft-ref]'),
    draftTotal: root.querySelector('[data-pos-draft-total]'),
    clear: root.querySelector('[data-pos-clear]'),
    send: root.querySelector('[data-pos-send]'),
    queue: root.querySelector('[data-pos-queue]'),
    queueEmpty: root.querySelector('[data-pos-queue-empty]'),
    queueCount: root.querySelector('[data-pos-queue-count]'),
    sides: document.querySelector('[data-pos-sides]'),
    sidesTitle: document.querySelector('[data-pos-sides-title]'),
    sidesNote: document.querySelector('[data-pos-sides-note]'),
    sidesWarn: document.querySelector('[data-pos-sides-warn]'),
    sidesList: document.querySelector('[data-pos-sides-list]'),
    sidesAdd: document.querySelector('[data-pos-sides-add]'),
    sidesSkip: document.querySelector('[data-pos-sides-skip]'),
    sidesCancel: document.querySelector('[data-pos-sides-cancel]')
  };

  var state = {
    snapshot: null,
    stale: false,
    category: null,
    filter: '',
    local: { orders: [], activeRef: null },
    sideItem: null
  };

  var businessDate = root.dataset.businessDate || new Date().toISOString().slice(0, 10);

  function money(value) {
    return 'GH₵ ' + (Number(value) || 0).toFixed(2);
  }

  function esc(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (ch) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
    });
  }

  function tableName(id) {
    var outlets = (state.snapshot && state.snapshot.outlets) || [];
    for (var i = 0; i < outlets.length; i++) {
      var found = (outlets[i].tables || []).find(function (t) { return Number(t.id) === Number(id); });
      if (found) return found.table_no;
    }
    return null;
  }

  function outletName(id) {
    var outlets = (state.snapshot && state.snapshot.outlets) || [];
    var found = outlets.find(function (o) { return Number(o.id) === Number(id); });
    return found ? found.name : 'Outlet';
  }

  function itemById(id) {
    return ((state.snapshot && state.snapshot.items) || []).find(function (item) { return Number(item.id) === Number(id); });
  }

  // ── local state ────────────────────────────────────────────────────────────

  function saveLocal() {
    return core.local.set(LOCAL_KEY, state.local);
  }

  function activeLocalOrder() {
    return state.local.orders.find(function (order) { return order.ref === state.local.activeRef; }) || null;
  }

  function serverOrderFor(tableId) {
    var orders = (state.snapshot && state.snapshot.orders) || [];
    return orders.find(function (order) {
      return Number(order.pos_table_id) === Number(tableId) && ['open', 'billed'].indexOf(order.status) !== -1;
    }) || null;
  }

  function localOrderFor(tableId) {
    return state.local.orders.find(function (order) {
      return Number(order.tableId) === Number(tableId) && order.status !== 'closed';
    }) || null;
  }

  function openLocalOrder(tableId) {
    var existing = localOrderFor(tableId);
    if (existing) { state.local.activeRef = existing.ref; return existing; }
    var order = {
      ref: 'ref-' + core.uuid(),
      outletId: Number(el.outlet.value),
      tableId: tableId ? Number(tableId) : null,
      orderType: tableId ? 'dine_in' : el.orderType.value,
      covers: Number(el.covers.value) || 1,
      stayId: el.stay.value ? Number(el.stay.value) : null,
      lines: [],
      serverId: null,
      status: 'draft',
      createdAt: new Date().toISOString(),
      businessDate: businessDate
    };
    state.local.orders.unshift(order);
    state.local.activeRef = order.ref;
    return order;
  }

  // ── rendering ──────────────────────────────────────────────────────────────

  function paintStatus(status) {
    if (!state.snapshot) { el.state.textContent = 'Preparing the local terminal…'; return; }
    var parts = [];
    parts.push(status.online ? 'Online' : 'Offline — working from this device');
    if (status.queued) parts.push(status.queued + ' waiting to send');
    if (status.conflicts) parts.push(status.conflicts + ' need attention');
    parts.push('Snapshot ' + (state.stale ? 'saved ' : 'taken ') + new Date(state.snapshot.generated_at || Date.now()).toLocaleString('en-GB', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }));
    el.state.textContent = parts.join(' · ');
    el.state.classList.toggle('is-offline', !status.online);
  }

  function paintSnapshotNote() {
    if (!state.snapshot) return;
    var saved = state.snapshot._saved_at ? new Date(state.snapshot._saved_at) : null;
    el.snapshotNote.textContent = (saved ? 'Saved on this device ' + saved.toLocaleString('en-GB', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : 'Fresh from the server')
      + ' · business date ' + (root.dataset.businessDateLabel || businessDate);
  }

  function paintOutlets() {
    var outlets = (state.snapshot && state.snapshot.outlets) || [];
    if (el.outlet.options.length !== outlets.length || !el.outlet.value) {
      el.outlet.innerHTML = outlets.map(function (outlet) {
        return '<option value="' + outlet.id + '">' + esc(outlet.name) + '</option>';
      }).join('');
    }
    if (!el.outlet.value && outlets.length) el.outlet.value = outlets[0].id;
  }

  function paintStays() {
    var stays = (state.snapshot && state.snapshot.stays) || [];
    var chosen = el.stay.value;
    el.stay.innerHTML = '<option value="">Choose the room…</option>' + stays.map(function (stay) {
      return '<option value="' + stay.id + '">Room ' + esc(stay.room_no) + '</option>';
    }).join('');
    if (chosen) el.stay.value = chosen;
  }

  function paintTables() {
    var outlet = ((state.snapshot && state.snapshot.outlets) || []).find(function (o) { return Number(o.id) === Number(el.outlet.value); });
    var tables = (outlet && outlet.tables) || [];
    el.tables.innerHTML = tables.map(function (table) {
      var server = serverOrderFor(table.id);
      var local = localOrderFor(table.id);
      var busy = server || local;
      var total = server ? server.total : (local ? local.lines.reduce(function (sum, line) { return sum + line.amount; }, 0) : 0);
      var classes = ['offline-pos-table'];
      if (busy) classes.push('is-busy');
      if (local && !server) classes.push('is-local');
      if (state.local.activeRef && local && local.ref === state.local.activeRef) classes.push('is-active');
      return '<button type="button" class="' + classes.join(' ') + '" data-table="' + table.id + '">' +
        '<span class="offline-pos-table-no">' + esc(table.table_no) + '</span>' +
        '<span class="tiny">' + (table.seats || 0) + ' seats</span>' +
        (busy ? '<span class="offline-pos-table-total">' + money(total) + '</span>' : '<span class="tiny muted">free</span>') +
        (local && !server ? '<span class="offline-pos-table-flag">not sent</span>' : '') +
        '</button>';
    }).join('');
    el.tablesEmpty.hidden = tables.length > 0;
  }

  function paintOrders() {
    var rows = [];
    ((state.snapshot && state.snapshot.orders) || []).forEach(function (order) {
      rows.push({
        ref: null, serverId: order.id, label: order.order_no,
        where: order.pos_table_id ? 'Table ' + tableName(order.pos_table_id) : outletName(order.outlet_id),
        lines: (order.items || []).length, total: order.total, state: order.status
      });
    });
    state.local.orders.filter(function (o) { return o.lines.length || o.status !== 'draft'; }).forEach(function (order) {
      rows.push({
        ref: order.ref, serverId: order.serverId,
        label: order.serverId ? 'Order #' + order.serverId : 'New order (' + (order.tableId ? 'table ' + tableName(order.tableId) : 'takeaway') + ')',
        where: order.tableId ? 'Table ' + tableName(order.tableId) : outletName(order.outletId),
        lines: order.lines.length,
        total: order.lines.reduce(function (sum, line) { return sum + line.amount; }, 0),
        state: order.serverId ? 'open' : (order.lines.length ? 'queued' : 'draft')
      });
    });
    el.ordersCount.textContent = rows.length ? '(' + rows.length + ')' : '';
    el.orders.innerHTML = rows.map(function (row) {
      return '<button type="button" class="offline-pos-order' + (row.ref && row.ref === state.local.activeRef ? ' is-active' : '') + '" data-order-ref="' + esc(row.ref || '') + '">' +
        '<b>' + esc(row.label) + '</b>' +
        '<span class="tiny muted">' + esc(row.where) + ' · ' + row.lines + ' line' + (row.lines === 1 ? '' : 's') + '</span>' +
        '<span class="offline-pos-order-total">' + money(row.total) + '</span>' +
        '<span class="badge' + (row.state === 'queued' ? ' badge-amber' : row.state === 'draft' ? '' : ' badge-green') + '">' + esc(row.state) + '</span>' +
        '</button>';
    }).join('');
    el.ordersEmpty.hidden = rows.length > 0;
  }

  function paintCategories() {
    var categories = (state.snapshot && state.snapshot.categories) || [];
    if (!state.category && categories.length) state.category = categories[0].id;
    el.categories.innerHTML = categories.map(function (category) {
      return '<button type="button" role="tab" aria-selected="' + (Number(category.id) === Number(state.category)) + '" class="offline-pos-category' +
        (Number(category.id) === Number(state.category) ? ' is-active' : '') + '" data-category="' + category.id + '">' + esc(category.name) + '</button>';
    }).join('');
  }

  function paintItems() {
    var items = ((state.snapshot && state.snapshot.items) || []).filter(function (item) {
      if (state.filter) return item.name.toLowerCase().indexOf(state.filter) !== -1;
      return Number(item.category_id) === Number(state.category);
    });
    el.items.innerHTML = items.map(function (item) {
      return '<button type="button" class="offline-pos-item" data-item="' + item.id + '">' +
        '<span class="offline-pos-item-name">' + esc(item.name) + '</span>' +
        '<span class="offline-pos-item-meta">' + money(item.price) +
        ((item.sides || []).length
          ? ' · <span class="offline-pos-item-side">' + (item.side_required ? 'side required' : (item.sides.length === 1 ? '1 side' : item.sides.length + ' sides')) + '</span>'
          : '') + '</span>' +
        '</button>';
    }).join('');
    el.itemsEmpty.hidden = items.length > 0;
  }

  function paintDraft() {
    var order = activeLocalOrder();
    var lines = order ? order.lines : [];
    var total = lines.reduce(function (sum, line) { return sum + line.amount; }, 0);
    el.draftTitle.textContent = order
      ? (order.serverId ? 'Order on the server' : 'New order') + (order.tableId ? ' · table ' + tableName(order.tableId) : '')
      : 'New order';
    el.draftRef.textContent = order ? (order.serverId ? 'sent' : 'queued on this device') : '';
    el.draft.innerHTML = lines.map(function (line, index) {
      return '<div class="offline-pos-line">' +
        '<div><b>' + esc(line.name) + '</b>' +
        (line.sides && line.sides.length ? '<div class="tiny muted">Side dishes: ' + esc(line.sides.map(function (s) {
          var qty = Math.max(1, Number(s.qty) || 1);
          return s.name + (qty > 1 ? ' ×' + qty : '') + (Number(s.rate) > 0 ? ' +' + (Number(s.rate) * qty).toFixed(2) : ' included');
        }).join(' · ')) + '</div>' : '') +
        (line.notes ? '<div class="tiny muted">' + esc(line.notes) + '</div>' : '') +
        '</div>' +
        '<div class="offline-pos-qty">' +
          '<button type="button" class="btn btn-sm" data-line-down="' + index + '" aria-label="One less ' + esc(line.name) + '">−</button>' +
          '<span>' + line.qty + '</span>' +
          '<button type="button" class="btn btn-sm" data-line-up="' + index + '" aria-label="One more ' + esc(line.name) + '">+</button>' +
        '</div>' +
        '<div class="offline-pos-line-total">' + money(line.amount) + '</div>' +
        '<button type="button" class="btn btn-sm" data-line-remove="' + index + '" aria-label="Remove ' + esc(line.name) + '">×</button>' +
      '</div>';
    }).join('');
    el.draftEmpty.hidden = lines.length > 0;
    el.draftTotal.textContent = money(total);
    el.send.disabled = !lines.length || root.dataset.canOrder !== '1';
    el.send.textContent = order && order.serverId ? 'Send to kitchen' : 'Send and open the order';
  }

  function paintQueue() {
    core.pending().then(function (rows) {
      var waiting = rows.filter(function (row) { return row.status === 'queued' || row.status === 'retry'; });
      var problems = rows.filter(function (row) { return row.status === 'conflict' || row.status === 'rejected' || row.status === 'stalled'; });
      el.queueCount.textContent = (waiting.length ? waiting.length + ' waiting' : '') + (problems.length ? (waiting.length ? ' · ' : '') + problems.length + ' need attention' : '');
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
    paintStatus(status || core.status());
    paintSnapshotNote();
    paintOutlets();
    paintStays();
    paintTables();
    paintOrders();
    paintCategories();
    paintItems();
    paintDraft();
    paintQueue();
  }

  // ── line handling ──────────────────────────────────────────────────────────

  function addLine(item, sides) {
    var order = activeLocalOrder() || openLocalOrder(currentTableId());
    var units = (sides || []).reduce(function (all, side) {
      for (var n = 0; n < Math.max(1, Math.min(9, Number(side.qty) || 1)); n++) all.push(Number(side.id));
      return all;
    }, []);
    var sideTotal = (sides || []).reduce(function (sum, side) {
      return sum + Number(side.rate || 0) * Math.max(1, Math.min(9, Number(side.qty) || 1));
    }, 0);
    var existing = order.lines.find(function (line) {
      return Number(line.menuItemId) === Number(item.id) &&
        JSON.stringify((line.sideIds || []).slice().sort()) === JSON.stringify(units.slice().sort());
    });
    if (existing) {
      existing.qty += 1;
      existing.amount = round(existing.qty * existing.rate);
    } else {
      order.lines.push({
        menuItemId: item.id,
        name: item.name,
        qty: 1,
        rate: round(Number(item.price) + sideTotal),
        amount: round(Number(item.price) + sideTotal),
        sides: sides || [],
        sideIds: units,
        notes: null
      });
    }
    if (order.status === 'draft') order.status = 'collecting';
    saveLocal().then(function () { paintAll(); });
  }

  function round(value) { return Math.round((Number(value) || 0) * 100) / 100; }

  function currentTableId() {
    var order = activeLocalOrder();
    if (order && order.tableId) return order.tableId;
    return null;
  }

  /**
   * The side sheet. Sides are ticked, not chosen one of one (v9.30): a dish can carry a salad AND an extra banku, and
   * the till has to add both rates. A dish whose side dish is required hides "continue with none" rather than accepting
   * an answer the kitchen cannot plate.
   */
  function openSideSheet(item) {
    state.sideItem = item;
    el.sidesTitle.textContent = 'Side dish for ' + item.name;
    var required = item.side_required === true;
    // v10.34 the sides are a shelf of cards, as they are on the online till: the terminal is tapped with a thumb, and a
    // card is a target where a line of small print is not. Each card keeps .offline-pos-side-row, because that is what
    // pickedSides() and stepSide() walk up to, and its count stays on the card it counts (v9.31).
    el.sidesList.innerHTML = (item.sides || []).map(function (side) {
      var rate = Number(side.rate) > 0 ? money(side.rate) : 'included';
      return '<div class="offline-pos-side-row offline-pos-side-card">' +
        '<label class="offline-pos-side offline-pos-side-pick">' +
          '<input type="checkbox" name="offlineSide" value="' + side.id + '" data-side-id="' + side.id + '">' +
          '<span class="offline-pos-side-name">' + esc(side.name) + '</span>' +
          '<span class="tiny offline-pos-side-rate">' + rate + '</span>' +
        '</label>' +
        '<span class="offline-pos-side-qty">' +
          '<button type="button" data-side-step="-1" aria-label="One fewer ' + esc(side.name) + '">−</button>' +
          '<b data-side-count>1</b>' +
          '<button type="button" data-side-step="1" aria-label="One more ' + esc(side.name) + '">+</button>' +
        '</span>' +
      '</div>';
    }).join('');
    if (el.sidesNote) {
      el.sidesNote.textContent = required
        ? 'This dish must be served with a side dish. Tick what the guest wants — the kitchen cannot plate it without one.'
        : 'This dish comes with a side. Tick one or more, or continue without if the guest does not want one.';
    }
    if (el.sidesWarn) el.sidesWarn.hidden = true;
    if (el.sidesSkip) el.sidesSkip.hidden = required;
    el.sides.hidden = false;
    var first = el.sidesList.querySelector('input');
    if (first) first.focus();
  }

  /** Every side ticked in the sheet, each with the count beside it, as the line stores them. */
  function pickedSides() {
    var counts = {};
    [].slice.call(el.sidesList.querySelectorAll('input:checked')).forEach(function (input) {
      var row = input.closest('.offline-pos-side-row');
      var count = row ? Number(row.querySelector('[data-side-count]').textContent) : 1;
      counts[Number(input.value)] = Math.max(1, Math.min(9, count || 1));
    });
    return (state.sideItem && state.sideItem.sides ? state.sideItem.sides : []).filter(function (side) {
      return counts[Number(side.id)] !== undefined;
    }).map(function (side) {
      return { id: side.id, name: side.name, rate: side.rate, qty: counts[Number(side.id)] };
    });
  }

  /** The − / + beside a side: ticking it first, then counting it. */
  function stepSide(button) {
    var row = button.closest('.offline-pos-side-row');
    if (!row) return;
    var box = row.querySelector('[data-side-id]');
    var count = row.querySelector('[data-side-count]');
    if (box && !box.checked) box.checked = true;
    var next = Math.max(1, Math.min(9, (Number(count.textContent) || 1) + Number(button.dataset.sideStep)));
    count.textContent = String(next);
  }

  function closeSideSheet() {
    state.sideItem = null;
    el.sides.hidden = true;
  }

  // ── sending ────────────────────────────────────────────────────────────────

  function queueOrderCreate(order) {
    return core.enqueue('pos.order.create', {
      local_ref: order.ref,
      outlet_id: order.outletId,
      pos_table_id: order.tableId,
      order_type: order.orderType,
      covers: order.covers,
      reservation_room_id: order.stayId,
      business_date: order.businessDate,
      created_at: order.createdAt
    }, { label: 'Open order · ' + (order.tableId ? 'table ' + tableName(order.tableId) : 'takeaway'), baseUpdatedAt: order.createdAt });
  }

  function queueTicket(order, lines) {
    var payload = {
      lines: lines.map(function (line) {
        return {
          menu_item_id: line.menuItemId,
          qty: line.qty,
          variants: line.sideIds || [],
          notes: line.notes || null,
          hold: false
        };
      })
    };
    if (order.serverId) payload.order_id = order.serverId;
    else payload.local_ref = order.ref;

    return core.enqueue('pos.order.kot', payload, {
      label: 'Kitchen ticket · ' + lines.length + ' dish' + (lines.length === 1 ? '' : 'es') + ' · ' + (order.serverId ? 'order ' + order.serverId : (order.tableId ? 'table ' + tableName(order.tableId) : 'takeaway')),
      baseUpdatedAt: order.createdAt
    });
  }

  function send() {
    var order = activeLocalOrder();
    if (!order || !order.lines.length) return;
    if (root.dataset.canOrder !== '1') {
      el.state.textContent = 'Your login cannot send orders.';
      return;
    }
    if (order.orderType === 'room_service' && !order.stayId) {
      el.state.textContent = 'Room service needs the in-house room it is billed to. Choose the room, then send again.';
      return;
    }
    var lines = order.lines.slice();
    var server = order.tableId ? serverOrderFor(order.tableId) : null;
    if (server) order.serverId = server.id;

    el.send.disabled = true;
    el.send.textContent = 'Saving…';

    var chain = order.serverId ? Promise.resolve(null) : queueOrderCreate(order);
    chain
      .then(function (row) {
        if (row && row.conflict) { /* handled by the queue summary */ }
        return queueTicket(order, lines);
      })
      .then(function () {
        order.lines = [];
        order.status = order.serverId ? 'sent' : 'queued';
        saveLocal();
        el.state.textContent = navigator.onLine
          ? 'Saved and sending…'
          : 'Saved on this device · ' + lines.length + ' dish' + (lines.length === 1 ? '' : 'es') + ' will reach the kitchen when the connection returns';
        return core.flush();
      })
      .then(function () { paintAll(); })
      .catch(function (error) {
        el.state.textContent = 'Could not save the ticket: ' + error.message;
        el.send.disabled = false;
        paintAll();
      });
  }

  // ── events ─────────────────────────────────────────────────────────────────

  el.tables.addEventListener('click', function (event) {
    var button = event.target.closest('[data-table]');
    if (!button) return;
    var tableId = button.getAttribute('data-table');
    var local = localOrderFor(tableId);
    if (local) {
      state.local.activeRef = local.ref;
    } else {
      var server = serverOrderFor(tableId);
      var order = openLocalOrder(tableId);
      if (server) order.serverId = server.id;
    }
    saveLocal().then(function () { paintAll(); });
  });

  el.orders.addEventListener('click', function (event) {
    var button = event.target.closest('[data-order-ref]');
    if (!button) return;
    var ref = button.getAttribute('data-order-ref');
    var order = state.local.orders.find(function (o) { return o.ref === ref; });
    if (order) {
      state.local.activeRef = ref;
      saveLocal().then(function () { paintAll(); });
    }
  });

  el.categories.addEventListener('click', function (event) {
    var button = event.target.closest('[data-category]');
    if (!button) return;
    state.category = Number(button.getAttribute('data-category'));
    state.filter = '';
    el.search.value = '';
    paintCategories();
    paintItems();
  });

  el.items.addEventListener('click', function (event) {
    var button = event.target.closest('[data-item]');
    if (!button) return;
    var item = itemById(button.getAttribute('data-item'));
    if (!item) return;
    if ((item.sides || []).length) openSideSheet(item);
    else addLine(item, []);
  });

  el.search.addEventListener('input', function () {
    state.filter = el.search.value.trim().toLowerCase();
    paintItems();
  });

  el.draft.addEventListener('click', function (event) {
    var order = activeLocalOrder();
    if (!order) return;
    var up = event.target.closest('[data-line-up]');
    var down = event.target.closest('[data-line-down]');
    var remove = event.target.closest('[data-line-remove]');
    if (up) {
      var line = order.lines[Number(up.getAttribute('data-line-up'))];
      if (line) { line.qty += 1; line.amount = round(line.qty * line.rate); }
    } else if (down) {
      var lower = order.lines[Number(down.getAttribute('data-line-down'))];
      if (lower) {
        lower.qty -= 1;
        lower.amount = round(lower.qty * lower.rate);
        if (lower.qty <= 0) order.lines.splice(order.lines.indexOf(lower), 1);
      }
    } else if (remove) {
      order.lines.splice(Number(remove.getAttribute('data-line-remove')), 1);
    } else {
      return;
    }
    saveLocal().then(function () { paintAll(); });
  });

  el.newOrder.addEventListener('click', function () {
    var order = openLocalOrder(null);
    saveLocal().then(function () { paintAll(); });
  });

  el.clear.addEventListener('click', function () {
    var order = activeLocalOrder();
    if (!order || order.serverId) { state.local.activeRef = null; paintAll(); return; }
    order.lines = [];
    saveLocal().then(function () { paintAll(); });
  });

  el.send.addEventListener('click', send);

  el.outlet.addEventListener('change', function () { paintTables(); });
  el.orderType.addEventListener('change', function () {
    el.stayField.hidden = el.orderType.value !== 'room_service';
    var order = activeLocalOrder();
    if (order && !order.serverId && order.status === 'draft') order.orderType = el.orderType.value;
  });
  el.stay.addEventListener('change', function () {
    var order = activeLocalOrder();
    if (order && !order.serverId) { order.stayId = el.stay.value ? Number(el.stay.value) : null; saveLocal(); }
  });

  el.sidesAdd.addEventListener('click', function () {
    if (!state.sideItem) return;
    var sides = pickedSides();
    if (state.sideItem.side_required === true && !sides.length) {
      if (el.sidesWarn) el.sidesWarn.hidden = false;
      var firstInput = el.sidesList.querySelector('input');
      if (firstInput) firstInput.focus();
      return;
    }
    addLine(state.sideItem, sides);
    closeSideSheet();
  });
  el.sidesSkip.addEventListener('click', function () {
    if (!state.sideItem) return;
    if (state.sideItem.side_required === true) {
      if (el.sidesWarn) el.sidesWarn.hidden = false;
      return;
    }
    addLine(state.sideItem, []);
    closeSideSheet();
  });
  el.sidesCancel.addEventListener('click', closeSideSheet);
  el.sidesList.addEventListener('click', function (event) {
    var step = event.target.closest('[data-side-step]');
    if (!step) return;
    event.preventDefault();
    event.stopPropagation();
    stepSide(step);
  });

  document.querySelectorAll('[data-offline-sync-now]').forEach(function (button) {
    button.addEventListener('click', function () { core.flush({ force: true }).then(paintAll); });
  });

  core.onChange(function (status) { paintStatus(status); });
  window.addEventListener('pms-offline:applied', function (event) {
    var detail = event.detail || {};
    (detail.applied || []).forEach(function (entry) {
      if (!entry.result || !entry.result.order) return;
      var ref = entry.payload && entry.payload.local_ref;
      var order = state.local.orders.find(function (o) { return o.ref === ref; });
      if (order) {
        order.serverId = entry.result.order.id;
        order.status = 'sent';
      }
      if (entry.result.order.offline_ref) {
        var byRef = state.local.orders.find(function (o) { return o.ref === entry.result.order.offline_ref; });
        if (byRef) byRef.serverId = entry.result.order.id;
      }
    });
    saveLocal();
    // A snapshot after a sync keeps the table list and running totals right.
    core.snapshot(SNAPSHOT, root.dataset.snapshotUrl).then(applySnapshot).catch(function () {});
  });

  // ── boot ───────────────────────────────────────────────────────────────────

  function applySnapshot(data) {
    state.snapshot = data;
    state.stale = !!data._stale;
    if (!state.local.orders.length && data.orders) {
      data.orders.forEach(function (order) {
        if (!order.pos_table_id) return;
        var known = state.local.orders.find(function (o) { return o.serverId === order.id; });
        if (known) { known.serverId = order.id; return; }
        state.local.orders.push({
          ref: 'srv-' + order.id, outletId: order.outlet_id, tableId: order.pos_table_id,
          orderType: order.order_type, covers: order.covers, stayId: null, lines: [],
          serverId: order.id, status: 'sent', createdAt: order.created_at, businessDate: order.business_date
        });
      });
      saveLocal();
    }
    paintAll();
  }

  core.local.get(LOCAL_KEY).then(function (stored) {
    if (stored && Array.isArray(stored.orders)) state.local = stored;
    return core.snapshot(SNAPSHOT, root.dataset.snapshotUrl);
  }).then(function (data) {
    applySnapshot(data);
    if (data.needs_shift) {
      el.state.textContent = 'Open your service shift while connected before sending orders from this device.';
    }
  }).catch(function (error) {
    el.state.textContent = error.message + ' Connect once to download the menu for this device.';
  });

  core.watch();
  paintQueue();
})();
