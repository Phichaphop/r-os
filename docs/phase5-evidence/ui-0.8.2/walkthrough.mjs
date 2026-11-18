import { chromium } from 'playwright'; import fs from 'fs';
const B = 'http://127.0.0.1:8098'; const OUT = 'p082'; fs.mkdirSync(OUT, { recursive: true });
const PW = 'Demo-c8d55255c554'; const LE = '01M57N3V289SSGM1PK8DFBEW8D';
const axeSrc = fs.readFileSync('node_modules/axe-core/axe.min.js', 'utf8');
const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium_headless_shell-1194/chrome-linux/headless_shell' });
const out = []; const log = (...a) => { console.log(...a); out.push(a.join(' ')); };
async function page(user, vw = 1366, dark = false) {
  const ctx = await b.newContext({ viewport: { width: vw, height: 900 }, bypassCSP: true, colorScheme: dark ? 'dark' : 'light' });
  const p = await ctx.newPage();
  p.on('pageerror', (e) => log('pageerror ' + (user || 'guest') + ': ' + e.message));
  if (dark) await p.addInitScript(() => { try { localStorage.setItem('ros.theme', 'dark'); } catch (e) {} });
  if (user) { await p.goto(B + '/login'); await p.fill('#f-identifier', user); await p.fill('#f-password', PW);
    await Promise.all([p.waitForURL((u) => u.pathname !== '/login'), p.click('form[action$="/login"] button[type=submit]')]); }
  return p;
}
async function visit(p, path, name, shot = true) {
  const st = (await p.goto(B + path)).status();
  const overflow = await p.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  const hero = await p.evaluate(() => !!document.querySelector('.page-hero'));
  if (shot) await p.screenshot({ path: OUT + '/' + name + '.png', fullPage: true });
  await p.addScriptTag({ content: axeSrc }).catch(() => {});
  const v = await p.evaluate(async () => (await window.axe.run(document, { runOnly: ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'] })).violations.map((v) => v.impact + ':' + v.id + '(' + v.nodes.length + ') ' + v.nodes.slice(0, 2).map((n) => n.target.join(' ')).join(' | ')));
  const raw = await p.evaluate(() => (document.body.innerText.match(/\b(home|settings|org|hub|employees|leave|attendance|nav|common)\.[a-z_]+(\.[a-z_]+)*\b/g) || []).slice(0, 5));
  log(name + ': ' + st + ' hero=' + hero + ' overflow=' + overflow + ' axe=' + JSON.stringify(v) + (raw.length ? ' RAWKEYS=' + raw : ''));
}
const flash = async (p) => (await p.locator('.alert').allInnerTexts()).map((s) => s.replace(/\s+/g, ' ').slice(0, 120)).join(' || ');
// 1) build the HR example through the forms: HR Division + HR HQ + HR BR1
const sys = await page('sysadmin2.demo');
async function unit(code, name, level, parentCode, siteCode) {
  await sys.goto(B + '/admin/org/department/' + LE + '/new');
  await sys.fill('#f-code', code); await sys.fill('#f-name_th', name);
  await sys.selectOption('#f-level', level);
  if (parentCode) { const v = await sys.locator('#f-parent option').evaluateAll((os, c) => (os.find((o) => o.textContent.includes(' ' + c + ' ')) || {}).value, parentCode); await sys.selectOption('#f-parent', v); }
  if (siteCode) { const v = await sys.locator('#f-site option').evaluateAll((os, c) => (os.find((o) => o.textContent.includes(c)) || {}).value, siteCode); await sys.selectOption('#f-site', v); }
  await Promise.all([sys.waitForNavigation(), sys.click('main form[action*="/admin/org/department/"] button[type=submit]')]);
  log('unit ' + code + ':', new URL(sys.url()).pathname, await flash(sys));
}
await unit('HRDIV', 'HR Division', 'division', null, null);
await unit('HR-HQ', 'HR สำนักงานใหญ่', 'section', 'HRDIV', 'HQ');
await unit('HR-BR1', 'HR สาขา 1', 'section', 'HRDIV', 'BR1');
await visit(sys, '/admin/org?le=' + LE, 's1-org-chart');
await visit(sys, '/admin/org?view=list', 's2-org-list');
for (const [path, name] of [['/', 's3-home'], ['/admin/users', 's4-users'], ['/admin/settings/guest', 's5-guest'], ['/admin/settings/appearance', 's6-appearance'], ['/admin/config', 's7-config'], ['/admin/modules', 's8-modules'], ['/approvals', 's9-approvals']]) await visit(sys, path, name);
const e = await page('employee.demo1');
for (const [path, name] of [['/', 'e1-home'], ['/leave', 'e2-leave'], ['/attendance', 'e3-attendance'], ['/calc', 'e4-calc'], ['/qr', 'e5-qr'], ['/pdf', 'e6-pdf'], ['/draw', 'e7-draw'], ['/me/profile', 'e8-profile'], ['/requests', 'e9-requests'], ['/calendars', 'e10-calendars']]) await visit(e, path, name);
const hr = await page('hr.demo1');
for (const [path, name] of [['/employees', 'h1-employees'], ['/attendance/periods', 'h2-periods']]) await visit(hr, path, name);
const g = await page(null);
await visit(g, '/', 'g1-guest-home');
const m = await page('employee.demo1', 375);
await visit(m, '/', 'm1-home-375'); await visit(m, '/leave', 'm2-leave-375');
const ms = await page('sysadmin2.demo', 375);
await visit(ms, '/admin/org?le=' + LE, 'm3-org-375');
const gm = await page(null, 375); await visit(gm, '/', 'm4-guest-375');
const d = await page('employee.demo1', 1366, true);
await visit(d, '/', 'd1-home-dark'); await visit(d, '/leave', 'd2-leave-dark');
await b.close();
fs.writeFileSync(OUT + '/log.txt', out.join('\n'));
