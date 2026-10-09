// Browser test of the installation wizard (development only).
// Usage: node tests/installer_e2e.mjs <base-url> <webroot-dir> <db-name> <screenshot-dir>
// Needs: mysql CLI as an admin user; webroot writable by this process (for permission tests).
import { createRequire } from 'module';
import { execSync } from 'child_process';
import fs from 'fs';
const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

const [BASE, ROOT, DB, SHOTS] = process.argv.slice(2);
const DBPASS = 'motopass';
const DBHOST = process.env.MOTO_DB_HOST || 'localhost';
const ADMIN = 'shopowner';
const ADMIN_PW = process.env.MOTO_INITIAL_PW || 'Initial#Pass2026';
const results = [];
let failed = 0;
const sh = (c) => execSync(c, { encoding: 'utf8' }).trim();
const sql = (q) => execSync('mysql -N', { input: q, encoding: 'utf8' }).trim();
function assert(c, m) { if (!c) throw new Error(m || 'assertion failed'); }
async function step(name, fn) {
  try { await fn(); results.push(['PASS', name]); console.log('  PASS ', name); }
  catch (e) { failed++; results.push(['FAIL', name, String(e.message || e).split('\n')[0]]); console.log('  FAIL ', name, '\n       ', String(e.message || e).split('\n')[0]); }
}

sql(`DROP DATABASE IF EXISTS \`${DB}\`; CREATE DATABASE \`${DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON \`${DB}\`.* TO 'moto'@'localhost';`);
sql(`DROP DATABASE IF EXISTS moto_conflict; CREATE DATABASE moto_conflict; CREATE TABLE moto_conflict.users (id INT PRIMARY KEY, email VARCHAR(50)); GRANT ALL ON moto_conflict.* TO 'moto'@'localhost';`);

const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1280, height: 860 } });
const page = await ctx.newPage();
page.setDefaultTimeout(10000);
const consoleErrors = [];
page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text()); });

async function fillDb(over = {}) {
  const v = { db_host: DBHOST, db_port: '3306', db_name: DB, db_user: 'moto', db_pass: DBPASS, ...over };
  for (const [k, val] of Object.entries(v)) await page.fill('#' + k, val);
}

