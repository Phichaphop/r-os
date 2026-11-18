/*
 * Vehicle booking map (M05 · DEC-90) — Leaflet, self-hosted (ui/js/vendor/leaflet).
 * - pick mode: click the map to set the start or the destination (radio "vh_pick"), or type "lat, lng", paste a map
 *   link (its point is read when the link carries one) or choose a saved place — every way fills the same fields,
 *   so the form works without the map too. The straight-line distance is shown at once; the server keeps its own.
 * - view mode: the two pins of a booking.
 * - place mode (admin): one pin that fills the place's "lat, lng" field.
 * Tiles come from the server-set URL (allowed in the page's CSP); they are asked for with the site's origin as referrer.
 */
(function () {
  'use strict';
  if (!window.L) { return; }
  var L = window.L;

  function parsePoint(text) {
    var m = /^\s*(-?\d{1,2}(?:\.\d{1,8})?)\s*[,\s]\s*(-?\d{1,3}(?:\.\d{1,8})?)\s*$/.exec(text || '');
    if (!m) { return null; }
    return valid(parseFloat(m[1]), parseFloat(m[2]));
  }
  function valid(lat, lng) {
    if (isNaN(lat) || isNaN(lng) || lat < -90 || lat > 90 || lng < -180 || lng > 180 || (lat === 0 && lng === 0)) { return null; }
    return [Math.round(lat * 1e6) / 1e6, Math.round(lng * 1e6) / 1e6];
  }
  var HOSTS = ['google.com', 'www.google.com', 'maps.google.com', 'google.co.th', 'www.google.co.th', 'maps.google.co.th',
    'maps.app.goo.gl', 'goo.gl', 'openstreetmap.org', 'www.openstreetmap.org', 'osm.org', 'maps.apple.com'];
  function linkAllowed(url) {
    var m = /^https:\/\/([^/?#:@]+)(\/|\?|#|$)/i.exec(url || '');
    return !!m && url.length <= 500 && HOSTS.indexOf(m[1].toLowerCase()) >= 0;
  }
  function fromLink(url) {
    if (!linkAllowed(url)) { return null; }
    var u;
    try { u = decodeURIComponent(url); } catch (e) { u = url; }
    var n = '(-?\\d{1,3}\\.\\d{2,8})';
    var pats = [
      new RegExp('!3d' + n + '!4d' + n),
      new RegExp('[?&](?:q|query|ll|destination|daddr|saddr|center|sll)=(?:loc:)?' + n + '\\s*,\\s*' + n),
      new RegExp('[?&]mlat=' + n + '&mlon=' + n),
      new RegExp('#map=\\d{1,2}/' + n + '/' + n),
      new RegExp('@' + n + ',' + n)
    ];
    for (var i = 0; i < pats.length; i++) {
      var m = pats[i].exec(u);
      if (m) { var p = valid(parseFloat(m[1]), parseFloat(m[2])); if (p) { return p; } }
    }
    return null;
  }
  function km(a, b) {
    var R = 6371.0088, r = Math.PI / 180;
    var dp = (b[0] - a[0]) * r, dl = (b[1] - a[1]) * r;
    var x = Math.sin(dp / 2) * Math.sin(dp / 2) + Math.cos(a[0] * r) * Math.cos(b[0] * r) * Math.sin(dl / 2) * Math.sin(dl / 2);
    return 2 * R * Math.atan2(Math.sqrt(x), Math.sqrt(1 - x));
  }
  function fmt(p) { return p[0].toFixed(6) + ', ' + p[1].toFixed(6); }

  function makeMap(el) {
    var center = parsePoint(el.getAttribute('data-center')) || [13.0, 101.0];
    var map = L.map(el, { scrollWheelZoom: false, attributionControl: true }).setView(center, parseInt(el.getAttribute('data-zoom') || '6', 10));
    map.attributionControl.setPrefix('<a href="https://leafletjs.com">Leaflet</a>');
    L.tileLayer(el.getAttribute('data-tiles'), {
      maxZoom: 19,
      attribution: el.getAttribute('data-attribution'),
      referrerPolicy: 'strict-origin-when-cross-origin'
    }).addTo(map);
    return map;
  }
  function pin(map, p, kind, label) {
    var m = L.circleMarker(p, { radius: 9, className: 'vh-pin vh-pin--' + kind, interactive: false }).addTo(map);
    if (label) { m.bindTooltip(label, { permanent: true, direction: 'top', offset: [0, -8], className: 'vh-pin-label' }); }
    return m;
  }

  function viewMode(el) {
    var map = makeMap(el);
    var a = parsePoint(el.getAttribute('data-from')), b = parsePoint(el.getAttribute('data-to'));
    var pts = [];
    if (a) { pin(map, a, 'from', el.getAttribute('data-l-a')); pts.push(a); }
    if (b) { pin(map, b, 'to', el.getAttribute('data-l-b')); pts.push(b); }
    if (pts.length === 2) { L.polyline(pts, { className: 'vh-line', interactive: false }).addTo(map); map.fitBounds(pts, { padding: [40, 40], maxZoom: 15 }); }
    else if (pts.length === 1) { map.setView(pts[0], 14); }
  }

  function placeMode(el) {
    var map = makeMap(el);
    var input = document.getElementById(el.getAttribute('data-point'));
    var marker = null;
    function show(p, move) {
      if (marker) { map.removeLayer(marker); marker = null; }
      if (p) { marker = pin(map, p, 'place', null); if (move) { map.setView(p, Math.max(map.getZoom(), 14)); } }
    }
    map.on('click', function (ev) { var p = valid(ev.latlng.lat, ev.latlng.lng); if (p && input) { input.value = fmt(p); show(p, false); } });
    if (input) {
      input.addEventListener('change', function () { show(parsePoint(input.value), true); });
      show(parsePoint(input.value), true);
    }
  }

  function pickMode(el) {
    var map = makeMap(el);
    var form = el.closest('form') || document;
    var out = form.querySelector('[data-vh-km]');
    var markers = { from: null, to: null };
    var line = null;
    function field(side, k) { return form.querySelector('[name="' + side + '_' + k + '"]'); }
    function point(side) {
      var place = field(side, 'place');
      if (place && place.value) {
        var o = place.options[place.selectedIndex];
        return parsePoint(o.getAttribute('data-point'));
      }
      var f = field(side, 'point');
      return f ? parsePoint(f.value) : null;
    }
    function redraw(fit) {
      var pts = [];
      ['from', 'to'].forEach(function (side) {
        if (markers[side]) { map.removeLayer(markers[side]); markers[side] = null; }
        var p = point(side);
        if (p) { markers[side] = pin(map, p, side, el.getAttribute(side === 'from' ? 'data-l-a' : 'data-l-b')); pts.push(p); }
      });
      if (line) { map.removeLayer(line); line = null; }
      var a = point('from'), b = point('to');
      if (a && b) {
        line = L.polyline([a, b], { className: 'vh-line', interactive: false }).addTo(map);
        var d = km(a, b);
        var round = form.querySelector('[name="round_trip"]');
        if (round && round.checked) { d = d * 2; }
        if (out) { out.textContent = (out.getAttribute('data-template') || '{km}').replace('{km}', d.toFixed(1)); }
      } else if (out) { out.textContent = out.getAttribute('data-empty') || ''; }
      if (fit && pts.length === 2) { map.fitBounds(pts, { padding: [40, 40], maxZoom: 15 }); }
      else if (fit && pts.length === 1) { map.setView(pts[0], Math.max(map.getZoom(), 13)); }
    }
    function current() {
      var r = form.querySelector('input[name="vh_pick"]:checked');
      return r ? r.value : 'from';
    }
    map.on('click', function (ev) {
      var side = current();
      var p = valid(ev.latlng.lat, ev.latlng.lng);
      if (!p) { return; }
      var place = field(side, 'place');
      if (place) { place.value = ''; place.dispatchEvent(new Event('change')); }
      var f = field(side, 'point');
      if (f) { f.value = fmt(p); }
      if (side === 'from') { var nx = form.querySelector('input[name="vh_pick"][value="to"]'); if (nx && !point('to')) { nx.checked = true; } }
      redraw(false);
    });
    ['from', 'to'].forEach(function (side) {
      var f = field(side, 'point');
      if (f) { f.addEventListener('change', function () { redraw(true); }); }
      var link = field(side, 'link');
      var hint = form.querySelector('[data-vh-link-hint="' + side + '"]');
      if (link) {
        var onLink = function () {
          var v = link.value.trim();
          if (hint) { hint.textContent = ''; }
          if (!v) { return; }
          var p = fromLink(v);
          if (p) { if (f && !f.value) { f.value = fmt(p); } redraw(true); if (hint) { hint.textContent = el.getAttribute('data-l-link-ok') || ''; } }
          else if (hint) { hint.textContent = el.getAttribute(linkAllowed(v) ? 'data-l-link-nopoint' : 'data-l-link-bad') || ''; }
        };
        link.addEventListener('change', onLink);
        link.addEventListener('paste', function () { setTimeout(onLink, 0); });
      }
      var place = field(side, 'place');
      if (place) {
        var box = form.querySelector('[data-vh-own="' + side + '"]');
        var sync = function () { if (box) { box.hidden = !!place.value; } redraw(true); };
        place.addEventListener('change', sync);
        if (box) { box.hidden = !!place.value; }
      }
    });
    var round = form.querySelector('[name="round_trip"]');
    if (round) { round.addEventListener('change', function () { redraw(false); }); }
    redraw(true);
  }

  document.querySelectorAll('[data-vh-map]').forEach(function (el) {
    var mode = el.getAttribute('data-vh-map');
    if (mode === 'pick') { pickMode(el); } else if (mode === 'place') { placeMode(el); } else { viewMode(el); }
  });
})();
