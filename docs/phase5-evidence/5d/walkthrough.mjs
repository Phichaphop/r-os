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
const local = () => { const d = new Date(Date.now() + 7 * 3600 * 1000 - 60000); return d.toISOString().slice(0, 16); };
const hr = await page('hr.demo1');
const head = await page('hr.head1');
await approveFirst(head, 'leftover');

// 1) M02 forms
await visit(hr, '/forms/admin', 'f1-forms-admin');
await hr.fill('#nf-code', 'HR-01'); await hr.fill('#nf-cat', 'บุคคล'); await hr.fill('#nf-title', 'แบบฟอร์มขอหนังสือรับรองการทำงาน (ทดสอบ)');
await hr.fill('#nf-desc', 'ใช้ขอหนังสือรับรองเงินเดือน/การทำงาน');
await submit(hr, 'form[action$="/forms/admin"] button[type=submit]');
log('form created:', await flash(hr));
await hr.setInputFiles('#up-file', 'form.pdf'); await hr.fill('#up-note', 'ฉบับแรก');
await submit(hr, 'form[action$="/upload"] button[type=submit]');
log('uploaded:', await flash(hr));
await submit(hr, 'button[name="action"][value="submit"]');
log('submitted:', await flash(hr));
await audit(hr, 'f2-form-manage');
await approveFirst(head, 'form approve');
const emp = await page('employee.demo1', 390);
await visit(emp, '/forms', 'f3-forms-list-390');
await Promise.all([emp.waitForNavigation(), emp.locator('a.form-item__title').first().click()]);
await audit(emp, 'f4-form-show-390');
const dl = await emp.context().request.get(B + new URL(emp.url()).pathname + '/file');
log('download:', dl.status(), dl.headers()['content-type'], (await dl.body()).slice(0, 5).toString());

// 2) M19 announcements: normal with pop-up + ack, and an important one (approval)
await visit(hr, '/announcements/admin', 'a1-ann-admin');
await hr.fill('#na-cat', 'อาคาร'); await hr.fill('#na-title', 'ปิดปรับปรุงลิฟต์ฝั่งตะวันออก (ทดสอบ)');
await hr.fill('#na-body', 'วันเสาร์นี้ 08:00–17:00 ปิดปรับปรุงลิฟต์ฝั่งตะวันออก\n\nกรุณาใช้ลิฟต์ฝั่งตะวันตกหรือบันได');
await hr.selectOption('#na-pop', '5'); await hr.fill('#na-from', local()); await hr.check('input[name="requires_ack"]'); await hr.check('input[name="pinned"]');
await hr.fill('#na-link', 'https://example.org/');
await submit(hr, 'form[action$="/announcements/admin"] button[type=submit]');
log('ann created:', await flash(hr));
await submit(hr, 'form[action$="/action"] button[type=submit]');
log('ann publish:', await flash(hr));
const e2 = await page('employee.demo1', 1366);
await e2.goto(B + '/');
await e2.waitForTimeout(500);
log('popup open:', await e2.locator('dialog[data-home-popup]').evaluate((d) => d.open).catch(() => 'none'));
await audit(e2, 'a2-home-popup');
await e2.waitForTimeout(5600);
log('popup after 5s:', await e2.locator('dialog[data-home-popup]').evaluate((d) => d.open).catch(() => 'none'));
await visit(e2, '/announcements', 'a3-ann-board');
await Promise.all([e2.waitForNavigation(), e2.locator('.ann-item__title a').first().click()]);
await audit(e2, 'a4-ann-read');
await submit(e2, 'form[action$="/ack"] button[type=submit]');
log('ack:', await flash(e2));
await submit(e2, 'button[name="reaction"][value="thanks"]');
await hr.goto(B + '/announcements/admin'); await Promise.all([hr.waitForNavigation(), hr.locator('main table a').first().click()]);
await audit(hr, 'a5-ann-engagement');
await hr.goto(B + '/announcements/admin');
await hr.fill('#na-cat', 'HR'); await hr.fill('#na-title', 'ประกาศวันหยุดประจำปี 2570 (ทดสอบ)'); await hr.fill('#na-body', 'รายละเอียดตามไฟล์แนบ'); await hr.selectOption('#na-prio', 'important'); await hr.fill('#na-from', local());
await submit(hr, 'form[action$="/announcements/admin"] button[type=submit]');
await submit(hr, 'form[action$="/action"] button[type=submit]');
log('important:', await flash(hr));
await approveFirst(head, 'important approve');

// 3) M40 controlled document + acknowledgement
await visit(hr, '/policies/admin', 'd1-dc-admin');
await hr.fill('#nd-code', 'POL-IT-01'); await hr.fill('#nd-title', 'นโยบายการใช้คอมพิวเตอร์และอีเมล (ทดสอบ)'); await hr.fill('#nd-review', '12');
await submit(hr, 'form[action$="/policies/admin"] button[type=submit]');
log('doc created:', await flash(hr));
await hr.setInputFiles('#vu-file', 'form.pdf'); await hr.fill('#vu-sum', 'ฉบับแรกของนโยบาย');
await submit(hr, 'form[action$="/upload"] button[type=submit]');
log('version uploaded:', await flash(hr));
await submit(hr, 'button[name="action"][value="submit"]');
await approveFirst(head, 'doc approve');
await visit(emp, '/policies', 'd2-dc-mine-390');
await Promise.all([emp.waitForNavigation(), emp.locator('a.form-item__title').first().click()]);
await audit(emp, 'd3-dc-read-390');
const docUrl = new URL(emp.url()).pathname;
const pdf = await emp.context().request.get(B + docUrl + '/file');
log('opened file:', pdf.status(), pdf.headers()['content-type']);
await emp.reload();
await emp.check('input[name="confirm"]');
await submit(emp, 'form[action$="/ack"] button[type=submit]');
log('doc ack:', await flash(emp));
await audit(emp, 'd4-dc-acked-390');
await hr.goto(B + '/policies/admin'); await Promise.all([hr.waitForNavigation(), hr.locator('main table a').first().click()]);
await audit(hr, 'd5-dc-report');

// 4) M47 directory
const e3 = await page('employee.demo1', 1366);
await e3.goto(B + '/directory');
await e3.fill('#dir-q', 'D1'); await e3.waitForTimeout(900);
log('type-ahead cards:', await e3.locator('.dir-card').count());
await audit(e3, 'r1-directory-search');
await visit(e3, '/directory?view=team', 'r2-directory-team');
const e4 = await page('employee.demo1', 1366, true);
await visit(e4, '/directory?q=D1', 'r3-directory-dark');
await visit(hr, '/directory/admin', 'r4-directory-admin');
await visit(e2, '/', 'h1-home-sections');

fs.writeFileSync(OUT + '/log.txt', out.join('\n') + '\n');
await b.close();
