// End-to-end tests: the real Electron app (built from this folder) against a real MotoSupply server.
//   xvfb-run node tests/e2e.mjs <server-url> <database> [screenshot-dir]
// Requires: a freshly installed MotoSupply site at <server-url> (http://127.0.0.1:…), mysql CLI access to
// <database>, and Playwright (PLAYWRIGHT_MODULE). Test fixtures are written straight into the database.
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import net from 'node:net';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const { _electron } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const APP = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const SERVER = process.argv[2] || 'http://127.0.0.1:8090';
const DB = process.argv[3] || 'moto_e2e';
const SHOTS = process.argv[4] || fs.mkdtempSync(path.join(os.tmpdir(), 'moto-desk-shots-'));
const WORK = fs.mkdtempSync(path.join(os.tmpdir(), 'moto-desk-'));
const PDFS = path.join(WORK, 'pdf');
fs.mkdirSync(PDFS);
fs.mkdirSync(SHOTS, { recursive: true });
const PW = 'Cashier#Pass42';

const results = [];
let failed = 0;
async function step(name, fn) {
  try { await fn(); results.push(['PASS', name]); console.log('  PASS ', name); }
  catch (e) {
    failed++;
    const lines = String(e?.message ?? e).split('\n');
    const msg = lines[0] + (lines.find((l) => /waiting for/.test(l)) ? ' — ' + lines.find((l) => /waiting for/.test(l)).trim() : '');
    results.push(['FAIL', name, msg]);
    console.log('  FAIL ', name, '\n       ', msg);
  }
}
const assert = (c, m) => { if (!c) throw new Error(m || 'assertion failed'); };
const sql = (q) => execFileSync('mysql', ['-N', '-B', DB], { input: q }).toString().trim();
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
async function until(fn, ms = 8000, msg = 'condition') {
  const end = Date.now() + ms;
  while (Date.now() < end) { if (await fn()) return; await sleep(150); }
  throw new Error('timed out waiting for ' + msg);
}

// ---------------------------------------------------------------- fixtures
const hash = execFileSync('php', ['-r', `echo password_hash('${PW}', PASSWORD_DEFAULT);`]).toString();
const role = (slug) => sql(`SELECT id FROM roles WHERE slug = '${slug}'`);
const mkUser = (u, r, active = 1) => sql(`INSERT INTO users (username, password_hash, full_name, role, role_id, must_change_password, is_active, created_at, updated_at)
  VALUES ('${u}', '${hash}', '${u.replace('desk_', '').replace(/^./, (c) => c.toUpperCase())} Tester', '${r}', ${role(r)}, 0, ${active}, UTC_TIMESTAMP(), UTC_TIMESTAMP()); SELECT LAST_INSERT_ID();`);
mkUser('desk_cashier', 'cashier');
mkUser('desk_cashier2', 'cashier');
mkUser('desk_stock', 'inventory');
mkUser('desk_off', 'cashier', 0);
const cat = (n) => sql(`INSERT INTO categories (name, created_at) VALUES ('${n}', UTC_TIMESTAMP()); SELECT LAST_INSERT_ID();`);
const oil = cat('Engine Oil');
const plugs = cat('Spark Plugs');
const mkProduct = (sku, barcode, name, catId, price, stock, active = 1) => Number(sql(`INSERT INTO products (sku, barcode, name, category_id, unit, cost_price, selling_price, stock_qty, is_active, created_at, updated_at)
  VALUES ('${sku}', '${barcode}', '${name}', ${catId}, 'pc', 100, ${price}, ${stock}, ${active}, UTC_TIMESTAMP(), UTC_TIMESTAMP()); SELECT LAST_INSERT_ID();`));
const P = {
  motul: mkProduct('DSK-001', '4801234567890', 'Motul 5100 4T 10W-40', oil, '450.00', 24),
  ngk: mkProduct('DSK-014', '4806512384012', 'NGK Iridium Spark Plug', plugs, '620.00', 8),
  shell: mkProduct('DSK-027', '4807123098211', 'Shell Advance AX7 1L', oil, '395.00', 3),
  old: mkProduct('DSK-099', '4809999999999', 'Discontinued Oil', oil, '100.00', 10, 0),
};
const stock = (id) => Number(sql(`SELECT stock_qty FROM products WHERE id = ${id}`));
const salesCount = () => Number(sql('SELECT COUNT(*) FROM sales'));
sql("INSERT INTO settings (setting_key, setting_value, updated_at) VALUES ('receipt_auto_print', '1', UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE setting_value = '1'");

