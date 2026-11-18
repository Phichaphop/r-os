import { chromium } from 'playwright'; import fs from 'fs';
const B = 'http://127.0.0.1:8098'; const OUT = 'p0112'; fs.mkdirSync(OUT, { recursive: true });
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
const shot = async (p, name) => { await p.screenshot({ path: OUT + '/' + name + '.png', fullPage: true }); log(name + ' (shot)'); };
{
  const p = await page('employee.demo1');
  await visit(p, '/draw', 's1-draw-start');
  await p.fill('#qd-title', 'จับรางวัลปีใหม่ (ทดสอบ)');
  await p.fill('textarea[name=names]', ['สมชาย', 'สมหญิง', 'มานะ', 'มานี', 'ปิติ', 'ชูใจ', 'วีระ', 'ดวงใจ'].join('\n'));
  await p.fill('#qd-prize', 'รางวัลที่ 3 · ร่ม');
  await p.waitForTimeout(300);
  await audit(p, 's2-draw-ready');
  log('take-out checked by default: ' + await p.isChecked('[data-qd-remove]'));
  await p.click('[data-qd-canvas]');
  await p.waitForSelector('dialog[open]', { timeout: 20000 });
  await audit(p, 's3-draw-winner');
  const w1 = await p.locator('[data-qd-winners] li').allInnerTexts(); log('winner 1: ' + w1.join(','));
  await p.click('[data-qd-close]');
  log('url after first spin: ' + new URL(p.url()).pathname);
  log('names left: ' + (await p.inputValue('textarea[name=names]')).split('\n').length);
  await p.fill('#qd-prize', 'รางวัลที่ 1 · ทีวี');
  await p.click('[data-qd-mode=race]'); await p.fill('input[name=count]', '2');
  await p.click('[data-qd-spin]');
  await p.waitForSelector('dialog[open]', { timeout: 25000 });
  log('race winners: ' + (await p.locator('[data-qd-winners] li').allInnerTexts()).join(','));
  await p.click('[data-qd-close]');
  log('saved list: ' + (await p.locator('[data-qd-history] li').allInnerTexts()).join(' | '));
  await audit(p, 's4-draw-history');
  // reload: continues
  await p.reload(); await p.waitForTimeout(400);
  await audit(p, 's5-draw-reopened');
  log('after reload — saved list: ' + (await p.locator('[data-qd-history] li').count()) + ' · names: ' + (await p.inputValue('textarea[name=names]')).split('\n').length);
  await submit(p, '[data-qd-finish-form] button[type=submit]');
  log('after finish: ' + new URL(p.url()).pathname + ' · ' + await flash(p));
  await audit(p, 's6-draw-results');
  await p.context().close();
  const m = await page('employee.demo1', 390);
  await m.goto(B + '/draw'); await m.fill('textarea[name=names]', 'A\nB\nC\nD'); await m.waitForTimeout(300); await audit(m, 's7-draw-390');
  await m.context().close();
  const d = await page('employee.demo1', 1366, true);
  await d.goto(B + '/draw'); await d.fill('textarea[name=names]', 'หนึ่ง\nสอง\nสาม\nสี่'); await d.waitForTimeout(300); await audit(d, 's8-draw-dark');
  await d.context().close();
  const q = await page('employee.demo1');
  await visit(q, '/draw/quick', 's9-quick-still');
  await q.context().close();
}
fs.writeFileSync(OUT + '/log.txt', out.join('\n') + '\n');
await b.close();
