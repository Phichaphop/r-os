import { chromium } from 'playwright'; import fs from 'fs';
const B = 'http://127.0.0.1:8098'; const OUT = 'p0111'; fs.mkdirSync(OUT, { recursive: true });
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
// ---- quick draw ----
{
  const p = await page('employee.demo1');
  await visit(p, '/draw/quick', 'q1-quick-empty');
  await p.fill('textarea[name=names]', ['สมชาย', 'สมหญิง', 'มานะ', 'มานี', 'ปิติ', 'ชูใจ', 'วีระ', 'ดวงใจ', 'อนันต์', 'กมล'].join('\n'));
  await p.waitForTimeout(400);
  await audit(p, 'q2-quick-wheel');
  log('tally: ' + await p.locator('[data-qd-tally]').innerText());
  await p.click('[data-qd-canvas]');
  await p.waitForTimeout(2500); await shot(p, 'q3-quick-spinning');
  await p.waitForSelector('dialog[open]', { timeout: 20000 });
  await audit(p, 'q4-quick-winner');
  const w = await p.locator('[data-qd-winners] li').allInnerTexts(); log('winner: ' + w.join(','));
  await p.click('[data-qd-take-out]');
  const left = (await p.inputValue('textarea[name=names]')).split('\n').length; log('names after take-out: ' + left + ' (winner gone: ' + !(await p.inputValue('textarea[name=names]')).includes(w[0]) + ')');
  await p.click('[data-qd-mode=race]'); await p.waitForTimeout(300);
  await audit(p, 'q5-quick-race-idle');
  await p.check('[data-qd-remove]'); await p.fill('input[name=count]', '3');
  await p.click('[data-qd-spin]');
  await p.waitForSelector('dialog[open]', { timeout: 25000 });
  const w3 = await p.locator('[data-qd-winners] li').allInnerTexts(); log('race winners: ' + w3.join(','));
  log('take-out hidden when auto: ' + await p.locator('[data-qd-take-out]').isHidden());
  log('names after auto: ' + (await p.inputValue('textarea[name=names]')).split('\n').length);
  await shot(p, 'q6-quick-race-winner');
  await p.click('[data-qd-close]');
  log('history: ' + (await p.locator('[data-qd-history] li').allInnerTexts()).join(' | '));
  await p.context().close();
  const m = await page('employee.demo1', 390);
  await m.goto(B + '/draw/quick'); await m.fill('textarea[name=names]', 'A\nB\nC\nD\nE'); await m.waitForTimeout(300); await audit(m, 'q7-quick-390');
  await m.context().close();
  const d = await page('employee.demo1', 1366, true);
  await d.goto(B + '/draw/quick'); await d.fill('textarea[name=names]', 'หนึ่ง\nสอง\nสาม\nสี่\nห้า\nหก'); await d.waitForTimeout(300); await audit(d, 'q8-quick-dark');
  await d.context().close();
}
// ---- full draw: sequence that keeps names ----
{
  const p = await page('employee.demo1');
  await visit(p, '/draw', 'f1-draw-index');
  await p.fill('#f-name', 'ใครตอบคำถาม (ทดสอบ)');
  await p.check('input[name=prize_mode][value=sequence]');
  await p.fill('input[name=seq_count]', '5');
  await p.uncheck('input[type=checkbox][name=no_repeat]');
  await p.fill('#dn-names', ['ต้น', 'แพร', 'บอส', 'มิว', 'เจ'].join('\n'));
  await submit(p, 'form[action$="/draw"] button[type=submit]');
  log('created: ' + await flash(p));
  await audit(p, 'f2-draw-edit-ready');
  await p.check('input[name=law_ack]');
  await submit(p, 'form[action$="/lock"] button[type=submit]');
  log('after lock url: ' + new URL(p.url()).pathname);
  await p.waitForTimeout(300);
  await audit(p, 'f3-draw-show-wheel');
  await p.click('[data-draw-canvas]');
  await p.waitForSelector('[data-draw-overlay]:not([hidden]) .draw-reveal', { timeout: 20000 });
  await audit(p, 'f4-draw-show-winner');
  log('reveal: ' + (await p.locator('.draw-reveal').innerText()).replace(/\s+/g, ' '));
  await p.click('.draw-reveal .btn');
  log('finish visible: ' + await p.locator('[data-draw-finish]').isVisible());
  // switch to race and draw again
  await submit(p, 'button[name=mode][value=race]');
  await p.waitForTimeout(300); await audit(p, 'f5-draw-show-race');
  await p.fill('[data-draw-count]', '2');
  await p.keyboard.press('Space').catch(() => {});
  await p.locator('body').click({ position: { x: 5, y: 5 } }).catch(() => {});
  await p.click('[data-draw-go]').catch(() => {});
  await p.waitForSelector('[data-draw-overlay]:not([hidden]) .draw-reveal', { timeout: 25000 });
  await shot(p, 'f6-draw-race-winner');
  await p.click('.draw-reveal .btn');
  log('board: ' + (await p.locator('[data-draw-board] li').allInnerTexts()).join(' | '));
  await submit(p, 'form[action$="/confirm"] button[type=submit]');
  log('after confirm url: ' + new URL(p.url()).pathname + ' · ' + await flash(p));
  await audit(p, 'f7-draw-results');
  await p.context().close();
}
fs.writeFileSync(OUT + '/log.txt', out.join('\n') + '\n');
await b.close();
