import { chromium } from 'playwright'; import fs from 'fs';
const B = 'http://127.0.0.1:8098'; const OUT = 'p0113'; fs.mkdirSync(OUT, { recursive: true });
const PW = 'Demo-c8d55255c554';
const axeSrc = fs.readFileSync('node_modules/axe-core/axe.min.js', 'utf8');
const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium_headless_shell-1194/chrome-linux/headless_shell' });
const out = []; const log = (...a) => { console.log(...a); out.push(a.join(' ')); };
async function page(user, vw = 1366, dark = false) {
  const ctx = await b.newContext({ viewport: { width: vw, height: 900 }, bypassCSP: true, colorScheme: dark ? 'dark' : 'light' });
  const p = await ctx.newPage();
  p.on('pageerror', (e) => log('pageerror ' + user + ': ' + e.message));
  if (dark) await p.addInitScript(() => { try { localStorage.setItem('ros.theme', 'dark'); } catch (e) {} });
  await p.goto(B + '/login'); await p.fill('#f-identifier', user); await p.fill('#f-password', PW);
  await Promise.all([p.waitForURL((u) => u.pathname !== '/login'), p.click('form[action$="/login"] button[type=submit]')]);
  return p;
}
async function audit(p, name) {
  const overflow = await p.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  await p.screenshot({ path: OUT + '/' + name + '.png', fullPage: true });
  await p.addScriptTag({ content: axeSrc }).catch(() => {});
  const v = await p.evaluate(async () => (await window.axe.run(document, { runOnly: ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'] })).violations.map((v) => v.impact + ':' + v.id + '(' + v.nodes.length + ') ' + v.nodes.slice(0, 2).map((n) => n.target.join(' ')).join(' | ')));
  const raw = await p.evaluate(() => (document.body.innerText.match(/\b(draw|common|form)\.[a-z_]+(\.[a-z_]+)*\b/g) || []).slice(0, 5));
  log(name + ': ' + new URL(p.url()).pathname + ' overflow=' + overflow + ' axe=' + JSON.stringify(v) + (raw.length ? ' RAWKEYS=' + raw : ''));
}
async function visit(p, path, name) { await p.goto(B + path); await audit(p, name); }
const flash = async (p) => (await p.locator('.alert').allInnerTexts()).map((s) => s.replace(/\s+/g, ' ').slice(0, 120)).join(' || ');
const submit = async (p, sel) => { await Promise.all([p.waitForNavigation(), p.click(sel)]); };
const names13 = ['สมชาย', 'สมหญิง', 'มานะ', 'มานี', 'ปิติ', 'ชูใจ', 'วีระ', 'ดวงใจ', 'อนันต์', 'กมล', 'นิดา', 'ต้น', 'แพร'];
for (const [path, tag] of [['/draw/quick', 'q'], ['/draw', 's']]) {
  const p = await page('employee.demo1');
  await p.goto(B + path);
  await p.fill('textarea[name=names]', names13.join('\n'));
  await p.click('[data-qd-mode=race]'); await p.waitForTimeout(300);
  await audit(p, tag + '1-race-start');
  const t0 = Date.now();
  await p.click('[data-qd-spin]');
  for (const [ms, n] of [[2500, 2], [5500, 3], [8500, 4]]) { await p.waitForTimeout(ms - (Date.now() - t0)); await p.screenshot({ path: OUT + '/' + tag + n + '-race-' + ms + 'ms.png' }); }
  await p.waitForSelector('dialog[open]', { timeout: 25000 });
  log(tag + ' race took ~' + Math.round((Date.now() - t0) / 1000) + 's · winner: ' + (await p.locator('[data-qd-winners] li').allInnerTexts()).join(','));
  await audit(p, tag + '5-race-winner');
  await p.context().close();
}
{ const m = await page('employee.demo1', 390); await m.goto(B + '/draw/quick'); await m.fill('textarea[name=names]', names13.slice(0, 6).join('\n')); await m.click('[data-qd-mode=race]'); await m.waitForTimeout(300); await audit(m, 'm1-race-390'); await m.context().close(); }
{ const d = await page('employee.demo1', 1366, true); await d.goto(B + '/draw/quick'); await d.fill('textarea[name=names]', names13.join('\n')); await d.click('[data-qd-mode=race]'); await d.waitForTimeout(300); await audit(d, 'd1-race-dark'); await d.context().close(); }
fs.writeFileSync(OUT + '/log.txt', out.join('\n') + '\n');
await b.close();
