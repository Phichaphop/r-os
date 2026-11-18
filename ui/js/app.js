// Blue OS shared UI behaviour (ES module, no build step — ADR-006).
// Preferences live in localStorage only as a per-device convenience; failures are ignored.

const store = {
  get(k) { try { return localStorage.getItem('blue.' + k); } catch { return null; } },
  set(k, v) { try { localStorage.setItem('blue.' + k, v); } catch { /* private mode */ } },
};
const root = document.documentElement;

function initPreferences() {
  document.querySelectorAll('[data-action="toggle-theme"]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const next = root.dataset.theme === 'dark' ? 'light' : 'dark';
      root.dataset.theme = next;
      store.set('theme', next);
      btn.setAttribute('aria-pressed', String(next === 'dark'));
    });
    btn.setAttribute('aria-pressed', String(root.dataset.theme === 'dark'));
  });
  document.querySelectorAll('[data-action="set-density"]').forEach((el) => {
    el.addEventListener('change', () => { root.dataset.density = el.value; store.set('density', el.value); });
    if (el.value === (root.dataset.density || 'comfortable')) el.checked = true;
  });
}

function initShell() {
  const shell = document.querySelector('.shell');
  if (!shell) return;
  const collapse = shell.querySelector('[data-action="collapse-sidebar"]');
  collapse?.addEventListener('click', () => {
    const rail = root.dataset.sidebar !== 'rail';
    root.dataset.sidebar = rail ? 'rail' : 'full';
    store.set('sidebar', root.dataset.sidebar);
    collapse.setAttribute('aria-expanded', String(!rail));
  });
  const menuBtn = shell.querySelector('[data-action="open-drawer"]');
  const close = () => { shell.dataset.drawer = 'closed'; menuBtn?.setAttribute('aria-expanded', 'false'); };
  menuBtn?.addEventListener('click', () => {
    const open = shell.dataset.drawer !== 'open';
    shell.dataset.drawer = open ? 'open' : 'closed';
    menuBtn.setAttribute('aria-expanded', String(open));
    if (open) shell.querySelector('.sidebar a, .sidebar button')?.focus();
  });
  shell.addEventListener('click', (e) => { if (shell.dataset.drawer === 'open' && e.target === shell) close(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && shell.dataset.drawer === 'open') { close(); menuBtn?.focus(); } });
}

// Modal / drawer on <dialog>: native focus trap + Esc; focus returns to the opener.
function initDialogs() {
  document.querySelectorAll('[data-open-dialog]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const dlg = document.getElementById(btn.dataset.openDialog);
      if (!dlg) return;
      dlg.showModal();
      dlg.addEventListener('close', () => btn.focus(), { once: true });
    });
  });
  document.querySelectorAll('dialog [data-close-dialog]').forEach((btn) => {
    btn.addEventListener('click', () => btn.closest('dialog')?.close());
  });
}

// Tabs: ARIA tablist with Left/Right/Home/End.
function initTabs() {
  document.querySelectorAll('[role="tablist"]').forEach((list) => {
    const tabs = [...list.querySelectorAll('[role="tab"]')];
    const select = (tab) => {
      tabs.forEach((t) => {
        const on = t === tab;
        t.setAttribute('aria-selected', String(on));
        t.tabIndex = on ? 0 : -1;
        document.getElementById(t.getAttribute('aria-controls'))?.toggleAttribute('hidden', !on);
      });
      tab.focus();
    };
    tabs.forEach((t, i) => {
      t.addEventListener('click', () => select(t));
      t.addEventListener('keydown', (e) => {
        const idx = { ArrowRight: i + 1, ArrowLeft: i - 1, Home: 0, End: tabs.length - 1 }[e.key];
        if (idx === undefined) return;
        e.preventDefault();
        select(tabs[(idx + tabs.length) % tabs.length]);
      });
    });
  });
}

