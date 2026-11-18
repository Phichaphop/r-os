import { chromium } from 'playwright'; import fs from 'fs'; import { execFileSync } from 'child_process';
const B = 'http://127.0.0.1:8098'; const OUT = 'p0130'; fs.mkdirSync(OUT, { recursive: true });
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
  const raw = await p.evaluate(() => (document.body.innerText.match(/\b(jd|certs|celebrate|common|form)\.[a-z_]+(\.[a-z_]+)*\b/g) || []).slice(0, 5));
  log(name + ': ' + new URL(p.url()).pathname + ' overflow=' + overflow + ' axe=' + JSON.stringify(v) + (raw.length ? ' RAWKEYS=' + raw : ''));
}
async function visit(p, path, name) { await p.goto(B + path); await audit(p, name); }
const flash = async (p) => (await p.locator('.alert').allInnerTexts()).map((s) => s.replace(/\s+/g, ' ').slice(0, 120)).join(' || ');
const submit = async (p, sel) => { await Promise.all([p.waitForNavigation(), p.click(sel)]); };
const tag = Date.now().toString(36).slice(-4);
const php = (...a) => execFileSync('php', a).toString().trim();

// ---- M56 JD
const h = await page('hr.demo1');
await visit(h, '/jd/admin', '01-jd-admin');
await h.fill('#f-code', 'JD-SALES-' + tag); await h.fill('#f-position_title', 'พนักงานขาย');
await submit(h, 'form[action$="/jd/admin"] button[type=submit]');
log('jd create: ' + await flash(h) + ' → ' + new URL(h.url()).pathname);
const profile = new URL(h.url()).pathname;
await h.fill('#f-th_summary', 'ขายสินค้าและดูแลลูกค้าในพื้นที่ที่รับผิดชอบให้ได้ตามเป้าหมาย');
await h.fill('#f-th_duties', 'พบลูกค้าตามแผนการเยี่ยม\nจัดทำใบเสนอราคาและติดตามการสั่งซื้อ\nติดตามการชำระเงิน\nรายงานยอดขายประจำสัปดาห์');
await h.fill('#f-th_qualifications', 'ปวส. หรือปริญญาตรีทุกสาขา\nมีใบขับขี่รถยนต์');
await h.fill('#f-th_kpis', 'ยอดขายตามเป้ารายเดือน\nจำนวนลูกค้าใหม่ต่อเดือน');
await h.fill('#f-en_summary', 'Sell and look after customers in the assigned area');
await h.fill('#f-en_duties', 'Visit customers as planned\nPrepare quotations');
await submit(h, 'form[action$="/draft"] button[type=submit]');
log('jd draft: ' + await flash(h));
await audit(h, '02-jd-manage-draft');
await submit(h, 'form[action*="/jd/admin/v/"] button[value=submit]');
log('jd submit: ' + await flash(h));
const vpid = await h.locator('a[href^="/jd/v/"]').first().getAttribute('href');
log('jd approvers: ' + php(process.env.S + '/e2e/approveall.php', ROOT, vpid.split('/').pop()).replace(/\n/g, ', '));
await visit(h, profile, '03-jd-report-pending');

const e = await page('employee.demo1', 390);
await visit(e, '/jd', '04-jd-mine-mobile');
await e.click('a[href^="/jd/v/"]'); await e.waitForLoadState();
await audit(e, '05-jd-read-mobile');
await e.check('input[name=confirm]');
await submit(e, 'form[action$="/ack"] button[type=submit]');
log('jd ack: ' + await flash(e));
await audit(e, '06-jd-acked-mobile');
await e.goto(B + vpid);
await e.setViewportSize({ width: 1366, height: 900 });
await e.emulateMedia({ media: 'print' });
await e.screenshot({ path: OUT + '/07-jd-print.png', fullPage: true });
await e.pdf({ path: OUT + '/07-jd.pdf', format: 'A4' }).catch((x) => log('pdf: ' + x.message.slice(0, 80)));
await e.emulateMedia({ media: 'screen' });
await visit(h, profile, '08-jd-report-done');

