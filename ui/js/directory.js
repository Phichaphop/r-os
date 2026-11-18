/* M47 type-ahead: as the person types, ask the server for matching contact cards (the same check as a normal search). */
(function () {
  'use strict';
  var form = document.querySelector('[data-dir-search]');
  if (!form) { return; }
  var input = form.querySelector('input[name="q"]');
  var timer = null;
  var seq = 0;
  input.addEventListener('input', function () {
    if (timer) { window.clearTimeout(timer); }
    timer = window.setTimeout(function () {
      var params = new URLSearchParams(new FormData(form));
      params.set('partial', '1');
      var mine = ++seq;
      fetch(form.getAttribute('data-partial') + '?' + params.toString(), { credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.text() : null; })
        .then(function (html) {
          if (html === null || mine !== seq) { return; }
          var box = document.querySelector('[data-dir-results]');
          if (!box) { return; }
          var tpl = document.createElement('template');
          tpl.innerHTML = html.trim();
          var fresh = tpl.content.querySelector('[data-dir-results]');
          if (fresh) { box.replaceWith(fresh); }
          var q = params.get('q') || '';
          try { window.history.replaceState(null, '', '?view=' + encodeURIComponent(params.get('view') || 'all') + (q ? '&q=' + encodeURIComponent(q) : '')); } catch (e) { /* ignore */ }
        })
        .catch(function () { /* keep the current results */ });
    }, 300);
  });
}());
