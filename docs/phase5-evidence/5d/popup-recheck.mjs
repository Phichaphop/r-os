import { chromium } from 'playwright'; import fs from 'fs';
const B = 'http://127.0.0.1:8098'; const OUT = 'p0110'; fs.mkdirSync(OUT, { recursive: true });
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
const local = () => { const d = new Date(Date.now() + 7 * 3600 * 1000 - 60000); return d.toISOString().slice(0, 16); };
const hr = await page('hr.demo1');
await hr.goto(B + '/announcements/admin');
await hr.fill('#na-cat', 'ข่าวบริษัท'); await hr.fill('#na-title', 'ยินดีต้อนรับสู่ระบบ R-OS (ทดสอบ)'); await hr.fill('#na-body', 'ทดลองประกาศแบบเด้งตอนเข้าสู่ระบบ 5 วินาที');
await hr.selectOption('#na-pop', '5'); await hr.fill('#na-from', local());
await submit(hr, 'form[action$="/announcements/admin"] button[type=submit]');
await submit(hr, 'form[action$="/action"] button[type=submit]');
log('published:', await flash(hr));
// login lands on the home page: the pop-up must be open right away and close itself after 5 s
const ctx = await b.newContext({ viewport: { width: 1366, height: 900 }, bypassCSP: true });
const p = await ctx.newPage();
p.on('pageerror', (e) => log('pageerror: ' + e.message));
await p.goto(B + '/login'); await p.fill('#f-identifier', 'manager.demo1'); await p.fill('#f-password', PW);
await Promise.all([p.waitForURL((u) => u.pathname !== '/login'), p.click('form[action$="/login"] button[type=submit]')]);
await p.waitForTimeout(400);
log('popup open after login:', await p.locator('dialog[data-home-popup]').evaluate((d) => d.open).catch(() => 'none'));
await audit(p, 'a2-home-popup');
await p.waitForTimeout(5800);
log('popup open after 5 s:', await p.locator('dialog[data-home-popup]').evaluate((d) => d.open).catch(() => 'none'));
await p.reload();
log('popup on next visit:', await p.locator('dialog[data-home-popup]').count());
fs.appendFileSync(OUT + '/log.txt', '\n--- pop-up recheck ---\n' + out.join('\n') + '\n');
await b.close();