// ---------------------------------------------------------------- app launcher and proxy
async function launch(userData, extraEnv = {}) {
  const app = await _electron.launch({
    executablePath: path.join(APP, 'node_modules/electron/dist/electron'),
    args: ['--no-sandbox', `--user-data-dir=${userData}`, APP],
    env: { ...process.env, MOTOSUPPLY_ALLOW_HTTP_LOCALHOST: '1', MOTOSUPPLY_PRINT_TO_PDF_DIR: PDFS,
      MOTOSUPPLY_MACHINE_CONFIG: path.join(WORK, 'no-machine-config.json'), ...extraEnv },
  });
  const page = await app.firstWindow();
  page.setDefaultTimeout(8000);
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  await page.waitForLoadState('domcontentloaded');
  return { app, page, errors };
}
async function setServer(page, url) {
  await page.waitForSelector('#screen-setup:not([hidden])');
  await page.fill('#server-url', url);
  await page.click('#setup-submit');
  await page.waitForSelector('#screen-login:not([hidden])');
}
async function login(page, user = 'desk_cashier', pass = PW) {
  await page.fill('#login-user', user);
  await page.fill('#login-pass', pass);
  await page.click('#login-submit');
}
async function scan(page, code) {
  await page.locator('#search').pressSequentially(code, { delay: 4 });
  await page.keyboard.press('Enter');
}
const lineQty = (page, name) => page.locator('.c-line', { hasText: name }).locator('.qty-value').textContent();
const pdfCount = () => fs.readdirSync(PDFS).filter((f) => f.endsWith('.pdf')).length;

// Proxy between the app and the server, to simulate network problems.
const proxy = { mode: 'pass', port: 0, sockets: new Set() };
// Switching to "down" also cuts the app's open keep-alive connections, like a real network failure.
const setProxy = (mode) => { proxy.mode = mode; if (mode === 'down') for (const s of proxy.sockets) s.destroy(); };
const [srvHost, srvPort] = new URL(SERVER).host.split(':');
const proxyServer = net.createServer((client) => {
  proxy.sockets.add(client);
  client.on('close', () => proxy.sockets.delete(client));
  if (proxy.mode === 'down') return client.destroy();
  const up = net.connect(Number(srvPort || 80), srvHost);
  let req = '';
  let drop = false;
  client.on('data', (d) => {
    req += d.toString('latin1');
    if (proxy.mode === 'drop-checkout' && req.includes('r=sales.checkout')) drop = true;
    up.write(d);
  });
  up.on('data', (d) => { if (!drop) client.write(d); });
  up.on('end', () => (drop ? client.destroy() : client.end()));
  up.on('error', () => client.destroy());
  client.on('error', () => up.destroy());
});
await new Promise((r) => proxyServer.listen(0, '127.0.0.1', r));
proxy.port = proxyServer.address().port;

// ================================================================ main session
const ud1 = path.join(WORK, 'user1');
let { app, page, errors } = await launch(ud1);

await step('Security: no Node.js in the screen, only the fixed bridge; no navigation or pop-ups', async () => {
  await page.waitForSelector('#screen-setup:not([hidden])');
  assert(await page.evaluate(() => typeof require === 'undefined' && typeof process === 'undefined'), 'no Node globals');
  const keys = await page.evaluate(() => Object.keys(window.moto).sort().join(','));
  assert(keys === 'authorizeServerChange,branding,cartState,checkServer,checkout,discardPending,init,login,logout,lookup,onSession,onStatus,print,printers,receipt,recent,reprint,search,setPrinter,stock', keys);
  assert(await page.evaluate(() => window.open('https://example.com') === null), 'window.open blocked');
  const fetched = await page.evaluate(() => fetch('https://example.com').then(() => 'loaded', () => 'blocked'));
  assert(fetched === 'blocked', 'network from the screen is blocked');
  assert((await app.windows()).length === 1, 'no extra windows');
  // (Navigation away from the app is tested last: Playwright keeps waiting after a cancelled navigation.)
});

