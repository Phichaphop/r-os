// Usage: ROS_ADMIN_USER=... ROS_ADMIN_PASS=... ROS_DEMO_PASS=... node <script> (dev server on :8080; needs playwright + axe-core; /tmp/totp.php prints a TOTP code)
import { chromium } from 'playwright'; import { execSync } from 'child_process'; import fs from 'fs';
const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium_headless_shell-1194/chrome-linux/headless_shell' });
const p = await (await b.newContext({ viewport: { width: 1366, height: 900 } })).newPage();
const focus = async () => p.evaluate(() => { const a = document.activeElement; return `${a.tagName}#${a.id}.${a.className} vis=${getComputedStyle(a).outlineStyle !== 'none' || getComputedStyle(a).boxShadow !== 'none'}`; });
await p.goto('http://127.0.0.1:8080/login');
const seq = [];
for (let i = 0; i < 8; i++) { await p.keyboard.press('Tab'); seq.push(await focus()); }
console.log('login tab order:\n ' + seq.join('\n '));
await p.focus('#f-identifier'); await p.keyboard.type(process.env.ROS_ADMIN_USER); await p.keyboard.press('Tab'); await p.keyboard.type(process.env.ROS_ADMIN_PASS); await p.keyboard.press('Enter');
await p.waitForLoadState('networkidle'); console.log('after enter:', p.url());
await new Promise(r => setTimeout(r, 31000));
console.log('mfa focus on load:', await focus());
await p.focus('#f-code'); await p.keyboard.type(execSync(`php /tmp/totp.php ${fs.readFileSync('p2/secret-phichaphop','utf8')}`).toString()); await Promise.all([p.waitForURL((u) => !u.pathname.startsWith('/mfa')), p.keyboard.press('Enter')]);
await p.waitForLoadState('networkidle'); console.log('after mfa:', p.url());
await p.goto('http://127.0.0.1:8080/admin/users');
const s2 = []; for (let i = 0; i < 14; i++) { await p.keyboard.press('Tab'); s2.push(await focus()); }
console.log('users page tab order:\n ' + s2.join('\n '));
// open user menu with keyboard
await p.focus('.user-button'); await p.keyboard.press('Enter');
console.log('menu open:', await p.isVisible('.menu__list'), 'focus:', await focus());
await p.keyboard.press('Escape'); console.log('after esc open:', await p.isVisible('.menu__list'), await focus());
await b.close();
