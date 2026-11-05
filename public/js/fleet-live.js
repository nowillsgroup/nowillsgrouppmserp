/* Live fleet map: polls the permission-gated feed and positions markers inside an offline SVG map.
   No external tiles or CDNs are loaded – the page works on a hotel LAN with no internet. */
(function () {
  'use strict';
  var map = document.getElementById('fleetMap');
  if (!map) { return; }
  var feed = map.getAttribute('data-feed');
  var svg = map.querySelector('svg');
  var layer = document.getElementById('fleetMarkers');
  var status = document.getElementById('fleetMapStatus');
  var updated = document.getElementById('fleetMapUpdated');
  var empty = document.getElementById('fleetMapEmpty');
  if (!feed || !svg || !layer) { return; }

  var W = 1000, H = 625, PAD = 60;
  var timer = null;
  var MS = {
    moving: 'Moving', parked: 'Parked', offline: 'Signal lost', never: 'No signal yet', disabled: 'Device disabled'
  };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function project(points) {
    var lats = [], lngs = [];
    points.forEach(function (p) { lats.push(p[0]); lngs.push(p[1]); });
    var minLat = Math.min.apply(null, lats), maxLat = Math.max.apply(null, lats);
    var minLng = Math.min.apply(null, lngs), maxLng = Math.max.apply(null, lngs);
    // keep a minimum span so a single parked car is not blown up to the whole map
    var spanLat = Math.max(maxLat - minLat, 0.012);
    var spanLng = Math.max(maxLng - minLng, 0.012);
    var cLat = (minLat + maxLat) / 2, cLng = (minLng + maxLng) / 2;
    return function (lat, lng) {
      var x = PAD + ((lng - (cLng - spanLng / 2)) / spanLng) * (W - PAD * 2);
      var y = PAD + (((cLat + spanLat / 2) - lat) / spanLat) * (H - PAD * 2);
      return [Math.max(12, Math.min(W - 12, x)), Math.max(12, Math.min(H - 12, y))];
    };
  }

  function grid() {
    var out = '';
    for (var i = 1; i < 8; i++) {
      out += '<line class="grid-line" x1="' + (W / 8 * i) + '" y1="0" x2="' + (W / 8 * i) + '" y2="' + H + '"/>';
    }
    for (var j = 1; j < 5; j++) {
      out += '<line class="grid-line" x1="0" y1="' + (H / 5 * j) + '" x2="' + W + '" y2="' + (H / 5 * j) + '"/>';
    }
    return out;
  }

  function render(data) {
    var rows = (data.vehicles || []).filter(function (v) { return v.position; });
    var fences = data.fences || [];
    var pts = [];
    rows.forEach(function (v) {
      (v.trail || []).forEach(function (t) { pts.push(t); });
      pts.push([v.position.lat, v.position.lng]);
    });
    fences.forEach(function (f) { pts.push([f.lat, f.lng]); });

    if (!pts.length) {
      layer.innerHTML = '';
      if (empty) { empty.hidden = false; }
      if (status) { status.textContent = 'No vehicle has reported a GPS position yet.'; }
      return;
    }
    if (empty) { empty.hidden = true; }
    var at = project(pts);
    var out = grid();

    fences.forEach(function (f) {
      var c = at(f.lat, f.lng);
      // radius in screen pixels: project a point one radius north of the centre
      var edge = at(f.lat + (f.radius_m / 111320), f.lng);
      var r = Math.sqrt(Math.pow(edge[0] - c[0], 2) + Math.pow(edge[1] - c[1], 2));
      if (!isFinite(r) || r < 4) { r = 4; }
      out += '<g class="fence ' + esc(f.kind) + '" transform="translate(' + c[0].toFixed(1) + ',' + c[1].toFixed(1) + ')"' +
        ' role="img" aria-label="' + esc(f.name) + ' – ' + esc(f.kind) + ' fence, ' + f.radius_m + ' metres">' +
        '<circle r="' + r.toFixed(1) + '"><title>' + esc(f.name) + ' · ' + f.radius_m + ' m</title></circle>' +
        '<text x="0" y="' + (-r - 5).toFixed(1) + '">' + esc(f.name) + '</text></g>';
    });

    rows.forEach(function (v) {
      var trail = (v.trail || []).map(function (t) { return at(t[0], t[1]); });
      if (trail.length > 1) {
        out += '<polyline class="trail' + (v.state === 'offline' ? ' stale' : '') + '" points="' +
          trail.map(function (p) { return p[0].toFixed(1) + ',' + p[1].toFixed(1); }).join(' ') + '"/>';
      }
    });

    rows.forEach(function (v) {
      var p = at(v.position.lat, v.position.lng);
      var label = (v.vehicle && v.vehicle.code ? v.vehicle.code : 'Vehicle') + ' – ' + (MS[v.state] || v.state);
      out += '<g class="marker ' + esc(v.state) + '" transform="translate(' + p[0].toFixed(1) + ',' + p[1].toFixed(1) + ')"' +
        ' role="img" aria-label="' + esc(label) + '">' +
        '<circle r="9"><title>' + esc(label) + '</title></circle>' +
        '<text x="13" y="4">' + esc(v.vehicle && v.vehicle.code ? v.vehicle.code : '') + '</text></g>';
    });

    layer.innerHTML = out;
    if (status) {
      var moving = (data.vehicles || []).filter(function (v) { return v.state === 'moving'; }).length;
      var offline = (data.vehicles || []).filter(function (v) { return v.state === 'offline'; }).length;
      status.textContent = rows.length + ' vehicle(s) on the map · ' + moving + ' moving · ' + offline + ' signal lost' +
        (fences.length ? ' · ' + fences.length + ' fence(s)' : '');
    }
    if (updated && data.generated_at) {
      updated.textContent = 'Updated ' + new Date(data.generated_at).toLocaleTimeString();
    }
  }

  function load() {
    fetch(feed, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
      .then(function (data) {
        if (data && data.ok) { render(data); }
        if (status) { status.removeAttribute('data-error'); }
      })
      .catch(function () {
        if (status) {
          status.setAttribute('data-error', '1');
          status.textContent = 'Live positions could not be refreshed – retrying shortly.';
        }
      });
  }

  load();
  timer = window.setInterval(load, 20000);
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) {
      window.clearInterval(timer); timer = null;
    } else if (!timer) {
      load();
      timer = window.setInterval(load, 20000);
    }
  });
})();