await step('First run: server address is validated (HTTPS required, must be a MotoSupply server)', async () => {
  await page.waitForSelector('#screen-setup:not([hidden])');
  for (const [url, expect] of [['http://example.com', 'https://'], ['ftp://x.example', 'https://'], ['https://user:pw@shop.example.com', 'username or password'],
    ['http://127.0.0.1:1', 'Could not connect'], [SERVER + '/does-not-exist', 'MotoSupply']]) {
    await page.fill('#server-url', url);
    await page.click('#setup-submit');
    await page.waitForSelector('#setup-error:not([hidden])');
    const t = await page.textContent('#setup-error');
    assert(t.includes(expect), `${url}: ${t}`);
  }
  await page.screenshot({ path: `${SHOTS}/01-setup.png` });
  await setServer(page, SERVER);
  assert((await page.textContent('#login-host')).includes(new URL(SERVER).host));
});

await step('Sign-in screen shows the shop branding and connection status', async () => {
  await until(async () => (await page.textContent('#login-shop')) === 'MotoSupply Shop', 8000, 'shop name');
  await until(async () => (await page.getAttribute('[data-status-pill]', 'data-state')) === 'connected', 12000, 'connected');
  await page.screenshot({ path: `${SHOTS}/02-login.png` });
});

await step('Inventory-only, inactive and wrong-password accounts are refused', async () => {
  for (const [u, p, expect] of [['desk_stock', PW, 'not allowed to use the cashier app'], ['desk_off', PW, 'Invalid username or password'], ['desk_cashier', 'nope', 'Invalid username or password']]) {
    await login(page, u, p);
    await page.waitForSelector('#login-error:not([hidden])');
    const t = await page.textContent('#login-error');
    assert(t.includes(expect), `${u}: ${t}`);
    assert(await page.isHidden('#screen-pos'));
  }
});

await step('Changing the server needs an administrator; a cashier is refused', async () => {
  await page.click('#change-server');
  await page.fill('#admin-user', 'desk_cashier');
  await page.fill('#admin-pass', PW);
  await page.click('#admin-form button[type=submit]');
  await page.waitForSelector('#admin-error:not([hidden])');
  assert((await page.textContent('#admin-error')).includes('Only an administrator'));
  await page.fill('#admin-user', 'admin');
  await page.fill('#admin-pass', 'Moto!Counter2026');
  await page.click('#admin-form button[type=submit]');
  await page.waitForSelector('#screen-setup:not([hidden])');
  await page.click('#setup-cancel');
  await page.waitForSelector('#screen-login:not([hidden])');
  assert(sql("SELECT COUNT(*) FROM api_tokens t JOIN users u ON u.id = t.user_id WHERE u.username = 'admin' AND t.revoked_at IS NULL") === '0', 'admin check token revoked at once');
});

await step('Cashier signs in: POS screen only, no administration features', async () => {
  await login(page);
  await page.waitForSelector('#screen-pos:not([hidden])');
  await page.waitForSelector('.p-card');
  assert((await page.textContent('#pos-user')) === 'Cashier Tester');
  const body = await page.textContent('body');
  for (const w of ['User management', 'Adjust stock', 'Import', 'Audit log', 'Appearance', 'Updates', 'Reports']) assert(!body.includes(w), 'no ' + w);
  assert(await page.isHidden('#t-discount-row'), 'no discount control without permission');
  await page.screenshot({ path: `${SHOTS}/03-pos.png` });
});

