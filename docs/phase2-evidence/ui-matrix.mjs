// Usage: ROS_ADMIN_USER=... ROS_ADMIN_PASS=... ROS_DEMO_PASS=... node <script> (dev server on :8080; needs playwright + axe-core; /tmp/totp.php prints a TOTP code)
import { chromium } from 'playwright';
import { execSync } from 'child_process';
import fs from 'fs';
const B = 'http://127.0.0.1:8080';
const axeSrc = fs.readFileSync('node_modules/axe-core/axe.min.js', 'utf8');
const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium_headless_shell-1194/chrome-linux/headless_shell' });
const results = [];
async function login(page, user, pass) {
  await page.goto(B + '/login');
  await page.fill('#f-identifier', user); await page.fill('#f-password', pass);
  await page.click('form[action$="/login"] button[type=submit]');
  await page.waitForLoadState('networkidle');
  if (page.url().includes('/mfa/setup')) {
    const secret = (await page.textContent('.code-box')).replace(/\s/g, '');
    fs.writeFileSync(`p2/secret-${user}`, secret);
    await page.fill('#f-code', execSync(`php /tmp/totp.php ${secret}`).toString());
    await page.click('form[action$="/mfa/setup"] button[type=submit]'); await page.waitForLoadState('networkidle');
  } else if (page.url().includes('/mfa/verify')) {
    const secret = fs.readFileSync(`p2/secret-${user}`, 'utf8');
    await new Promise((r) => setTimeout(r, 31000));
    await page.fill('#f-code', execSync(`php /tmp/totp.php ${secret}`).toString());
    await page.click('form[action$="/mfa/verify"] button[type=submit]'); await page.waitForLoadState('networkidle');
  }
}
const pass = { process.env.ROS_ADMIN_USER: process.env.ROS_ADMIN_PASS };
const demo = process.env.ROS_DEMO_PASS ?? '';
const pages = { phichaphop: ['/', '/admin/users', '/admin/roles', '/admin/org', '/admin/audit', '/me', '/admin/users/new', '/admin/org/legal-entity/new'], 'hr.demo1': ['/', '/admin/org', '/me'] };
for (const theme of ['light', 'dark']) {
  for (const [user, paths] of Object.entries(pages)) {
    for (const w of [360, 1366]) {
      const ctx = await b.newContext({ viewport: { width: w, height: 900 }, colorScheme: theme, bypassCSP: true });
      const p = await ctx.newPage();
      const errs = [];
      p.on('console', (m) => { if (m.type() === 'error') errs.push(m.text()); });
      await login(p, user, pass[user] ?? demo);
      // add user detail page for admin
      const list = [...paths];
      if (user === process.env.ROS_ADMIN_USER) {
        await p.goto(B + '/admin/users?q=hr.demo1'); const href = await p.getAttribute('td a', 'href'); list.push(href);
      }
      for (const path of list) {
        await p.goto(B + path, { waitUntil: 'networkidle' });
        const overflow = await p.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        await p.addScriptTag({ content: axeSrc });
        const axe = await p.evaluate(async () => (await window.axe.run(document, { runOnly: ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'] })).violations.map((v) => `${v.impact}:${v.id}(${v.nodes.length}) ${v.nodes.slice(0, 2).map((n) => n.target.join(' ')).join(' | ')}`));
        const name = `${user}-${path.replace(/[^a-z0-9]+/gi, '_')}-${w}-${theme}`;
        if (w === 1366 || path === '/admin/users') await p.screenshot({ path: `p2/${name}.png`, fullPage: true });
        results.push({ user, path, w, theme, status: p.url(), overflow, axe, errs: [...errs] });
        errs.length = 0;
      }
      await ctx.close();
    }
  }
}
// login page + mfa setup/verify pages (guest)
for (const w of [360, 1366]) {
  const ctx = await b.newContext({ viewport: { width: w, height: 900 }, bypassCSP: true }); const p = await ctx.newPage();
  for (const path of ['/login', '/password/forgot', '/password/reset']) {
    await p.goto(B + path); await p.addScriptTag({ content: axeSrc });
    const axe = await p.evaluate(async () => (await window.axe.run(document, { runOnly: ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'] })).violations.map((v) => `${v.impact}:${v.id}`));
    const overflow = await p.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    results.push({ user: 'guest', path, w, theme: 'light', overflow, axe, errs: [] });
    await p.screenshot({ path: `p2/guest-${path.replace(/\//g, '_')}-${w}.png` });
  }
  await ctx.close();
}
await b.close();
fs.writeFileSync('p2/results.json', JSON.stringify(results, null, 1));
const bad = results.filter((r) => r.overflow > 0 || r.axe.length || r.errs.length);
console.log('checked', results.length, 'bad', bad.length);
for (const r of bad) console.log(JSON.stringify(r));
