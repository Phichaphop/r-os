/* M26 general calculators — run entirely in the browser (no data is sent; BMI never leaves the device). */
(() => {
  'use strict';

  // ---- safe expression evaluator (no eval): numbers, + − × ÷ ^ %, ( ), sin cos tan log ln √, π e -------------
  const FUNCS = { sin: 1, cos: 1, tan: 1, log: 1, ln: 1, sqrt: 1 };
  function tokenize(src) {
    const s = src.replace(/×/g, '*').replace(/÷/g, '/').replace(/−/g, '-').replace(/√/g, 'sqrt').replace(/π/g, 'pi').replace(/,/g, '');
    const out = [];
    let i = 0;
    while (i < s.length) {
      const c = s[i];
      if (/\s/.test(c)) { i++; continue; }
      if (/[0-9.]/.test(c)) {
        let j = i; while (j < s.length && /[0-9.]/.test(s[j])) j++;
        if (j < s.length && /[eE]/.test(s[j]) && /[0-9+-]/.test(s[j + 1] || '')) { j += 2; while (j < s.length && /[0-9]/.test(s[j])) j++; }
        const n = Number(s.slice(i, j));
        if (!Number.isFinite(n)) throw new Error('number');
        out.push({ t: 'n', v: n }); i = j; continue;
      }
      if (/[a-z]/i.test(c)) {
        let j = i; while (j < s.length && /[a-z]/i.test(s[j])) j++;
        const w = s.slice(i, j).toLowerCase();
        if (w === 'pi') out.push({ t: 'n', v: Math.PI });
        else if (w === 'e') out.push({ t: 'n', v: Math.E });
        else if (FUNCS[w]) out.push({ t: 'f', v: w });
        else throw new Error('name');
        i = j; continue;
      }
      if ('+-*/^%()'.includes(c)) { out.push({ t: 'o', v: c }); i++; continue; }
      throw new Error('char');
    }
    return out;
  }
  function evaluate(src, deg) {
    const toks = tokenize(src);
    let p = 0;
    const peek = () => toks[p];
    const take = () => toks[p++];
    const angle = (x) => (deg ? (x * Math.PI) / 180 : x);
    // grammar: expr = term {(+|-) term}; term = unary {(*|/) unary}; unary = -unary | power; power = postfix [^ unary]; postfix = primary {%}
    function primary() {
      const tk = take();
      if (!tk) throw new Error('end');
      if (tk.t === 'n') return tk.v;
      if (tk.t === 'f') {
        let arg;
        if (peek() && peek().v === '(') { take(); arg = expr(); if (!take() || toks[p - 1].v !== ')') throw new Error('paren'); } else arg = power();
        switch (tk.v) {
          case 'sin': return Math.sin(angle(arg));
          case 'cos': return Math.cos(angle(arg));
          case 'tan': return Math.tan(angle(arg));
          case 'log': return Math.log10(arg);
          case 'ln': return Math.log(arg);
          default: return Math.sqrt(arg);
        }
      }
      if (tk.v === '(') { const v = expr(); const close = take(); if (!close || close.v !== ')') throw new Error('paren'); return v; }
      throw new Error('token');
    }
    function postfix() { let v = primary(); while (peek() && peek().v === '%') { take(); v /= 100; } return v; }
    function power() { const b = postfix(); if (peek() && peek().v === '^') { take(); return Math.pow(b, unary()); } return b; }
    function unary() { if (peek() && (peek().v === '-' || peek().v === '+')) { const s = take().v; const v = unary(); return s === '-' ? -v : v; } return power(); } // -2^2 = -(2^2)
    function term() {
      let v = unary();
      while (peek() && (peek().v === '*' || peek().v === '/' || peek().t === 'n' || peek().t === 'f' || peek().v === '(')) {
        if (peek().v === '*' || peek().v === '/') { const o = take().v; const r = unary(); v = o === '*' ? v * r : v / r; } else v *= power(); // implicit ×: 2π, 3(4)
      }
      return v;
    }
    function expr() { let v = term(); while (peek() && (peek().v === '+' || peek().v === '-')) { const o = take().v; const r = term(); v = o === '+' ? v + r : v - r; } return v; }
    const v = expr();
    if (p !== toks.length) throw new Error('rest');
    return v;
  }
  const fmtNum = (v) => {
    if (!Number.isFinite(v)) return '—';
    const r = Math.round(v * 1e12) / 1e12;
    return Math.abs(r) >= 1e15 || (r !== 0 && Math.abs(r) < 1e-9) ? r.toExponential(10).replace(/\.?0+e/, 'e') : r.toLocaleString(undefined, { maximumFractionDigits: 10 });
  };

  document.querySelectorAll('[data-calc="basic"]').forEach((box) => {
    const expr = box.querySelector('[data-calc-expr]');
    const out = box.querySelector('[data-calc-out]');
    const angleBtn = box.querySelector('[data-angle]');
    const hist = box.querySelector('[data-calc-history]');
    const histEmpty = box.querySelector('[data-calc-history-empty]');
    let deg = false;
    // history of this page only (kept in memory, never sent) — click a line to reuse its result
    const addHistory = (src, v) => {
      if (!hist) return;
      const li = document.createElement('li');
      const b = document.createElement('button');
      b.type = 'button'; b.className = 'calc-history__item';
      const q = document.createElement('span'); q.className = 'calc-history__expr mono'; q.textContent = src + ' =';
      const a = document.createElement('span'); a.className = 'calc-history__value mono'; a.textContent = fmtNum(v);
      b.append(q, a);
      b.addEventListener('click', () => { expr.value = String(Math.round(v * 1e12) / 1e12); run(false); expr.focus(); });
      li.append(b);
      hist.prepend(li);
      while (hist.children.length > 30) hist.lastElementChild.remove();
      if (histEmpty) histEmpty.hidden = true;
    };
    const run = (final) => {
      const src = expr.value.trim();
      if (src === '') { out.textContent = '0'; return; }
      try {
        const v = evaluate(src, deg); out.textContent = fmtNum(v);
        if (final && Number.isFinite(v)) { if (String(Math.round(v * 1e12) / 1e12) !== src) addHistory(src, v); expr.value = String(Math.round(v * 1e12) / 1e12); }
      } catch { if (final) out.textContent = '—'; }
    };
    box.querySelector('[data-calc-clear]')?.addEventListener('click', () => { expr.value = ''; run(false); expr.focus(); });
    box.querySelector('[data-calc-history-clear]')?.addEventListener('click', () => { if (hist) hist.replaceChildren(); if (histEmpty) histEmpty.hidden = false; });
    box.querySelectorAll('[data-key]').forEach((b) => b.addEventListener('click', () => {
      const k = b.dataset.key;
      if (k === 'C') expr.value = '';
      else if (k === '⌫') expr.value = expr.value.slice(0, -1);
      else if (k === '=') return run(true);
      else if (k === 'deg') { deg = !deg; angleBtn.setAttribute('aria-pressed', String(deg)); angleBtn.textContent = deg ? 'RAD→DEG' : 'DEG'; }
      else if (k === 'x²') expr.value += '^2';
      else if (['sin', 'cos', 'tan', 'log', 'ln', '√'].includes(k)) expr.value += k + '(';
      else expr.value += k;
      run(false);
      expr.focus();
    }));
    expr.addEventListener('input', () => run(false));
    expr.addEventListener('keydown', (ev) => { if (ev.key === 'Enter') { ev.preventDefault(); run(true); } if (ev.key === 'Escape') { expr.value = ''; run(false); } });
  });

  // ---- age / service length ------------------------------------------------------------------------------
  const parseDate = (s) => { const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s || ''); return m ? new Date(Date.UTC(+m[1], +m[2] - 1, +m[3])) : null; };
  function ymd(a, b) {
    let y = b.getUTCFullYear() - a.getUTCFullYear();
    let m = b.getUTCMonth() - a.getUTCMonth();
    let d = b.getUTCDate() - a.getUTCDate();
    if (d < 0) { m--; d += new Date(Date.UTC(b.getUTCFullYear(), b.getUTCMonth(), 0)).getUTCDate(); }
    if (m < 0) { y--; m += 12; }
    return { y, m, d };
  }
  const fill = (tpl, o) => tpl.replace(/\{(\w+)\}/g, (_, k) => (k in o ? String(o[k]) : ''));
  document.querySelectorAll('[data-calc="age"], [data-calc="service"]').forEach((box) => {
    const out = box.querySelector('[data-calc-out]');
    const get = (n) => box.querySelector(`[data-calc-in="${n}"]`);
    const run = () => {
      const a = parseDate(get('from').value);
      let b = parseDate(get('to').value);
      out.textContent = '';
      if (!a || !b) return;
      if (b < a) { out.textContent = out.dataset.tBad; return; }
      const inclusive = get('inclusive')?.checked;
      if (inclusive) b = new Date(b.getTime() + 86400000);
      const r = ymd(a, b);
      const days = Math.round((b - a) / 86400000);
      const lines = [fill(out.dataset.tYmd, r), fill(out.dataset.tDays, { n: days.toLocaleString() })];
      if (box.dataset.calc === 'age') {
        let next = new Date(Date.UTC(b.getUTCFullYear(), a.getUTCMonth(), a.getUTCDate()));
        if (next < b) next = new Date(Date.UTC(b.getUTCFullYear() + 1, a.getUTCMonth(), a.getUTCDate()));
        lines.push(fill(out.dataset.tNext, { n: Math.round((next - b) / 86400000) }));
      }
      out.replaceChildren(...lines.map((l, i) => { const el = document.createElement(i === 0 ? 'strong' : 'div'); el.textContent = l; return el; }));
    };
    box.querySelectorAll('[data-calc-in]').forEach((el) => el.addEventListener('input', run));
    box.querySelectorAll('[data-calc-in]').forEach((el) => el.addEventListener('change', run));
    run();
  });

  // ---- units (physical/legal Thai land units are fixed definitions, not rates) --------------------------------
  const UNITS = {
    weight: { kg: 1, g: 0.001, mg: 1e-6, t: 1000, lb: 0.45359237, oz: 0.028349523125 },
    length: { m: 1, cm: 0.01, mm: 0.001, km: 1000, in: 0.0254, ft: 0.3048, yd: 0.9144, mi: 1609.344, wa: 2 },
    area: { m2: 1, km2: 1e6, rai: 1600, ngan: 400, sqwa: 4, ha: 10000, acre: 4046.8564224, ft2: 0.09290304 },
    temperature: { C: 1, F: 1, K: 1 },
  };
  const toC = { C: (v) => v, F: (v) => ((v - 32) * 5) / 9, K: (v) => v - 273.15 };
  const fromC = { C: (v) => v, F: (v) => (v * 9) / 5 + 32, K: (v) => v + 273.15 };
  document.querySelectorAll('[data-calc="unit"]').forEach((box) => {
    const labels = JSON.parse(box.querySelector('[data-unit-labels]').dataset.unitLabels || '{}');
    const kind = box.querySelector('[data-calc-in="kind"]');
    const val = box.querySelector('[data-calc-in="value"]');
    const from = box.querySelector('[data-calc-in="from"]');
    const to = box.querySelector('[data-calc-in="to"]');
    const out = box.querySelector('[data-calc-out]');
    const fillUnits = () => {
      const us = Object.keys(UNITS[kind.value]);
      [from, to].forEach((sel, i) => { sel.replaceChildren(...us.map((u) => { const o = document.createElement('option'); o.value = u; o.textContent = labels[u] || u; return o; })); sel.value = us[Math.min(i, us.length - 1)]; });
    };
    const run = () => {
      const v = Number(String(val.value).replace(/,/g, ''));
      if (!Number.isFinite(v) || val.value.trim() === '') { out.textContent = ''; return; }
      const r = kind.value === 'temperature' ? fromC[to.value](toC[from.value](v)) : (v * UNITS[kind.value][from.value]) / UNITS[kind.value][to.value];
      out.textContent = `${fmtNum(v)} ${labels[from.value] || from.value} = ${fmtNum(r)} ${labels[to.value] || to.value}`;
    };
    kind.addEventListener('change', () => { fillUnits(); run(); });
    [val, from, to].forEach((el) => { el.addEventListener('input', run); el.addEventListener('change', run); });
    fillUnits(); run();
  });

  // ---- BMI: computed here, never submitted ---------------------------------------------------------------------
  document.querySelectorAll('[data-calc="bmi"]').forEach((box) => {
    const out = box.querySelector('[data-calc-out]');
    const tr = JSON.parse(out.dataset.t || '{}');
    const w = box.querySelector('[data-calc-in="w"]');
    const h = box.querySelector('[data-calc-in="h"]');
    const run = () => {
      const kg = Number(w.value); const cm = Number(h.value);
      if (!(kg > 0 && kg < 500 && cm > 30 && cm < 280)) { out.textContent = ''; return; }
      const bmi = kg / ((cm / 100) ** 2);
      const cat = bmi < 18.5 ? 'under' : bmi < 25 ? 'normal' : bmi < 30 ? 'over' : 'obese';
      const strong = document.createElement('strong'); strong.textContent = fill(tr.value, { v: bmi.toFixed(1) });
      const div = document.createElement('div'); div.textContent = tr[cat];
      out.replaceChildren(strong, div);
    };
    [w, h].forEach((el) => el.addEventListener('input', run));
    window.addEventListener('pagehide', () => { w.value = ''; h.value = ''; out.textContent = ''; });
  });

  // ---- "clear" on the other browser calculators: inputs back to how the page drew them, result emptied --------------
  document.querySelectorAll('[data-calc]:not([data-calc="basic"])').forEach((box) => {
    const btn = box.querySelector('[data-calc-clear]');
    if (!btn) return;
    const initial = [...box.querySelectorAll('[data-calc-in]')].map((el) => [el, el.type === 'checkbox' ? el.checked : el.defaultValue ?? el.value]);
    btn.addEventListener('click', () => {
      initial.forEach(([el, v]) => {
        if (el.type === 'checkbox') el.checked = v;
        else if (el.tagName === 'SELECT') el.selectedIndex = 0;
        else el.value = v;
        el.dispatchEvent(new Event(el.tagName === 'SELECT' ? 'change' : 'input', { bubbles: true }));
      });
      const out = box.querySelector('[data-calc-out]');
      if (box.dataset.calc === 'bmi' && out) out.textContent = '';
      box.querySelector('[data-calc-in]')?.focus();
    });
  });

  window.RosCalc = { evaluate }; // for tests in the browser console
})();
