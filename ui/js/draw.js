/* M29 lucky draw — wheel and animal race on a canvas (0.11.1).
   Full event ([data-draw]): the server draws (and remembers) the winners; this script only animates the answer it gets.
   Quick draw ([data-quick-draw]): nothing is stored; the browser picks with crypto.getRandomValues (unbiased), like the
   page says — it is a quick game, not a draw that needs proof.
   Both open on the wheel / start line itself (no gift-box screen): click the wheel, press Space or the button to draw.
   Sounds are WebAudio tones (no files); full screen; reduced motion respected. */
(() => {
  'use strict';
  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const css = () => getComputedStyle(document.documentElement);
  const col = (n) => css().getPropertyValue(n).trim() || 'gray';
  const GAME = ['--color-game-1', '--color-game-2', '--color-game-3', '--color-game-4', '--color-game-5', '--color-game-6', '--color-game-7', '--color-game-8'];
  const MAX_SLICES = 120;
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  const easeOut = (t) => 1 - Math.pow(1 - t, 4);
  const font = (px, w = 600) => `${w} ${px}px "IBM Plex Sans Thai", "Bai Jamjuree", sans-serif`;
  const randInt = (n) => { // unbiased 0..n-1 (rejection sampling on 32-bit values)
    const lim = Math.floor(0x100000000 / n) * n; const b = new Uint32Array(1);
    do { crypto.getRandomValues(b); } while (b[0] >= lim);
    return b[0] % n;
  };
  const shuffle = (a) => { for (let i = a.length - 1; i > 0; i--) { const j = randInt(i + 1); [a[i], a[j]] = [a[j], a[i]]; } return a; };
  const animate = (ms, frame) => new Promise((resolve) => {
    const start = performance.now();
    const step = (now) => { const t = Math.min(1, (now - start) / ms); frame(t); if (t < 1) requestAnimationFrame(step); else resolve(); };
    requestAnimationFrame(step);
  });

  // ---- sound ----------------------------------------------------------------------------------------
  const makeSound = (isOn) => {
    let audio = null;
    const tone = (f, d = 0.08, type = 'sine', vol = 0.15) => {
      if (!isOn()) return;
      try {
        audio = audio || new (window.AudioContext || window.webkitAudioContext)();
        const o = audio.createOscillator(); const g = audio.createGain();
        o.type = type; o.frequency.value = f; o.connect(g); g.connect(audio.destination);
        const t = audio.currentTime; g.gain.setValueAtTime(vol, t); g.gain.exponentialRampToValueAtTime(0.0001, t + d);
        o.start(t); o.stop(t + d + 0.02);
      } catch { /* no audio */ }
    };
    /** A burst of soft noise — the crowd at the finish (no sound files). */
    const cheer = () => {
      if (!isOn()) return;
      try {
        audio = audio || new (window.AudioContext || window.webkitAudioContext)();
        const len = audio.sampleRate * 1.6; const buf = audio.createBuffer(1, len, audio.sampleRate); const d = buf.getChannelData(0);
        for (let i = 0; i < len; i++) d[i] = (Math.random() * 2 - 1) * (1 - i / len) * (0.6 + 0.4 * Math.sin(i / 900));
        const src = audio.createBufferSource(); const f = audio.createBiquadFilter(); const g = audio.createGain();
        f.type = 'bandpass'; f.frequency.value = 1400; f.Q.value = 0.6; g.gain.value = 0.18;
        src.buffer = buf; src.connect(f); f.connect(g); g.connect(audio.destination); src.start();
      } catch { /* no audio */ }
    };
    return {
      tone, cheer,
      fanfare: () => [523, 659, 784, 1047].forEach((f, i) => setTimeout(() => tone(f, 0.25, 'triangle', 0.2), i * 140)),
      bugle: () => [[392, 0], [523, 160], [659, 320], [784, 480], [659, 700], [784, 860]].forEach(([f, at]) => setTimeout(() => tone(f, at >= 860 ? 0.45 : 0.14, 'sawtooth', 0.07), at)),
    };
  };

  // ---- renderer -------------------------------------------------------------------------------------
  const makeRenderer = (canvas, sound, words) => {
    const ctx = canvas.getContext('2d');
    let W = canvas.width; let H = canvas.height;
    let angle = 0;
    const clear = () => { W = canvas.width; H = canvas.height; ctx.fillStyle = col('--color-surface'); ctx.fillRect(0, 0, W, H); };
    /** Square picture on narrow screens (a phone), wide on larger ones. Returns true when the size changed. */
    const resize = () => {
      const narrow = (canvas.parentElement?.clientWidth || 960) < 640;
      const [w, h] = narrow ? [720, 720] : [960, 560];
      if (canvas.width === w && canvas.height === h) return false;
      canvas.width = w; canvas.height = h; return true;
    };
    /** Bigger text when the picture is shown much smaller than it is drawn (a phone). */
    const zoom = () => Math.max(1, Math.min(1.8, (canvas.width / (canvas.clientWidth || canvas.width)) * 0.6));
    const fit = (text, maxW) => { if (ctx.measureText(text).width <= maxW) return text; let t = text; while (t.length > 1 && ctx.measureText(t + '…').width > maxW) t = t.slice(0, -1); return t + '…'; };
    const message = (text) => { clear(); ctx.fillStyle = col('--color-text-muted'); ctx.font = font(28, 500); ctx.textAlign = 'center'; ctx.fillText(text, W / 2, H / 2); };
    const fills = (n) => { const out = []; for (let i = 0; i < n; i++) out.push(col(GAME[i % GAME.length])); if (n > 1 && n % GAME.length === 1) out[n - 1] = col(GAME[2]); return out; };

    const wheel = (names, a, extra = '') => {
      clear();
      const cx = W / 2; const cy = H / 2 + 6; const r = Math.min(W, H) / 2 - 34; const n = names.length;
      if (n === 0) {
        ctx.beginPath(); ctx.arc(cx, cy, r, 0, Math.PI * 2); ctx.fillStyle = col('--color-surface-2'); ctx.fill();
        ctx.lineWidth = 3; ctx.strokeStyle = col('--color-border'); ctx.stroke();
        ctx.fillStyle = col('--color-text-muted'); ctx.font = font(22, 500); ctx.textAlign = 'center'; ctx.fillText(words.empty || '', cx, cy + 8);
        return;
      }
      const seg = (Math.PI * 2) / n; const fc = fills(n);
      ctx.save(); ctx.beginPath(); ctx.arc(cx, cy, r + 10, 0, Math.PI * 2); ctx.fillStyle = col('--color-navy'); ctx.fill(); ctx.restore();
      for (let i = 0; i < n; i++) {
        const a0 = a + i * seg - Math.PI / 2;
        ctx.beginPath(); ctx.moveTo(cx, cy); ctx.arc(cx, cy, r, a0, a0 + seg); ctx.closePath();
        ctx.fillStyle = fc[i]; ctx.fill();
        if (n <= 60) { ctx.strokeStyle = col('--color-surface'); ctx.lineWidth = 2; ctx.stroke(); }
        if (n <= MAX_SLICES) {
          const px = Math.round((n <= 8 ? 26 : n <= 16 ? 21 : n <= 30 ? 17 : n <= 60 ? 13 : 9) * zoom());
          ctx.save(); ctx.translate(cx, cy); ctx.rotate(a0 + seg / 2); ctx.fillStyle = col('--color-game-ink'); ctx.font = font(px); ctx.textAlign = 'right';
          ctx.fillText(fit(names[i], r - 70), r - 16, px * 0.36); ctx.restore();
        }
      }
      // pegs on the rim
      if (n <= 60) for (let i = 0; i < n; i++) { const pa = a + i * seg - Math.PI / 2; ctx.beginPath(); ctx.arc(cx + Math.cos(pa) * (r + 4), cy + Math.sin(pa) * (r + 4), 3, 0, Math.PI * 2); ctx.fillStyle = col('--color-on-navy'); ctx.fill(); }
      // hub
      ctx.beginPath(); ctx.arc(cx, cy, 46, 0, Math.PI * 2); ctx.fillStyle = col('--color-surface'); ctx.fill();
      ctx.lineWidth = 6; ctx.strokeStyle = col('--color-navy'); ctx.stroke();
      ctx.fillStyle = col('--color-text'); ctx.font = font(Math.round(18 * zoom()), 700); ctx.textAlign = 'center'; ctx.fillText(words.spin || '', cx, cy + 6);
      // pointer at the top
      ctx.beginPath(); ctx.moveTo(cx - 22, cy - r - 36); ctx.lineTo(cx + 22, cy - r - 36); ctx.lineTo(cx, cy - r + 10); ctx.closePath();
      ctx.fillStyle = col('--color-danger'); ctx.fill(); ctx.lineWidth = 3; ctx.strokeStyle = col('--color-surface'); ctx.stroke();
      if (extra) { ctx.fillStyle = col('--color-text-muted'); ctx.font = font(15, 500); ctx.textAlign = 'right'; ctx.fillText(extra, W - 16, H - 14); }
    };

    /** Spins the wheel of `names` so that slice `idx` stops under the pointer. */
    const spin = async (names, idx) => {
      const n = names.length; const seg = (Math.PI * 2) / n; const TAU = Math.PI * 2;
      const want = -(idx * seg + seg / 2) + (Math.random() - 0.5) * seg * 0.7; // a stop anywhere inside the slice
      const now = ((angle % TAU) + TAU) % TAU;
      const delta = (((want - now) % TAU) + TAU) % TAU;
      const from = angle; const to = angle + delta + TAU * (5 + randInt(3));
      if (reduced) { angle = to; wheel(names, angle); return; }
      let last = -1;
      await animate(5600, (t) => {
        angle = from + (to - from) * easeOut(t); wheel(names, angle);
        const s = Math.floor((angle - from) / seg); if (s !== last) { last = s; sound.tone(900, 0.03, 'square', 0.05); }
      });
    };

    // ---- duck race (0.11.3 — after the online-stopwatch.com duck race): side view of a river, ducks bob along, the
    //      camera follows the leader, places change during the race, the drawn winners take the lead at the end ------
    const RACE_MS = 10000; // a 10-second race
    const MAX_DUCKS = 20;
    const raceColors = () => ({ sky: col('--color-race-sky'), cloud: col('--color-race-foam'), grass: col('--color-race-grass'), bush: col('--color-race-bush'),
      water: col('--color-race-water'), deep: col('--color-race-deep'), foam: col('--color-race-foam'), ink: col('--color-game-ink'), beak: col('--color-game-2'),
      tag: col('--color-surface'), tagText: col('--color-text'), flagA: col('--color-game-ink'), flagB: col('--color-race-foam'), danger: col('--color-danger'),
      ducks: GAME.map(col) });
    const geo = (n) => {
      const skyH = H * 0.24; const bankH = H * 0.07; const top = skyH + bankH + 18; const bottom = H - 26;
      const rows = Array.from({ length: n }, (_, i) => { const d = n === 1 ? 1 : i / (n - 1); return { y: top + (bottom - top) * d, s: (0.62 + 0.5 * d) * Math.min(1.25, H / 560) }; });
      return { skyH, bankH, top, rows, start: 140, length: W * 2.6 };
    };
    const drawDuck = (C, x, y, s, color, bob, name, highlight) => {
      ctx.save(); ctx.translate(x, y + bob); ctx.rotate(Math.sin(bob) * 0.04); ctx.scale(s, s);
      // ripple under the duck
      ctx.globalAlpha = 0.55; ctx.fillStyle = C.foam; ctx.beginPath(); ctx.ellipse(-4, 12, 34, 5, 0, 0, Math.PI * 2); ctx.fill(); ctx.globalAlpha = 1;
      // tail, body, wing, head, beak, eye
      ctx.fillStyle = color; ctx.strokeStyle = C.ink; ctx.lineWidth = 2;
      ctx.beginPath(); ctx.moveTo(-30, -2); ctx.lineTo(-40, -16); ctx.lineTo(-22, -8); ctx.closePath(); ctx.fill(); ctx.stroke();
      ctx.beginPath(); ctx.ellipse(-4, 0, 30, 15, 0, 0, Math.PI * 2); ctx.fill(); ctx.stroke();
      ctx.beginPath(); ctx.ellipse(-8, -2, 13, 7, -0.25, 0, Math.PI); ctx.stroke();
      ctx.beginPath(); ctx.arc(18, -18, 12, 0, Math.PI * 2); ctx.fill(); ctx.stroke();
      ctx.fillStyle = C.beak; ctx.beginPath(); ctx.ellipse(32, -15, 9, 4.5, 0.15, 0, Math.PI * 2); ctx.fill(); ctx.stroke();
      ctx.fillStyle = C.ink; ctx.beginPath(); ctx.arc(21, -21, 2.6, 0, Math.PI * 2); ctx.fill();
      ctx.restore();
      // name tag above the duck (not scaled below a readable size)
      const px = Math.max(11, Math.round(13 * s * zoom()));
      ctx.font = font(px, highlight ? 700 : 600); ctx.textAlign = 'center';
      const label = fit(name, 150 * Math.max(1, s));
      const tw = ctx.measureText(label).width + 12; const ty = y + bob - 46 * s;
      ctx.fillStyle = highlight ? C.danger : C.tag; ctx.globalAlpha = 0.92;
      ctx.beginPath(); ctx.roundRect ? ctx.roundRect(x - tw / 2, ty - px, tw, px + 8, 6) : ctx.rect(x - tw / 2, ty - px, tw, px + 8); ctx.fill(); ctx.globalAlpha = 1;
      ctx.fillStyle = highlight ? C.foam : C.tagText; ctx.fillText(label, x, ty + 1);
    };
    /** One picture of the river: `xs` = each duck's distance from the start (pixels), `cam` = left edge of the view. */
    const river = (C, names, xs, cam, t, clock = '', lead = -1) => {
      W = canvas.width; H = canvas.height;
      const g = geo(names.length);
      ctx.fillStyle = C.sky; ctx.fillRect(0, 0, W, g.skyH + 2);
      for (let k = -1; k < 6; k++) { // clouds (slow parallax)
        const cx = ((k * 260 - cam * 0.15) % (W + 260) + W + 260) % (W + 260) - 130; const cy = 30 + (k % 3) * 22;
        ctx.fillStyle = C.cloud; ctx.beginPath(); ctx.ellipse(cx, cy, 46, 14, 0, 0, Math.PI * 2); ctx.ellipse(cx + 26, cy - 8, 26, 14, 0, 0, Math.PI * 2); ctx.fill();
      }
      ctx.fillStyle = C.grass; ctx.fillRect(0, g.skyH, W, g.bankH + 20);
      for (let k = -1; k < 16; k++) { // bushes on the bank (faster parallax)
        const bx = ((k * 90 - cam * 0.5) % (W + 180) + W + 180) % (W + 180) - 90;
        ctx.fillStyle = C.bush; ctx.beginPath(); ctx.arc(bx, g.skyH + 6, 18, Math.PI, 0); ctx.arc(bx + 22, g.skyH + 6, 13, Math.PI, 0); ctx.fill();
      }
      const wTop = g.skyH + g.bankH;
      const wg = ctx.createLinearGradient(0, wTop, 0, H); wg.addColorStop(0, C.water); wg.addColorStop(1, C.deep);
      ctx.fillStyle = wg; ctx.fillRect(0, wTop, W, H - wTop);
      ctx.strokeStyle = C.foam; ctx.lineWidth = 2;
      for (let band = 0; band < 7; band++) { // moving wave lines
        const y0 = wTop + 14 + band * ((H - wTop) / 7); ctx.globalAlpha = 0.35;
        ctx.beginPath();
        for (let x = 0; x <= W; x += 12) { const wx = x + cam * (0.8 + band * 0.04); ctx.lineTo(x, y0 + Math.sin(wx / 38 + t * 9 + band) * 3); }
        ctx.stroke();
      }
      ctx.globalAlpha = 1;
      // start and finish lines (checkered posts across the river)
      for (const [wx, big] of [[g.start, false], [g.start + g.length, true]]) {
        const sx = wx - cam; if (sx < -20 || sx > W + 20) continue;
        const sq = 10; for (let y = wTop, k = 0; y < H; y += sq, k++) for (let c = 0; c < (big ? 2 : 1); c++) { ctx.fillStyle = (k + c) % 2 ? C.flagA : C.flagB; ctx.fillRect(sx + c * sq, y, sq, sq); }
        if (big) { ctx.fillStyle = C.flagA; ctx.fillRect(sx, g.skyH - 40, 4, 60); ctx.fillStyle = C.danger; ctx.beginPath(); ctx.moveTo(sx + 4, g.skyH - 40); ctx.lineTo(sx + 44, g.skyH - 30); ctx.lineTo(sx + 4, g.skyH - 20); ctx.fill(); }
      }
      names.forEach((name, i) => {
        const r = g.rows[i]; const bob = Math.sin(t * 11 + i * 1.7) * 3 * r.s;
        drawDuck(C, g.start + xs[i] - cam - 41 * r.s, r.y, r.s, C.ducks[i % C.ducks.length], bob, name, i === lead);
      });
      if (clock) { // countdown pill
        ctx.font = font(Math.round(22 * zoom()), 700); const tw = ctx.measureText(clock).width + 28;
        ctx.fillStyle = C.tag; ctx.globalAlpha = 0.9; ctx.beginPath(); ctx.roundRect ? ctx.roundRect(W / 2 - tw / 2, 10, tw, 36 * zoom(), 18) : ctx.rect(W / 2 - tw / 2, 10, tw, 36); ctx.fill(); ctx.globalAlpha = 1;
        ctx.fillStyle = C.tagText; ctx.textAlign = 'center'; ctx.fillText(clock, W / 2, 10 + 26 * zoom());
      }
      return g;
    };
    const idleRace = (names, extra) => {
      W = canvas.width; H = canvas.height;
      const ducks = names.slice(0, MAX_DUCKS); const C = raceColors();
      if (ducks.length === 0) { river(C, [], [], 0, 0); ctx.fillStyle = C.tagText; ctx.font = font(22, 600); ctx.textAlign = 'center'; ctx.fillText(words.empty || '', W / 2, H / 2 + 40); return; }
      river(C, ducks, ducks.map(() => 0), 0, 0);
      if (extra) { ctx.fillStyle = C.tag; ctx.font = font(15, 600); ctx.textAlign = 'right'; ctx.fillText(extra, W - 16, H - 10); }
    };

    /**
     * Runs a duck race among (up to 20 of) `pool` in which `winners` cross the line first, in order (0.40.0, 9.1):
     * the choreography comes from ui/js/race-plan.js — the lead changes hands several times, the runner-up leads into
     * the last stretch, and often it is a photo finish, slowed down at the line and then shown as a frozen photo.
     */
    const race = async (pool, winners) => {
      W = canvas.width; H = canvas.height;
      const n = Math.min(MAX_DUCKS, Math.max(pool.length, winners.length));
      const top = winners.slice(0, n);
      const ducks = shuffle([...top, ...shuffle(pool.filter((x) => !top.includes(x))).slice(0, n - top.length)]);
      const C = raceColors(); const g = geo(ducks.length); const L = g.length;
      const P = window.BlueRacePlan.plan(ducks.length, top.map((d) => ducks.indexOf(d)));
      const c0 = P.finish[P.order[0]] ?? 0.92;
      const c1 = P.order.length > 1 && P.finish[P.order[1]] !== null ? P.finish[P.order[1]] : c0;
      const pace = (t) => (t < 0.1 ? 5 * t * t : t - 0.05) / 0.95; // a short push-off, then steady
      // race time → share of the course; a little sideways wobble (bob) is added when drawn, never forwards/backwards
      const xsAt = (rt) => ducks.map((_, i) => window.BlueRacePlan.at(P, i, rt) * L);
      let cam = 0;
      const frame = (rt, follow = true) => {
        const xs = xsAt(rt);
        const lead = window.BlueRacePlan.leader(P, rt);
        const want = Math.min(Math.max(0, g.start + xs[lead] - W * 0.62), g.start + L + 160 - W);
        if (follow) cam += (want - cam) * 0.12;
        const left = Math.max(0, Math.ceil((1 - rt) * RACE_MS / 1000));
        river(C, ducks, xs, cam, rt * RACE_MS / 1000, `00:${String(left).padStart(2, '0')}`, lead);
        return xs;
      };
      // the photo at the line: the moment the winner touches it, enlarged, with the line and the gaps in seconds
      const photoFrame = () => {
        cam = g.start + L - W * 0.55;
        const xs = xsAt(c0); river(C, ducks, xs, cam, c0 * RACE_MS / 1000, '', ducks.indexOf(top[0]));
        const snap = document.createElement('canvas'); snap.width = W; snap.height = H; snap.getContext('2d').drawImage(canvas, 0, 0);
        const lineX = g.start + L - cam; const Z = 1.6; const zw = W / Z; const zh = H / Z;
        const fy = P.order.slice(0, 2).map((d) => g.rows[d].y); const midY = fy.reduce((a, b) => a + b, 0) / fy.length; // centred on the first two
        const sx = Math.min(Math.max(0, lineX - zw * 0.6), W - zw); const sy = Math.min(H - zh, Math.max(0, midY - zh * 0.5));
        ctx.save(); ctx.drawImage(snap, sx, sy, zw, zh, 0, 0, W, H);
        ctx.globalAlpha = 0.18; ctx.fillStyle = C.ink; ctx.fillRect(0, 0, W, H); ctx.globalAlpha = 1;
        const lx = (lineX - sx) * Z; ctx.strokeStyle = C.danger; ctx.lineWidth = 3; ctx.setLineDash([10, 6]);
        ctx.beginPath(); ctx.moveTo(lx, 0); ctx.lineTo(lx, H); ctx.stroke(); ctx.setLineDash([]);
        const px = Math.round(20 * zoom()); ctx.font = font(px, 600);
        const rows = P.order.filter((d) => P.finish[d] !== null).slice(0, 3).map((d, k) => `${k + 1}. ${fit(ducks[d], 220)}  ${k === 0 ? '' : '+' + ((P.finish[d] - c0) * RACE_MS / 1000).toFixed(2) + (words.sec || ' s')}`);
        ctx.font = font(px, 700);
        const head = words.photo || 'PHOTO FINISH';
        const bw = Math.max(ctx.measureText(head).width, ...rows.map((r) => ctx.measureText(r).width)) + 32; const bh = (rows.length + 1) * (px + 12) + 18;
        ctx.fillStyle = C.tag; ctx.globalAlpha = 0.95; ctx.beginPath(); ctx.roundRect ? ctx.roundRect(16, 16, bw, bh, 10) : ctx.rect(16, 16, bw, bh); ctx.fill(); ctx.globalAlpha = 1;
        ctx.textAlign = 'left'; ctx.fillStyle = C.danger; ctx.fillText(`📸 ${head}`, 32, 16 + px + 10);
        ctx.fillStyle = C.tagText; ctx.font = font(px, 600); rows.forEach((r, k) => ctx.fillText(r, 32, 16 + (k + 2) * (px + 12) + 4));
        ctx.restore();
      };
      if (reduced) { cam = g.start + L + 160 - W; frame(1, false); if (P.photo) { photoFrame(); await sleep(2500); } return; }
      sound.bugle?.();
      let beat = 0;
      // normal speed until just before the line, slow motion through a close finish, then normal again
      const slowFrom = Math.max(0, c0 - 0.03); const slowTo = Math.min(1, c1 + 0.02);
      const slowMs = P.photo ? 1800 : (slowTo - slowFrom) * RACE_MS;
      await animate(slowFrom * RACE_MS, (u) => { const rt = slowFrom * pace(u) / pace(1); frame(rt); if (u - beat > 0.08) { beat = u; sound.tone(160 + Math.random() * 80, 0.05, 'sine', 0.03); } });
      await animate(slowMs, (u) => frame(slowFrom + (slowTo - slowFrom) * u));
      sound.cheer?.();
      await animate((1 - slowTo) * RACE_MS, (u) => frame(slowTo + (1 - slowTo) * u));
      if (P.photo) {
        sound.tone(1200, 0.06, 'square', 0.08); // camera click
        photoFrame(); await sleep(2600);
      }
      // hold on the finish with the winner tagged
      const xs = xsAt(1); const win = ducks.indexOf(top[0]); cam = Math.min(Math.max(0, g.start + xs[win] - W * 0.62), g.start + L + 160 - W);
      await animate(900, (t) => river(C, ducks, xs.map((x, i) => x + (i === win ? t * 30 : 0)), cam, (RACE_MS / 1000) + t, '🏁', win));
    };

    /** A short burst of confetti over the current picture. */
    const confetti = async () => {
      if (reduced) return;
      const snap = ctx.getImageData(0, 0, W, H);
      const bits = Array.from({ length: 140 }, () => ({ x: W / 2, y: H / 2, vx: (Math.random() - 0.5) * 18, vy: -Math.random() * 16 - 4, c: col(GAME[randInt(GAME.length)]), s: 5 + Math.random() * 6, r: Math.random() * 6 }));
      await animate(1600, (t) => {
        ctx.putImageData(snap, 0, 0);
        bits.forEach((b) => { b.x += b.vx; b.y += b.vy; b.vy += 0.6; b.r += 0.2; ctx.save(); ctx.translate(b.x, b.y); ctx.rotate(b.r); ctx.globalAlpha = 1 - t; ctx.fillStyle = b.c; ctx.fillRect(-b.s / 2, -b.s / 4, b.s, b.s / 2); ctx.restore(); });
      });
      ctx.putImageData(snap, 0, 0);
    };

    return { wheel: (names, extra) => wheel(names, angle, extra), spin, race, idleRace, message, confetti, resize };
  };

  /** Up to MAX_SLICES names to put on the wheel — always including `must` (the winner). */
  const sample = (names, must = []) => {
    if (names.length <= MAX_SLICES) return names.slice();
    const rest = shuffle(names.filter((x) => !must.includes(x))).slice(0, MAX_SLICES - must.length);
    return shuffle([...must, ...rest]);
  };
  const fullscreen = (el) => { if (document.fullscreenElement) document.exitFullscreen(); else el.requestFullscreen?.(); };
  const typing = (ev) => ev.target instanceof HTMLElement && ev.target.closest('input, textarea, select, button, a, summary, [contenteditable]');

  // =================================================================================================
  // Full event: the server draws
  // =================================================================================================
  const stage = document.querySelector('[data-draw]');
  if (stage) {
    const T = JSON.parse(stage.dataset.t || '{}');
    const canvas = stage.querySelector('[data-draw-canvas]');
    const overlay = stage.querySelector('[data-draw-overlay]');
    const announce = stage.querySelector('[data-draw-announce]');
    const board = stage.querySelector('[data-draw-board]');
    const prizeSel = stage.querySelector('[data-draw-prize]');
    const countIn = stage.querySelector('[data-draw-count]');
    const go = stage.querySelector('[data-draw-go]');
    const finish = stage.querySelector('[data-draw-finish]');
    const leftText = stage.querySelector('[data-draw-left]');
    const mode = stage.dataset.mode === 'race' ? 'race' : 'wheel';
    const remove = stage.dataset.remove === '1';
    const sound = makeSound(() => stage.querySelector('[data-draw-sound]')?.checked !== false);
    const R = makeRenderer(canvas, sound, { spin: T.spin || '', empty: T.done || '', photo: T.photo || '', sec: T.sec || '' });
    let names = JSON.parse(stage.dataset.names || '[]');
    const idle = () => { R.resize(); return mode === 'race' ? R.idleRace(names) : R.wheel(sample(names)); };
    idle();
    let rz = 0;
    window.addEventListener('resize', () => { clearTimeout(rz); rz = setTimeout(() => { if (!busy && R.resize()) idle(); }, 200); });

    const place = (n) => (T.seq || '#{n}').replace('{n}', String(n));
    const prizeLeft = () => Number((prizeSel?.selectedOptions ? prizeSel.selectedOptions[0] : prizeSel)?.dataset.left || 0);
    const showWinners = (prize, winners) => {
      overlay.replaceChildren();
      const box = document.createElement('div'); box.className = 'draw-reveal';
      const h = document.createElement('p'); h.className = 'draw-reveal__prize'; h.textContent = `🎉 ${T.winner || ''} · ${prize}`; box.append(h);
      const ol = document.createElement('ol'); ol.className = 'draw-reveal__list';
      winners.forEach((w) => { const li = document.createElement('li'); li.textContent = (w.seq ? `${place(w.seq)} · ` : '') + w.name + (w.group ? ` · ${w.group}` : ''); ol.append(li); });
      const next = document.createElement('button'); next.type = 'button'; next.className = 'btn btn--primary'; next.textContent = T.next || 'OK';
      box.append(ol, next); overlay.append(box); overlay.hidden = false; next.focus();
      announce.textContent = `${T.winner || ''} ${prize}: ${winners.map((w) => (w.seq ? `${place(w.seq)} ` : '') + w.name).join(', ')}`;
      const close = () => { overlay.hidden = true; idle(); go?.focus(); };
      next.addEventListener('click', (ev) => { ev.stopPropagation(); close(); }, { once: true });
      overlay.addEventListener('click', close, { once: true });
    };
    const countdown = async () => {
      if (reduced || !stage.querySelector('[data-draw-countdown]')?.checked) return;
      overlay.hidden = false;
      for (const n of [3, 2, 1]) { overlay.replaceChildren(Object.assign(document.createElement('div'), { className: 'draw-count', textContent: String(n) })); sound.tone(660, 0.15, 'sine', 0.18); await sleep(800); }
      overlay.hidden = true;
    };
    const newToken = () => Array.from(crypto.getRandomValues(new Uint8Array(16)), (b) => b.toString(16).padStart(2, '0')).join('');
    let token = newToken();
    let busy = false;
    const sync = () => {
      const left = prizeLeft();
      if (countIn) { countIn.max = String(Math.max(1, left)); if (Number(countIn.value) > left) countIn.value = String(Math.max(1, left)); }
      if (go) go.disabled = left < 1 || busy;
      canvas.classList.toggle('is-clickable', left > 0 && !busy);
    };
    prizeSel?.addEventListener?.('change', () => { token = newToken(); sync(); });
    countIn?.addEventListener('input', () => { token = newToken(); });
    sync();

    const run = async () => {
      if (!go || go.disabled || busy) return;
      busy = true; sync(); overlay.hidden = true;
      const body = new URLSearchParams({ _csrf: stage.dataset.csrf, prize: prizeSel.value, count: countIn.value, token });
      const req = fetch(stage.dataset.endpoint, { method: 'POST', headers: { 'X-CSRF-Token': stage.dataset.csrf, Accept: 'application/json' }, body, credentials: 'same-origin' })
        .then(async (r) => ({ ok: r.ok, data: await r.json().catch(() => ({})) }));
      await countdown();
      let res;
      try { res = await req; } catch { res = { ok: false, data: { error: T.error } }; }
      if (!res.ok) { announce.textContent = res.data.error || T.error || ''; busy = false; sync(); return; } // same token → a retry cannot draw twice
      const { prize, winners } = res.data;
      const won = winners.map((w) => w.name);
      if (mode === 'race') {
        await R.race([...new Set([...names, ...won])], won);
      } else {
        for (const w of winners.slice(0, 5)) {
          const shown = sample(names.includes(w.name) ? names : [...names, w.name], [w.name]);
          await R.spin(shown, shown.indexOf(w.name)); sound.tone(1200, 0.2, 'triangle', 0.15); await sleep(reduced ? 0 : 400);
        }
      }
      sound.fanfare(); await R.confetti();
      if (remove) names = names.filter((x) => !won.includes(x));
      showWinners(prize.name, winners);
      winners.forEach((w) => {
        const li = document.createElement('li'); li.innerHTML = '<span class="draw-board__prize"></span> <strong></strong>';
        li.querySelector('.draw-board__prize').textContent = w.seq ? place(w.seq) : prize.name; li.querySelector('strong').textContent = w.name; board.append(li);
      });
      if (prizeSel.selectedOptions) {
        const opt = prizeSel.selectedOptions[0];
        opt.dataset.left = String(prize.remaining);
        opt.textContent = opt.textContent.replace(/\([^)]*\)$/, `(${(T.remaining || '{n}').replace('{n}', prize.remaining)})`);
        if (prize.remaining < 1) { opt.disabled = true; const next = Array.from(prizeSel.options).find((o) => !o.disabled); if (next) prizeSel.value = next.value; }
      } else {
        prizeSel.dataset.left = String(prize.remaining);
        if (leftText) leftText.textContent = leftText.textContent.replace(/\d[\d,]*/, String(prize.remaining));
      }
      if (finish) { finish.hidden = false; }
      const anyLeft = prizeSel.selectedOptions ? Array.from(prizeSel.options).some((o) => Number(o.dataset.left) > 0) : prize.remaining > 0;
      if (!anyLeft) { finish?.classList.add('is-ready'); announce.textContent += ` · ${T.all_done || ''}`; }
      token = newToken(); busy = false; sync();
    };
    go?.addEventListener('click', run);
    canvas.addEventListener('click', run);
    document.addEventListener('keydown', (ev) => { if ((ev.key === ' ' || ev.key === 'Enter') && !typing(ev) && overlay.hidden) { ev.preventDefault(); run(); } });
    stage.querySelector('[data-draw-fullscreen]')?.addEventListener('click', () => fullscreen(stage));
  }

  // =================================================================================================
  // Quick draw (in the browser, nothing stored) and the saved lucky draw (0.11.2: same screen, the server draws and keeps
  // every spin — data-endpoint set)
  // =================================================================================================
  const quick = document.querySelector('[data-quick-draw]');
  if (quick) {
    const T = JSON.parse(quick.dataset.t || '{}');
    const form = quick.querySelector('[data-qd-form]');
    const area = form.querySelector('textarea[name="names"]');
    const countIn = form.querySelector('input[name="count"]');
    const removeIn = quick.querySelector('[data-qd-remove]');
    const canvas = quick.querySelector('[data-qd-canvas]');
    const dialog = quick.querySelector('[data-qd-dialog]');
    const dList = dialog.querySelector('[data-qd-winners]');
    const dRemove = dialog.querySelector('[data-qd-take-out]');
    const tally = quick.querySelector('[data-qd-tally]');
    const history = quick.querySelector('[data-qd-history]');
    const historyBox = quick.querySelector('[data-qd-history-box]');
    const announce = quick.querySelector('[data-qd-announce]');
    const spinBtn = quick.querySelector('[data-qd-spin]');
    const sound = makeSound(() => quick.querySelector('[data-qd-sound]')?.checked !== false);
    const R = makeRenderer(canvas, sound, { spin: T.spin || '', empty: T.empty || '', photo: T.photo || '', sec: T.sec || '' });
    quick.querySelectorAll('[data-qd-js]').forEach((el) => { el.hidden = false; });
    quick.querySelectorAll('[data-qd-nojs]').forEach((el) => { el.hidden = true; });
    let mode = 'wheel';
    try { mode = localStorage.getItem('blue.quickdraw.mode') === 'race' ? 'race' : 'wheel'; } catch { /* private mode */ }
    let busy = false; let round = 0; let last = [];
    const saved = !!quick.dataset.endpoint;
    const titleIn = form.querySelector('input[name="title"]');
    const prizeIn = form.querySelector('input[name="prize"]');
    const finish = document.querySelector('[data-qd-finish]');
    const finishForm = finish?.querySelector('[data-qd-finish-form]');
    const finishName = finish?.querySelector('[data-qd-finish-name]');
    let pending = ''; // token of a spin not yet answered — a retry sends the same one, so it can never draw twice
    const newToken = () => Array.from(crypto.getRandomValues(new Uint8Array(16)), (b) => b.toString(16).padStart(2, '0')).join('');
    titleIn?.addEventListener('input', () => { if (finishName) finishName.value = titleIn.value; });
    /** Asks the server to draw (saved mode). */
    const serverPick = async (names, k) => {
      pending = pending || newToken();
      const body = new URLSearchParams({ _csrf: quick.dataset.csrf || '', event: quick.dataset.event || '', title: titleIn?.value || '', names: names.join('\n'), count: String(k),
        prize: prizeIn?.value || '', token: pending, mode, take_out: removeIn?.checked ? '1' : '0' });
      let res;
      try {
        const r = await fetch(quick.dataset.endpoint, { method: 'POST', headers: { 'X-CSRF-Token': quick.dataset.csrf || '', Accept: 'application/json' }, body, credentials: 'same-origin' });
        res = { ok: r.ok, data: await r.json().catch(() => ({})) };
      } catch { res = { ok: false, data: { error: T.error } }; }
      if (!res.ok) throw new Error(res.data.error || T.error || 'Error');
      pending = '';
      const d = res.data;
      if (d.event && d.event !== quick.dataset.event) {
        quick.dataset.event = d.event;
        const base = finish?.dataset.base || '/draw/';
        try { window.history.replaceState(null, '', base + d.event); } catch { /* ignore */ }
        if (finishForm) finishForm.action = base + d.event + '/confirm';
      }
      if (finish) finish.hidden = false;
      return d;
    };

    const list = () => { const seen = new Map(); area.value.split(/\r?\n/).forEach((l) => { const n = l.trim().slice(0, 120); if (n && !seen.has(n.toLocaleLowerCase())) seen.set(n.toLocaleLowerCase(), n); }); return [...seen.values()]; };
    const more = (n) => (n > MAX_SLICES ? (T.more || '+{n}').replace('{n}', String(n - MAX_SLICES)) : '');
    const idle = () => {
      R.resize();
      const names = list();
      tally.textContent = (T.count || '{n}').replace('{n}', names.length.toLocaleString());
      if (mode === 'race') R.idleRace(names, names.length > 20 ? (T.lanes || '').replace('{n}', String(names.length)) : '');
      else R.wheel(names.slice(0, MAX_SLICES), more(names.length));
      spinBtn.disabled = busy || names.length === 0;
    };
    const setMode = (m) => {
      mode = m; try { localStorage.setItem('blue.quickdraw.mode', m); } catch { /* ignore */ }
      quick.querySelectorAll('[data-qd-mode]').forEach((b) => { const on = b.dataset.qdMode === m; b.classList.toggle('is-on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); });
      canvas.setAttribute('aria-label', m === 'race' ? (T.race || '') : (T.wheel || '')); idle();
    };
    let timer = 0;
    area.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(idle, 150); });
    const takeOut = (gone, redraw = true) => {
      const drop = new Set(gone.map((x) => x.toLocaleLowerCase()));
      area.value = area.value.split(/\r?\n/).filter((l) => !drop.has(l.trim().slice(0, 120).toLocaleLowerCase())).join('\n');
      if (redraw) idle();
    };
    const tools = {
      shuffle: () => { area.value = shuffle(list()).join('\n'); idle(); },
      sort: () => { area.value = list().sort((a, b) => a.localeCompare(b, 'th')).join('\n'); idle(); },
      clear: () => { area.value = ''; idle(); area.focus(); },
      copy: async () => { try { await navigator.clipboard.writeText(Array.from(history.querySelectorAll('li'), (li) => li.textContent).join('\n')); announce.textContent = T.copied || ''; } catch { /* no clipboard */ } },
      reset: () => { history.replaceChildren(); historyBox.hidden = true; round = 0; },
    };
    quick.querySelectorAll('[data-qd-tool]').forEach((b) => b.addEventListener('click', () => tools[b.dataset.qdTool]?.()));
    quick.querySelectorAll('[data-qd-mode]').forEach((b) => b.addEventListener('click', () => { if (!busy) setMode(b.dataset.qdMode); }));

    const run = async () => {
      if (busy) return;
      const names = list();
      if (names.length === 0) { announce.textContent = T.need_names || ''; area.focus(); return; }
      let k = Math.max(1, Math.min(200, Number(countIn.value) || 1));
      if (k > names.length) k = names.length;
      countIn.value = String(k);
      busy = true; spinBtn.disabled = true; announce.textContent = T.drawing || '';
      let winners = []; let lines = [];
      if (saved) {
        let d;
        try { d = await serverPick(names, k); } catch (err) { announce.textContent = err.message; busy = false; idle(); return; }
        winners = d.winners.map((w) => w.name);
        lines = d.winners.map((w) => `${(T.place || '{n}').replace('{n}', String(w.seq))} · ${w.name} · ${d.prize}`);
      } else {
        const pool = names.slice();
        for (let i = 0; i < k; i++) winners.push(pool.splice(randInt(pool.length), 1)[0]);
      }
      if (mode === 'race') {
        await R.race(names, winners);
      } else {
        for (const w of winners.slice(0, 5)) {
          const shown = sample(names.includes(w) ? names : [...names, w], [w]);
          await R.spin(shown, shown.indexOf(w)); sound.tone(1200, 0.2, 'triangle', 0.15);
          if (winners.length > 1) await sleep(reduced ? 0 : 350);
        }
      }
      sound.fanfare(); await R.confetti();
      last = winners; round += 1;
      if (saved) {
        lines.forEach((line) => history.prepend(Object.assign(document.createElement('li'), { textContent: line })));
      } else {
        history.prepend(Object.assign(document.createElement('li'), { textContent: `${(T.round || '{n}').replace('{n}', String(round))}: ${winners.join(', ')}` }));
      }
      historyBox.hidden = false;
      dList.replaceChildren(...winners.map((w) => Object.assign(document.createElement('li'), { textContent: w })));
      const auto = removeIn?.checked;
      dRemove.hidden = !!auto;
      if (auto) takeOut(winners, false); // the finish stays on screen behind the winner dialog; redrawn when it closes
      announce.textContent = `${T.winner || ''}: ${winners.join(', ')}`;
      busy = false; spinBtn.disabled = false;
      tally.textContent = (T.count || '{n}').replace('{n}', list().length.toLocaleString());
      if (typeof dialog.showModal === 'function') dialog.showModal(); else dialog.setAttribute('open', '');
    };
    dRemove.addEventListener('click', () => { takeOut(last, false); dialog.close?.(); });
    dialog.addEventListener('close', () => { if (!busy) idle(); });
    dialog.querySelectorAll('[data-qd-close]').forEach((b) => b.addEventListener('click', () => { dialog.close?.(); if (!dialog.close) dialog.removeAttribute('open'); }));
    dialog.querySelector('[data-qd-again]')?.addEventListener('click', () => { dialog.close?.(); run(); });
    form.addEventListener('submit', (ev) => { ev.preventDefault(); run(); });
    let rz = 0;
    window.addEventListener('resize', () => { clearTimeout(rz); rz = setTimeout(() => { if (!busy && R.resize()) idle(); }, 200); });
    spinBtn.addEventListener('click', run);
    canvas.addEventListener('click', run);
    document.addEventListener('keydown', (ev) => { if ((ev.key === ' ' || ev.key === 'Enter') && !typing(ev) && !dialog.open) { ev.preventDefault(); run(); } });
    quick.querySelector('[data-qd-fullscreen]')?.addEventListener('click', () => fullscreen(quick.querySelector('[data-qd-stage]')));
    setMode(mode);
  }
})();
