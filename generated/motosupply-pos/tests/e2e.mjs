// Browser end-to-end test (development only; Node is NOT needed in production).
// Usage: node tests/e2e.mjs http://127.0.0.1:8080 <screenshot-dir>
import { createRequire } from 'module';
const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

const BASE = process.argv[2] || 'http://127.0.0.1:8080';
const SHOTS = process.argv[3] || './screens';
const NEW_PW = 'Moto$hop2026';
const VOID_PIN = '482915';
const ADMIN_USER = process.env.MOTO_ADMIN_USER || 'admin';
const INITIAL_PW = process.env.MOTO_INITIAL_PW || 'Initial#Pass2026';
const results = [];
let failed = 0;

async function step(name, fn) {
  try { await fn(); results.push(['PASS', name]); console.log('  PASS ', name); }
  catch (e) {
    failed++;
    const lines = String(e.message || e).split('\n');
    const msg = lines[0] + (lines.find((l) => /waiting for/.test(l)) ? ' — ' + lines.find((l) => /waiting for/.test(l)).trim() : '');
    results.push(['FAIL', name, msg]);
    console.log('  FAIL ', name, '\n       ', msg);
  }
}
function assert(c, m) { if (!c) throw new Error(m || 'assertion failed'); }

const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, acceptDownloads: true });
const page = await ctx.newPage();
page.setDefaultTimeout(8000);
const consoleErrors = [];
page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text()); });
page.on('pageerror', (e) => consoleErrors.push(String(e)));
page.on('dialog', (d) => d.accept());
const url = (r, q = '') => `${BASE}/index.php?r=${r}${q}`;

await step('Unauthenticated pages redirect to login', async () => {
  await page.goto(url('products'));
  assert(page.url().includes('r=login'), page.url());
});
await step('Login page: logo area, show/hide password, no default credentials shown', async () => {
  assert(await page.isVisible('.login-shell'), 'professional login layout');
  await page.fill('#password', 'abc');
  await page.click('[data-pw-toggle]');
  assert((await page.getAttribute('#password', 'type')) === 'text', 'password shown');
  assert((await page.getAttribute('[data-pw-toggle]', 'aria-pressed')) === 'true');
  await page.click('[data-pw-toggle]');
  assert((await page.getAttribute('#password', 'type')) === 'password', 'password hidden again');
  const text = (await page.textContent('body')).toLowerCase();
  assert(!/default (password|login)|admin\s*\/\s*admin|password:\s*\w/.test(text), 'no default credentials on the page');
  assert(await page.getAttribute('#username', 'autocomplete') === 'username');
  assert(await page.getAttribute('#password', 'autocomplete') === 'current-password');
  await page.fill('#password', '');
  await page.click('button[type=submit]');
  assert(page.url().includes('r=login'), 'empty password is not submitted');
});
await step('Invalid login shows a generic error', async () => {
  await page.fill('#username', ADMIN_USER);
  await page.fill('#password', 'wrong-password');
  await page.click('button[type=submit]');
  assert(await page.isVisible('text=Invalid username or password.'));
});
await step('Login works and a flagged account is forced to change its password', async () => {
  await page.fill('#username', ADMIN_USER);
  await page.fill('#password', INITIAL_PW);
  await page.click('button[type=submit]');
  await page.waitForURL(/password\.change/);
  await page.screenshot({ path: `${SHOTS}/01-forced-password-change.png` });
  await page.goto(url('dashboard'));
  assert(page.url().includes('password.change'), 'dashboard blocked until password changed');
  await page.fill('#current_password', INITIAL_PW);
  await page.fill('#new_password', NEW_PW);
  await page.fill('#confirm_password', NEW_PW);
  await page.click('form[action*="password.change"] button[type=submit]');
  await page.waitForURL(/r=dashboard/);
});
await step('Dashboard shows empty states with no data', async () => {
  assert(await page.isVisible('text=No transactions yet'), 'recent empty state');
  assert((await page.textContent('.stat-grid')).includes('₱0.00'));
  await page.screenshot({ path: `${SHOTS}/02-dashboard-empty.png`, fullPage: true });
});

