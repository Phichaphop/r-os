/*
 * 0.39.0 (item 3.1) signature tool — everything happens in this browser tab:
 *   1. draw a signature (mouse, finger or pen; or type a name) → download a transparent PNG or copy it as Base64;
 *   2. optionally open a PDF from this device, place the signature on any page (tap/click, drag, arrow keys, size),
 *      and download the signed copy — PDF.js draws the pages, pdf-lib writes the file.
 * Nothing is sent to the server and nothing is stored, not even in browser storage: closing the page forgets the signature.
 */
(() => {
  'use strict';
  const root = document.querySelector('[data-sign]');
  if (!root) { return; }
  const d = root.dataset;
  const $ = (sel) => root.querySelector(sel);
  const live = $('[data-live]');
  const say = (msg) => { live.textContent = ''; setTimeout(() => { live.textContent = msg; }, 30); };
  const fill = (msg, vars) => msg.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m));

  // ---- 1. the pad ---------------------------------------------------------------------------------
  const pad = $('[data-pad]');
  const hint = $('[data-pad-hint]');
  const ctx = pad.getContext('2d');
  const strokes = []; // {ink, width, pts: [[x, y] as 0..1 of the pad]} or {ink, text}
  let current = null;
  const swatch = (ink) => getComputedStyle(root.querySelector(`.sign__swatch--${ink}`)).backgroundColor;
  const ink = () => (root.querySelector('input[name="sign_ink"]:checked') || {}).value || 'black';
  const width = () => Number($('[data-width]').value || '3');

  const size = () => {
    const r = pad.getBoundingClientRect();
    const dpr = Math.min(window.devicePixelRatio || 1, 3);
    pad.width = Math.max(1, Math.round(r.width * dpr));
    pad.height = Math.max(1, Math.round(r.height * dpr));
    redraw();
  };
  const paint = (c, s, w, h, scale) => {
    c.strokeStyle = c.fillStyle = swatch(s.ink);
    if (s.text !== undefined) {
      const px = Math.round(h * 0.42);
      c.font = `italic 500 ${px}px ${getComputedStyle(pad).fontFamily}`;
      c.textAlign = 'center';
      c.textBaseline = 'alphabetic';
      c.fillText(s.text, w / 2, h * 0.68, w * 0.92);
      return;
    }
    c.lineWidth = s.width * scale;
    c.lineCap = 'round';
    c.lineJoin = 'round';
    const p = s.pts.map(([x, y]) => [x * w, y * h]);
    c.beginPath();
    if (p.length === 1) {
      c.arc(p[0][0], p[0][1], c.lineWidth / 2, 0, Math.PI * 2);
      c.fill();
      return;
    }
    c.moveTo(p[0][0], p[0][1]);
    for (let i = 1; i < p.length - 1; i++) {
      const mx = (p[i][0] + p[i + 1][0]) / 2;
      const my = (p[i][1] + p[i + 1][1]) / 2;
      c.quadraticCurveTo(p[i][0], p[i][1], mx, my);
    }
    c.lineTo(p[p.length - 1][0], p[p.length - 1][1]);
    c.stroke();
  };
  function redraw() {
    ctx.clearRect(0, 0, pad.width, pad.height);
    const scale = pad.width / pad.getBoundingClientRect().width || 1;
    strokes.forEach((s) => paint(ctx, s, pad.width, pad.height, scale));
    hint.hidden = strokes.length > 0;
    clearTimeout(redraw.t);
    redraw.t = setTimeout(changed, current ? 400 : 60); // the cropped copy is rebuilt when the pen rests
  }
  const point = (ev) => {
    const r = pad.getBoundingClientRect();
    return [Math.min(1, Math.max(0, (ev.clientX - r.left) / r.width)), Math.min(1, Math.max(0, (ev.clientY - r.top) / r.height))];
  };
  pad.addEventListener('pointerdown', (ev) => {
    ev.preventDefault();
    pad.setPointerCapture(ev.pointerId);
    if (strokes.length && strokes[strokes.length - 1].text !== undefined) { strokes.length = 0; } // drawing replaces a typed name
    current = { ink: ink(), width: width() * (ev.pointerType === 'pen' && ev.pressure > 0 ? 0.6 + ev.pressure * 0.8 : 1), pts: [point(ev)] };
    strokes.push(current);
    redraw();
  });
  pad.addEventListener('pointermove', (ev) => {
    if (!current) { return; }
    const evs = typeof ev.getCoalescedEvents === 'function' ? ev.getCoalescedEvents() : [ev];
    evs.forEach((e) => current.pts.push(point(e)));
    redraw();
  });
  const stop = () => { if (current) { current = null; clearTimeout(redraw.t); changed(); } };
  pad.addEventListener('pointerup', stop);
  pad.addEventListener('pointercancel', stop);
  window.addEventListener('resize', () => { clearTimeout(size.t); size.t = setTimeout(size, 150); });
  root.querySelectorAll('input[name="sign_ink"]').forEach((r) => r.addEventListener('change', () => {
    strokes.forEach((s) => { s.ink = ink(); }); // one signature, one ink
    redraw();
  }));

  // the drawing, cropped to what was drawn, on a transparent background, at 3× the pad's size on screen
  const exportCanvas = () => {
    if (strokes.length === 0) { return null; }
    const r = pad.getBoundingClientRect();
    const k = 3;
    const big = document.createElement('canvas');
    big.width = Math.round(r.width * k);
    big.height = Math.round(r.height * k);
    const c = big.getContext('2d');
    strokes.forEach((s) => paint(c, s, big.width, big.height, k));
    const px = c.getImageData(0, 0, big.width, big.height).data;
    let x0 = big.width; let y0 = big.height; let x1 = -1; let y1 = -1;
    for (let y = 0; y < big.height; y++) {
      for (let x = 0; x < big.width; x++) {
        if (px[(y * big.width + x) * 4 + 3] > 8) {
          if (x < x0) { x0 = x; } if (x > x1) { x1 = x; } if (y < y0) { y0 = y; } if (y > y1) { y1 = y; }
        }
      }
    }
    if (x1 < 0) { return null; }
    const m = 6 * k;
    x0 = Math.max(0, x0 - m); y0 = Math.max(0, y0 - m); x1 = Math.min(big.width - 1, x1 + m); y1 = Math.min(big.height - 1, y1 + m);
    const out = document.createElement('canvas');
    out.width = x1 - x0 + 1;
    out.height = y1 - y0 + 1;
    out.getContext('2d').drawImage(big, x0, y0, out.width, out.height, 0, 0, out.width, out.height);
    return out;
  };
  let sigUrl = null; // data: URL of the current signature (also what the Base64 box shows)
  let sigRatio = 0.35;
  function changed() {
    const c = strokes.length ? exportCanvas() : null;
    sigUrl = c ? c.toDataURL('image/png') : null;
    sigRatio = c ? c.height / c.width : 0.35;
    $('[data-b64]').value = sigUrl || '';
    root.querySelectorAll('[data-sig-img]').forEach((img) => { if (sigUrl) { img.src = sigUrl; } });
    layout();
  }
  const download = (blob, name) => {
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = name;
    document.body.appendChild(a);
    a.click();
    setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
  };

  root.addEventListener('click', async (ev) => {
    const btn = ev.target.closest('[data-act]');
    if (!btn) { return; }
    const act = btn.dataset.act;
    if (act === 'undo') { strokes.pop(); redraw(); }
    if (act === 'clear') { strokes.length = 0; redraw(); }
    if (act === 'typed') {
      const text = $('[data-typed]').value.trim();
      if (text === '') { $('[data-typed]').focus(); return; }
      strokes.length = 0;
      strokes.push({ ink: ink(), text });
      redraw();
    }
    if (act === 'png') {
      const c = exportCanvas();
      if (!c) { say(d.msgEmpty); return; }
      c.toBlob((b) => download(b, 'signature.png'), 'image/png');
    }
    if (act === 'copy') {
      if (!sigUrl) { say(d.msgEmpty); return; }
      try { await navigator.clipboard.writeText(sigUrl); say(d.msgCopied); } catch (e) {
        const box = $('[data-b64]');
        box.closest('details').open = true;
        box.focus();
        box.select();
        say(d.msgCopyFailed);
      }
    }
    if (act === 'prev') { show(pageNo - 1); }
    if (act === 'next') { show(pageNo + 1); }
    if (act === 'place') { place(0.5, 0.75, true); }
    if (act === 'save') { save(); }
    if (act === 'remove') { remove(Number(btn.dataset.idx)); }
  });

  // ---- 2. place on a PDF ---------------------------------------------------------------------------
  const docBox = $('[data-doc]');
  const stage = $('[data-stage]');
  const pageCanvas = $('[data-page-canvas]');
  const layer = $('[data-layer]');
  const list = $('[data-placements]');
  let bytes = null; let fileName = 'document.pdf'; let doc = null; let pageNo = 1; let viewport = null;
  const marks = []; // {page, x, y, w} — top-left corner and width as fractions of the page as shown
  const viewports = new Map();
  let lib = null;
  const pdfjs = () => {
    if (!lib) { lib = import(d.lib).then((m) => { m.GlobalWorkerOptions.workerSrc = d.worker; return m; }); }
    return lib;
  };

  $('[data-pdf]').addEventListener('change', async (ev) => {
    const f = ev.target.files && ev.target.files[0];
    if (!f) { return; }
    if (f.size > Number(d.maxMb) * 1048576) { say(d.msgTooBig); ev.target.value = ''; return; }
    const buf = new Uint8Array(await f.arrayBuffer());
    if (String.fromCharCode(...buf.slice(0, 5)) !== '%PDF-') { say(d.msgNotPdf); ev.target.value = ''; return; }
    bytes = buf;
    fileName = f.name.replace(/\.pdf$/i, '') || 'document';
    marks.length = 0;
    viewports.clear();
    try {
      const m = await pdfjs();
      doc = await m.getDocument({ data: bytes.slice(), isEvalSupported: false, standardFontDataUrl: d.fonts, enableXfa: false }).promise;
    } catch (e) {
      doc = null;
      say(e && e.name === 'PasswordException' ? d.msgEncrypted : d.msgFailed);
      docBox.hidden = true;
      return;
    }
    docBox.hidden = false;
    renderList();
    show(1);
  });

  let rendering = null;
  async function show(n) {
    if (!doc) { return; }
    pageNo = Math.min(doc.numPages, Math.max(1, n));
    $('[data-page-label]').textContent = fill(d.msgPage, { n: pageNo, total: doc.numPages });
    const page = await doc.getPage(pageNo);
    const base = page.getViewport({ scale: 1 });
    viewports.set(pageNo, base);
    const cssW = stage.getBoundingClientRect().width || 600;
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    viewport = page.getViewport({ scale: (cssW / base.width) * dpr });
    pageCanvas.width = Math.ceil(viewport.width);
    pageCanvas.height = Math.ceil(viewport.height);
    if (rendering) { rendering.cancel(); }
    rendering = page.render({ canvasContext: pageCanvas.getContext('2d'), viewport });
    try { await rendering.promise; } catch (e) { /* cancelled by a newer page */ }
    layout();
  }

  function layout() {
    layer.replaceChildren();
    marks.forEach((m, i) => {
      if (m.page !== pageNo || !sigUrl) { return; }
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'sign__mark';
      b.dataset.idx = String(i);
      b.setAttribute('aria-label', fill(d.msgPlacement, { n: i + 1, page: m.page }));
      const img = document.createElement('img');
      img.src = sigUrl;
      img.alt = '';
      img.dataset.sigImg = '1';
      b.appendChild(img);
      b.style.left = `${m.x * 100}%`;
      b.style.top = `${m.y * 100}%`;
      b.style.width = `${m.w * 100}%`;
      layer.appendChild(b);
    });
  }
  function renderList() {
    list.replaceChildren(...marks.map((m, i) => {
      const li = document.createElement('li');
      li.className = 'row';
      const span = document.createElement('span');
      span.textContent = fill(d.msgPlacement, { n: i + 1, page: m.page });
      const rm = document.createElement('button');
      rm.type = 'button';
      rm.className = 'btn btn--ghost btn--sm';
      rm.dataset.act = 'remove';
      rm.dataset.idx = String(i);
      rm.textContent = d.msgRemove;
      li.append(span, rm);
      return li;
    }));
  }
  function place(cx, cy, focus) {
    if (!sigUrl) { say(d.msgNeedSig); pad.focus(); return; }
    const w = Number($('[data-size]').value) / 100;
    const vp = viewports.get(pageNo);
    const h = w * sigRatio * (vp ? vp.width / vp.height : 0.7);
    marks.push({ page: pageNo, x: Math.min(1 - w, Math.max(0, cx - w / 2)), y: Math.min(1 - h, Math.max(0, cy - h / 2)), w });
    layout();
    renderList();
    say(fill(d.msgPlaced, { page: pageNo }));
    if (focus) { layer.lastElementChild?.focus(); }
  }
  function remove(i) {
    marks.splice(i, 1);
    layout();
    renderList();
    say(d.msgRemoved);
  }

  // click / tap on the page places the signature there; dragging a placed one moves it
  let drag = null;
  stage.addEventListener('pointerdown', (ev) => {
    const mark = ev.target.closest('.sign__mark');
    const r = stage.getBoundingClientRect();
    if (mark) {
      const m = marks[Number(mark.dataset.idx)];
      drag = { m, el: mark, dx: (ev.clientX - r.left) / r.width - m.x, dy: (ev.clientY - r.top) / r.height - m.y, id: ev.pointerId };
      mark.setPointerCapture(ev.pointerId);
      ev.preventDefault();
      return;
    }
    place((ev.clientX - r.left) / r.width, (ev.clientY - r.top) / r.height, false);
  });
  stage.addEventListener('pointermove', (ev) => {
    if (!drag) { return; }
    const r = stage.getBoundingClientRect();
    const h = drag.el.getBoundingClientRect().height / r.height;
    drag.m.x = Math.min(1 - drag.m.w, Math.max(0, (ev.clientX - r.left) / r.width - drag.dx));
    drag.m.y = Math.min(1 - h, Math.max(0, (ev.clientY - r.top) / r.height - drag.dy));
    drag.el.style.left = `${drag.m.x * 100}%`;
    drag.el.style.top = `${drag.m.y * 100}%`;
  });
  const drop = () => { drag = null; };
  stage.addEventListener('pointerup', drop);
  stage.addEventListener('pointercancel', drop);
  // keyboard: arrows move the focused signature (Shift = faster), Delete removes it
  layer.addEventListener('keydown', (ev) => {
    const mark = ev.target.closest('.sign__mark');
    if (!mark) { return; }
    const i = Number(mark.dataset.idx);
    const m = marks[i];
    const step = ev.shiftKey ? 0.05 : 0.01;
    const moves = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };
    if (moves[ev.key]) {
      ev.preventDefault();
      m.x = Math.min(1 - m.w, Math.max(0, m.x + moves[ev.key][0]));
      m.y = Math.min(0.98, Math.max(0, m.y + moves[ev.key][1]));
      mark.style.left = `${m.x * 100}%`;
      mark.style.top = `${m.y * 100}%`;
    } else if (ev.key === 'Delete' || ev.key === 'Backspace') {
      ev.preventDefault();
      remove(i);
    }
  });
  $('[data-size]').addEventListener('input', () => {
    const w = Number($('[data-size]').value) / 100;
    marks.filter((m) => m.page === pageNo).forEach((m) => { m.w = w; m.x = Math.min(1 - w, m.x); });
    layout();
  });

  async function save() {
    if (!doc || !bytes) { return; }
    if (!sigUrl) { say(d.msgNeedSig); return; }
    if (marks.length === 0) { say(d.msgNeedPlace); return; }
    try {
      const PDFLib = await import(d.pdflib);
      const out = await PDFLib.PDFDocument.load(bytes, { updateMetadata: false });
      const png = await out.embedPng(sigUrl);
      for (const m of marks) {
        if (!viewports.has(m.page)) {
          const p = await doc.getPage(m.page);
          viewports.set(m.page, p.getViewport({ scale: 1 }));
        }
        const vp = viewports.get(m.page);
        const left = m.x * vp.width;
        const top = m.y * vp.height;
        const wpx = m.w * vp.width;
        const hpx = wpx * (png.height / png.width);
        // the box as seen on screen → PDF user space; works for rotated pages and offset crop boxes
        const [x0, y0] = vp.convertToPdfPoint(left, top + hpx);
        const [x1, y1] = vp.convertToPdfPoint(left + wpx, top + hpx);
        const wPdf = Math.hypot(x1 - x0, y1 - y0);
        const angle = Math.atan2(y1 - y0, x1 - x0) * 180 / Math.PI;
        out.getPage(m.page - 1).drawImage(png, { x: x0, y: y0, width: wPdf, height: wPdf * (png.height / png.width), rotate: PDFLib.degrees(angle) });
      }
      const signed = await out.save();
      download(new Blob([signed], { type: 'application/pdf' }), `${fileName}-signed.pdf`);
      say(d.msgReady);
    } catch (e) {
      say(e && /encrypt/i.test(String(e.message)) ? d.msgEncrypted : d.msgFailed);
    }
  }

  size();
})();
