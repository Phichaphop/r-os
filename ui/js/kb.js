/* M55: before reporting a fault, suggest published answers that match what is typed in the title (no answer = nothing shown). */
(function () {
  'use strict';
  var box = document.querySelector('[data-kb-suggest]');
  if (!box) { return; }
  var input = document.getElementById('f-title');
  if (!input) { return; }
  var timer = null;
  var seq = 0;
  input.addEventListener('input', function () {
    if (timer) { window.clearTimeout(timer); }
    timer = window.setTimeout(function () {
      var q = input.value.trim();
      var mine = ++seq;
      if (q.length < 3) { box.innerHTML = '<div data-kb-results></div>'; return; }
      fetch(box.getAttribute('data-kb-suggest') + '?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.text() : null; })
        .then(function (html) {
          if (html === null || mine !== seq) { return; }
          var tpl = document.createElement('template');
          tpl.innerHTML = html.trim();
          var fresh = tpl.content.querySelector('[data-kb-results]');
          var old = box.querySelector('[data-kb-results]');
          if (fresh && old) { old.replaceWith(fresh); }
        })
        .catch(function () { /* keep the form usable without suggestions */ });
    }, 350);
  });
}());
