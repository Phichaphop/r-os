/* M13 card on screen: the QR changes every 30 seconds (a screenshot stops working), and "full screen" makes the card
   and its QR as large as possible for a scanner or a guard. The QR comes from the server as SVG (same origin). */
(function () {
  'use strict';
  var stage = document.querySelector('[data-eid-stage]');
  if (!stage) { return; }
  var box = stage.querySelector('[data-eid-qr]');
  var full = stage.querySelector('[data-eid-full]');
  var timer = null;

  function schedule(sec) {
    if (timer) { window.clearTimeout(timer); }
    timer = window.setTimeout(refresh, Math.max(5, sec || 30) * 1000);
  }
  function refresh() {
    if (!box || document.hidden) { schedule(10); return; }
    fetch(stage.getAttribute('data-qr-url'), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) {
        if (j && j.ok && typeof j.svg === 'string' && j.svg.indexOf('<svg') === 0) { box.innerHTML = j.svg; schedule(j.refresh); }
        else { schedule(30); }
      })
      .catch(function () { schedule(15); });
  }
  if (box) { schedule(parseInt(box.getAttribute('data-refresh') || '30', 10)); }
  document.addEventListener('visibilitychange', function () { if (!document.hidden) { refresh(); } });

  if (full) {
    full.hidden = false;
    var label = full.querySelector('span');
    var set = function (on) {
      stage.classList.toggle('is-full', on);
      if (label) { label.textContent = stage.getAttribute(on ? 'data-msg-exit' : 'data-msg-full') || ''; }
    };
    full.addEventListener('click', function () {
      var on = !stage.classList.contains('is-full');
      if (on && stage.requestFullscreen) { stage.requestFullscreen().catch(function () {}); }
      if (!on && document.fullscreenElement && document.exitFullscreen) { document.exitFullscreen().catch(function () {}); }
      set(on);
    });
    document.addEventListener('fullscreenchange', function () { if (!document.fullscreenElement) { set(false); } });
    document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') { set(false); } });
  }
}());