await step('Search by name and SKU; category tabs; archived products are not listed', async () => {
  await page.fill('#search', 'NGK');
  await until(async () => (await page.locator('.p-card').count()) === 1 && (await page.textContent('.p-card')).includes('NGK'), 5000, 'name search');
  await page.fill('#search', 'DSK-027');
  await until(async () => (await page.textContent('#grid')).includes('Shell Advance'), 5000, 'sku search');
  await page.fill('#search', 'Discontinued');
  await until(async () => (await page.textContent('#grid')).includes('No products match'), 5000, 'archived hidden');
  await page.fill('#search', '');
  await page.keyboard.press('Escape');
  await page.click('.tab:has-text("Spark Plugs")');
  await until(async () => (await page.locator('.p-card').count()) === 1, 5000, 'category');
  await page.click('.tab:has-text("All products")');
  await until(async () => (await page.locator('.p-card').count()) === 3, 5000, 'all');
});

await step('Barcode scanner: scan adds to cart, rescan increments, unknown and archived codes are reported', async () => {
  await page.click('#cart-title'); // focus outside inputs: scanner keystrokes still reach the search box
  await scan(page, '4801234567890');
  await page.waitForSelector('.c-line');
  await scan(page, '4801234567890');
  await until(async () => (await lineQty(page, 'Motul')) === '2', 5000, 'qty 2');
  await scan(page, '0000000000000');
  await page.waitForSelector('#toast.is-error:not([hidden])');
  assert((await page.textContent('#toast')).includes('No active product'));
  await scan(page, '4809999999999');
  await until(async () => (await page.textContent('#toast')).includes('4809999999999'), 5000, 'archived refused');
  assert((await page.locator('.c-line').count()) === 1);
  assert(!(await page.textContent('body')).includes('Scanner ready'), 'no persistent scanner banner');
});

await step('Quantity keypad: digits, backspace, clear, stock limit, cancel and physical keys', async () => {
  await page.click('.c-line .qty-value');
  await page.waitForSelector('#dlg-qty[open]');
  assert((await page.textContent('#qty-stock')).includes('Available stock: 24'));
  const tag = await page.evaluate(() => document.activeElement.tagName);
  assert(tag === 'BUTTON', 'focus on a button, so the Windows touch keyboard does not open: ' + tag);
  for (const k of ['2', '5']) await page.click(`#dlg-qty [data-key="${k}"]`);
  await page.click('#qty-ok');
  assert((await page.textContent('#qty-error')).includes('Only 24'), 'over stock refused');
  await page.click('#dlg-qty [data-key="back"]');
  assert((await page.textContent('#qty-display')) === '2');
  await page.click('#dlg-qty [data-key="clear"]');
  await page.click('#dlg-qty [data-key="0"]');
  await page.click('#qty-ok');
  assert((await page.textContent('#qty-error')).includes('1 or more'), 'zero refused');
  await page.click('#dlg-qty .modal-actions [data-close]');
  assert((await lineQty(page, 'Motul')) === '2', 'cancel keeps quantity');
  await page.click('.c-line .qty-value');
  await page.keyboard.type('13');
  await page.keyboard.press('Backspace');
  await page.keyboard.type('5');
  await page.keyboard.press('Enter');
  await until(async () => (await lineQty(page, 'Motul')) === '15', 3000, 'qty 15');
  await page.click('.c-line .qty-value');
  await page.keyboard.type('3');
  await page.keyboard.press('Escape');
  assert((await lineQty(page, 'Motul')) === '15', 'Escape cancels');
  await page.screenshot({ path: `${SHOTS}/04-keypad.png` });
});

const clearSearch = async () => {
  await page.fill('#search', '');
  await page.press('#search', 'Escape');
  await page.waitForSelector('.p-card[aria-label^="Add NGK"]');
};
await step('Cart totals: line totals, subtotal, plus/minus and remove', async () => {
  await clearSearch();
  await page.click('.p-card[aria-label^="Add NGK"]');
  await page.click('.c-line:has-text("NGK") button[aria-label^="Increase"]');
  assert((await page.textContent('#t-subtotal')) === '₱7,990.00', await page.textContent('#t-subtotal')); // 15×450 + 2×620
  assert((await page.textContent('#t-total')) === '₱7,990.00');
  await page.click('.c-line:has-text("NGK") button[aria-label^="Decrease"]');
  assert((await page.textContent('#t-total')) === '₱7,370.00');
  await page.click('button[aria-label="Remove NGK Iridium Spark Plug"]');
  assert((await page.locator('.c-line').count()) === 1);
  assert((await page.textContent('#t-total')) === '₱6,750.00');
});

