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
async function pageGeo(user, lat, lng, acc = 20, vw = 390) {
  const ctx = await b.newContext({ viewport: { width: vw, height: 860 }, bypassCSP: true, geolocation: { latitude: lat, longitude: lng, accuracy: acc }, permissions: ['geolocation'] });
  const p = await ctx.newPage();
  p.on('pageerror', (e) => log('pageerror ' + user + ': ' + e.message));
  await p.goto(B + '/login'); await p.fill('#f-identifier', user); await p.fill('#f-password', PW);
  await Promise.all([p.waitForURL((u) => u.pathname !== '/login'), p.click('form[action$="/login"] button[type=submit]')]);
  return { p, ctx };
}
async function approveFirst(p, tag) {
  await p.goto(B + '/approvals');
  const link = p.locator('main a[href*="/approvals/0"]').first();
  if (!(await link.count())) { log(tag + ': nothing to approve'); return; }
  await Promise.all([p.waitForNavigation(), link.click()]);
  await submit(p, 'button[name="decision"][value="approve"]');
  if (p.url().includes('reauth')) {
    await p.fill('#f-password', PW); await submit(p, 'form[action$="/reauth"] button[type=submit]');
    if (await p.locator('button[name="decision"][value="approve"]').count()) { await submit(p, 'button[name="decision"][value="approve"]'); }
  }
  log(tag + ':', await flash(p));
}
const notice = 'บริษัทเก็บพิกัดเฉพาะตอนที่พนักงานกดลงเวลาเข้า-ออก เพื่อยืนยันการทำงานนอกสถานที่ ดูได้เฉพาะพนักงาน หัวหน้า และ HR ระหว่างตรวจ เก็บตามระยะเวลาที่อนุมัติ (ทดสอบ)';

// 1) HR writes the GPS policy; another HR approves
const hr = await page('hr.demo1');
const head = await page('hr.head1');
await approveFirst(head, 'leftover approve');
await visit(hr, '/gps/admin', 'g1-gps-admin');
if ((await flash(hr)).includes('ยังไม่มีนโยบาย')) {
await hr.click('section[aria-labelledby="po-h"] details summary');
await hr.selectOption('#po-out', 'flag'); await hr.fill('#po-acc', '100'); await hr.fill('#po-off', '4'); await hr.fill('#po-notice', notice);
await hr.fill('#po-src', 'นโยบายความเป็นส่วนตัวพนักงาน (ทดสอบ)'); await hr.fill('#po-reason', 'เปิดใช้ทดสอบ');
await submit(hr, 'form[action$="/gps/admin/policy"] button[type=submit]');
log('gps policy:', await flash(hr));
await approveFirst(head, 'gps policy approve');
}

// 2) employee: notice → check in inside the area → check out far away (held)
const { p: emp, ctx: ectx } = await pageGeo('employee.demo1', 13.7565, 100.5019, 18);
await visit(emp, '/gps', 'e1-gps-notice');
await submit(emp, 'form[action$="/gps/notice"] button[type=submit]');
await audit(emp, 'e2-gps-ready');
await Promise.all([emp.waitForNavigation(), emp.click('button[data-direction="in"]')]);
log('check in:', await flash(emp));
await ectx.setGeolocation({ latitude: 13.8000, longitude: 100.5500, accuracy: 25 });
await Promise.all([emp.waitForNavigation(), emp.click('button[data-direction="out"]')]);
log('check out far:', await flash(emp));
await audit(emp, 'e3-gps-list');
await Promise.all([emp.waitForNavigation(), emp.locator('main table a').first().click()]);
await audit(emp, 'e4-gps-detail-map');

// 3) line manager reviews
const mgr = await page('manager.demo1');
await visit(mgr, '/gps/review', 'm1-gps-review');
await Promise.all([mgr.waitForNavigation(), mgr.locator('main table a').first().click()]);
await audit(mgr, 'm2-gps-punch-review');
await mgr.fill('#f-review_note', 'ไปพบลูกค้าตามที่แจ้ง (ทดสอบ)');
await submit(mgr, 'button[name="action"][value="approve"]');
log('review:', await flash(mgr));
await visit(hr, '/gps/report', 'h1-gps-report');
log('attendance rows from gps:', execFileSync('mysql', ['-N', 'ros_fresh4b', '-e', "SELECT COUNT(*) FROM att_raw_punches WHERE source='gps'"]).toString().trim());

