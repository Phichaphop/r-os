/*
 * QR reader (0.41.1). Reads a QR code from a picture (choose, drop or paste with Ctrl+V) or from the camera, entirely in
 * the browser with jsQR (ui/js/vendor/jsqr) — no picture, frame or result is sent anywhere (this file makes no request).
 * The result is shown with textContent only; links open only for http(s) after the reader sees the address.
 */
(() => {
  'use strict';
  const root = document.querySelector('[data-qr-scan]');
  if (!root || typeof window.jsQR !== 'function') { return; }
  const d = root.dataset;
  const fileInput = root.querySelector('[data-qr-scan-file]');
  const status = root.querySelector('[data-qr-scan-status]');
  const out = root.querySelector('[data-qr-scan-result]');
  const thumb = root.querySelector('[data-qr-scan-thumb]');
  const startBtn = root.querySelector('[data-qr-scan-start]');
  const stopBtn = root.querySelector('[data-qr-scan-stop]');
  const videoBox = root.querySelector('[data-qr-scan-video-box]');
  const video = root.querySelector('[data-qr-scan-video]');
  const canvas = document.createElement('canvas');
  const ctx = canvas.getContext('2d', { willReadFrequently: true });
  let stream = null;
  let lastUrl = null;

  const say = (msg, bad) => { status.textContent = msg; status.classList.toggle('text-danger', !!bad); };
  const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) { n.className = cls; } if (text !== undefined) { n.textContent = text; } return n; };

  // ---- decoding: jsQR works best around 400–1200 px, so a big photo is tried at several sizes
  const decodeSource = (src, w, h) => {
    for (const max of [1200, 800, 500, 1800]) {
      const s = Math.min(1, max / Math.max(w, h));
      const cw = Math.max(1, Math.round(w * s)); const ch = Math.max(1, Math.round(h * s));
      canvas.width = cw; canvas.height = ch;
      ctx.drawImage(src, 0, 0, cw, ch);
      const img = ctx.getImageData(0, 0, cw, ch);
      const hit = window.jsQR(img.data, cw, ch, { inversionAttempts: 'attemptBoth' });
      if (hit && hit.data !== '') { return hit.data; }
      if (s === 1) { break; } // already full size: smaller tries were done
    }
    return null;
  };

  const readFile = (file) => {
    if (!file) { return; }
    if (!/^image\//.test(file.type)) { say(d.msgNotImage, true); return; }
    stopCamera();
    say(d.msgReading);
    if (lastUrl) { URL.revokeObjectURL(lastUrl); }
    lastUrl = URL.createObjectURL(file);
    const img = new Image();
    img.onload = () => {
      thumb.src = lastUrl; thumb.hidden = false;
      const text = decodeSource(img, img.naturalWidth, img.naturalHeight);
      if (text === null) { out.hidden = true; out.replaceChildren(); say(d.msgNone, true); return; }
      show(text);
    };
    img.onerror = () => { say(d.msgBadImage, true); };
    img.src = lastUrl;
  };

  // ---- what the code holds
  const unesc = (s) => s.replace(/\\([\\;,:"])/g, '$1');
  const wifiParts = (s) => { // WIFI:T:WPA;S:name;P:pass;H:true;; — values escape \ ; , : "
    const o = {}; const body = s.slice(5); let key = ''; let val = ''; let inKey = true; let esc = false;
    for (const ch of body) {
      if (esc) { val += ch; esc = false; continue; }
      if (!inKey && ch === '\\') { esc = true; continue; }
      if (inKey) { if (ch === ':') { inKey = false; } else if (ch !== ';') { key += ch; } continue; }
      if (ch === ';') { o[key.toUpperCase()] = val; key = ''; val = ''; inKey = true; continue; }
      val += ch;
    }
    if (key !== '') { o[key.toUpperCase()] = val; }
    return o;
  };
  const vcardParts = (s) => {
    const lines = s.replace(/\r\n[ \t]/g, '').split(/\r?\n/);
    const o = { name: '', org: '', title: '', tel: [], email: [], url: [], adr: '' };
    for (const line of lines) {
      const i = line.indexOf(':'); if (i < 0) { continue; }
      const k = line.slice(0, i).split(';')[0].toUpperCase(); const v = unesc(line.slice(i + 1)).trim();
      if (v === '') { continue; }
      if (k === 'FN') { o.name = v; } else if (k === 'N' && o.name === '') { o.name = v.split(';').filter(Boolean).reverse().join(' '); }
      else if (k === 'ORG') { o.org = v.replace(/;/g, ' '); } else if (k === 'TITLE') { o.title = v; }
      else if (k === 'TEL') { o.tel.push(v); } else if (k === 'EMAIL') { o.email.push(v); } else if (k === 'URL') { o.url.push(v); }
      else if (k === 'ADR') { o.adr = v.split(';').filter(Boolean).join(' '); }
    }
    return o;
  };
  const kindOf = (t) => {
    const s = t.trim();
    if (/^https?:\/\//i.test(s)) { return 'url'; }
    if (/^WIFI:/i.test(s)) { return 'wifi'; }
    if (/^BEGIN:VCARD/i.test(s)) { return 'vcard'; }
    if (/^tel:/i.test(s)) { return 'tel'; }
    if (/^mailto:/i.test(s) || /^MATMSG:/i.test(s)) { return 'email'; }
    if (/^(SMSTO|sms):/i.test(s)) { return 'sms'; }
    if (/^geo:/i.test(s)) { return 'geo'; }
    return 'text';
  };
  const safeUrl = (s) => { try { const u = new URL(s.trim()); return (u.protocol === 'https:' || u.protocol === 'http:') ? u : null; } catch (e) { return null; } };

  const copyBtn = (text) => {
    const b = el('button', 'btn btn--secondary btn--sm', d.msgCopy); b.type = 'button';
    b.addEventListener('click', async () => {
      try { await navigator.clipboard.writeText(text); say(d.msgCopied); } catch (e) { say(d.msgCopyFailed, true); }
    });
    return b;
  };
  const row = (dl, label, value, secret) => {
    if (value === undefined || value === null || value === '') { return; }
    const dt = el('dt', null, label); const dd = el('dd', 'qr-scan__value');
    const v = el('span', 'qr-scan__text', secret ? '••••••••' : value); dd.append(v);
    if (secret) {
      const t = el('button', 'btn btn--ghost btn--sm', d.msgShow); t.type = 'button'; t.setAttribute('aria-pressed', 'false');
      t.addEventListener('click', () => { const on = t.getAttribute('aria-pressed') !== 'true'; t.setAttribute('aria-pressed', on ? 'true' : 'false'); v.textContent = on ? value : '••••••••'; t.textContent = on ? d.msgHide : d.msgShow; });
      dd.append(' ', t);
    }
    dd.append(' ', copyBtn(value));
    dl.append(dt, dd);
  };

  const show = (text) => {
    const kind = kindOf(text);
    const typeLabel = { url: d.typeUrl, wifi: d.typeWifi, vcard: d.typeVcard, tel: d.typeTel, email: d.typeEmail, sms: d.typeSms, geo: d.typeGeo, text: d.typeText }[kind];
    const head = el('div', 'row'); head.append(el('span', 'badge badge--info', typeLabel));
    const dl = el('dl', 'qr-scan__fields');
    const s = text.trim();
    const actions = el('div', 'row');
    if (kind === 'url') {
      const u = safeUrl(s);
      if (u) {
        let shown = u.href; try { shown = decodeURI(u.href); } catch (e) { /* keep the encoded form */ }
        row(dl, d.fWeb, shown);
        const host = el('p', 'text-sm', d.msgLinkWarn.replace('{host}', u.hostname));
        const a = el('a', 'btn btn--primary btn--sm', d.msgOpen); a.href = u.href; a.target = '_blank'; a.rel = 'noopener noreferrer nofollow';
        actions.append(a);
        out.replaceChildren(head, dl, host, actions);
      } else {
        row(dl, d.fRaw, s); out.replaceChildren(head, dl, el('p', 'text-sm text-danger', d.msgUnsafeLink));
      }
    } else {
      if (kind === 'wifi') {
        const w = wifiParts(s);
        row(dl, d.fNetwork, w.S); row(dl, d.fSecurity, w.T || 'nopass'); row(dl, d.fPassword, w.P, true);
        if (/^true$/i.test(w.H || '')) { row(dl, d.fHidden, d.fYes); }
      } else if (kind === 'vcard') {
        const v = vcardParts(s);
        row(dl, d.fName, v.name); row(dl, d.fOrg, v.org); row(dl, d.fTitle, v.title);
        v.tel.forEach((x) => row(dl, d.fPhone, x)); v.email.forEach((x) => row(dl, d.fEmail, x)); v.url.forEach((x) => row(dl, d.fWeb, x)); row(dl, d.fAddress, v.adr);
      } else if (kind === 'tel') {
        const n = s.slice(4).trim(); row(dl, d.fPhone, n);
        if (/^[+0-9()\-\s]{3,30}$/.test(n)) { const a = el('a', 'btn btn--primary btn--sm', d.typeTel); a.href = 'tel:' + n.replace(/[^+0-9]/g, ''); actions.append(a); }
      } else if (kind === 'email') {
        if (/^MATMSG:/i.test(s)) {
          const m = {}; s.slice(7).split(';').forEach((p) => { const i = p.indexOf(':'); if (i > 0) { m[p.slice(0, i).toUpperCase()] = p.slice(i + 1); } });
          row(dl, d.fTo, m.TO); row(dl, d.fSubject, m.SUB); row(dl, d.fBody, m.BODY);
        } else {
          let to = ''; let q = '';
          const i = s.indexOf('?'); to = decodeURIComponent(s.slice(7, i < 0 ? undefined : i)); q = i < 0 ? '' : s.slice(i + 1);
          const p = new URLSearchParams(q); row(dl, d.fTo, to); row(dl, d.fSubject, p.get('subject') || ''); row(dl, d.fBody, p.get('body') || '');
        }
      } else if (kind === 'sms') {
        const parts = s.replace(/^(SMSTO|sms):/i, '').split(':'); row(dl, d.fPhone, parts[0]); row(dl, d.fBody, parts.slice(1).join(':'));
      } else if (kind === 'geo') {
        row(dl, d.fPlace, s.slice(4).split('?')[0]);
      }
      row(dl, kind === 'text' ? d.typeText : d.fRaw, s);
      out.replaceChildren(head, dl);
      if (actions.childElementCount) { out.append(actions); }
    }
    out.hidden = false;
    say(d.msgDone);
  };

  // ---- inputs
  fileInput.addEventListener('change', () => { readFile(fileInput.files[0]); });
  document.addEventListener('paste', (ev) => {
    const item = Array.from(ev.clipboardData ? ev.clipboardData.items : []).find((i) => i.kind === 'file' && /^image\//.test(i.type));
    if (item) { ev.preventDefault(); readFile(item.getAsFile()); }
  });

  // ---- camera: about 6 frames a second, stops on the first code, on "stop", or when the tab is hidden
  let timer = null;
  const stopCamera = () => {
    if (timer) { clearTimeout(timer); timer = null; }
    if (stream) { stream.getTracks().forEach((tr) => tr.stop()); stream = null; }
    video.srcObject = null; videoBox.hidden = true; stopBtn.hidden = true; startBtn.hidden = false;
  };
  const tick = () => {
    timer = null;
    if (!stream) { return; }
    if (video.readyState >= 2 && video.videoWidth > 0) {
      const s = Math.min(1, 720 / Math.max(video.videoWidth, video.videoHeight));
      const w = Math.round(video.videoWidth * s); const h = Math.round(video.videoHeight * s);
      canvas.width = w; canvas.height = h; ctx.drawImage(video, 0, 0, w, h);
      const hit = window.jsQR(ctx.getImageData(0, 0, w, h).data, w, h, { inversionAttempts: 'dontInvert' });
      if (hit && hit.data !== '') { stopCamera(); thumb.hidden = true; show(hit.data); return; }
    }
    timer = setTimeout(tick, 160);
  };
  startBtn.addEventListener('click', async () => {
    if (!window.isSecureContext) { say(d.msgCameraHttps, true); return; }
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { say(d.msgNoCamera, true); return; }
    try {
      stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false });
    } catch (e) {
      stream = null;
      say(e && (e.name === 'NotAllowedError' || e.name === 'SecurityError') ? d.msgCameraDenied : d.msgNoCamera, true);
      return;
    }
    video.srcObject = stream; videoBox.hidden = false; startBtn.hidden = true; stopBtn.hidden = false;
    try { await video.play(); } catch (e) { /* autoplay of a muted inline video is allowed; ignore */ }
    out.hidden = true; say(d.msgLooking);
    tick();
  });
  stopBtn.addEventListener('click', stopCamera);
  document.addEventListener('visibilitychange', () => { if (document.visibilityState !== 'visible' && stream) { stopCamera(); } });
  window.addEventListener('pagehide', stopCamera);
})();
