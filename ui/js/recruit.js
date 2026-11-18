/* M08 application form (phase 5E): one step at a time on a phone, a progress bar, each step checked before moving on.
   Without JavaScript every step is shown and the server checks everything. Work history adds up the years. */
(() => {
  'use strict';
  const form = document.querySelector('[data-apply]');
  if (!form) return;
  const steps = Array.from(form.querySelectorAll('[data-step]'));
  const nav = form.querySelector('[data-apply-nav]');
  const back = form.querySelector('[data-apply-back]');
  const next = form.querySelector('[data-apply-next]');
  const submit = form.querySelector('[data-apply-submit]');
  const progress = form.querySelector('[data-apply-progress]');
  const fill = form.querySelector('[data-apply-fill]');
  const bar = form.querySelector('[data-apply-bar]');
  const count = form.querySelector('[data-apply-count]');
  const name = form.querySelector('[data-apply-name]');
  let at = Math.max(0, steps.findIndex((s) => s.dataset.step === form.dataset.start));
  const show = (i, focus = true) => {
    at = Math.min(Math.max(0, i), steps.length - 1);
    steps.forEach((s, k) => { s.hidden = k !== at; });
    back.hidden = at === 0;
    next.hidden = at === steps.length - 1;
    submit.hidden = at !== steps.length - 1;
    count.textContent = `${at + 1} / ${steps.length}`;
    name.textContent = steps[at].dataset.title || '';
    fill.classList.remove(...Array.from(fill.classList).filter((c) => c.startsWith('w-')));
    fill.classList.add(`w-${Math.round(((at + 1) / steps.length) * 8)}`);
    bar.setAttribute('aria-valuenow', String(at + 1));
    if (focus) steps[at].querySelector('legend')?.focus?.();
    if (focus) steps[at].scrollIntoView({ block: 'start', behavior: 'smooth' });
  };
  steps.forEach((s) => { const l = s.querySelector('legend'); if (l) l.tabIndex = -1; });
  progress.hidden = false; nav.hidden = false;
  back.addEventListener('click', () => show(at - 1));
  next.addEventListener('click', () => {
    const bad = Array.from(steps[at].querySelectorAll('input, select, textarea')).find((el) => !el.checkValidity() || (el.required && el.type === 'checkbox' && !el.checked) || (el.required && el.type !== 'checkbox' && el.value.trim() === ''));
    if (bad) { bad.reportValidity?.(); bad.focus(); return; }
    show(at + 1);
  });
  // years of work experience
  const out = form.querySelector('[data-apply-years]');
  const months = (v) => { const m = /^(\d{4})-(\d{2})$/.exec(v || ''); return m ? Number(m[1]) * 12 + Number(m[2]) - 1 : null; };
  const sum = () => {
    let total = 0;
    for (let i = 0; i < 3; i++) {
      const a = months(form.querySelector(`[name="work[${i}][from]"]`)?.value);
      const b = months(form.querySelector(`[name="work[${i}][to]"]`)?.value) ?? (a !== null ? months(new Date().toISOString().slice(0, 7)) : null);
      if (a !== null && b !== null && b >= a) total += b - a + 1;
    }
    out.textContent = total > 0 ? (out.dataset.label || '{y} / {m}').replace('{y}', String(Math.floor(total / 12))).replace('{m}', String(total % 12)) : '';
  };
  form.addEventListener('input', (ev) => { if (ev.target.name?.startsWith('work[')) sum(); });
  sum();
  show(at, false);
})();