// 4) e-ID: HR issues cards, uploads a photo
await visit(hr, '/eid/admin', 'i1-eid-admin');
await submit(hr, 'form[action$="/eid/admin/issue-missing"] button[type=submit]');
log('issue missing:', await flash(hr));
const d4 = execFileSync('mysql', ['-N', 'ros_fresh4b', '-e', "SELECT public_id FROM employees WHERE employee_code='D1-0004'"]).toString().trim();
await hr.goto(B + '/eid/admin/e/' + d4);
await hr.setInputFiles('#ph-file', 'avatar.png');
await submit(hr, 'form[enctype="multipart/form-data"] button[type=submit]');
log('photo:', await flash(hr));
await audit(hr, 'i2-eid-employee');

// 5) employee shows the card (button → card → full screen)
await visit(emp, '/eid', 'c1-eid-home');
await Promise.all([emp.waitForNavigation(), emp.click('main a[href$="/eid/card"]')]);
await audit(emp, 'c2-eid-card-390');
await emp.click('[data-eid-full]'); await emp.waitForTimeout(400);
await audit(emp, 'c3-eid-fullscreen');
const empD = await page('employee.demo1', 1366, true);
await visit(empD, '/eid/card', 'c4-eid-card-dark');

// 6) verify (guard / HR)
const url = execFileSync('php', ['eidtoken.php', '../fresh4b', 'D1-0004']).toString().trim();
await hr.goto(url); await audit(hr, 'v1-eid-verify-valid');
log('verify:', (await hr.locator('.alert').first().innerText()).replace(/\s+/g, ' '));
await hr.goto(url.slice(0, -3) + 'abc'); log('verify tampered:', (await hr.locator('.alert').first().innerText()).replace(/\s+/g, ' '));

// 7) wallet: rules (HR) → finance checker approves → top-up (HR) → finance checker approves
await visit(hr, '/eid/wallet/admin', 'w1-wallet-admin');
await hr.click('section[aria-labelledby="ru-h"] details summary');
await hr.fill('#ru-cur', 'THB'); await hr.fill('#ru-top', '500'); await hr.fill('#ru-bal', '1000'); await hr.fill('#ru-low', '100');
await hr.fill('#ru-purpose', 'สวัสดิการค่าอาหารโรงอาหาร (ทดสอบ)'); await hr.fill('#ru-src', 'ประกาศสวัสดิการ (ทดสอบ)'); await hr.fill('#ru-reason', 'เริ่มใช้');
await submit(hr, 'form[action$="/eid/wallet/admin/rules"] button[type=submit]');
log('wallet rules:', await flash(hr));
const fin = await page('finance.checker');
await approveFirst(fin, 'wallet rules approve');
await hr.goto(B + '/eid/wallet/admin');
const boxes = hr.locator('input[name="employees[]"]');
log('people:', await boxes.count());
await boxes.nth(0).check(); await boxes.nth(1).check();
await hr.fill('#tp-amount', '400'); await hr.fill('#tp-reason', 'สวัสดิการอาหาร พ.ย. (ทดสอบ)');
await submit(hr, 'form[action$="/eid/wallet/admin/topup"] button[type=submit]');
log('topup:', await flash(hr));
await audit(hr, 'w2-topup-pending');
await approveFirst(fin, 'topup approve');
await hr.reload(); await audit(hr, 'w3-topup-approved');
await visit(hr, '/eid/wallet/admin', 'w4-wallet-recon');
await visit(emp, '/eid', 'c5-eid-wallet');
await visit(fin, '/finance/requests', 'f1-posting-request');

fs.writeFileSync(OUT + '/log.txt', out.join('\n') + '\n');
await b.close();
