import { chromium } from 'playwright'; import fs from 'fs'; import { execFileSync } from 'child_process';
const B = 'http://127.0.0.1:8098'; const OUT = 'p0150'; fs.mkdirSync(OUT, { recursive: true });
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
  const raw = await p.evaluate(() => (document.body.innerText.match(/\b(appraisal|employees|common|form)\.[a-z_]+(\.[a-z_]+)*\b/g) || []).slice(0, 5));
  log(name + ': ' + new URL(p.url()).pathname + ' overflow=' + overflow + ' axe=' + JSON.stringify(v) + (raw.length ? ' RAWKEYS=' + raw : ''));
}
async function visit(p, path, name) { await p.goto(B + path); await audit(p, name); }
const flash = async (p) => (await p.locator('.alert').allInnerTexts()).map((s) => s.replace(/\s+/g, ' ').slice(0, 120)).join(' || ');
const submit = async (p, sel) => { await Promise.all([p.waitForNavigation(), p.click(sel)]); };
const tag = Date.now().toString(36).slice(-4);
const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Bangkok' }).format(new Date());
const plus = (n) => { const d = new Date(today + 'T00:00:00Z'); d.setUTCDate(d.getUTCDate() + n); return d.toISOString().slice(0, 10); };
const CODE = ('E2E' + tag).toUpperCase();
// ---- HR: form
const h = await page('hr.demo1');
await visit(h, '/appraisal/admin/forms', '01-apr-forms');
await h.fill('#f-code', 'F' + CODE); await h.fill('#f-name', 'แบบประเมินทดสอบ ' + tag); await h.fill('#f-scale_max', '5');
await h.fill('#f-bands', '4.5 | ดีเยี่ยม\n3.5 | ดี\n2.5 | พอใช้\n1 | ต้องปรับปรุง');
const items = [['kpi', 'ยอดขายตามเป้า', '40'], ['kpi', 'ลูกค้าใหม่', '30'], ['competency', 'การทำงานเป็นทีม', '20']];
for (const [i, [s, t, w]] of items.entries()) { await h.selectOption('#it-' + i + '-s', s); await h.fill('#it-' + i + '-t', t); await h.fill('#it-' + i + '-w', w); }
await submit(h, 'form[action$="/appraisal/admin/forms"] button[type=submit]');
log('form (weights 90): ' + await flash(h)); await audit(h, '02-apr-form-error');
await h.fill('#it-2-w', '30');
await submit(h, 'form[action$="/appraisal/admin/forms"] button[type=submit]');
log('form: ' + await flash(h));
// ---- HR: cycle
await visit(h, '/appraisal/admin', '03-apr-admin');
await h.fill('#f-code', CODE); await h.fill('#f-name', 'ประเมินปลายปี (ทดสอบ ' + tag + ')');
await h.selectOption('#f-form', await h.locator('#f-form option', { hasText: 'F' + CODE }).getAttribute('value'));
await h.fill('#f-self_due', plus(3)); await h.fill('#f-manager_due', plus(5)); await h.fill('#f-final_due', plus(7)); await h.fill('#f-results_on', today);
await h.check('input[name=final_review]');
await h.locator('details:has(input[name="departments[]"]) summary').click();
await h.locator('label.check', { hasText: 'SALES' }).locator('input').check();
await submit(h, 'form[action$="/appraisal/admin/cycles"] button[type=submit]');
log('cycle: ' + await flash(h)); const cycle = new URL(h.url()).pathname; await audit(h, '04-apr-cycle-draft');
await submit(h, 'form[action$="/open"] button');
log('open: ' + await flash(h));
// ---- employee: self review
const e = await page('employee.demo1');
await visit(e, '/appraisal', '05-apr-mine');
await Promise.all([e.waitForNavigation(), e.locator('a', { hasText: tag }).first().click()]);
const apr = new URL(e.url()).pathname; await audit(e, '06-apr-self');
const pick = async (p, no, val) => p.locator(`input[name="score[${no}]"][value="${val}"]`).check();
await pick(e, 0, 4); await pick(e, 1, 3);
await e.fill('#note-0', 'ปิดยอดได้ 105% ของเป้า (ตัวอย่าง)'); await e.fill('#f-self_comment', 'ปีนี้ตั้งใจพัฒนาลูกค้าใหม่');
await submit(e, 'button[name=submit][value="1"]');
log('self missing: ' + await flash(e)); await audit(e, '07-apr-self-error');
await pick(e, 2, 5);
await submit(e, 'button[name=submit][value="1"]');
log('self: ' + await flash(e));
// ---- manager
const m = await page('manager.demo1');
await visit(m, '/appraisal', '08-apr-mgr-inbox');
await visit(m, apr, '09-apr-mgr');
await pick(m, 0, 4); await pick(m, 1, 4); await pick(m, 2, 3);
await m.fill('#f-strengths', 'ดูแลลูกค้าดี'); await m.fill('#f-manager_comment', 'ทำงานได้ตามเป้า สื่อสารดี');
await m.fill('#f-development_plan', 'อบรมการเจรจาต่อรอง ภายในไตรมาส 1'); await m.fill('#f-comp_proposal', 'เสนอขึ้นเงินเดือนตามรอบ (ตัวอย่าง)');
await submit(m, 'button[name=submit][value="1"]');
log('manager: ' + await flash(m));
// ---- HR final review
await visit(h, apr, '10-apr-final');
await h.fill('#f-calibrated', '3.4'); await h.fill('#f-calibration_reason', 'เทียบกับทีมขายทั้งหมดแล้ว');
await submit(h, 'button[name=decision][value=approve]');
log('final: ' + await flash(h));
// ---- employee result + appeal
await visit(e, apr, '11-apr-result');
log('result shows 3.4: ' + (await e.locator('body').innerText()).includes('3.4') + ' · proposal hidden: ' + !(await e.locator('body').innerText()).includes('เสนอขึ้นเงินเดือน'));
await e.fill('#f-employee_comment', 'ยอดขายไตรมาส 4 ยังไม่ถูกนับรวม');
await submit(e, 'button[name=appeal][value="1"]');
log('appeal: ' + await flash(e));
await visit(h, apr, '12-apr-hr-appeal');
await h.fill('#f-outcome', 'ตรวจยอดแล้ว คงผลเดิม (ตัวอย่าง)');
await submit(h, 'button[name=outcome_action][value=close]');
log('appeal closed: ' + await flash(h));
await visit(h, cycle, '13-apr-cycle-report');
const x = await h.request.get(B + cycle + '/export'); log('export xlsx: ' + x.status() + ' ' + x.headers()['content-type']);
const c = await h.request.get(B + cycle + '/export?format=csv'); log('export csv: ' + c.status() + ' D1-0004=' + (await c.text()).includes('D1-0004'));
// mobile + dark
const em = await page('employee.demo1', 390); await visit(em, apr, '14-apr-result-mobile');
const md = await page('manager.demo1', 1366, true); await visit(md, '/appraisal', '15-apr-inbox-dark');
const hm = await page('hr.demo1', 390); await visit(hm, cycle, '16-apr-cycle-mobile'); await visit(hm, '/appraisal/admin/forms', '17-apr-forms-mobile');
for (const p of [e, m, h, em, md, hm]) await p.context().close();
await b.close();
fs.writeFileSync(OUT + '/log.txt', out.join('\n') + '\n');