const products = [
  ['Motul 5100 4T 10W-40', 'MS-001', '4801234567890', 'Engine Oil', '350', '450', '24'],
  ['NGK Iridium Spark Plug', 'MS-014', '4806512384012', 'Spark Plugs', '470', '620', '8'],
  ['Honda Genuine Oil 10W-30', 'MS-027', '4807123098211', 'Engine Oil', '285', '380', '32'],
  ['RK Heavy Duty Chain 428H', 'MS-043', '4809214539008', 'Chains', '675', '895', '12'],
  ['Bendix Ceramic Brake Pads', 'MS-058', '4805123567781', 'Brake System', '520', '720', '6'],
  ['Yuasa YTX7L-BS Battery', 'MS-072', '4808214765320', 'Batteries', '1420', '1850', '4'],
  ['Osram LED Headlight H4', 'MS-089', '4804456721288', 'Electrical', '900', '1250', '0'],
  ['Motul C2 Chain Lube 400ml', 'MS-105', '4801134598722', 'Lubricants', '410', '560', '18'],
];
await step('Create products through the form', async () => {
  for (const [name, sku, barcode, cat, cost, price, stock] of products) {
    await page.goto(url('products.create'));
    await page.fill('#name', name); await page.fill('#sku', sku); await page.fill('#barcode', barcode);
    await page.fill('#category', cat); await page.fill('#cost_price', cost); await page.fill('#selling_price', price);
    await page.fill('#stock_qty', stock); await page.fill('#low_stock_threshold', '10');
    await page.click('main form.card button[type=submit]');
    await page.waitForURL(/r=products$/);
  }
  assert((await page.textContent('.pager-info')).includes('of 8'), 'eight products listed');
});
await step('Duplicate SKU shows a field error', async () => {
  await page.goto(url('products.create'));
  await page.fill('#name', 'Dup'); await page.fill('#sku', 'ms-001'); await page.fill('#selling_price', '10');
  await page.click('main form.card button[type=submit]');
  assert(await page.isVisible('text=Another product already uses this SKU.'));
  assert(await page.inputValue('#name') === 'Dup', 'form keeps entered values');
});
await step('Product image upload is stored and served', async () => {
  const fs = await import('fs');
  // 1x1 PNG
  const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
  fs.writeFileSync(`${SHOTS}/tiny.png`, png);
  await page.goto(url('products', '&q=MS-105'));
  await page.click('a.strong:has-text("Motul C2")');
  await page.setInputFiles('#image', `${SHOTS}/tiny.png`);
  await page.click('main form.card button[type=submit]');
  await page.waitForURL(/r=products$/);
  await page.goto(url('products', '&q=MS-105'));
  const src = await page.getAttribute('img.thumb', 'src');
  assert(src && /uploads\/products\/[a-f0-9]{32}\.png$/.test(src), 'image path ' + src);
  const r = await page.request.get(new URL(src, BASE).href);
  assert(r.status() === 200 && (await r.body()).length === png.length, 'image served');
  // A PHP file disguised as an image is rejected.
  fs.writeFileSync(`${SHOTS}/evil.png`, '<?php echo 1;');
  await page.click('a.strong:has-text("Motul C2")');
  await page.setInputFiles('#image', `${SHOTS}/evil.png`);
  await page.click('main form.card button[type=submit]');
  assert(await page.isVisible('text=Upload a JPG, PNG, WEBP or GIF image.'));
});
await step('Inventory page renders with stock status', async () => {
  await page.goto(url('products'));
  assert(await page.isVisible('text=Out of Stock'));
  await page.screenshot({ path: `${SHOTS}/03-inventory.png`, fullPage: true });
});
await step('Manual stock adjustment is recorded', async () => {
  await page.goto(url('products', '&q=MS-089'));
  await page.click('details.menu summary');
  await page.click('text=Adjust stock');
  await page.check('input[value=add]');
  await page.fill('#quantity', '3');
  await page.fill('#reason', 'Delivery received');
  await page.click('main form.card button[type=submit]');
  await page.waitForURL(/products\.movements/);
  assert(await page.isVisible('td:has-text("Delivery received")'));
  await page.screenshot({ path: `${SHOTS}/04-stock-history.png` });
});