await step('Payment: short payment refused, change calculated, sale saved once, receipt printed automatically', async () => {
  const before = { sales: salesCount(), stock: stock(P.motul), pdf: pdfCount() };
  await page.keyboard.press('F8');
  await page.waitForSelector('#dlg-pay[open]');
  assert((await page.textContent('#pay-due')) === '₱6,750.00');
  for (const k of ['5', '0', '0', '0']) await page.click(`#dlg-pay [data-key="${k}"]`);
  assert((await page.textContent('#pay-change')).startsWith('Short'), 'short shown');
  await page.click('#pay-ok');
  assert((await page.textContent('#pay-error')).includes('Insufficient payment'));
  assert(salesCount() === before.sales, 'nothing saved');
  await page.click('#dlg-pay [data-key="clear"]');
  for (const k of ['7', '0', '0', '0']) await page.click(`#dlg-pay [data-key="${k}"]`);
  assert((await page.textContent('#pay-change')) === '₱250.00');
  await page.screenshot({ path: `${SHOTS}/05-payment.png` });
  // Double click and Enter: only one request may reach the server.
  await page.click('#pay-ok');
  await page.keyboard.press('Enter').catch(() => {});
  await page.waitForSelector('#dlg-done[open]');
  const txn = (await page.textContent('#done-txn')).replace('Transaction ', '');
  assert(/^MS-\d{6}-[0-9A-F]{6}$/.test(txn), txn);
  assert((await page.textContent('#done-change')) === '₱250.00');
  assert(salesCount() === before.sales + 1, 'exactly one sale');
  assert(stock(P.motul) === before.stock - 15, 'stock deducted by the server');
  await until(() => pdfCount() === before.pdf + 1, 8000, 'automatic receipt print');
  await until(async () => (await page.textContent('#done-print')).includes('sent to the printer'), 5000, 'print status');
  await page.screenshot({ path: `${SHOTS}/06-done.png` });
  await page.click('#done-printbtn');
  await until(() => pdfCount() === before.pdf + 2, 8000, 'manual print');
  assert(salesCount() === before.sales + 1 && stock(P.motul) === before.stock - 15, 'printing never repeats a sale');
  await page.keyboard.press('Enter');
  await page.waitForSelector('#dlg-done:not([open])', { state: 'attached' });
  assert((await page.textContent('#cart-count')) === '0 items', 'cart reset');
});

await step('Stock changed meanwhile: cashier must correct the cart; nothing is deducted', async () => {
  await clearSearch();
  await page.click('.p-card[aria-label^="Add Shell"]');
  await page.click('.c-line .qty-value');
  await page.keyboard.type('3');
  await page.keyboard.press('Enter');
  sql(`UPDATE products SET stock_qty = 1 WHERE id = ${P.shell}`); // another till sold two
  await page.keyboard.press('F8');
  await page.waitForSelector('.c-line.is-invalid');
  assert(await page.isHidden('#dlg-pay[open]'), 'payment not opened');
  assert((await page.textContent('.c-line.is-invalid')).includes('Only 1 available'));
  assert(await page.isDisabled('#btn-pay'), 'pay blocked');
  await page.click('.c-line .qty-value');
  await page.keyboard.type('1');
  await page.keyboard.press('Enter');
  await page.keyboard.press('F8');
  await page.waitForSelector('#dlg-pay[open]');
  sql(`UPDATE products SET stock_qty = 0 WHERE id = ${P.shell}`); // sold out while paying
  const before = salesCount();
  await page.click('#quick-cash button:first-child');
  await page.click('#pay-ok');
  await page.waitForSelector('#dlg-pay:not([open])', { state: 'attached' });
  await page.waitForSelector('.c-line.is-invalid');
  assert(salesCount() === before, 'server refused, nothing saved');
  assert(stock(P.shell) === 0, 'no deduction');
  await page.screenshot({ path: `${SHOTS}/07-stock-changed.png` });
  await page.click('button[aria-label="Remove Shell Advance AX7 1L"]');
  sql(`UPDATE products SET stock_qty = 3 WHERE id = ${P.shell}`);
});

