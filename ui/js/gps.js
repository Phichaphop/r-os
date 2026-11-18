/* M57 check-in/out: reads the location ONLY when the employee presses a button (never in the background), fills the
   form and sends it. With no connection — and only when the company allows offline check-in — the reading is kept in
   this browser and sent when the connection returns (the server holds it for review and checks its age). */
(function () {
  'use strict';
  var form = document.querySelector('[data-gps-form]');
  if (!form) { return; }
  var KEY = 'blue.gps.queue';
  var status = form.querySelector('[data-gps-status]');
  var buttons = form.querySelectorAll('button[data-direction]');
  var offlineHours = parseInt(form.getAttribute('data-offline-hours') || '0', 10) || 0;
  var msg = function (k) { return form.getAttribute('data-msg-' + k) || ''; };
  var say = function (text) { if (status) { status.textContent = text; } };
  var field = function (n) { return form.querySelector('[name="' + n + '"]'); };

  function eventId() {
    var b = new Uint8Array(16);
    (window.crypto || window.msCrypto).getRandomValues(b);
    return Array.prototype.map.call(b, function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
  }
  function readQueue() { try { return JSON.parse(localStorage.getItem(KEY) || '[]') || []; } catch (e) { return []; } }
  function writeQueue(q) { try { localStorage.setItem(KEY, JSON.stringify(q)); } catch (e) { /* storage blocked: nothing queued */ } }
  function busy(on) { Array.prototype.forEach.call(buttons, function (b) { b.disabled = on; }); }

  function send(item) {
    var body = new FormData();
    body.append('_csrf', field('_csrf').value);
    body.append('_ajax', '1');
    Object.keys(item).forEach(function (k) { body.append(k, item[k]); });
    return fetch(form.getAttribute('action'), { method: 'POST', body: body, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json().then(function (j) { return { http: r.status, data: j }; }); });
  }

  function flush() {
    var q = readQueue();
    if (!q.length || navigator.onLine === false) { if (q.length) { say(msg('pending').replace('{n}', q.length)); } return; }
    var item = q[0];
    send(item).then(function (res) {
      var rest = readQueue().filter(function (x) { return x.event !== item.event; });
      writeQueue(rest);
      say((res.data && res.data.message) || msg('synced'));
      if (rest.length) { flush(); } else if (res.data && res.data.ok) { window.setTimeout(function () { window.location.reload(); }, 1200); }
    }).catch(function () { say(msg('pending').replace('{n}', q.length)); });
  }

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    var btn = ev.submitter || document.activeElement;
    var dir = btn && btn.getAttribute ? btn.getAttribute('data-direction') : null;
    if (dir !== 'in' && dir !== 'out') { return; }
    if (!navigator.geolocation) { say(msg('unsupported')); return; }
    busy(true);
    say(msg('locating'));
    navigator.geolocation.getCurrentPosition(function (pos) {
      var item = { direction: dir, lat: pos.coords.latitude.toFixed(6), lng: pos.coords.longitude.toFixed(6), accuracy: Math.ceil(pos.coords.accuracy || 0),
        captured: String(Math.round(pos.timestamp || Date.now())), event: eventId(), note: field('note').value || '' };
      if (navigator.onLine === false) {
        busy(false);
        if (offlineHours <= 0) { say(msg('offline-off')); return; }
        item.offline = '1';
        var q = readQueue(); q.push(item); writeQueue(q);
        say(msg('queued'));
        return;
      }
      Object.keys(item).forEach(function (k) { var el = field(k); if (el) { el.value = item[k]; } });
      field('offline').value = '0';
      form.submit();
    }, function (err) {
      busy(false);
      say(err && err.code === 1 ? msg('denied') : (err && err.code === 3 ? msg('timeout') : msg('unavailable')));
    }, { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 });
  });

  busy(false);
  window.addEventListener('online', flush);
  flush();
}());