// Toast: polite live region, pauses on hover/focus.
export function toast(message) {
  let region = document.querySelector('.toast-region');
  if (!region) {
    region = document.createElement('div');
    region.className = 'toast-region';
    region.setAttribute('aria-live', 'polite');
    document.body.append(region);
  }
  const el = document.createElement('div');
  el.className = 'toast';
  const text = document.createElement('div');
  text.textContent = message;
  const btn = document.createElement('button');
  btn.className = 'toast__close';
  btn.type = 'button';
  btn.setAttribute('aria-label', document.documentElement.lang === 'th' ? 'ปิด' : 'Close');
  btn.textContent = '×';
  btn.addEventListener('click', () => el.remove());
  el.append(text, btn);
  region.append(el);
  let timer = setTimeout(() => el.remove(), 5000);
  const pause = () => clearTimeout(timer);
  const resume = () => { timer = setTimeout(() => el.remove(), 3000); };
  el.addEventListener('mouseenter', pause);
  el.addEventListener('mouseleave', resume);
  el.addEventListener('focusin', pause);
  el.addEventListener('focusout', resume);
}

function initDemos() {
  document.querySelectorAll('[data-toast]').forEach((b) => b.addEventListener('click', () => toast(b.dataset.toast)));
  document.querySelectorAll('[data-demo-loading]').forEach((b) => b.addEventListener('click', () => {
    if (b.getAttribute('aria-busy') === 'true') return;
    b.setAttribute('aria-busy', 'true');
    setTimeout(() => b.removeAttribute('aria-busy'), 1500);
  }));
  // Masked value demo — the real flow asks for re-authentication first and writes an audit record (P0-04).
  document.querySelectorAll('[data-masked]').forEach((wrap) => {
    const out = wrap.querySelector('[data-masked-value]');
    const btn = wrap.querySelector('button');
    const masked = out.textContent;
    btn?.addEventListener('click', () => {
      const showing = btn.getAttribute('aria-pressed') === 'true';
      out.textContent = showing ? masked : wrap.dataset.masked;
      btn.setAttribute('aria-pressed', String(!showing));
      if (!showing) setTimeout(() => { out.textContent = masked; btn.setAttribute('aria-pressed', 'false'); }, 10000);
    });
  });
  document.querySelectorAll('[data-menu]').forEach((m) => {
    const btn = m.querySelector('button');
    const list = m.querySelector('.menu__list');
    btn.addEventListener('click', () => { const open = list.hidden; list.hidden = !open; btn.setAttribute('aria-expanded', String(open)); });
    const hide = () => { list.hidden = true; btn.setAttribute('aria-expanded', 'false'); };
    document.addEventListener('click', (e) => { if (!m.contains(e.target)) hide(); });
    m.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !list.hidden) { hide(); btn.focus(); } });
    m.addEventListener('focusout', (e) => { if (e.relatedTarget && !m.contains(e.relatedTarget)) hide(); });
  });
}