await step('Today\'s sales: reprint is marked REPRINT and never creates a sale', async () => {
  const before = { sales: salesCount(), pdf: pdfCount() };
  await page.keyboard.press('F9');
  await page.waitForSelector('.recent-row');
  await page.click('.recent-row button');
  await until(async () => (await page.textContent('#recent-msg')).includes('sent to the printer'), 8000, 'reprint');
  assert(pdfCount() === before.pdf + 1 && salesCount() === before.sales);
  assert(fs.readdirSync(PDFS).some((f) => f.includes('-reprint-')), 'reprint copy marked');
  await page.screenshot({ path: `${SHOTS}/08-recent.png` });
  await page.keyboard.press('Escape');
});

await step('Session expiry: sign-in screen, cart kept and restored after signing in again', async () => {
  await scan(page, '4806512384012');
  await page.waitForSelector('.c-line');
  sql("UPDATE api_tokens SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE revoked_at IS NULL");
  await page.fill('#search', 'Motul');
  await page.waitForSelector('#screen-login:not([hidden])');
  assert((await page.textContent('#login-notice')).includes('expired'));
  await page.screenshot({ path: `${SHOTS}/09-session-expired.png` });
  await page.fill('#login-pass', PW);
  await page.click('#login-submit');
  await page.waitForSelector('#screen-pos:not([hidden])');
  assert((await lineQty(page, 'NGK')) === '1', 'cart restored');
});

await step('Sign-out revokes the session on the server', async () => {
  page.once('dialog', (d) => d.accept());
  await page.click('#btn-logout');
  await page.waitForSelector('#dlg-confirm[open]');
  await page.click('#confirm-yes');
  await page.waitForSelector('#screen-login:not([hidden])');
  assert(sql("SELECT COUNT(*) FROM api_tokens t JOIN users u ON u.id = t.user_id WHERE u.username = 'desk_cashier' AND t.revoked_at IS NULL AND t.expires_at > UTC_TIMESTAMP()") === '0');
});

await step('Different window sizes: no horizontal overflow; total and Pay stay visible', async () => {
  await login(page);
  await page.waitForSelector('#screen-pos:not([hidden])');
  await scan(page, '4801234567890');
  await page.waitForSelector('.c-line');
  for (const [w, h] of [[820, 600], [1024, 768], [1280, 800], [1366, 768], [1920, 1080]]) {
    await app.evaluate(({ BrowserWindow }, [w, h]) => { const b = BrowserWindow.getAllWindows()[0]; b.unmaximize(); b.setContentSize(w, h); }, [w, h]);
    await sleep(300);
    const r = await page.evaluate(() => {
      const pay = document.querySelector('#btn-pay').getBoundingClientRect();
      return { over: document.documentElement.scrollWidth - innerWidth, payVisible: pay.bottom <= innerHeight && pay.right <= innerWidth, w: innerWidth, h: innerHeight };
    });
    assert(r.over <= 0, `overflow ${r.over}px at ${w}x${h}`);
    assert(r.payVisible, `Pay visible at ${w}x${h}`);
    await page.screenshot({ path: `${SHOTS}/10-size-${w}x${h}.png` });
  }
  await app.evaluate(({ BrowserWindow }) => BrowserWindow.getAllWindows()[0].maximize());
  await page.click('#btn-clear');
  await page.click('#confirm-yes');
  await page.waitForSelector('.c-line', { state: 'detached' });
});

await step('No errors in the screen\'s console', async () => {
  const relevant = errors.filter((e) => !/status of 4\d\d/.test(e));
  assert(relevant.length === 0, relevant.join(' | '));
});
await app.close();

// ================================================================ network failures (through the proxy)
const ud2 = path.join(WORK, 'user2');
({ app, page, errors } = await launch(ud2));
const PROXY_URL = `http://127.0.0.1:${proxy.port}`;