let txn = '';
await step('POS: barcode scan (type + Enter) adds to cart; unknown barcode is reported', async () => {
  await page.goto(url('pos'));
  await page.waitForSelector('.p-card');
  // Scanner = fast typing followed by Enter.
  await page.locator('#pos-search').pressSequentially('4801234567890', { delay: 5 });
  await page.keyboard.press('Enter');
  await page.waitForSelector('.c-line');
  await page.locator('#pos-search').pressSequentially('4801234567890', { delay: 5 });
  await page.keyboard.press('Enter');
  await page.waitForFunction(() => document.querySelector('.c-line .qty-value')?.textContent === '2');
  await page.locator('#pos-search').pressSequentially('0000000000000', { delay: 5 });
  await page.keyboard.press('Enter');
  await page.waitForSelector('.toast.is-error:not([hidden])');
  assert((await page.textContent('.toast')).includes('No product found'));
  assert(!(await page.isVisible('text=Scanner ready')), 'no persistent scanner banner');
});
await step('POS: add by search/click, category tab, change quantity, discount', async () => {
  await page.fill('#pos-search', '');
  await page.click('.tab:has-text("Spark Plugs")');
  await page.waitForFunction(() => document.querySelectorAll('.p-card').length === 1);
  await page.click('.p-card button[aria-label^="Add NGK"]');
  await page.click('.tab:has-text("All Products")');
  await page.waitForFunction(() => document.querySelectorAll('.p-card').length === 8);
  assert(await page.isDisabled('.p-card button[aria-label^="Add Osram"]') === false, 'Osram has 3 after adjustment');
  await page.click('.p-card button[aria-label^="Add RK"]');
  await page.click('button[aria-label="Remove RK Heavy Duty Chain 428H"]');
  await page.click('[data-discount-toggle]');
  await page.fill('[data-discount-value]', '100');
  const total = await page.textContent('[data-total]');
  assert(total === '₱1,420.00', 'total ' + total); // 2×450 + 620 − 100
  await page.screenshot({ path: `${SHOTS}/05-pos-cart.png` });
});
await step('POS: insufficient payment is rejected, then sale completes', async () => {
  await page.keyboard.press('F8');
  await page.waitForSelector('#pay-dialog[open]');
  await page.fill('#tendered', '1000');
  assert((await page.textContent('[data-pay-change]')).startsWith('Short'));
  await page.click('[data-complete]');
  assert(await page.isVisible('[data-pay-error]:has-text("Insufficient")'));
  await page.fill('#tendered', '2000');
  assert((await page.textContent('[data-pay-change]')) === '₱580.00');
  await page.screenshot({ path: `${SHOTS}/06-pos-payment.png` });
  await page.click('[data-complete]');
  await page.waitForSelector('#done-dialog[open]');
  txn = (await page.textContent('[data-done-txn]')).replace('Transaction #', '').trim();
  assert(/^MS-\d{6}-[0-9A-F]{6}$/.test(txn), txn);
  assert((await page.textContent('[data-done-change]')) === '₱580.00');
  await page.screenshot({ path: `${SHOTS}/07-pos-done.png` });
  await page.click('[data-new-sale]');
  assert((await page.textContent('[data-cart-count]')) === '0 items', 'cart reset');
});
await step('Receipt shows all required fields', async () => {
  await page.goto(url('sales'));
  await page.click(`a:has-text("#${txn}") >> nth=0`);
  const href = await page.getAttribute('a:has-text("Print receipt")', 'href');
  const r = await ctx.newPage();
  r.on('dialog', (d) => d.dismiss());
  await r.goto(new URL(href, BASE).href.replace('&print=1', ''));
  const text = await r.textContent('.receipt');
  for (const s of ['MotoSupply Shop', txn, 'Motul 5100 4T 10W-40', 'NGK Iridium Spark Plug', '₱1,520.00', '-₱100.00', '₱1,420.00', '₱2,000.00', '₱580.00', 'Cash tendered', 'Change']) {
    assert(text.includes(s), 'receipt missing ' + s);
  }
  await r.screenshot({ path: `${SHOTS}/08-receipt.png`, fullPage: true });
  await r.close();
});
await step('Stock deducted after the sale', async () => {
  await page.goto(url('products', '&q=MS-001'));
  assert((await page.textContent('tbody tr')).includes('22'));
});
await step('Void approval PIN is set in My Account (separate from the password)', async () => {
  await page.goto(url('account'));
  await page.fill('#pin_current_password', NEW_PW);
  await page.fill('#pin', '123456'); await page.fill('#pin2', '123456');
  await page.click('form[action*="account.pin"] button[type=submit]');
  assert(await page.isVisible('text=simple sequence'), 'sequential PIN refused');
  await page.fill('#pin_current_password', NEW_PW);
  await page.fill('#pin', VOID_PIN); await page.fill('#pin2', VOID_PIN);
  await page.click('form[action*="account.pin"] button[type=submit]');
  assert(await page.isVisible('text=Your void approval PIN is saved'));
  assert(!(await page.content()).includes(VOID_PIN), 'PIN never shown again');
});
await step('Sales history, detail and void with supervisor approval', async () => {
  await page.goto(url('sales'));
  await page.screenshot({ path: `${SHOTS}/09-sales-history.png`, fullPage: true });
  await page.click(`a:has-text("#${txn}") >> nth=0`);
  await page.fill('#void-reason', 'Customer changed mind');
  await page.fill('#void-approver', ADMIN_USER);
  await page.fill('#void-pin', '000000');
  await page.click('button:has-text("Void sale")');
  assert(await page.isVisible('text=Approval failed'), 'wrong PIN refused');
  assert(!(await page.isVisible('text=The sale was voided')));
  await page.fill('#void-reason', 'Customer changed mind');
  await page.fill('#void-approver', ADMIN_USER);
  await page.fill('#void-pin', NEW_PW);
  await page.click('button:has-text("Void sale")');
  assert(await page.isVisible('text=Approval failed'), 'login password is not accepted as the void PIN');
  await page.fill('#void-reason', 'Customer changed mind');
  await page.fill('#void-approver', ADMIN_USER);
  await page.fill('#void-pin', VOID_PIN);
  await page.click('button:has-text("Void sale")');
  assert(await page.isVisible('text=The sale was voided'));
  assert((await page.textContent('main')).includes('approved by ' + ADMIN_USER), 'approver shown');
  assert(!(await page.isVisible('button:has-text("Void sale")')), 'cannot void twice');
  await page.screenshot({ path: `${SHOTS}/10-sale-voided.png`, fullPage: true });
});
await step('Second sale for reports', async () => {
  await page.goto(url('pos'));
  await page.waitForSelector('.p-card');
  await page.locator('#pos-search').pressSequentially('MS-072', { delay: 5 });
  await page.keyboard.press('Enter');
  await page.waitForSelector('.c-line');
  await page.click('[data-pay]');
  await page.click('.quick-cash button:has-text("Exact")');
  await page.click('[data-complete]');
  await page.waitForSelector('#done-dialog[open]');
  await page.click('[data-new-sale]');
});
await step('Dashboard shows real data', async () => {
  await page.goto(url('dashboard'));
  const stats = await page.textContent('.stat-grid');
  assert(stats.includes('₱1,850.00'), 'today net excludes voided sale: ' + stats.replace(/\s+/g, ' '));
  assert(await page.isVisible('.bar-chart'));
  await page.screenshot({ path: `${SHOTS}/11-dashboard.png`, fullPage: true });
});
await step('Reports page and CSV/PDF downloads', async () => {
  await page.goto(url('reports', '&type=sales&group=day&preset=today'));
  assert((await page.textContent('.summary-grid')).includes('₱1,850.00'));
  await page.screenshot({ path: `${SHOTS}/12-reports.png`, fullPage: true });
  const [csv] = await Promise.all([page.waitForEvent('download'), page.click('a:has-text("Export CSV")')]);
  const csvPath = await csv.path();
  const fs = await import('fs');
  const body = fs.readFileSync(csvPath, 'utf8');
  assert(body.includes('Net sales (revenue)'), 'csv summary');
  const [pdf] = await Promise.all([page.waitForEvent('download'), page.click('a:has-text("Download PDF")')]);
  const pdfBuf = fs.readFileSync(await pdf.path());
  assert(pdfBuf.subarray(0, 8).toString() === '%PDF-1.4', 'pdf header');
  fs.copyFileSync(await pdf.path(), `${SHOTS}/sales-report.pdf`);
  fs.copyFileSync(csvPath, `${SHOTS}/sales-report.csv`);
});
await step('Settings save and system check', async () => {
  await page.goto(url('settings'));
  await page.fill('#shop_address', '128 Rizal Avenue, Quezon City');
  await page.fill('#shop_phone', '+63 917 555 0182');
  await page.click('.page-head button:has-text("Save Changes")');
  assert(await page.isVisible('text=Settings saved.'));
  assert((await page.inputValue('#shop_address')) === '128 Rizal Avenue, Quezon City');
  await page.screenshot({ path: `${SHOTS}/13-settings.png`, fullPage: true });
  await page.goto(url('settings.system'));
  await page.screenshot({ path: `${SHOTS}/14-system-check.png`, fullPage: true });
});
const display = () => page.textContent('[data-keypad-display]');
const lineQty = () => page.textContent('.c-line .qty-value');
await step('POS keypad: digits, backspace, clear, stock limit, cancel, physical keyboard', async () => {
  await page.goto(url('pos'));
  await page.waitForSelector('.p-card');
  await page.click('.p-card button[aria-label^="Add Motul 5100"]');
  await page.waitForSelector('.c-line');
  await page.click('.c-line .qty-value');
  await page.waitForSelector('#keypad-dialog[open]');
  const stockText = await page.textContent('[data-keypad-stock]');
  const avail = Number((stockText.match(/Available stock: (\d+)/) || [])[1]);
  assert(avail === 24, 'shows available stock: ' + stockText); // 24 − 2 sold + 2 returned by the void
  const tag = await page.evaluate(() => document.activeElement && document.activeElement.tagName);
  assert(tag !== 'INPUT' && tag !== 'TEXTAREA', 'no text field focused, so the phone keyboard stays closed');
  await page.screenshot({ path: `${SHOTS}/17-keypad.png` });
  for (const k of ['1', '5']) await page.click(`[data-key="${k}"]`);
  assert((await display()) === '15', 'multi-digit entry');
  await page.click('[data-key="back"]');
  assert((await display()) === '1', 'backspace');
  await page.click('[data-key="clear"]');
  for (const k of ['9', '9']) await page.click(`[data-key="${k}"]`);
  await page.click('[data-keypad-confirm]');
  assert(await page.isVisible('[data-keypad-error]:has-text("Only 24 in stock")'), 'over-stock refused');
  assert(await page.isVisible('#keypad-dialog[open]'), 'dialog stays open');
  assert((await lineQty()) === '1', 'cart unchanged until a valid Confirm');
  await page.click('[data-key="clear"]');
  await page.click('[data-key="0"]');
  await page.click('[data-keypad-confirm]');
  assert(await page.isVisible('[data-keypad-error]:has-text("1 or more")'), 'zero refused');
  await page.click('[data-key="clear"]');
  await page.click('[data-key="7"]');
  await page.click('.modal-actions [data-keypad-cancel]');
  assert((await lineQty()) === '1', 'cancel keeps the previous quantity');
  await page.click('.c-line .qty-value');
  await page.keyboard.type('12');
  await page.keyboard.press('Backspace');
  await page.keyboard.type('0');
  assert((await display()) === '10', 'physical keyboard digits and backspace');
  await page.keyboard.press('Enter');
  await page.waitForFunction(() => !document.querySelector('#keypad-dialog').open);
  assert((await lineQty()) === '10', 'Enter confirms');
  assert((await page.textContent('[data-total]')) === '₱4,500.00');
  await page.click('.c-line .qty-value');
  await page.keyboard.type('3');
  await page.keyboard.press('Escape');
  assert((await lineQty()) === '10', 'Escape cancels');
});
await step('POS: checkout is blocked and nothing is deducted when stock ran out meanwhile', async () => {
  // Another cashier sells/adjusts stock while this cart is open.
  const other = await ctx.newPage();
  await other.goto(url('products', '&q=MS-001'));
  await other.click('details.menu summary');
  await other.click('text=Adjust stock');
  await other.check('input[value=set]');
  await other.fill('#quantity', '4');
  await other.fill('#reason', 'Recount');
  await other.click('main form.card button[type=submit]');
  await other.close();
  await page.click('[data-pay]');
  await page.waitForSelector('#pay-dialog[open]');
  await page.click('.quick-cash button:has-text("Exact")');
  await page.click('[data-complete]');
  await page.waitForSelector('[data-pay-error]:not([hidden])');
  assert(/stock|available/i.test(await page.textContent('[data-pay-error]')), await page.textContent('[data-pay-error]'));
  await page.click('#pay-dialog [data-close] >> nth=0');
  const p2 = await ctx.newPage();
  await p2.goto(url('products', '&q=MS-001'));
  assert((await p2.textContent('tbody tr')).includes('4'), 'stock still 4');
  await p2.close();
  await page.click('[data-clear]');
});
await step('Sidebar collapses to an icon rail with tooltips and remembers the choice', async () => {
  await page.goto(url('dashboard'));
  await page.click('[data-rail-toggle]');
  assert(await page.evaluate(() => document.body.classList.contains('sidebar-rail')), 'rail mode');
  await page.waitForTimeout(400); // width transition
  const titles = await page.$$eval('.sidebar .nav-link', (ls) => ls.map((l) => l.getAttribute('title') || l.getAttribute('aria-label')));
  assert(titles.length > 3 && titles.every(Boolean), 'every rail icon has a tooltip/label');
  const w = await page.evaluate(() => document.querySelector('.sidebar').getBoundingClientRect().width);
  assert(w <= 96, 'rail width ' + w);
  await page.screenshot({ path: `${SHOTS}/18-sidebar-rail.png` });
  await page.reload();
  assert(await page.evaluate(() => document.body.classList.contains('sidebar-rail')), 'remembered after reload');
  await page.click('[data-rail-toggle]');
  await page.reload();
  assert(!(await page.evaluate(() => document.body.classList.contains('sidebar-rail'))), 'expanded again');
});
await step('Theme: live preview, saved, applied everywhere, reset', async () => {
  await page.goto(url('settings.appearance'));
  await page.fill('#theme_primary', '#1d4ed8');
  const live = await page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--accent').trim());
  assert(live === '#1d4ed8', 'live preview ' + live);
  await page.click('button:has-text("Save theme")');
  await page.goto(url('dashboard'));
  const saved = await page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--accent').trim());
  assert(saved === '#1d4ed8', 'persisted ' + saved);
  await page.screenshot({ path: `${SHOTS}/19-theme-blue.png` });
  await page.goto(url('settings.appearance'));
  await page.click('button:has-text("Reset to default")');
  await page.goto(url('dashboard'));
  assert((await page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--accent').trim())) === '#dd4a2b');
});
await step('Receipt settings: paper size, auto-print toggle and test print', async () => {
  await page.goto(url('settings.receipt'));
  await page.selectOption('#receipt_paper', '58mm');
  await page.check('#receipt_auto_print');
  await page.click('main form button[type=submit]');
  assert(await page.isChecked('#receipt_auto_print'));
  const [popup] = await Promise.all([ctx.waitForEvent('page'), page.click('a:has-text("Test print")')]);
  popup.on('dialog', (d) => d.dismiss());
  await popup.waitForLoadState();
  assert((await popup.textContent('body')).includes('TEST'), 'test receipt is marked as a test');
  assert(await popup.evaluate(() => document.body.className.includes('paper-58')), 'paper size applied');
  await popup.close();
  // With auto-print on, a completed sale prints through a hidden frame and never blocks the sale.
  await page.goto(url('pos'));
  await page.waitForSelector('.p-card');
  await page.click('.p-card button[aria-label^="Add Motul C2"]');
  await page.click('[data-pay]');
  await page.click('.quick-cash button:has-text("Exact")');
  await page.click('[data-complete]');
  await page.waitForSelector('#done-dialog[open]');
  await page.waitForSelector('[data-print-holder] iframe');
  assert((await page.getAttribute('[data-print-holder] iframe', 'src')).includes('embed=1'));
  await page.click('[data-new-sale]');
  await page.goto(url('settings.receipt'));
  await page.uncheck('#receipt_auto_print');
  await page.selectOption('#receipt_paper', '80mm');
  await page.click('main form button[type=submit]');
});
await step('CSV import: template download, preview with errors, confirm, summary', async () => {
  const fs = await import('fs');
  await page.goto(url('products.import'));
  const [tpl] = await Promise.all([page.waitForEvent('download'), page.click('a:has-text("with example")')]);
  const tplText = fs.readFileSync(await tpl.path(), 'utf8');
  assert(tplText.includes('EXAMPLE-SKU-001') && tplText.startsWith('﻿name,sku'), 'template with example row');
  fs.writeFileSync(`${SHOTS}/import.csv`, tplText + 'Shell Advance AX7 1L,IMP-001,,Engine Oil,,300,395,12,,bottle\nBroken row,IMP-002,,,,abc,,1,,\nMotul 5100 4T 10W-40,MS-001,,Engine Oil,,350,999,500,,bottle\n');
  await page.setInputFiles('#file', `${SHOTS}/import.csv`);
  await page.click('button:has-text("Upload and preview")');
  await page.waitForSelector('text=New products');
  const strip = await page.textContent('.summary-grid');
  assert(/New products\s*1/.test(strip) && /Skipped\s*2/.test(strip) && /errors\s*1/.test(strip), strip.replace(/\s+/g, ' '));
  await page.screenshot({ path: `${SHOTS}/20-import-preview.png`, fullPage: true });
  await page.click('button:has-text("Import 1 product")');
  await page.waitForSelector('text=Import finished');
  await page.goto(url('products', '&q=IMP-001'));
  assert((await page.textContent('tbody')).includes('Shell Advance AX7'), 'imported');
  await page.goto(url('products', '&q=MS-001'));
  assert(!(await page.textContent('tbody')).includes('999'), 'existing price untouched in create mode');
});
await step('Users: create a cashier; the cashier sees only permitted pages', async () => {
  await page.goto(url('users.create'));
  await page.fill('#username', 'till1');
  await page.fill('#full_name', 'Till One');
  await page.selectOption('#role_id', { label: 'Cashier — Runs the POS and views sales and inventory.' });
  await page.fill('#password', 'Counter#Till42');
  await page.fill('#password2', 'Counter#Till42');
  await page.click('main form button[type=submit]');
  await page.waitForURL(/r=users$/);
  assert((await page.textContent('main')).includes('till1'));
  await page.screenshot({ path: `${SHOTS}/21-users.png`, fullPage: true });
  const c2 = await browser.newContext({ viewport: { width: 1280, height: 800 } });
  const p = await c2.newPage();
  await p.goto(url('login'));
  await p.fill('#username', 'till1'); await p.fill('#password', 'Counter#Till42');
  await p.click('button[type=submit]');
  await p.waitForURL(/password\.change/);
  await p.fill('#current_password', 'Counter#Till42');
  await p.fill('#new_password', 'Register#Till77'); await p.fill('#confirm_password', 'Register#Till77');
  await p.click('form[action*="password.change"] button[type=submit]');
  await p.waitForLoadState();
  const nav = await p.textContent('.sidebar nav');
  assert(nav.includes('POS') && !nav.includes('Settings') && !nav.includes('Reports') && !nav.includes('Users'), 'nav: ' + nav.replace(/\s+/g, ' '));
  await p.goto(url('pos'));
  await p.waitForSelector('.p-card');
  assert(!(await p.isVisible('[data-discount-toggle]')), 'no discount control without permission');
  const r = await p.goto(url('settings'));
  assert(r.status() === 403, 'settings refused: ' + r.status());
  await c2.close();
});
await step('Email reports: settings and test email through SMTP', async () => {
  const { spawn } = await import('child_process');
  const fs = await import('fs');
  const dir = fs.mkdtempSync('/tmp/moto-e2e-smtp-');
  const port = 3525 + Math.floor(Math.random() * 300);
  const sink = spawn('python3', [new URL('./smtp_sink.py', import.meta.url).pathname, String(port), `${dir}/mail`, '/nonexistent', '/nonexistent']);
  await new Promise((res) => sink.stdout.once('data', res));
  try {
    await page.goto(url('settings.email'));
    await page.fill('#email_recipients', 'owner@shop.test');
    await page.selectOption('#mail_transport', 'smtp');
    await page.fill('#smtp_host', '127.0.0.1');
    await page.fill('#smtp_port', String(port));
    await page.selectOption('#smtp_encryption', 'none');
    await page.fill('#mail_from_address', 'reports@shop.test');
    await page.click('form[action*="settings.email"] button:has-text("Save")');
    assert(await page.isVisible('text=saved'), 'saved');
    await page.click('button:has-text("Send test email")');
    assert(await page.isVisible('text=Test email sent'), await page.textContent('main .alert'));
    const files = fs.readdirSync(`${dir}/mail`).filter((f) => f.endsWith('.eml'));
    assert(files.length === 1, 'one email delivered');
    const eml = fs.readFileSync(`${dir}/mail/${files[0]}`, 'utf8');
    const subj = (eml.match(/^Subject: (.*)$/m) || [])[1] || '';
    const decoded = subj.replace(/=\?UTF-8\?B\?([^?]+)\?=/g, (_, b) => Buffer.from(b, 'base64').toString('utf8'));
    assert(decoded.startsWith('[TEST]'), 'marked as test: ' + decoded);
    await page.screenshot({ path: `${SHOTS}/22-email-settings.png`, fullPage: true });
  } finally {
    sink.kill();
  }
});
await step('Audit log lists sensitive actions without secrets', async () => {
  await page.goto(url('audit'));
  const text = await page.textContent('main');
  for (const a of ['sale.void', 'user.create', 'user.void_pin.set', 'login']) assert(text.includes(a), 'audit shows ' + a);
  assert(!text.includes(VOID_PIN) && !text.includes(NEW_PW), 'no secrets');
  await page.screenshot({ path: `${SHOTS}/23-audit.png`, fullPage: true });
});
await step('Responsive: no horizontal overflow on phones, tablets (portrait/landscape) and desktops', async () => {
  const sizes = [[360, 740], [390, 844], [768, 1024], [1024, 768], [820, 1180], [1180, 820], [1280, 800], [1440, 900]];
  const routes = ['dashboard', 'pos', 'products', 'products.create', 'products.import', 'inventory.integrity', 'sales', 'reports',
    'users', 'users.create', 'account', 'settings', 'settings.receipt', 'settings.email', 'settings.appearance', 'settings.system', 'audit', 'updates'];
  for (const [w, h] of sizes) {
    await page.setViewportSize({ width: w, height: h });
    for (const r of routes) {
      await page.goto(url(r));
      const over = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
      assert(over <= 0, `${r} overflows by ${over}px at ${w}x${h}`);
    }
  }
});
await step('POS on tablets and phones: total and Pay always visible; cart sheet; keypad fits', async () => {
  for (const [w, h, name] of [[1024, 768, 'tablet-landscape'], [768, 1024, 'tablet-portrait'], [390, 844, 'phone']]) {
    await page.setViewportSize({ width: w, height: h });
    await page.goto(url('pos'));
    await page.waitForSelector('.p-card');
    await page.click('.p-card button[aria-label^="Add NGK"]');
    const inView = async (sel) => page.evaluate((s) => {
      const el = [...document.querySelectorAll(s)].find((e) => e.offsetParent !== null);
      if (!el) return false;
      const r = el.getBoundingClientRect();
      return r.top >= 0 && r.bottom <= innerHeight && r.left >= 0 && r.right <= innerWidth;
    }, sel);
    if (w >= 900) {
      assert(await inView('[data-pay]'), `Pay visible at ${name}`);
      assert(await inView('[data-total]'), `total visible at ${name}`);
    } else {
      assert(await inView('[data-pay-bar]'), `Pay bar visible at ${name}`);
      assert(await inView('[data-bar-total]'), `total visible at ${name}`);
      await page.click('[data-cart-open]');
      await page.waitForSelector('.pos-cart.is-open');
      await page.waitForTimeout(350); // slide-in animation
      assert(await inView('.pos-cart [data-pay]'), `Pay inside the cart sheet visible at ${name}`);
      assert(await inView('.pos-cart [data-total]'), `total inside the cart sheet visible at ${name}`);
    }
    await page.screenshot({ path: `${SHOTS}/24-pos-${name}.png` });
    await page.click('.c-line .qty-value');
    await page.waitForSelector('#keypad-dialog[open]');
    const fits = await page.evaluate(() => { const r = document.querySelector('#keypad-dialog').getBoundingClientRect(); return r.top >= 0 && r.bottom <= innerHeight && r.right <= innerWidth; });
    assert(fits, `keypad fits the screen at ${name}`);
    const keyH = await page.evaluate(() => document.querySelector('[data-key="5"]').getBoundingClientRect().height);
    assert(keyH >= 44, `keys are touch-sized (${keyH}px) at ${name}`);
    await page.screenshot({ path: `${SHOTS}/25-keypad-${name}.png` });
    await page.keyboard.press('Escape');
    if (w < 900) await page.click('[data-cart-close]');
    await page.click('[data-clear]', { force: true }).catch(() => {});
  }
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(url('dashboard'));
  await page.click('[data-open-sidebar]');
  await page.screenshot({ path: `${SHOTS}/16-mobile-nav.png` });
  await page.goto(url('sales'));
  await page.screenshot({ path: `${SHOTS}/26-mobile-sales-cards.png`, fullPage: true });
  await page.setViewportSize({ width: 1440, height: 900 });
});
await step('Logout destroys the session', async () => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(url('dashboard'));
  await page.click('.sidebar button:has-text("Logout")');
  await page.waitForURL(/r=login/);
  await page.goto(url('dashboard'));
  assert(page.url().includes('r=login'));
  await page.screenshot({ path: `${SHOTS}/00-login.png` });
});
await step('No JavaScript or CSP errors in the console', async () => {
  const relevant = consoleErrors.filter((e) => !/status of 4\d\d/.test(e));
  assert(relevant.length === 0, relevant.join(' | '));
});

await browser.close();
const fs = await import('fs');
fs.writeFileSync(`${SHOTS}/e2e-results.json`, JSON.stringify(results, null, 2));
console.log(`\n${results.length - failed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