// Settings → Appearance: live preview + contrast check (server computes both — one source of truth)
document.querySelectorAll('[data-theme-form]').forEach((form) => {
  const link = document.getElementById('theme-preview');
  const list = form.querySelector('[data-contrast-list]');
  const suggest = form.querySelector('[data-contrast-suggest]');
  const save = form.querySelector('[data-save]');
  const rows = [...form.querySelectorAll('[data-color-row]')];
  let presetColors = {};
  try { presetColors = JSON.parse(form.dataset.presetColors || '{}'); } catch { /* keep {} */ }
  const isHex = (v) => /^#[0-9a-f]{6}$/i.test(v);
  let timer;
  const icon = list.querySelector('use')?.getAttribute('href') || '';
  const render = (j) => {
    // the number of checks depends on which colours are set, so the list is rebuilt
    list.replaceChildren(...j.checks.map((c) => {
      const li = document.createElement('li');
      li.className = c.ok ? 'is-ok' : 'is-bad';
      if (icon) {
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('class', 'icon icon--sm'); svg.setAttribute('aria-hidden', 'true'); svg.setAttribute('focusable', 'false');
        const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
        use.setAttribute('href', icon.replace(/#i-[a-z-]+$/, c.ok ? '#i-circle-check' : '#i-alert-triangle'));
        svg.append(use); li.append(svg);
      }
      const label = document.createElement('span'); label.textContent = c.label;
      const ratio = document.createElement('span'); ratio.className = 'mono'; ratio.textContent = Number(c.ratio).toFixed(2) + ' : 1';
      li.append(label, ratio);
      return li;
    }));
    suggest.hidden = j.ok;
    if (!j.ok) suggest.textContent = j.message;
    if (save) save.disabled = !j.ok;
  };
  const update = () => {
    const preset = form.querySelector('input[name="preset"]:checked')?.value || 'steel';
    const params = { preset };
    rows.forEach((row) => {
      const f = row.dataset.colorRow;
      const hex = row.querySelector('[data-color-hex]');
      const picker = row.querySelector('[data-color-picker]');
      const fallback = presetColors[preset]?.[f] || '';
      hex.placeholder = fallback;
      if (!isHex(hex.value) && fallback) picker.value = fallback;
      params[f] = isHex(hex.value) ? hex.value.toUpperCase() : '';
    });
    const qs = new URLSearchParams(params).toString();
    if (link) link.href = link.dataset.base + '?' + qs;
    clearTimeout(timer);
    timer = setTimeout(async () => {
      try {
        const r = await fetch(form.dataset.checkUrl + '?' + qs, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (r.ok) render(await r.json());
      } catch { /* keep the last result */ }
    }, 150);
  };
  rows.forEach((row) => {
    const hex = row.querySelector('[data-color-hex]');
    const picker = row.querySelector('[data-color-picker]');
    picker?.addEventListener('input', () => { hex.value = picker.value.toUpperCase(); update(); });
    hex?.addEventListener('input', () => { if (isHex(hex.value)) picker.value = hex.value; update(); });
    row.querySelector('[data-color-clear]')?.addEventListener('click', () => { hex.value = ''; update(); hex.focus(); });
  });
  form.querySelectorAll('input[name="preset"]').forEach((r) => r.addEventListener('change', update));
});

// Picture uploads: shrink in the browser before sending (6.13) — the server shrinks again if it can,
// so this only saves upload time and works around small upload limits; any failure keeps the original file.
document.querySelectorAll('input[type="file"][data-image-max]').forEach((input) => {
  const note = input.closest('form')?.querySelector('[data-image-note]');
  input.addEventListener('change', async () => {
    if (note) { note.hidden = true; note.textContent = ''; }
    const file = input.files?.[0];
    if (!file || !/^image\/(png|jpeg|webp)$/.test(file.type) || typeof createImageBitmap !== 'function' || typeof DataTransfer !== 'function') return;
    const max = Number(input.dataset.imageMax) || 512;
    const budget = Number(input.dataset.imageBytes) || 900 * 1024;
    try {
      const bmp = await createImageBitmap(file, { imageOrientation: 'from-image' });
      const scale = Math.min(1, max / Math.max(bmp.width, bmp.height));
      if (scale === 1 && file.size <= budget) { bmp.close?.(); return; }
      const w = Math.max(1, Math.round(bmp.width * scale));
      const h = Math.max(1, Math.round(bmp.height * scale));
      const canvas = document.createElement('canvas');
      canvas.width = w; canvas.height = h;
      const ctx = canvas.getContext('2d');
      ctx.imageSmoothingEnabled = true; ctx.imageSmoothingQuality = 'high';
      ctx.drawImage(bmp, 0, 0, w, h);
      bmp.close?.();
      const type = file.type === 'image/jpeg' ? 'image/jpeg' : 'image/png';
      const blob = await new Promise((res) => canvas.toBlob(res, type, 0.88));
      if (!blob || blob.size >= file.size && scale === 1) return;
      const name = file.name.replace(/\.[^.]+$/, '') + (type === 'image/jpeg' ? '.jpg' : '.png');
      const dt = new DataTransfer();
      dt.items.add(new File([blob], name, { type, lastModified: Date.now() }));
      input.files = dt.files;
      if (note) {
        note.textContent = (note.dataset.template || '{size}').replace('{size}', w + '×' + h + ' px · ' + Math.max(1, Math.round(blob.size / 1024)) + ' KB');
        note.hidden = false;
      }
    } catch { /* the server checks and shrinks the original */ }
  });
});

// drop areas: highlight while a file is dragged over, list the chosen files (name + size)
document.querySelectorAll('[data-dropzone]').forEach((zone) => {
  const input = zone.querySelector('input[type="file"]');
  const list = zone.querySelector('[data-dropzone-files]');
  if (!input) return;
  const size = (b) => (b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB');
  const show = () => {
    const files = Array.from(input.files || []);
    zone.classList.toggle('has-files', files.length > 0);
    if (list) list.replaceChildren(...files.slice(0, 20).map((f) => { const li = document.createElement('li'); li.textContent = `${f.name} · ${size(f.size)}`; return li; }));
  };
  ['dragenter', 'dragover'].forEach((ev) => zone.addEventListener(ev, () => zone.classList.add('is-dragover')));
  ['dragleave', 'drop'].forEach((ev) => zone.addEventListener(ev, () => zone.classList.remove('is-dragover')));
  input.addEventListener('change', show);
  show();
});

document.querySelectorAll('[data-autosubmit]').forEach((el) => el.addEventListener('change', () => el.form?.submit()));

initPreferences();
initShell();
initDialogs();
initTabs();
initDemos();

// Ctrl+K / ⌘K focuses the global search box (P0-05 §2)
document.addEventListener('keydown', (ev) => {
  if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 'k') {
    const box = document.querySelector('[data-hotkey="k"]');
    if (box) { ev.preventDefault(); box.focus(); box.select?.(); }
  }
});

// Forms that need a confirmation ("type the reason first") — confirm-checkbox enables the submit button
document.querySelectorAll('[data-select-all]').forEach((all) => {
  all.addEventListener('change', () => {
    document.querySelectorAll(all.getAttribute('data-select-all')).forEach((cb) => { if (!cb.disabled) cb.checked = all.checked; });
  });
});

// Long server work done in steps (backups): the "continue" form submits itself again after N seconds while visible
document.querySelectorAll('form[data-continue-after]').forEach((form) => {
  const secs = Math.max(1, Number(form.getAttribute('data-continue-after')) || 3);
  const status = form.querySelector('[data-countdown]');
  let left = secs;
  const timer = setInterval(() => {
    if (document.hidden) return;
    left -= 1;
    if (status) status.textContent = String(Math.max(0, left));
    if (left <= 0) { clearInterval(timer); form.submit(); }
  }, 1000);
});

// Copy buttons (one-time secrets, tool results): clipboard API, or select + execCommand where the API is blocked (plain http)
document.querySelectorAll('[data-copy-target]').forEach((btn) => {
  btn.addEventListener('click', async () => {
    const el = document.getElementById(btn.getAttribute('data-copy-target') || '');
    if (!el) return;
    const text = (el.value ?? el.textContent).trim();
    let ok = false;
    try { await navigator.clipboard.writeText(text); ok = true; } catch {
      try {
        const range = document.createRange(); range.selectNodeContents(el);
        const sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(range);
        ok = document.execCommand('copy');
      } catch { /* the text stays selected for a manual copy */ }
    }
    if (ok) {
      btn.setAttribute('data-copied', '1');
      const label = btn.querySelector('span');
      const done = btn.getAttribute('data-copied-text');
      if (label && done) { const was = label.textContent; label.textContent = done; setTimeout(() => { label.textContent = was; btn.removeAttribute('data-copied'); }, 2000); }
    }
  });
});

// Sensitive tool pages (M28): forget what was typed when the page is left (7B item 4); the server sends no-store too
document.querySelectorAll('form[data-forget]').forEach((form) => {
  window.addEventListener('pagehide', () => {
    form.querySelectorAll('input:not([type=hidden]):not([type=checkbox]):not([type=radio]), textarea').forEach((el) => { el.value = ''; });
    document.querySelectorAll('[data-forget-result] pre, [data-forget-result] dd').forEach((el) => { el.textContent = ''; });
  });
});

// Journal form: running totals of debit / credit
document.querySelectorAll('[data-journal-form]').forEach((form) => {
  const out = form.querySelector('[data-journal-totals]');
  if (!out) return;
  const parse = (v) => { const n = Number(String(v).replace(/,/g, '').trim()); return Number.isFinite(n) ? Math.round(n * 100) : 0; };
  const fmt = (c) => (c / 100).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const update = () => {
    let d = 0; let c = 0;
    form.querySelectorAll('input[name="debit[]"]').forEach((i) => { d += parse(i.value); });
    form.querySelectorAll('input[name="credit[]"]').forEach((i) => { c += parse(i.value); });
    out.querySelector('[data-dr]').textContent = fmt(d);
    out.querySelector('[data-cr]').textContent = fmt(c);
    out.querySelector('[data-diff]').textContent = fmt(d - c);
    out.classList.toggle('is-unbalanced', d !== c);
  };
  form.addEventListener('input', update);
  update();
});

/* Lucky draw form: "sequence" shows "how many places"; the "take drawn names out" wording follows the kind of draw (0.6.1 → 0.11.1) */
document.querySelectorAll('[data-draw-prize-mode]').forEach((set) => {
  const form = set.closest('form');
  const seq = form?.querySelector('[data-draw-seq]');
  const sync = () => {
    const on = set.querySelector('input[name="prize_mode"]:checked')?.value === 'sequence';
    if (seq) seq.hidden = !on;
    form?.querySelectorAll('[data-draw-when]').forEach((el) => { el.hidden = (el.dataset.drawWhen === 'sequence') !== on; });
  };
  set.addEventListener('change', sync);
  sync();
});

/* My display settings (0.6.1): live preview of theme, highlight colour and font; nothing is saved until "Save". */
document.querySelectorAll('[data-look-form]').forEach((form) => {
  const link = document.getElementById('look-preview');
  const value = form.querySelector('[data-accent-value]');
  const row = form.querySelector('[data-accent-row]');
  const picker = form.querySelector('[data-accent-picker]');
  const hexIn = form.querySelector('[data-accent-hexinput]');
  const err = form.querySelector('[data-accent-error]');
  const save = form.querySelector('[data-look-save]');
  const fontSel = form.querySelector('[data-look-font]');
  const isHex = (v) => /^#[0-9a-f]{6}$/i.test(v);
  const media = window.matchMedia ? matchMedia('(prefers-color-scheme: dark)') : null;
  const resolveMode = (m) => {
    const mode = m === 'org' ? form.dataset.orgMode : m;
    return mode === 'auto' ? (media && media.matches ? 'dark' : 'light') : (mode === 'dark' ? 'dark' : 'light');
  };
  let timer;
  const refresh = () => {
    const accent = value.value;
    const font = fontSel && fontSel.value !== 'org' ? fontSel.value : '';
    const qs = new URLSearchParams({ accent, font }).toString();
    if (link) link.href = `${form.dataset.previewUrl}?${qs}`;
    clearTimeout(timer);
    timer = setTimeout(async () => {
      if (!isHex(accent)) { if (err) err.hidden = true; if (save) save.disabled = false; return; }
      try {
        const r = await fetch(`${form.dataset.previewUrl}?${qs}&format=json`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        const j = await r.json();
        if (err) { err.hidden = j.ok; err.textContent = j.message || ''; }
        if (save) save.disabled = !j.ok;
      } catch { /* preview only */ }
    }, 200);
  };
  form.querySelectorAll('input[name="theme"]').forEach((r) => r.addEventListener('change', () => { root.dataset.theme = resolveMode(r.value); }));
  form.querySelectorAll('input[name="accent_pick"]').forEach((r) => r.addEventListener('change', () => {
    const custom = r.hasAttribute('data-accent-custom');
    if (row) row.hidden = !custom;
    value.value = custom ? (isHex(hexIn?.value || '') ? hexIn.value.toUpperCase() : (picker?.value || '').toUpperCase()) : (r.dataset.accentHex || '');
    if (custom && hexIn && !isHex(hexIn.value)) hexIn.value = value.value;
    refresh();
  }));
  picker?.addEventListener('input', () => { hexIn.value = picker.value.toUpperCase(); value.value = hexIn.value; refresh(); });
  hexIn?.addEventListener('input', () => { if (isHex(hexIn.value)) { picker.value = hexIn.value; value.value = hexIn.value.toUpperCase(); refresh(); } });
  const markFont = () => form.querySelectorAll('[data-font-pick]').forEach((b) => b.classList.toggle('is-current', b.dataset.fontPick === fontSel.value));
  fontSel?.addEventListener('change', () => { markFont(); refresh(); });
  form.querySelectorAll('[data-font-pick]').forEach((b) => b.addEventListener('click', () => { fontSel.value = b.dataset.fontPick; markFont(); refresh(); }));
  form.addEventListener('submit', (ev) => {
    // the quick toggle in the top bar remembers per device; a saved setting replaces it on this device
    const m = ev.submitter?.name === 'reset' ? 'org' : (form.querySelector('input[name="theme"]:checked')?.value || 'org');
    try { if (m === 'light' || m === 'dark') localStorage.setItem('blue.theme', m); else localStorage.removeItem('blue.theme'); } catch { /* private mode */ }
  });
});

/* Org chart wider than the screen: start with the top of the tree (the group) in view (0.6.1) */
document.querySelectorAll('[data-oc-scroll]').forEach((box) => {
  if (box.scrollWidth > box.clientWidth) box.scrollLeft = (box.scrollWidth - box.clientWidth) / 2;
});

/* Sidebar groups fold/unfold (0.27.1, DEC-92): remembered per device; the group with the current page stays open */
const navGroups = [...document.querySelectorAll('[data-navgroup]')].map((g) => {
  const btn = g.querySelector('.sidebar__group-toggle');
  const body = g.querySelector('.sidebar__group-items');
  const key = 'navgroup.' + g.dataset.navgroup;
  const set = (open) => { body.hidden = !open; btn.setAttribute('aria-expanded', String(open)); g.classList.toggle('is-open', open); };
  if (!g.hasAttribute('data-navgroup-current')) {
    const saved = store.get(key);
    if (saved === 'open' || saved === 'closed') set(saved === 'open');
  }
  btn.addEventListener('click', () => { const open = body.hidden; set(open); store.set(key, open ? 'open' : 'closed'); });
  return { g, body, set };
});

/* Menu filter for long menus: shows only matching items, opening their groups/sub-menus; Esc clears */
document.querySelectorAll('[data-navfilter]').forEach((box) => {
  const input = box.querySelector('input');
  const empty = box.querySelector('[data-navfilter-empty]');
  box.hidden = false;
  let saved = null;
  const norm = (x) => x.toLocaleLowerCase().normalize('NFC').trim();
  const apply = () => {
    const q = norm(input.value);
    if (q === '') {
      document.querySelectorAll('.sidebar [data-nav-miss]').forEach((el) => el.removeAttribute('data-nav-miss'));
      if (saved) { saved.forEach((s) => { s.n.set(s.open); s.subs.forEach(([sub, h]) => { sub.hidden = h; }); }); saved = null; }
      empty.hidden = true;
      return;
    }
    if (!saved) saved = navGroups.map((n) => ({ n, open: !n.body.hidden, subs: [...n.g.querySelectorAll('.sidebar__sub')].map((sub) => [sub, sub.hidden]) }));
    let hits = 0;
    navGroups.forEach((n) => {
      let groupHits = 0;
      n.g.querySelectorAll('.sidebar__link').forEach((a) => {
        const ok = norm(a.textContent || '').includes(q);
        a.toggleAttribute('data-nav-miss', !ok);
        if (ok) groupHits++;
      });
      n.g.querySelectorAll('[data-subnav]').forEach((item) => {
        const any = item.querySelector('.sidebar__link:not([data-nav-miss])');
        item.toggleAttribute('data-nav-miss', !any);
        const sub = item.querySelector('.sidebar__sub');
        if (any && sub) sub.hidden = !sub.querySelector('.sidebar__link:not([data-nav-miss])');
        const parent = item.querySelector('.sidebar__row > .sidebar__link');
        if (any && parent) parent.removeAttribute('data-nav-miss');
      });
      n.g.toggleAttribute('data-nav-miss', groupHits === 0);
      if (groupHits) n.set(true);
      hits += groupHits;
    });
    empty.hidden = hits > 0;
  };
  input.addEventListener('input', apply);
  input.addEventListener('keydown', (ev) => { if (ev.key === 'Escape' && input.value !== '') { ev.preventDefault(); ev.stopPropagation(); input.value = ''; apply(); } });
});

/* Sidebar sub-menus fold/unfold (0.6.2): folded by default, remembered per device; the current page's menu stays open */
document.querySelectorAll('[data-subnav]').forEach((item) => {
  const btn = item.querySelector('.sidebar__toggle');
  const sub = item.querySelector('.sidebar__sub');
  if (!btn || !sub) return;
  const key = 'subnav.' + item.dataset.subnav;
  const set = (open) => { sub.hidden = !open; btn.setAttribute('aria-expanded', String(open)); item.classList.toggle('is-open', open); };
  if (!item.hasAttribute('data-subnav-current') && store.get(key) === 'open') set(true);
  btn.addEventListener('click', () => { const open = sub.hidden; set(open); store.set(key, open ? 'open' : 'closed'); });
});

