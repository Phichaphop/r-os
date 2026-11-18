import { chromium } from 'playwright'; import fs from 'fs';
const B = 'http://127.0.0.1:8098'; const OUT = 'p090'; fs.mkdirSync(OUT, { recursive: true });
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
  const raw = await p.evaluate(() => (document.body.innerText.match(/\b(shifts|overtime|attendance)\.[a-z_]+(\.[a-z_]+)*\b/g) || []).slice(0, 5));
  log(name + ': ' + new URL(p.url()).pathname + ' overflow=' + overflow + ' axe=' + JSON.stringify(v) + (raw.length ? ' RAWKEYS=' + raw : ''));
}
async function visit(p, path, name) { await p.goto(B + path); await audit(p, name); }
const flash = async (p) => (await p.locator('.alert').allInnerTexts()).map((s) => s.replace(/\s+/g, ' ').slice(0, 120)).join(' || ');
const submit = async (p, sel) => { await Promise.all([p.waitForNavigation(), p.click(sel)]); };

// 1) HR creates patterns (morning + overnight) and a roster through the forms
const hr = await page('hr.demo1');
await visit(hr, '/shifts/admin', 's1-admin-empty');
for (const [code, name, s, e, tone] of [['E', 'กะเช้าตรู่ (ทดสอบ)', '05:00', '14:00', 'morning'], ['G', 'กะยามดึก (ทดสอบ)', '21:00', '06:00', 'night']]) {
  await hr.goto(B + '/shifts/admin');
  await hr.click('#pt-form > summary');
  await hr.fill('#pt-code', code); await hr.fill('#pt-name', name); await hr.fill('#pt-start', s); await hr.fill('#pt-end', e); await hr.selectOption('#pt-tone', tone);
  await submit(hr, 'form[action$="/shifts/admin/pattern"] button[type=submit]');
  log('pattern ' + code + ':', await flash(hr));
}
await hr.goto(B + '/shifts/admin');
await hr.click('text=สร้างตารางกะใหม่');
await hr.fill('#ro-name', 'ฝ่ายขาย สาขา 1 (ทดสอบ)'); await hr.fill('#ro-start', '2026-10-26'); await hr.fill('#ro-end', '2026-11-08');
await submit(hr, 'form[action$="/shifts/admin/roster"] button[type=submit]');
log('roster:', new URL(hr.url()).pathname, await flash(hr));
const rosterUrl = new URL(hr.url()).pathname;
await audit(hr, 's2-grid-empty');
// fill: everyone M Mon–Fri, then copy week
const boxes = hr.locator('input[name="employees[]"]');
const n = await boxes.count(); for (let i = 0; i < Math.min(n, 4); i++) await boxes.nth(i).check();
await hr.selectOption('#f-pattern', { label: 'M' });
await submit(hr, 'form[action$="/fill"] button[type=submit]');
log('fill:', await flash(hr));
await submit(hr, 'form[action$="/copy"] button[type=submit]');
log('copy:', await flash(hr));
await audit(hr, 's3-grid-filled');
await submit(hr, 'form[action$="/publish"] button[type=submit]');
log('publish:', await flash(hr));
await audit(hr, 's4-roster-published');
// employee view + swap form
const emp = await page('employee.demo1');
await visit(emp, '/shifts', 'e1-my-shifts');
await visit(emp, '/attendance', 'e2-attendance-month');
// OT
await visit(emp, '/overtime', 'o1-ot-index');
await emp.fill('#f-work_date', '2026-10-28'); await emp.fill('#f-start', '15:30'); await emp.fill('#f-end', '18:00'); await emp.fill('#f-reason', 'ปิดยอดขายสิ้นเดือน (ทดสอบ)');
await submit(emp, 'form[action$="/overtime"] button[type=submit]');
log('ot submit:', new URL(emp.url()).pathname, await flash(emp));
await audit(emp, 'o2-ot-request');
await emp.goto(B + '/overtime'); await emp.fill('#f-work_date', '2026-10-28'); await emp.fill('#f-start', '14:00'); await emp.fill('#f-end', '16:00'); await emp.fill('#f-reason', 'ทับกะ');
await submit(emp, 'form[action$="/overtime"] button[type=submit]');
log('ot in shift error:', JSON.stringify(await emp.locator('.field__error').allInnerTexts()));
const mgr = await page('manager.demo1');
await visit(mgr, '/approvals', 'm1-approvals');
await visit(mgr, '/overtime', 'm2-ot-team');
await visit(hr, '/overtime/admin', 'h1-ot-admin');
await visit(hr, '/overtime/report', 'h2-ot-report');
await visit(hr, '/overtime/verify', 'h3-ot-verify');
const m = await page('employee.demo1', 375); await visit(m, '/shifts', 'x1-shifts-375'); await visit(m, '/overtime', 'x2-ot-375');
const hm = await page('hr.demo1', 375); await visit(hm, rosterUrl, 'x3-grid-375');
const d = await page('hr.demo1', 1366, true); await visit(d, rosterUrl, 'x4-grid-dark');
await b.close();
fs.writeFileSync(OUT + '/log.txt', out.join('\n'));