// ---- M12 certificates
await visit(h, '/certs/admin/types', '09-certs-types');
await h.fill('#f-code', 'EMP-' + tag); await h.fill('#f-name_th', 'หนังสือรับรองการทำงาน');
const tplOpt = await h.locator('#f-template_th option').nth(1).getAttribute('value');
await h.selectOption('#f-template_th', tplOpt);
await h.fill('#f-max_copies', '3'); await h.fill('#f-signer_name', 'นางสาวตัวอย่าง ใจดี'); await h.fill('#f-signer_title', 'ผู้จัดการฝ่ายบุคคล');
await submit(h, 'form[action$="/certs/admin/types"] button[type=submit]');
log('cert type: ' + await flash(h));
const em = await page('employee.demo1', 390);
await visit(em, '/certs', '10-certs-ask-mobile');
await em.locator('input[name=type]').last().check();
await em.fill('#f-copies', '2'); await em.fill('#f-purpose', 'ยื่นขอสินเชื่อธนาคาร');
await submit(em, 'form[action$="/certs"] button[type=submit]');
log('cert ask: ' + await flash(em) + ' → ' + new URL(em.url()).pathname);
const req = new URL(em.url()).pathname.split('/').pop();
await audit(em, '11-certs-submitted-mobile');
await visit(h, '/certs/admin', '12-certs-queue');
await visit(h, '/certs/admin/r/' + req, '13-certs-work');
await submit(h, 'form[action$="/take"] button[type=submit]');
await audit(h, '14-certs-issue-form');
await submit(h, 'form[action$="/issue"] button[type=submit]');
log('cert issue: ' + await flash(h));
await submit(h, 'form[action$="/ready"] button[type=submit]');
log('cert ready: ' + await flash(h));
await visit(em, '/certs/r/' + req, '15-certs-ready-code-mobile');
const code = (await em.locator('.applied-code').innerText()).trim();
await h.goto(B + '/certs/admin/r/' + req);
await h.fill('#f-code', code);
await submit(h, 'form[action$="/handover"] button[type=submit]');
log('cert handover: ' + await flash(h));
await audit(h, '16-certs-received');
await em.goto(B + '/certs/r/' + req);
await em.locator('input[name=rating][value="5"]').check();
await em.fill('#f-comment', 'รวดเร็วมาก');
await submit(em, 'form[action$="/rate"] button[type=submit]');
log('cert rate: ' + await flash(em));
await audit(em, '17-certs-after-rating-mobile');
const doc = await h.context().request.get(B + '/certs/r/' + req + '/doc');
log('cert doc: ' + doc.status() + ' ' + (await doc.text()).includes('หนังสือรับรอง'));

// ---- M45 birthdays
const today = new Date(); const md = String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');
const later = String(today.getMonth() + 1).padStart(2, '0') + '-28';
log('births: ' + php(process.env.S + '/e2e/setbirth.php', ROOT, 'D1-0004=1995-' + md, 'D1-0005=1990-' + later));
await visit(h, '/celebrate', '18-cel-off');
await visit(h, '/celebrate/admin', '19-cel-admin');
await h.check('input[name=birthdays_enabled]'); await h.check('input[name=anniversaries_enabled]');
await h.fill('#f-notice_text', '(ตัวอย่าง DEMO — ไม่ใช่ข้อความทางกฎหมาย) บริษัทแสดงวัน-เดือนเกิดและวันครบรอบการทำงานบนบอร์ดเพื่อร่วมแสดงความยินดี ไม่แสดงปีเกิด พนักงานซ่อนของตนเองได้ที่หน้า "ของฉัน"');
await submit(h, 'form[action$="/celebrate/admin"] button[type=submit]');
log('cel settings: ' + await flash(h));
await audit(h, '20-cel-admin-on');
await visit(h, '/celebrate', '21-cel-board');
await h.locator('details.cel-card__wish summary').first().click();
await submit(h, 'details.cel-card__wish[open] button[type=submit]');
log('cel wish: ' + await flash(h));
const ed = await page('employee.demo1', 390, true);
await visit(ed, '/celebrate', '22-cel-board-mobile-dark');
await visit(ed, '/celebrate/me', '23-cel-me-mobile-dark');
await ed.context().close(); await em.context().close(); await e.context().close(); await h.context().close();
await b.close();
fs.writeFileSync(OUT + '/log.txt', out.join('\n') + '\n');
