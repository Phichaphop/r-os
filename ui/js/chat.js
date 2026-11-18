/*
 * M17 chat (0.41.0). Asks the server for news every few seconds (chat.poll_seconds, 5–10) ONLY while a conversation is
 * open and the tab is visible (Q-01) — hidden tab or another page: no requests at all. Sends without reloading; a send
 * that fails is kept in the box and retried with the same client id, so the server stores it once.
 * Message HTML comes from the server (the same escaped partial as the page).
 */
(() => {
  'use strict';
  const box = document.querySelector('[data-chat]');
  if (!box) { return; }
  const d = box.dataset;
  const list = box.querySelector('[data-chat-list]');
  const scroller = box.querySelector('[data-chat-scroll]');
  const form = box.querySelector('[data-chat-form]');
  const input = form.querySelector('textarea[name="body"]');
  const files = form.querySelector('[data-chat-files]');
  const picked = form.querySelector('[data-chat-picked]');
  const clientId = form.querySelector('[data-chat-client]');
  const errorBox = form.querySelector('[data-chat-error]');
  const sendBtn = form.querySelector('button[type="submit"]');
  let last = Number(d.last || '0');
  let since = d.since || '';
  const every = Math.min(10, Math.max(5, Number(d.seconds || '6'))) * 1000;
  let wait = every;
  let timer = null;
  let busy = false;
  let again = false; // a poll asked for while one is running (e.g. right after sending) runs as soon as it ends

  const nearBottom = () => scroller.scrollHeight - scroller.scrollTop - scroller.clientHeight < 120;
  const toBottom = () => { scroller.scrollTop = scroller.scrollHeight; };
  const frag = (html) => document.createRange().createContextualFragment(html);
  const newId = () => Array.from(crypto.getRandomValues(new Uint8Array(16)), (b) => b.toString(16).padStart(2, '0')).join('');
  const fill = (msg, vars) => msg.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m));

  // a "new messages" button when the reader has scrolled up
  const jump = document.createElement('button');
  jump.type = 'button'; jump.className = 'btn btn--primary btn--sm chat__jump'; jump.textContent = d.msgNew; jump.hidden = true;
  jump.addEventListener('click', () => { toBottom(); jump.hidden = true; });
  scroller.after(jump);
  scroller.addEventListener('scroll', () => { if (nearBottom()) { jump.hidden = true; } });

  const put = (item) => {
    const el = list.querySelector(`[data-id="${item.id}"]`);
    const node = frag(item.html);
    if (el) { el.replaceWith(node); } else { list.appendChild(node); }
  };

  const poll = async () => {
    timer = null;
    if (document.visibilityState !== 'visible') { return; }
    if (busy) { again = true; return; }
    busy = true;
    try {
      const u = new URL(d.poll, location.href);
      u.searchParams.set('after', String(last));
      u.searchParams.set('since', since);
      const res = await fetch(u, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' });
      if (!res.ok) { throw new Error(String(res.status)); }
      const j = await res.json();
      const stick = nearBottom();
      (j.changed || []).forEach(put);
      (j.messages || []).forEach(put);
      if ((j.messages || []).length) {
        box.querySelector('[data-chat-empty]')?.remove();
        if (stick) { toBottom(); } else { jump.hidden = false; }
      }
      Object.entries(j.reads || {}).forEach(([id, n]) => {
        const el = list.querySelector(`[data-read="${id}"]`);
        if (el) { el.textContent = n > 0 ? (d.kind === 'direct' ? d.msgRead : fill(d.msgReadN, { n })) : d.msgSent; }
      });
      last = Math.max(last, Number(j.last || 0));
      since = j.now || since;
      wait = every;
    } catch (e) {
      wait = Math.min(60000, wait * 2); // server unreachable: back off, never hammer it
    } finally {
      busy = false;
      if (again) { again = false; poll(); } else { schedule(); }
    }
  };
  const schedule = () => {
    if (timer) { clearTimeout(timer); }
    timer = document.visibilityState === 'visible' ? setTimeout(poll, wait) : null; // hidden tab: no requests
  };
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') { wait = every; poll(); } else if (timer) { clearTimeout(timer); timer = null; } });

  files.addEventListener('change', () => {
    const n = files.files.length;
    picked.textContent = n ? Array.from(files.files).map((f) => f.name).join(', ') : '';
  });
  input.addEventListener('keydown', (ev) => {
    if (ev.key === 'Enter' && !ev.shiftKey && !ev.isComposing) { ev.preventDefault(); form.requestSubmit(); }
  });
  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    if (input.value.trim() === '' && files.files.length === 0) { input.focus(); return; }
    errorBox.hidden = true;
    sendBtn.disabled = true; sendBtn.setAttribute('aria-busy', 'true');
    try {
      const res = await fetch(d.send, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json', 'X-CSRF-Token': d.csrf }, credentials: 'same-origin' });
      const j = await res.json().catch(() => ({}));
      if (!res.ok || !j.ok) {
        errorBox.textContent = j.error || d.msgFailed; errorBox.hidden = false;
        if (res.status === 422) { clientId.value = newId(); } // refused: the next try is a new message
        return;
      }
      input.value = ''; files.value = ''; picked.textContent = '';
      clientId.value = newId();
      await poll();
      toBottom();
    } catch (e) {
      errorBox.textContent = d.msgFailed; errorBox.hidden = false; // network: same client id → the retry is stored once
    } finally {
      sendBtn.disabled = false; sendBtn.removeAttribute('aria-busy');
      input.focus();
    }
  });

  if (location.hash.startsWith('#m-')) { document.getElementById(location.hash.slice(1))?.scrollIntoView({ block: 'center' }); } else { toBottom(); }
  box.classList.add('is-live');
  schedule();
})();
