import { chromium } from 'playwright'; import fs from 'fs';
const B = 'http://127.0.0.1:8098'; const OUT = 'p081'; fs.mkdirSync(OUT, { recursive: true });
const PW = 'Demo-c8d55255c554';
const axeSrc = fs.readFileSync('node_modules/axe-core/axe.min.js', 'utf8');
const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium_headless_shell-1194/chrome-linux/headless_shell' });
const out = []; const log = (...a) => { console.log(...a); out.push(a.join(' ')); };
async function page(user, vw = 1366, dark = false) {
  const ctx = await b.newContext({ viewport: { width: vw, height: 900 }, bypassCSP: true, colorScheme: dark ? 'dark' : 'light' });
  const p = await ctx.newPage();
  p.on('pageerror', (e) => log('pageerror ' + (user || 'guest') + ': ' + e.message));
  p.on('console', (m) => { if (m.type() === 'error') log('console ' + (user || 'guest') + ': ' + m.text()); });
  if (dark) await p.addInitScript(() => { try { localStorage.setItem('ros.theme', 'dark'); } catch (e) {} });
  if (user) {
    await p.goto(B + '/login'); await p.fill('#f-identifier', user); await p.fill('#f-password', PW);
    await Promise.all([p.waitForURL((u) => u.pathname !== '/login'), p.click('form[action$="/login"] button[type=submit]')]);
  }
  return p;
}
async function axe(p, name) {
  await p.addScriptTag({ content: axeSrc }).catch((e) => log('axe inject failed ' + e.message));
  const v = await p.evaluate(async () => (await window.axe.run(document, { runOnly: ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'] })).violations.map((v) => v.impact + ':' + v.id + '(' + v.nodes.length + ') ' + v.nodes.slice(0, 2).map((n) => n.target.join(' ')).join(' | ')));
  const raw = await p.evaluate(() => (document.body.innerText.match(/\b(home|settings|draw|nav|org|hub|config)\.[a-z_]+(\.[a-z_]+)*\b/g) || []).slice(0, 5));
  log('   axe=' + JSON.stringify(v) + (raw.length ? ' RAWKEYS=' + raw : ''));
}
async function visit(p, path, name) {
  const st = (await p.goto(B + path)).status();
  const overflow = await p.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  await p.screenshot({ path: OUT + '/' + name + '.png', fullPage: true });
  log(name + ': ' + st + ' at ' + new URL(p.url()).pathname + new URL(p.url()).search + ' overflow=' + overflow);
  await axe(p, name);
}
const flash = async (p) => (await p.locator('.alert').allInnerTexts()).map((s) => s.replace(/\s+/g, ' ').slice(0, 110)).join(' || ');

// 1) visitor, nothing opened yet
const g = await page(null);
await visit(g, '/', 'g1-home-default');
await g.goto(B + '/calc'); log('guest /calc closed →', new URL(g.url()).pathname + new URL(g.url()).search);

// 2) system admin opens tools, chooses apps, switches to dots
const sys = await page('sysadmin2.demo');
log('sys after login at', new URL(sys.url()).pathname);
await visit(sys, '/admin/settings/guest', 's1-guest-settings');
for (const c of ['calc', 'qr', 'crypto', 'pdf', 'draw']) await sys.check(`input[name="tools[]"][value=${c}]`);
for (const c of await sys.locator('input[name="home[]"]').evaluateAll((els) => els.map((e) => e.value))) {
  if (!['calc', 'qr', 'crypto', 'pdf', 'draw', 'leave', 'attendance', 'employees'].includes(c)) await sys.uncheck(`input[name="home[]"][value="${c}"]`);
}
await Promise.all([sys.waitForNavigation(), sys.click('form[action$="/admin/settings/guest"] button[type=submit]')]);
log('saved:', await flash(sys));
await sys.goto(B + '/admin/settings/appearance');
await sys.check('input[name=pattern][value=dots]');
await Promise.all([sys.waitForNavigation(), sys.click('form[data-theme-form] button[type=submit]:not([name=reset])')]);
log('appearance:', await flash(sys));
await visit(sys, '/', 's2-home-dots');

// 3) visitor again: launcher, tools, quick draw, sign-in-then-continue
const v = await page(null);
await visit(v, '/', 'g2-home-open');
await visit(v, '/calc', 'g3-calc');
await visit(v, '/qr', 'g4-qr');
await visit(v, '/draw/quick', 'g5-draw');
await v.fill('#f-names', 'สมชาย\nสมหญิง\nมานี\nมานะ\nปิติ'); await v.fill('#f-count', '2');
await Promise.all([v.waitForNavigation(), v.click('form[action$="/draw/quick"] button[type=submit]')]);
log('quick draw winners:', JSON.stringify(await v.locator('.quick-draw__list li').allInnerTexts()));
await v.screenshot({ path: OUT + '/g6-draw-result.png', fullPage: true }); await axe(v, 'g6');
await visit(v, '/pdf', 'g7-pdf');
const m = await page(null, 375);
await visit(m, '/', 'g8-home-375');
await visit(m, '/draw/quick', 'g9-draw-375');
const d = await page(null, 1366, true);
await visit(d, '/', 'g10-home-dark');
// locked app → sign in → continues to app
await v.goto(B + '/');
await Promise.all([v.waitForURL((u) => u.pathname === '/login'), v.click('a[href="/login?next=%2Fleave"]')]);
log('locked tile →', new URL(v.url()).pathname + new URL(v.url()).search);
await v.fill('#f-identifier', 'employee.demo1'); await v.fill('#f-password', PW);
await Promise.all([v.waitForURL((u) => u.pathname !== '/login'), v.click('form[action$="/login"] button[type=submit]')]);
log('after sign-in at', new URL(v.url()).pathname);

// 4) org levels + hub tile size
const hr = await page('sysadmin2.demo');
await visit(hr, '/admin/org', 's3-org-chart');
await visit(hr, '/admin/org?view=list', 's4-org-list');
const e = await page('employee.demo1');
await visit(e, '/', 'e1-hub');
const sizes = await e.evaluate(() => [...document.querySelectorAll('.app-tile')].map((t) => Math.round(t.getBoundingClientRect().height)));
log('tile heights', JSON.stringify([...new Set(sizes)]));
const req = await e.evaluate(() => { const r = document.querySelector('.app-tile__request'); return r ? Math.round(r.getBoundingClientRect().height) : null; });
log('request button height', req);
const em = await page('employee.demo1', 375);
await visit(em, '/', 'e2-hub-375');
const sizesM = await em.evaluate(() => [...document.querySelectorAll('.app-tile')].map((t) => Math.round(t.getBoundingClientRect().height)));
log('tile heights 375', JSON.stringify([...new Set(sizesM)]));
await b.close();
fs.writeFileSync(OUT + '/log.txt', out.join('\n'));