await step('Server unreachable: status shows "No connection" and checkout fails without a sale', async () => {
  await setServer(page, PROXY_URL);
  await login(page, 'desk_cashier2');
  await page.waitForSelector('#screen-pos:not([hidden])');
  await scan(page, '4806512384012');
  await page.waitForSelector('.c-line');
  await page.keyboard.press('F8');
  await page.waitForSelector('#dlg-pay[open]');
  await page.click('#quick-cash button:first-child');
  setProxy('down');
  const before = salesCount();
  await page.click('#pay-ok');
  await page.waitForSelector('#pay-error:not([hidden])');
  assert(/connection|reach/i.test(await page.textContent('#pay-error')), await page.textContent('#pay-error'));
  assert(await page.isHidden('#dlg-done[open]'), 'not reported as completed');
  assert(salesCount() === before, 'no sale');
  await until(async () => (await page.getAttribute('[data-status-pill]', 'data-state')) === 'offline', 15000, 'offline status');
  await page.screenshot({ path: `${SHOTS}/11-offline.png` });
  assert((await lineQty(page, 'NGK')) === '1', 'cart kept');
});

await step('Answer lost after the server saved the sale: "Check and retry" shows it, no duplicate', async () => {
  setProxy('drop-checkout');
  const before = { sales: salesCount(), stock: stock(P.ngk) };
  await page.click('#pay-ok');
  await page.waitForSelector('#pay-error:not([hidden])');
  assert((await page.textContent('#pay-error')).includes('not known yet'), await page.textContent('#pay-error'));
  assert((await page.textContent('#pay-ok')) === 'Check and retry');
  assert(salesCount() === before.sales + 1, 'server committed the sale');
  await page.screenshot({ path: `${SHOTS}/12-unknown-outcome.png` });
  setProxy('pass');
  await page.click('#pay-ok');
  await page.waitForSelector('#dlg-done[open]');
  assert((await page.textContent('#done-print')).includes('not charged twice'));
  assert(salesCount() === before.sales + 1, 'still exactly one sale');
  assert(stock(P.ngk) === before.stock - 1, 'deducted once');
  await page.keyboard.press('Enter');
});

await step('App closed while the outcome was unknown: next sign-in reports the saved sale', async () => {
  await scan(page, '4801234567890');
  await page.waitForSelector('.c-line');
  await page.keyboard.press('F8');
  await page.waitForSelector('#dlg-pay[open]');
  await page.click('#quick-cash button:first-child');
  setProxy('drop-checkout');
  const before = salesCount();
  await page.click('#pay-ok');
  await page.waitForSelector('#pay-error:not([hidden])');
  setProxy('pass');
  await app.evaluate(({ app: a }) => a.exit(0)); // crash-like exit, no clean-up
  ({ app, page, errors } = await launch(ud2));
  await page.waitForSelector('#screen-login:not([hidden])');
  await login(page, 'desk_cashier2');
  await page.waitForSelector('#dlg-done[open]');
  assert((await page.textContent('#done-print')).includes('completed before the app closed'));
  assert(salesCount() === before + 1, 'one sale');
  await page.keyboard.press('Enter');
});
await app.close();

// ================================================================ locked machine configuration
await step('Administrator-locked server address: no setup screen, no server change; navigation away is blocked', async () => {
  const cfg = path.join(WORK, 'machine.json');
  fs.writeFileSync(cfg, JSON.stringify({ server_url: SERVER }));
  const l = await launch(path.join(WORK, 'user3'), { MOTOSUPPLY_MACHINE_CONFIG: cfg });
  await l.page.waitForSelector('#screen-login:not([hidden])');
  assert(await l.page.isHidden('#change-server'), 'no server settings link');
  const r = await l.page.evaluate(() => window.moto.checkServer('http://127.0.0.1:8090'));
  assert(r.ok === false && r.error.code === 'locked', JSON.stringify(r));
  const before = l.page.url();
  await l.page.evaluate(() => { location.href = 'https://example.com/'; });
  await sleep(800);
  assert(l.page.url() === before, 'navigation away from the app is blocked: ' + l.page.url());
  assert(await l.page.evaluate(() => !document.querySelector('#screen-login').hidden), 'screen intact');
  await l.app.close();
});

proxyServer.close();
fs.writeFileSync(path.join(SHOTS, 'results.json'), JSON.stringify(results, null, 2));
console.log(`\n${results.length - failed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
