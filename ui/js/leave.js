/* M32 request form: ask the server how many working days the request takes and what is left (the same check runs on
   submit). Shows the half-day choice and the document field only when the type's policy allows them. */
(function () {
  'use strict';
  var form = document.querySelector('[data-leave-form]');
  if (!form) { return; }
  var rules = {};
  try { rules = JSON.parse(form.getAttribute('data-rules') || '{}'); } catch (e) { rules = {}; }
  var box = form.querySelector('[data-leave-preview]');
  var partBox = form.querySelector('[data-leave-part]');
  var docBox = form.querySelector('[data-leave-doc]');
  var type = form.querySelector('[name="type"]');
  var start = form.querySelector('[name="start_date"]');
  var end = form.querySelector('[name="end_date"]');
  var out = function (k) { return box ? box.querySelector('[data-out="' + k + '"]') : null; };
  var timer = null;
  var seq = 0;

  function set(k, text) { var el = out(k); if (el) { el.textContent = text || ''; el.hidden = !text; } }

  function toggles() {
    var r = rules[type.value] || { half: false, doc: false };
    var single = !end.value || end.value === start.value;
    if (partBox) {
      partBox.hidden = !(r.half && single);
      if (partBox.hidden) { var full = form.querySelector('#part-full'); if (full) { full.checked = true; } }
    }
    if (docBox) { docBox.hidden = !r.doc && !form.querySelector('[name="document"]').value; }
  }

  function check() {
    toggles();
    if (!type.value || !start.value || !box) { if (box) { box.hidden = true; } return; }
    var body = new FormData();
    ['type', 'start_date', 'end_date', 'reason'].forEach(function (n) { var el = form.querySelector('[name="' + n + '"]'); body.append(n, el ? el.value : ''); });
    var part = form.querySelector('[name="part"]:checked');
    body.append('part', part ? part.value : 'full');
    body.append('request', form.getAttribute('data-request') || '');
    var mine = ++seq;
    fetch(form.getAttribute('data-preview'), { method: 'POST', body: body, credentials: 'same-origin',
      headers: { 'X-CSRF-Token': form.getAttribute('data-csrf') || '', Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (mine !== seq) { return; }
        box.hidden = false;
        box.classList.toggle('is-error', !d.ok);
        if (!d.ok) {
          var msgs = [];
          Object.keys(d.errors || {}).forEach(function (k) { msgs.push(d.errors[k]); });
          set('units', msgs.join(' · '));
          set('days', ''); set('balance', ''); set('notes', '');
          return;
        }
        set('units', d.units);
        set('days', (d.days || []).join(', '));
        set('balance', d.balance);
        set('notes', [d.document].concat(d.warnings || []).filter(Boolean).join(' · '));
      })
      .catch(function () { if (box) { box.hidden = true; } });
  }

  function later() { window.clearTimeout(timer); timer = window.setTimeout(check, 250); }
  form.addEventListener('change', later);
  form.addEventListener('input', function (ev) { if (ev.target && ev.target.name !== 'reason') { later(); } });
  check();
})();
