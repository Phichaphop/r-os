import { chromium } from 'playwright'; import fs from 'fs';
const B = 'http://127.0.0.1:8098'; const OUT = 'p0100'; fs.mkdirSync(OUT, { recursive: true });
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
import { execFileSync } from 'child_process';
// recheck after D5C-01 / D5C-02 fixes: photo upload via the form, card status chip contrast in dark mode, GPS map axis
const hr = await page('hr.demo1');
const d4 = execFileSync('mysql', ['-N', 'ros_fresh4b', '-e', "SELECT public_id FROM employees WHERE employee_code='D1-0004'"]).toString().trim();
await hr.goto(B + '/eid/admin/e/' + d4);
await hr.setInputFiles('#ph-file', 'avatar.png');
await submit(hr, 'form[enctype="multipart/form-data"] button[type=submit]');
log('photo (after fix):', await flash(hr));
await audit(hr, 'i2-eid-employee');
const emp = await page('employee.demo1', 390);
await visit(emp, '/eid/card', 'c2-eid-card-390');
const empD = await page('employee.demo1', 1366, true);
await visit(empD, '/eid/card', 'c4-eid-card-dark');
const pid = execFileSync('mysql', ['-N', 'ros_fresh4b', '-e', "SELECT public_id FROM gps_punches ORDER BY id DESC LIMIT 1"]).toString().trim();
await visit(emp, '/gps/p/' + pid, 'e4-gps-detail-map');
fs.appendFileSync(OUT + '/log.txt', '\n--- recheck after fixes ---\n' + out.join('\n') + '\n');
await b.close();
