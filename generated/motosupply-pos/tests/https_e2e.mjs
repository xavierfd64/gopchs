// HTTPS / production-mode test (development only).
// Usage: node tests/https_e2e.mjs <https-base> <http-base-same-site> <db-name> <screenshot-dir>
import { createRequire } from 'module';
import { execSync } from 'child_process';
import fs from 'fs';
const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

const [HTTPS, HTTP, DB, SHOTS] = process.argv.slice(2);
const PW = 'Secure#Shop2026';
const sql = (q) => execSync('mysql -N', { input: q, encoding: 'utf8' }).trim();
const results = []; let failed = 0;
function assert(c, m) { if (!c) throw new Error(m || 'assertion failed'); }
async function step(name, fn) {
  try { await fn(); results.push(['PASS', name]); console.log('  PASS ', name); }
  catch (e) { failed++; results.push(['FAIL', name, String(e.message || e).split('\n')[0]]); console.log('  FAIL ', name, '\n       ', String(e.message || e).split('\n')[0]); }
}
sql(`DROP DATABASE IF EXISTS \`${DB}\`; CREATE DATABASE \`${DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON \`${DB}\`.* TO 'moto'@'127.0.0.1';`);

const browser = await chromium.launch();
const ctx = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1280, height: 860 } });
const page = await ctx.newPage();
// Plain-HTTP probes use their own context: cookies are not port-specific, so sharing the jar
// with the HTTPS session would replace its session cookie.
const plain = (await browser.newContext({ ignoreHTTPSErrors: true })).request;
page.setDefaultTimeout(10000);
page.on('dialog', (d) => d.accept());

await step('Over HTTPS the requirement shows OK and no testing confirmation is needed', async () => {
  await page.goto(HTTPS + '/install/index.php?step=requirements');
  const t = await page.textContent('.req-table');
  assert(/HTTPS \(SSL\)\s*OK\s*Active/.test(t.replace(/\s+/g, ' ')), 'HTTPS OK');
  assert(!(await page.isVisible('.ack-box')), 'no testing box');
  await page.screenshot({ path: `${SHOTS}/h1-requirements-https.png`, fullPage: true });
  await page.click('button:has-text("Continue")');
  await page.waitForURL(/step=database/);
});
await step('Wizard installs in production mode over HTTPS', async () => {
  for (const [k, v] of Object.entries({ db_host: '127.0.0.1', db_name: DB, db_user: 'moto', db_pass: 'motopass' })) await page.fill('#' + k, v);
  await page.click('button:has-text("Continue")');
  await page.waitForURL(/step=shop/);
  await page.click('button:has-text("Continue")');
  await page.waitForURL(/step=admin/);
  await page.fill('#admin_user', 'owner'); await page.fill('#admin_pass', PW); await page.fill('#admin_pass2', PW);
  await page.click('button:has-text("Continue")');
  await page.waitForURL(/step=install/);
  assert((await page.textContent('.summary-list')).includes('Production: HTTPS required'));
  await page.click('button:has-text("Install MotoSupply")');
  await page.waitForURL(/step=done/);
  assert((await page.textContent('.wizard-body')).includes('Production: HTTPS is required'));
  assert(sql(`SELECT setting_value FROM \`${DB}\`.settings WHERE setting_key='security_mode'`) === 'production');
});
await step('Login over HTTPS: Secure session cookie, no insecure banner', async () => {
  await page.goto(HTTPS + '/index.php?r=login');
  await page.fill('#username', 'owner'); await page.fill('#password', PW);
  await page.click('main form button[type=submit]');
  await page.waitForURL(/r=dashboard/);
  const c = (await ctx.cookies()).find((x) => x.name === 'MOTOSESS');
  assert(c && c.secure && c.httpOnly && c.sameSite === 'Lax', JSON.stringify(c));
  assert(!(await page.isVisible('.insecure-banner')));
});
await step('Production mode redirects plain HTTP to HTTPS', async () => {
  const r = await plain.get(HTTP + '/index.php?r=login', { maxRedirects: 0 });
  assert(r.status() === 303 && r.headers()['location'].startsWith('https://'), r.status() + ' ' + r.headers()['location']);
});
await step('No redirect loop: untrusted proxy header gets an explanation page instead', async () => {
  const r = await plain.get(HTTP + '/index.php?r=login', { maxRedirects: 0, headers: { 'X-Forwarded-Proto': 'https' } });
  assert(r.status() === 503, 'status ' + r.status());
  assert((await r.text()).includes('Secure connection problem'));
});
await step('No redirect loop: a second redirect within seconds is stopped', async () => {
  const r = await plain.get(HTTP + '/index.php?r=login', { maxRedirects: 0, headers: { Cookie: 'moto_https_redirect=' + Math.floor(Date.now() / 1000) } });
  assert(r.status() === 503, 'status ' + r.status());
});
await step('System Check over HTTPS: HTTPS OK and mode switch works both ways', async () => {
  await page.goto(HTTPS + '/index.php?r=settings.system');
  const t = (await page.textContent('main')).replace(/\s+/g, ' ');
  assert(t.includes('HTTPS active') && t.includes('Production (HTTPS required)'), 'status');
  await page.screenshot({ path: `${SHOTS}/h2-system-https.png`, fullPage: true });
  await page.click('button:has-text("Switch back to testing mode")');
  await page.waitForSelector('text=Testing mode enabled');
  const r = await plain.get(HTTP + '/index.php?r=login', { maxRedirects: 0 });
  assert(r.status() === 200, 'HTTP allowed in testing mode: ' + r.status());
  assert((await r.text()).includes('Testing mode only'), 'banner on HTTP');
  await page.click('button:has-text("Require HTTPS (production)")');
  await page.waitForSelector('text=HTTPS is now required');
  assert(sql(`SELECT setting_value FROM \`${DB}\`.settings WHERE setting_key='security_mode'`) === 'production');
});

await browser.close();
fs.writeFileSync(`${SHOTS}/https-results.json`, JSON.stringify(results, null, 2));
console.log(`\n${results.length - failed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
