/*
 * M03 reader: draws PDF pages with PDF.js (self-hosted in ui/js/vendor/pdfjs, no eval, no outside requests), one page at a
 * time, fit to width with zoom. The page you are on is saved (for "continue reading") and can be bookmarked; the page's text
 * comes from the library's index for screen readers. If anything fails, the "open in browser" link still works.
 */
const root = document.querySelector('[data-lb-reader]');
if (root) {
  const $ = (sel) => root.querySelector(sel);
  const status = $('[data-lb-status]');
  const canvas = $('[data-lb-canvas]');
  const view = $('[data-lb-view]');
  const pageInput = $('[data-lb-page]');
  const countEl = $('[data-lb-count]');
  const bmPage = $('[data-lb-bm-page]');
  const textBox = $('[data-lb-textbox]');
  const textEl = $('[data-lb-text]');
  const d = root.dataset;
  let doc = null;
  let pageNo = Math.max(1, parseInt(d.start, 10) || 1);
  let zoom = 1;
  let rendering = null;
  let saveTimer = null;
  const say = (msg) => { status.textContent = msg; };
  const fmt = (tpl, page) => tpl.replace('{page}', String(page));

  const save = () => {
    if (saveTimer) { clearTimeout(saveTimer); }
    saveTimer = setTimeout(() => {
      const body = new URLSearchParams({ page: String(pageNo), _csrf: d.csrf });
      fetch(d.progress, { method: 'POST', credentials: 'same-origin', headers: { 'x-requested-with': 'fetch', 'x-csrf-token': d.csrf }, body }).catch(() => {});
    }, 1200);
  };

  const loadText = () => {
    if (!textBox.open) { return; }
    textEl.textContent = '…';
    fetch(d.text + '?page=' + pageNo, { credentials: 'same-origin' })
      .then((r) => (r.ok ? r.json() : null))
      .then((j) => { textEl.textContent = j && j.text ? j.text : d.msgNotext; })
      .catch(() => { textEl.textContent = d.msgNotext; });
  };

  const render = async () => {
    if (!doc) { return; }
    pageNo = Math.min(Math.max(1, pageNo), doc.numPages);
    pageInput.value = String(pageNo);
    bmPage.value = String(pageNo);
    if (rendering) { try { rendering.cancel(); } catch (e) { /* ignore */ } }
    const page = await doc.getPage(pageNo);
    const base = page.getViewport({ scale: 1 });
    const width = Math.min(900, Math.max(240, view.clientWidth - 2)); // readable line length on wide screens
    const scale = (width / base.width) * zoom;
    const vp = page.getViewport({ scale });
    const ratio = window.devicePixelRatio || 1;
    canvas.width = Math.floor(vp.width * ratio);
    canvas.height = Math.floor(vp.height * ratio);
    canvas.style.width = Math.floor(vp.width) + 'px';
    canvas.style.height = Math.floor(vp.height) + 'px';
    canvas.setAttribute('aria-label', fmt(d.msgPage, pageNo));
    const ctx = canvas.getContext('2d');
    rendering = page.render({ canvasContext: ctx, viewport: vp, transform: ratio !== 1 ? [ratio, 0, 0, ratio, 0, 0] : null });
    try { await rendering.promise; } catch (e) { return; }
    say(fmt(d.msgPage, pageNo) + ' / ' + doc.numPages);
    const u = new URL(window.location.href);
    u.searchParams.set('page', String(pageNo));
    window.history.replaceState(null, '', u.toString());
    save();
    loadText();
  };

  const go = (n) => { pageNo = n; render(); };
  $('[data-lb-prev]').addEventListener('click', () => go(pageNo - 1));
  $('[data-lb-next]').addEventListener('click', () => go(pageNo + 1));
  pageInput.addEventListener('change', () => go(parseInt(pageInput.value, 10) || 1));
  root.querySelectorAll('[data-lb-zoom]').forEach((b) => b.addEventListener('click', () => {
    const z = parseInt(b.getAttribute('data-lb-zoom'), 10);
    zoom = z === 0 ? 1 : Math.min(3, Math.max(0.5, zoom + z * 0.25));
    render();
  }));
  textBox.addEventListener('toggle', loadText);
  document.addEventListener('keydown', (ev) => {
    const tag = (ev.target && ev.target.tagName) || '';
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') { return; }
    if (ev.key === 'ArrowRight' || ev.key === 'PageDown') { go(pageNo + 1); }
    if (ev.key === 'ArrowLeft' || ev.key === 'PageUp') { go(pageNo - 1); }
  });
  let resizeTimer = null;
  window.addEventListener('resize', () => { clearTimeout(resizeTimer); resizeTimer = setTimeout(render, 250); });

  (async () => {
    try {
      const pdfjs = await import(d.lib);
      pdfjs.GlobalWorkerOptions.workerSrc = d.worker;
      doc = await pdfjs.getDocument({ url: d.src, withCredentials: true, isEvalSupported: false, standardFontDataUrl: d.fonts, enableXfa: false }).promise;
      countEl.textContent = String(doc.numPages);
      pageInput.max = String(doc.numPages);
      await render();
    } catch (e) {
      say(d.msgError);
    }
  })();
}
