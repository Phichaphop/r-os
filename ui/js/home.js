/* Home app hub (6.4): filter app tiles by name/summary. Without JavaScript every tile is simply listed. */
(function () {
  'use strict';
  var box = document.querySelector('[data-hub-search]');
  var input = document.getElementById('hub-q');
  var hub = document.querySelector('[data-hub]');
  if (!box || !input || !hub) { return; }
  var items = Array.prototype.slice.call(hub.querySelectorAll('[data-hub-item]'));
  var groups = Array.prototype.slice.call(hub.querySelectorAll('[data-hub-group]'));
  var none = hub.querySelector('[data-hub-none]');
  var found = document.querySelector('[data-hub-found]');
  if (items.length === 0) { return; }
  box.hidden = false;

  function norm(s) { return (s || '').toLocaleLowerCase().replace(/\s+/g, ' ').trim(); }

  var timer = null;
  function apply() {
    var q = norm(input.value);
    var shown = 0;
    items.forEach(function (li) {
      var ok = q === '' || norm(li.getAttribute('data-hub-text')).indexOf(q) !== -1;
      li.hidden = !ok;
      if (ok) { shown++; }
    });
    groups.forEach(function (g) {
      g.hidden = g.querySelectorAll('[data-hub-item]:not([hidden])').length === 0;
    });
    if (none) { none.hidden = shown !== 0; }
    if (found) {
      window.clearTimeout(timer);
      timer = window.setTimeout(function () {
        found.textContent = q === '' ? '' : (found.getAttribute('data-template') || '{n}').replace('{n}', String(shown));
      }, 400);
    }
  }
  input.addEventListener('input', apply);
  input.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && input.value !== '') { input.value = ''; apply(); ev.stopPropagation(); }
    if (ev.key === 'Enter' && norm(input.value) !== '') {
      var first = hub.querySelector('[data-hub-item]:not([hidden]) a.app-tile');
      if (first) { ev.preventDefault(); first.click(); }
    }
  });
})();

/* Login pop-up offered by a module's home section (e.g. M19 announcements): shown once, closes itself after N seconds. */
(function () {
  'use strict';
  var d = document.querySelector('[data-home-popup]');
  if (!d || typeof d.showModal !== 'function') { return; }
  var left = parseInt(d.getAttribute('data-seconds') || '0', 10) || 0;
  var count = d.querySelector('[data-home-popup-count]');
  var close = function () { if (d.open) { d.close(); } };
  d.querySelector('[data-home-popup-close]').addEventListener('click', close);
  d.showModal();
  if (left > 0) {
    var tick = function () {
      if (count) { count.textContent = '(' + left + ')'; }
      if (left <= 0) { close(); return; }
      left -= 1; window.setTimeout(tick, 1000);
    };
    tick();
  }
}());
