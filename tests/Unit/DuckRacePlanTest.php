<?php

declare(strict_types=1);

namespace Blue\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 0.40.0 (R4, item 9.1): the duck race choreography (ui/js/race-plan.js) is checked over thousands of random races,
 * run with node: the drawn winners always cross first and in their drawn order, nobody swims backwards, the lead
 * changes hands several times, close finishes ("photo finish") are 20–60 ms apart and happen often.
 * The winners themselves are not chosen here — the server (recorded draw) or crypto.getRandomValues (quick draw) did.
 */
final class DuckRacePlanTest extends TestCase
{
    public function testPlannedRacesEndAsDrawnWithLeadChangesAndPhotoFinishes(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            self::markTestSkipped('node is not installed');
        }
        $lib = dirname(__DIR__, 2) . '/ui/js/race-plan.js';
        $js = <<<'JS'
const R = require(process.argv[2]);
let seed = 12345; const rng = () => { seed = (seed * 1103515245 + 12345) % 2147483648; return seed / 2147483648; };
const out = { races: 0, backwards: 0, wrongOrder: 0, early: 0, photos: 0, photoGapMin: 1, photoGapMax: 0, minLeadChanges: 99, minLeadChanges2: 99, multi: 0 };
for (let r = 0; r < 2400; r++) {
  const n = 1 + (r % 20); const k = 1 + (r * 7) % n;
  const idx = Array.from({ length: n }, (_, i) => i); for (let i = n - 1; i > 0; i--) { const j = Math.floor(rng() * (i + 1)); [idx[i], idx[j]] = [idx[j], idx[i]]; }
  const winners = idx.slice(0, k);
  const p = R.plan(n, winners, rng);
  const cross = new Array(n).fill(null);
  for (let i = 0; i < n; i++) {
    let prev = -1;
    for (let s = 0; s <= 4000; s++) {
      const t = s / 4000; const x = R.at(p, i, t);
      if (x < prev - 1e-9) out.backwards++;
      prev = x;
      if (x >= 1 && cross[i] === null) cross[i] = t;
    }
  }
  const finished = cross.map((c, i) => [c, i]).filter((a) => a[0] !== null).sort((a, b) => a[0] - b[0]).map((a) => a[1]);
  if (winners.some((w, j) => finished[j] !== w)) out.wrongOrder++;
  if (winners.some((w) => cross[w] === null)) out.early++;
  if (p.photo) { out.photos++; out.photoGapMin = Math.min(out.photoGapMin, p.gap); out.photoGapMax = Math.max(out.photoGapMax, p.gap); }
  if (n >= 3) out.minLeadChanges = Math.min(out.minLeadChanges, R.leadChanges(p));
  if (n === 2) out.minLeadChanges2 = Math.min(out.minLeadChanges2, R.leadChanges(p));
  if (k >= 10) out.multi++;
  out.races++;
}
console.log(JSON.stringify(out));
JS;
        $file = tempnam(sys_get_temp_dir(), 'race') . '.js';
        file_put_contents($file, $js);
        $raw = (string) shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($file) . ' ' . escapeshellarg($lib) . ' 2>&1');
        @unlink($file);
        $r = json_decode($raw, true);
        self::assertIsArray($r, $raw);
        self::assertSame(2400, $r['races']);
        self::assertSame(0, $r['backwards'], 'nobody swims backwards');
        self::assertSame(0, $r['wrongOrder'], 'the drawn winners cross first, in the drawn order');
        self::assertSame(0, $r['early'], 'every drawn winner crosses before the whistle, even with many prizes');
        self::assertGreaterThan(0, $r['multi'], 'races with 10+ prizes were included');
        self::assertGreaterThanOrEqual(3, $r['minLeadChanges'], 'with 3+ ducks the lead changes hands at least 3 times');
        self::assertGreaterThanOrEqual(2, $r['minLeadChanges2'], 'a two-duck race still changes lead');
        self::assertGreaterThan(2400 * 0.5, $r['photos'], 'photo finishes are common');
        self::assertGreaterThanOrEqual(0.002, $r['photoGapMin']);
        self::assertLessThanOrEqual(0.0061, $r['photoGapMax'], 'photo finish = 20–60 ms of a 10-second race');
    }
}
