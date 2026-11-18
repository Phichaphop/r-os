/*
 * S01 page pictures, ILovePDF style (0.39.0, item 8.1). Draws each page with PDF.js (self-hosted, no eval, nothing
 * from outside) from the job's own preview URL, only when the page scrolls into view. On top of that:
 *   organize / order — drag cards (or use the ‹ › buttons) to change the order, ⟳ to turn a page, untick "keep" to drop it;
 *   pick             — tick pages to fill the "pages" field.
 * Without script the same form still works with the number / select fields this script hides.
 */
(() => {
  'use strict';
  const grids = Array.from(document.querySelectorAll('[data-pdf-pages]'));
  if (grids.length === 0) { return; }
  const d = grids[0].dataset;
  let lib = null;
  const docs = new Map();
  const pdfjs = () => {
    if (!lib) {
      lib = import(d.lib).then((m) => { m.GlobalWorkerOptions.workerSrc = d.worker; return m; });
    }
    return lib;
  };
  const open = (src) => {
    if (!docs.has(src)) {
      docs.set(src, pdfjs().then((m) => m.getDocument({ url: src, withCredentials: true, isEvalSupported: false, standardFontDataUrl: d.fonts, enableXfa: false }).promise));
    }
    return docs.get(src);
  };
  const draw = async (canvas) => {
    try {
      const doc = await open(canvas.dataset.src);
      const page = await doc.getPage(Number(canvas.dataset.page || '1'));
      const base = page.getViewport({ scale: 1 });
      const dpr = Math.min(window.devicePixelRatio || 1, 2);
      const vp = page.getViewport({ scale: (180 / Math.max(base.width, base.height)) * dpr });
      canvas.width = Math.ceil(vp.width);
      canvas.height = Math.ceil(vp.height);
      await page.render({ canvasContext: canvas.getContext('2d'), viewport: vp }).promise;
      canvas.closest('.pdf-thumb')?.classList.add('is-drawn');
    } catch (e) {
      canvas.closest('.pdf-thumb')?.classList.add('is-failed');
      canvas.setAttribute('aria-label', `${canvas.getAttribute('aria-label') || ''} — ${d.msgError}`);
    }
  };
  const canvases = Array.from(document.querySelectorAll('canvas.pdf-thumb__canvas'));
  if ('IntersectionObserver' in window) {
    const io = new IntersectionObserver((entries) => {
      entries.forEach((en) => { if (en.isIntersecting) { io.unobserve(en.target); draw(en.target); } });
    }, { rootMargin: '200px' });
    canvases.forEach((c) => io.observe(c));
  } else {
    canvases.forEach(draw);
  }

  const live = document.createElement('div');
  live.className = 'sr-only';
  live.setAttribute('aria-live', 'polite');
  document.body.appendChild(live);
  const say = (msg) => { live.textContent = ''; setTimeout(() => { live.textContent = msg; }, 30); };
  const fill = (msg, vars) => msg.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m));

  grids.forEach((grid) => {
    const form = grid.closest('form') || document;
    form.querySelectorAll('.pdf-js-only').forEach((el) => { el.hidden = false; });
    grid.classList.add('is-js');
    const mode = grid.dataset.pdfPages;

    if (mode === 'organize' || mode === 'order') {
      const items = () => Array.from(grid.querySelectorAll('[data-pdf-item]'));
      const renumber = () => items().forEach((li, i) => { const p = li.querySelector('[data-pos]'); if (p) { p.value = String(i + 1); } });
      const moved = (li) => say(fill(d.msgMoved, { n: items().indexOf(li) + 1, total: items().length }));
      grid.addEventListener('click', (ev) => {
        const btn = ev.target.closest('button[data-act]');
        if (!btn) { return; }
        const li = btn.closest('[data-pdf-item]');
        if (btn.dataset.act === 'prev' && li.previousElementSibling) {
          grid.insertBefore(li, li.previousElementSibling); renumber(); btn.focus(); moved(li);
        } else if (btn.dataset.act === 'next' && li.nextElementSibling) {
          grid.insertBefore(li.nextElementSibling, li); renumber(); btn.focus(); moved(li);
        } else if (btn.dataset.act === 'rot') {
          const sel = li.querySelector('[data-rot]');
          const deg = (Number(sel.value) + 90) % 360;
          sel.value = String(deg);
          li.querySelector('.pdf-thumb').dataset.turn = String(deg);
          say(fill(d.msgRotated, { deg }));
        }
      });
      let dragging = null;
      grid.addEventListener('dragstart', (ev) => {
        dragging = ev.target.closest('[data-pdf-item]');
        if (!dragging) { return; }
        dragging.classList.add('is-dragging');
        ev.dataTransfer.effectAllowed = 'move';
        ev.dataTransfer.setData('text/plain', '');
      });
      grid.addEventListener('dragover', (ev) => {
        if (!dragging) { return; }
        ev.preventDefault();
        const over = ev.target.closest('[data-pdf-item]');
        if (!over || over === dragging) { return; }
        const r = over.getBoundingClientRect();
        const after = (ev.clientX - r.left) > r.width / 2;
        grid.insertBefore(dragging, after ? over.nextElementSibling : over);
      });
      const end = () => { if (dragging) { dragging.classList.remove('is-dragging'); renumber(); moved(dragging); dragging = null; } };
      grid.addEventListener('drop', (ev) => { ev.preventDefault(); end(); });
      grid.addEventListener('dragend', end);
      // the server orders by these numbers: put the cards in that order (after a form error the numbers come back)
      const sorted = items().map((li, i) => [Number(li.querySelector('[data-pos]')?.value || i + 1), i, li]).sort((a, b) => a[0] - b[0] || a[1] - b[1]);
      sorted.forEach(([, , li]) => grid.appendChild(li));
      renumber();
    }

    if (mode === 'pick') {
      const field = form.querySelector('input[name="pages"]');
      const boxes = Array.from(grid.querySelectorAll('[data-pick]'));
      const compact = (nums) => {
        const out = [];
        for (let i = 0; i < nums.length; i++) {
          let j = i;
          while (j + 1 < nums.length && nums[j + 1] === nums[j] + 1) { j++; }
          out.push(j > i ? `${nums[i]}-${nums[j]}` : String(nums[i]));
          i = j;
        }
        return out.join(', ');
      };
      const parse = (spec) => {
        const set = new Set();
        spec.split(',').forEach((part) => {
          const m = part.trim().match(/^(\d+)(?:\s*-\s*(\d*))?$/);
          if (!m) { return; }
          const a = Number(m[1]);
          const b = m[2] === undefined ? a : (m[2] === '' ? boxes.length : Number(m[2]));
          for (let k = a; k <= Math.min(b, boxes.length); k++) { set.add(k); }
        });
        return set;
      };
      const sync = () => {
        const set = parse(field ? field.value : '');
        boxes.forEach((b) => { b.checked = set.has(Number(b.dataset.pick)); b.closest('.pdf-page').classList.toggle('is-picked', b.checked); });
      };
      boxes.forEach((b) => b.addEventListener('change', () => {
        b.closest('.pdf-page').classList.toggle('is-picked', b.checked);
        if (field) { field.value = compact(boxes.filter((x) => x.checked).map((x) => Number(x.dataset.pick))); }
      }));
      if (field) { field.addEventListener('change', sync); sync(); }
    }
  });
})();
