import { chromium } from 'playwright'; import fs from 'fs'; import { execFileSync } from 'child_process';
const B = 'http://127.0.0.1:8098'; const OUT = 'p0120'; fs.mkdirSync(OUT, { recursive: true });
const PW = 'Demo-c8d55255c554'; const ROOT = process.env.ROOT;
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
  const raw = await p.evaluate(() => (document.body.innerText.match(/\b(recruit|talent|common|form)\.[a-z_]+(\.[a-z_]+)*\b/g) || []).slice(0, 5));
  log(name + ': ' + new URL(p.url()).pathname + ' overflow=' + overflow + ' axe=' + JSON.stringify(v) + (raw.length ? ' RAWKEYS=' + raw : ''));
}
async function visit(p, path, name) { await p.goto(B + path); await audit(p, name); }
const flash = async (p) => (await p.locator('.alert').allInnerTexts()).map((s) => s.replace(/\s+/g, ' ').slice(0, 120)).join(' || ');
const submit = async (p, sel) => { await Promise.all([p.waitForNavigation(), p.click(sel)]); };
const tag = Date.now().toString(36).slice(-4);

// 1) manager asks for people
const m = await page('manager.demo1');
await visit(m, '/recruit/requisitions', '01-req-form');
await m.fill('#f-position_title', 'เจ้าหน้าที่บัญชี ' + tag); await m.fill('#f-justification', 'เพิ่มคนสำหรับงานปิดงบรายเดือน (ทดสอบ)');
await m.fill('#f-salary_min', '20000'); await m.fill('#f-salary_max', '28000');
await submit(m, 'form[action$="/recruit/requisitions"] button[type=submit]');
log('req: ' + await flash(m));
log('after req: ' + new URL(m.url()).pathname);
const reqPid = new URL(m.url()).pathname.startsWith('/recruit/r/') ? new URL(m.url()).pathname.split('/').pop() : (await m.locator('a[href*="/recruit/r/"]').first().getAttribute('href')).split('/').pop();
log('approvers: ' + execFileSync('php', [process.env.S + '/e2e/approveall.php', ROOT, reqPid]).toString().trim().replace(/\n/g, ', '));
await m.context().close();

// 2) HR: notice, posting, publish
const h = await page('hr.demo1');
await visit(h, '/recruit/settings', '02-settings');
if (await h.locator('#f-notice_text').count()) {
  const cur = await h.locator('#f-notice_text').inputValue();
  if (cur.trim() === '') { await h.fill('#f-notice_text', '(ตัวอย่าง DEMO — ไม่ใช่ข้อความทางกฎหมาย) บริษัทเก็บข้อมูลผู้สมัครเพื่อพิจารณาการจ้างงานเท่านั้น'); await submit(h, 'form[action$="/recruit/settings"] button[type=submit]'); log('settings: ' + await flash(h)); }
}
await visit(h, '/recruit/r/' + reqPid, '03-requisition');
await h.fill('#f-title', 'เจ้าหน้าที่บัญชี ' + tag); await h.fill('#f-description', 'ดูแลบัญชีเจ้าหนี้ ลูกหนี้ ปิดงบรายเดือน ใช้ Excel ได้ดี'); await h.fill('#f-location', 'ชลบุรี');
await submit(h, 'form[action$="/postings"] button[type=submit]');
log('posting: ' + await flash(h) + ' at ' + new URL(h.url()).pathname);
const postPid = new URL(h.url()).pathname.split('/').pop();
await submit(h, 'form[action$="/status"] button[value=published], form[action$="/status"] [name=to][value=published] ~ button, form[action$="/status"] button[type=submit]');
log('publish: ' + await flash(h));

// 3) applicant on a phone
const a = await page('employee.demo1', 390);
await visit(a, '/recruit/jobs', '04-jobs-mobile');
await visit(a, '/recruit/jobs/' + postPid + '/apply', '05-apply-start-mobile');
await a.context().close();

// 4) talent pool
await visit(h, '/talent', '06-talent-empty');
await visit(h, '/talent/new', '07-talent-new');
await h.selectOption('#f-source', 'walk_in');
await h.fill('#f-name', 'วิไล ใจดี ' + tag); await h.fill('#f-phone', '08' + String(Date.now()).slice(-8));
await h.fill('#f-positions', 'บัญชี, การเงิน'); await h.fill('#f-skills', 'Excel, SAP'); await h.fill('#f-experience_years', '4');
await h.fill('#f-consent_basis', 'เซ็นแบบฟอร์มยินยอม walk-in (ทดสอบ)');
await h.setInputFiles('#f-cv', { name: 'cv.pdf', mimeType: 'application/pdf', buffer: fs.readFileSync(ROOT + '/tests/fixtures/pdf/a.pdf') });
await submit(h, 'form[action$="/talent/new"] button[type=submit]');
log('talent: ' + await flash(h));
await audit(h, '08-talent-profile');
await h.selectOption('#f-posting', postPid);
await submit(h, 'form[action$="/link"] button[type=submit]');
log('put forward: ' + await flash(h) + ' → ' + new URL(h.url()).pathname);
await audit(h, '09-candidate-from-talent');
await visit(h, '/talent', '10-talent-list');
const hd = await page('hr.demo1', 390, true);
await visit(hd, '/talent', '11-talent-list-mobile-dark');
await hd.context().close();

// 5) resume builder (employee), print view
const r = await page('employee.demo1', 390);
await visit(r, '/talent/builder', '12-builder-mobile');
await r.fill('#f-name', 'สมชาย ทดสอบ'); await r.fill('#f-headline', 'ช่างซ่อมบำรุง ประสบการณ์ 6 ปี');
await r.fill('#f-summary', 'ถนัดงานไฟฟ้าโรงงานและ PLC ต้องการงานประจำในชลบุรี');
await r.fill('#rb-wt0', 'ช่างไฟฟ้า'); await r.fill('#rb-wo0', 'บริษัท ตัวอย่าง จำกัด'); await r.fill('#rb-wf0', '2019'); await r.fill('#rb-wd0', 'ดูแลระบบไฟฟ้าสายการผลิต\nลดเวลาหยุดเครื่อง 20%');
await r.fill('#rb-ed0', 'ปวส. ไฟฟ้ากำลัง'); await r.fill('#rb-es0', 'วิทยาลัยเทคนิคตัวอย่าง'); await r.fill('#rb-ey0', '2018');
await r.fill('#f-skills', 'ไฟฟ้า, PLC, งานเชื่อม'); await r.fill('#f-languages', 'ไทย (ภาษาแม่), อังกฤษ (พอใช้)');
await submit(r, 'form[action$="/talent/builder"] button[type=submit]');
await audit(r, '13-builder-result-mobile');
await r.setViewportSize({ width: 1366, height: 900 });
await r.emulateMedia({ media: 'print' });
await r.screenshot({ path: OUT + '/14-builder-print.png', fullPage: true });
const hidden = await r.evaluate(() => ['.sidebar', '.topbar', '.page-header', '.resume-form'].map((s) => { const el = document.querySelector(s); return s + '=' + (el ? getComputedStyle(el).display : 'none'); }).join(' '));
log('print view: ' + hidden);
await r.pdf?.({ path: OUT + '/14-builder.pdf', format: 'A4' }).catch((e) => log('pdf: ' + e.message.slice(0, 80)));
await r.context().close();
await h.context().close();
await b.close();
fs.writeFileSync(OUT + '/log.txt', out.join('\n') + '\n');
