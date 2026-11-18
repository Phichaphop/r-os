/*
 * Duck race choreography (0.40.0, item 9.1). The winners are decided BEFORE the race — by the server in a recorded
 * draw, or by crypto.getRandomValues in a quick draw — and this only plans how the race LOOKS so that it ends exactly
 * that way:
 *   - every duck's distance is a smooth, never-backwards curve (monotone cubic through checkpoints, Fritsch–Carlson),
 *     so nobody swims backwards and nobody crosses the line before their planned moment;
 *   - the lead changes hands several times: a scripted leader at each checkpoint (never the same twice in a row,
 *     sometimes a duck that will not win), the runner-up leads into the last stretch and the winner passes at the line;
 *   - often a photo finish: first and second cross 20–60 ms apart (in a 10-second race), shown afterwards as a frozen
 *     "photo" at the line;
 *   - the drawn winners cross first, in their drawn order; the others finish after them or are still swimming.
 * Pure functions, no DOM: used by ui/js/draw.js and checked by tests/Unit/DuckRacePlanTest.php (node).
 */
(function (root) {
  'use strict';
  const CHECKPOINTS = [0, 0.12, 0.26, 0.4, 0.54, 0.68, 0.8];
  const PHOTO_GAP = [0.002, 0.006]; // share of the race: 20–60 ms of a 10-second race
  const PHOTO_CHANCE = 0.65;

  const between = (rng, a, b) => a + (b - a) * rng();
  const pick = (rng, list) => list[Math.floor(rng() * list.length) % list.length];

  /** Fritsch–Carlson slopes for knots [[t, x], …] with non-decreasing x → the curve never goes backwards. */
  const slopes = (k) => {
    const n = k.length; const d = []; const m = new Array(n).fill(0);
    for (let i = 0; i < n - 1; i++) d.push((k[i + 1][1] - k[i][1]) / (k[i + 1][0] - k[i][0]));
    if (n < 2) return m;
    m[0] = d[0]; m[n - 1] = d[n - 2];
    for (let i = 1; i < n - 1; i++) m[i] = d[i - 1] * d[i] <= 0 ? 0 : (d[i - 1] + d[i]) / 2;
    for (let i = 0; i < n - 1; i++) {
      if (d[i] === 0) { m[i] = 0; m[i + 1] = 0; continue; }
      const a = m[i] / d[i]; const b = m[i + 1] / d[i]; const s = a * a + b * b;
      if (s > 9) { const t = 3 / Math.sqrt(s); m[i] = t * a * d[i]; m[i + 1] = t * b * d[i]; }
    }
    return m;
  };
  const evalCurve = (k, m, t) => {
    if (t <= k[0][0]) return k[0][1];
    const last = k.length - 1;
    if (t >= k[last][0]) return k[last][1];
    let i = 0; while (t > k[i + 1][0]) i++;
    const h = k[i + 1][0] - k[i][0]; const s = (t - k[i][0]) / h;
    const s2 = s * s; const s3 = s2 * s;
    return (2 * s3 - 3 * s2 + 1) * k[i][1] + (s3 - 2 * s2 + s) * h * m[i] + (-2 * s3 + 3 * s2) * k[i + 1][1] + (s3 - s2) * h * m[i + 1];
  };

  /**
   * @param {number} n ducks in the race
   * @param {number[]} winners duck indices in the order they must finish (1st, 2nd, …)
   * @param {() => number} [rng] uniform [0, 1) — Math.random by default (looks only; the result is already fixed)
   * @returns {{n: number, paths: {k: number[][], m: number[]}[], finish: (number|null)[], order: number[], photo: boolean, gap: number, script: number[]}}
   */
  const plan = (n, winners, rng = Math.random) => {
    const order = winners.filter((w, i) => w >= 0 && w < n && winners.indexOf(w) === i);
    // a couple of other ducks finish after the drawn winners, the rest are still in the water at the whistle
    const others = Array.from({ length: n }, (_, i) => i).filter((i) => !order.includes(i));
    for (let i = others.length - 1; i > 0; i--) { const j = Math.floor(rng() * (i + 1)); [others[i], others[j]] = [others[j], others[i]]; }
    const finishers = [...order, ...others.slice(0, Math.min(others.length, 2))];
    const photo = finishers.length >= 2 && rng() < PHOTO_CHANCE;
    const finish = new Array(n).fill(null);
    const c0 = between(rng, 0.9, 0.93);
    const gaps = finishers.map((_, i) => (i === 0 ? 0 : i === 1 ? (photo ? between(rng, PHOTO_GAP[0], PHOTO_GAP[1]) : between(rng, 0.01, 0.025)) : between(rng, 0.008, 0.03)));
    // many prizes: the later gaps are squeezed so everyone drawn still crosses before the whistle (the photo gap stays)
    const budget = 0.988 - c0 - (gaps[1] || 0); const rest = gaps.slice(2).reduce((a, b) => a + b, 0);
    const squeeze = rest > budget ? budget / rest : 1;
    let c = c0;
    finishers.forEach((d, i) => { c += i >= 2 ? gaps[i] * squeeze : gaps[i]; finish[d] = c; });
    // who leads at each checkpoint: never the same duck twice running; the runner-up leads into the last stretch
    const rival = finishers.length >= 2 ? finishers[1] : finishers[0];
    const script = [];
    for (let j = 1; j < CHECKPOINTS.length; j++) {
      const prev = script[script.length - 1];
      let who;
      if (j === CHECKPOINTS.length - 1) who = rival;
      else {
        const pool = Array.from({ length: n }, (_, i) => i).filter((i) => i !== prev && (j !== CHECKPOINTS.length - 2 || i !== rival));
        who = pool.length ? pick(rng, pool) : (prev ?? 0);
      }
      script.push(who);
    }
    const base = (t) => Math.min(0.97, (t / c0) * 0.985); // the pack's average share of the course
    const pos = Array.from({ length: n }, () => [0]);
    for (let j = 1; j < CHECKPOINTS.length; j++) {
      const b = base(CHECKPOINTS[j]);
      const offs = Array.from({ length: n }, () => between(rng, -0.08, -0.005));
      const top = Math.max(...offs.filter((_, i) => i !== script[j - 1]));
      offs[script[j - 1]] = top + between(rng, 0.02, 0.04);
      for (let i = 0; i < n; i++) {
        let x = b * (1 + offs[i]);
        x = Math.max(x, pos[i][j - 1] + 0.012); // forwards only
        pos[i].push(Math.min(x, 0.975)); // and short of the line before the last stretch
      }
    }
    const paths = pos.map((xs, i) => {
      const k = CHECKPOINTS.map((t, j) => [t, xs[j]]);
      if (finish[i] !== null) {
        k.push([finish[i], 1]);
        k.push([1, 1 + (1 - finish[i]) * 0.9]);
      } else {
        const last = xs[xs.length - 1];
        k.push([1, Math.min(0.992, Math.max(last + 0.02, between(rng, 0.9, 0.985)))]);
      }
      return { k, m: slopes(k) };
    });
    return { n, paths, finish, order: finishers, photo, gap: finishers.length >= 2 && finish[finishers[1]] !== null ? finish[finishers[1]] - finish[finishers[0]] : 0, script };
  };

  /** Share of the course swum by duck `i` at race time `t` (0 start … 1 the line; above 1 = past it). */
  const at = (p, i, t) => evalCurve(p.paths[i].k, p.paths[i].m, Math.min(1, Math.max(0, t)));
  /** Index of the duck in front at time `t`. */
  const leader = (p, t) => { let best = 0; let bx = -1; for (let i = 0; i < p.n; i++) { const x = at(p, i, t); if (x > bx) { bx = x; best = i; } } return best; };
  /** How many times the lead changes hands, sampled `steps` times over the race (after the push-off). */
  const leadChanges = (p, steps = 400, from = 0.05) => {
    let n = 0; let prev = leader(p, from);
    for (let s = 1; s <= steps; s++) { const t = from + (1 - from) * (s / steps); const l = leader(p, t); if (l !== prev) { n++; prev = l; } }
    return n;
  };

  const api = { plan, at, leader, leadChanges, CHECKPOINTS, PHOTO_GAP };
  root.BlueRacePlan = api;
  if (typeof module === 'object' && module.exports) module.exports = api;
})(typeof globalThis !== 'undefined' ? globalThis : this);