await step('Opening the website starts the wizard (Welcome step)', async () => {
  await page.goto(BASE + '/');
  assert(page.url().includes('/install/'), page.url());
  assert(await page.isVisible('h1:has-text("MotoSupply POS & Inventory System")'));
  assert(await page.isVisible('text=Server requirements'));
  await page.screenshot({ path: `${SHOTS}/w1-welcome.png`, fullPage: true });
});
await step('Later steps cannot be skipped', async () => {
  await page.goto(BASE + '/install/index.php?step=install');
  assert(page.url().includes('step=requirements'), 'redirected back: ' + page.url());
});
await step('Requirements step shows OK/Warning statuses, write-tested folders and HTTPS warning', async () => {
  await page.goto(BASE + '/install/');
  await page.click('a:has-text("Install")');
  await page.waitForURL(/step=requirements/);
  const txt = await page.textContent('.req-table');
  for (const s of ['PHP version', 'PDO MySQL driver', 'PHP sessions', 'Application files', 'Security rules (.htaccess files)', 'Folder config/', 'Folder storage/', 'Folder storage/logs/', 'Folder uploads/products/', 'HTTPS (SSL)']) assert(txt.includes(s), 'missing ' + s);
  assert(txt.includes('Writable (write test passed)'), 'write test reported');
  assert(txt.includes('OK') && txt.includes('Warning'), 'statuses');
  assert(!txt.includes('Failed'), 'no failures on this server');
  assert(!/\/var\/www|\/home\/|\/srv\//.test(txt), 'no absolute server paths exposed');
  assert(await page.isVisible('a:has-text("Recheck Requirements")'));
  assert(fs.readdirSync(ROOT + '/storage/logs').every((f) => !f.startsWith('.moto-write-test-')), 'write-test files removed');
  await page.screenshot({ path: `${SHOTS}/w2-requirements.png`, fullPage: true });
});
await step('HTTP install requires the testing-mode confirmation', async () => {
  assert(await page.isVisible('.ack-box'), 'testing warning shown');
  await page.click('button:has-text("Continue")');
  await page.waitForSelector('.alert-error');
  assert((await page.textContent('.alert-error')).includes('not encrypted'));
  assert(page.url().includes('step=requirements'));
  await page.check('input[name=testing_ack]');
  await page.click('button:has-text("Continue")');
  await page.waitForURL(/step=database/);
});
await step('Wrong database password gives a plain-language error and is not echoed', async () => {
  await fillDb({ db_pass: 'Wr0ngSecretXYZ' });
  await page.click('button:has-text("Test connection")');
  await page.waitForSelector('.alert-error');
  assert((await page.textContent('.alert-error')).includes('username or password is incorrect'));
  const html = await page.content();
  assert(!html.includes('Wr0ngSecretXYZ'), 'password must not appear in the page');
  assert(!/SQLSTATE|PDOException|Access denied for user/.test(html), 'no raw database errors');
  await page.screenshot({ path: `${SHOTS}/w3-db-error.png`, fullPage: true });
});
await step('Unknown database host and unknown database name are explained', async () => {
  await fillDb({ db_host: 'sql999.invalid' });
  await page.click('button:has-text("Test connection")');
  await page.waitForSelector('.alert-error');
  assert((await page.textContent('.alert-error')).includes('Could not reach the database server'));
  await fillDb({ db_name: 'does_not_exist_123' });
  await page.click('button:has-text("Test connection")');
  await page.waitForSelector('.alert-error');
  const t = await page.textContent('.alert-error');
  assert(t.includes('was not found') || t.includes('not allowed to use that database'), t);
});
await step('Database with conflicting tables from another application is refused', async () => {
  await fillDb({ db_name: 'moto_conflict' });
  await page.click('button:has-text("Continue")');
  await page.waitForSelector('.alert-error');
  assert((await page.textContent('.alert-error')).includes('another application'));
  assert(sql('SELECT COUNT(*) FROM moto_conflict.users') === '0', 'other app table untouched');
});
await step('Test connection succeeds with correct details', async () => {
  await fillDb();
  await page.click('button:has-text("Test connection")');
  await page.waitForSelector('.alert-success');
  assert((await page.textContent('.alert-success')).includes('Connection successful'));
  assert((await page.inputValue('#db_pass')) === '', 'password field not refilled');
  await page.screenshot({ path: `${SHOTS}/w4-db-ok.png`, fullPage: true });
  await page.click('button:has-text("Continue")'); // blank password keeps the tested one
  await page.waitForURL(/step=shop/);
});
await step('Shop step validates and defaults to Asia/Manila and PHP', async () => {
  assert((await page.inputValue('#timezone')) === 'Asia/Manila');
  assert((await page.inputValue('#currency_code')) === 'PHP');
  await page.fill('#shop_name', '');
  await page.click('button:has-text("Continue")');
  assert(await page.isVisible('text=Enter the shop name'));
  await page.fill('#shop_name', 'MotoSupply Shop');
  await page.fill('#shop_address', '128 Rizal Avenue, Quezon City');
  await page.fill('#shop_phone', '+63 917 555 0182');
  await page.screenshot({ path: `${SHOTS}/w5-shop.png`, fullPage: true });
  await page.click('button:has-text("Continue")');
  await page.waitForURL(/step=admin/);
});
await step('Administrator step rejects admin/admin, weak and mismatched passwords', async () => {
  const tryPw = async (u, p, p2) => {
    await page.fill('#admin_user', u); await page.fill('#admin_pass', p); await page.fill('#admin_pass2', p2);
    await page.click('button:has-text("Continue")');
    return page.textContent('.wizard-body');
  };
  assert((await tryPw('admin', 'admin', 'admin')).includes('at least 8 characters'), 'admin/admin rejected');
  assert((await tryPw(ADMIN, 'password123', 'password123')).includes('three of'), 'weak rejected');
  assert((await tryPw(ADMIN, ADMIN_PW, ADMIN_PW + 'x')).includes('do not match'), 'mismatch rejected');
  assert((await page.inputValue('#admin_pass')) === '', 'password not echoed back');
  await page.screenshot({ path: `${SHOTS}/w6-admin-errors.png`, fullPage: true });
  await tryPw(ADMIN, ADMIN_PW, ADMIN_PW);
  await page.waitForURL(/step=install/);
});
await step('Install summary shows no secrets', async () => {
  const t = await page.textContent('.wizard-body');
  assert(t.includes(DB) && t.includes(ADMIN) && t.includes('MotoSupply Shop'));
  assert(!t.includes(DBPASS) && !t.includes(ADMIN_PW), 'no passwords');
  await page.screenshot({ path: `${SHOTS}/w7-ready.png`, fullPage: true });
});
await step('A failed install (config folder not writable) rolls back and can be retried', async () => {
  // A folder PHP cannot fix by itself (owned by another user, like a misconfigured upload).
  sh(`chown root:root ${ROOT}/config && chmod 755 ${ROOT}/config`);
  try {
    await page.click('button:has-text("Install MotoSupply")');
    await page.waitForSelector('.alert-error');
    const t = await page.textContent('.alert-error');
    assert(t.includes('Recheck Requirements'), t);
    await page.goto(BASE + '/install/index.php?step=requirements');
    const req = (await page.textContent('.req-table')).replace(/\s+/g, ' ');
    assert(/Folder config\/ \(configuration \(database settings\)\) Failed Not writable \(write test failed\)/.test(req), 'config shown as Failed: ' + req.slice(0, 400));
    assert(req.includes('(website folder)/') && req.includes('config/') && req.includes('Recheck Requirements'), 'instructions');
    assert(!(await page.isVisible('button:has-text("Continue")')), 'cannot continue');
    const hasUsers = sql(`SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB}' AND table_name='users'`) === '1';
    assert(!hasUsers || sql(`SELECT COUNT(*) FROM \`${DB}\`.users`) === '0', 'no administrator left behind');
    assert(!fs.existsSync(`${ROOT}/storage/installed.lock`), 'not locked');
    await page.screenshot({ path: `${SHOTS}/w8-install-error.png`, fullPage: true });
  } finally { sh(`chown 33:33 ${ROOT}/config`); }
  // Recheck after fixing the folder: the status updates to OK.
  await page.goto(BASE + '/install/index.php?step=requirements');
  assert(/Folder config\/ \(configuration \(database settings\)\) OK Writable/.test((await page.textContent('.req-table')).replace(/\s+/g, ' ')), 'recheck shows OK');
  // Late failure: data and config already written, then the lock cannot be created.
  fs.mkdirSync(`${ROOT}/storage/installed.lock`);
  try {
    await page.goto(BASE + '/install/index.php?step=install');
    await page.click('button:has-text("Install MotoSupply")');
    await page.waitForSelector('.alert-error');
    assert((await page.textContent('.alert-error')).includes('could not be locked'));
    assert(sql(`SELECT COUNT(*) FROM \`${DB}\`.users`) === '0', 'administrator removed again');
    assert(sql(`SELECT COUNT(*) FROM \`${DB}\`.settings`) === '0', 'settings removed again');
    assert(!fs.existsSync(`${ROOT}/config/config.php`), 'config removed again');
  } finally { fs.rmdirSync(`${ROOT}/storage/installed.lock`); }
  await page.goto(BASE + '/install/index.php?step=install');
  await page.click('button:has-text("Install MotoSupply")');
  await page.waitForURL(/step=done/).catch(async (e) => { throw new Error('retry failed: ' + (await page.textContent('.wizard-body')).slice(0, 300)); });
});
await step('Success page: login URL, lock confirmation, password reminder, no secrets', async () => {
  const t = await page.textContent('.wizard-body');
  assert(t.includes('Installation completed successfully'));
  assert(t.includes('index.php?r=login'));
  assert(t.includes('Locked'));
  assert(t.includes('Keep your administrator password secure'));
  assert(!t.includes(DBPASS) && !t.includes(ADMIN_PW), 'no secrets');
  await page.screenshot({ path: `${SHOTS}/w9-done.png`, fullPage: true });
});
await step('Database state after install: one hashed admin, schema version, shop settings', async () => {
  const row = sql(`SELECT username, LEFT(password_hash,4), must_change_password FROM \`${DB}\`.users`);
  assert(row === `${ADMIN}\t$2y$\t0`, row);
  assert(sql(`SELECT COUNT(*) FROM \`${DB}\`.users WHERE password_hash = '${ADMIN_PW}'`) === '0', 'no plaintext');
  assert(sql(`SELECT version FROM \`${DB}\`.schema_migrations`) === '1');
  assert(sql(`SELECT setting_value FROM \`${DB}\`.settings WHERE setting_key='shop_address'`) === '128 Rizal Avenue, Quezon City');
  assert(fs.existsSync(`${ROOT}/storage/installed.lock`));
  assert(!fs.readFileSync(`${ROOT}/storage/logs/` + fs.readdirSync(`${ROOT}/storage/logs`).find((f) => f.endsWith('.log')), 'utf8').includes(DBPASS), 'password not in logs');
});
await step('Go to Login works and the first login succeeds', async () => {
  await page.click('a:has-text("Go to Login")');
  await page.waitForURL(/r=login/);
  await page.fill('#username', ADMIN); await page.fill('#password', ADMIN_PW);
  await page.click('main form button[type=submit]');
  await page.waitForURL(/r=dashboard/);
  assert((await page.textContent('.store-card')).includes('MotoSupply Shop'));
  assert(await page.isVisible('.insecure-banner:has-text("Testing mode only")'), 'HTTP testing banner visible after login');
  assert(sql(`SELECT setting_value FROM \`${DB}\`.settings WHERE setting_key='security_mode'`) === 'testing', 'installed in testing mode');
});
await step('System Check: write-tested folders, log written, HTTPS cannot be required over HTTP', async () => {
  await page.goto(BASE + '/index.php?r=settings.system');
  const t = await page.textContent('main');
  assert(t.includes('Writable (write test passed)') && t.includes('Application log') && t.includes('Written to storage/logs/'), 'checks');
  assert(await page.isDisabled('button:has-text("Require HTTPS (production)")'), 'production switch disabled on HTTP');
  // A forged POST is refused server-side too.
  const tok = await page.getAttribute('#https form input[name=_csrf]', 'value');
  await page.request.post(BASE + '/index.php?r=settings.security', { form: { _csrf: tok, mode: 'production' } });
  assert(sql(`SELECT setting_value FROM \`${DB}\`.settings WHERE setting_key='security_mode'`) === 'testing', 'still testing');
  await page.screenshot({ path: `${SHOTS}/w12-system-check.png`, fullPage: true });
});
const hashBefore = sql(`SELECT password_hash FROM \`${DB}\`.users`);
await step('Installer is locked afterwards (GET and forged POSTs)', async () => {
  const anon = await browser.newContext();
  const p2 = await anon.newPage();
  for (const s of ['', 'index.php?step=database', 'index.php?step=admin', 'index.php?step=install']) {
    const r = await p2.goto(BASE + '/install/' + s);
    assert(r.status() === 403, s + ' -> ' + r.status());
    assert(await p2.isVisible('text=already installed'));
  }
  await p2.screenshot({ path: `${SHOTS}/w10-locked.png` });
  const r = await p2.request.post(BASE + '/install/index.php?step=admin', { form: { admin_user: 'attacker', admin_pass: 'Attack#Pass2026', admin_pass2: 'Attack#Pass2026' } });
  assert(r.status() === 403, 'post blocked');
  const r2 = await p2.request.post(BASE + '/install/index.php?step=install', { form: {} });
  assert(r2.status() === 403, 'install post blocked');
  await anon.close();
  assert(sql(`SELECT COUNT(*) FROM \`${DB}\`.users`) === '1', 'no extra users');
  assert(sql(`SELECT password_hash FROM \`${DB}\`.users`) === hashBefore, 'password unchanged');
});
await step('Even with the lock and config removed, an installed database is never overwritten', async () => {
  const cfg = fs.readFileSync(`${ROOT}/config/config.php`);
  const lock = fs.readFileSync(`${ROOT}/storage/installed.lock`);
  fs.unlinkSync(`${ROOT}/config/config.php`); fs.unlinkSync(`${ROOT}/storage/installed.lock`);
  try {
    const c2 = await browser.newContext(); const p3 = await c2.newPage();
    await p3.goto(BASE + '/install/index.php?step=requirements');
    await p3.check('input[name=testing_ack]');
    await p3.click('button:has-text("Continue")');
    await p3.waitForURL(/step=database/);
    for (const [k, v] of Object.entries({ db_host: DBHOST, db_name: DB, db_user: 'moto', db_pass: DBPASS })) await p3.fill('#' + k, v);
    await p3.click('button:has-text("Continue")');
    await p3.waitForSelector('.alert-error');
    assert((await p3.textContent('.alert-error')).includes('already contains a MotoSupply installation'));
    await c2.close();
  } finally {
    fs.writeFileSync(`${ROOT}/config/config.php`, cfg); fs.writeFileSync(`${ROOT}/storage/installed.lock`, lock);
    sh(`chown www-data:www-data ${ROOT}/config/config.php ${ROOT}/storage/installed.lock 2>/dev/null || true`);
  }
  assert(sql(`SELECT password_hash FROM \`${DB}\`.users`) === hashBefore, 'password unchanged');
});
await step('Installer internals and config are not web-accessible', async () => {
  for (const p of ['install/lib/Installer.php', 'install/views/layout.php', 'config/config.php', 'storage/installed.lock', 'README.md', 'INSTALLATION-CHECKLIST.md']) {
    const r = await page.request.get(BASE + '/' + p);
    assert([403, 404].includes(r.status()), p + ' -> ' + r.status());
  }
});
await step('Wizard pages fit phone and tablet widths without horizontal scrolling', async () => {
  const c = await browser.newContext({ viewport: { width: 375, height: 812 } });
  const p = await c.newPage();
  const r = await p.goto(BASE + '/install/');
  const over = await p.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  assert(over <= 0, 'locked page overflow ' + over);
  await p.screenshot({ path: `${SHOTS}/w11-mobile-locked.png` });
  await c.close();
});
await step('No console errors during the wizard', async () => {
  const relevant = consoleErrors.filter((e) => !/status of 4\d\d/.test(e));
  assert(relevant.length === 0, relevant.join(' | '));
});

await browser.close();
fs.writeFileSync(`${SHOTS}/installer-results.json`, JSON.stringify(results, null, 2));
console.log(`\n${results.length - failed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
