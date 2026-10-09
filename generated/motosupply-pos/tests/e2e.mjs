// Browser end-to-end test (development only; Node is NOT needed in production).
// Usage: node tests/e2e.mjs http://127.0.0.1:8080 <screenshot-dir>
import { createRequire } from 'module';
const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

const BASE = process.argv[2] || 'http://127.0.0.1:8080';
const SHOTS = process.argv[3] || './screens';
const NEW_PW = 'Moto$hop2026';
const ADMIN_USER = process.env.MOTO_ADMIN_USER || 'admin';
const INITIAL_PW = process.env.MOTO_INITIAL_PW || 'Initial#Pass2026';
const results = [];
let failed = 0;

async function step(name, fn) {
  try { await fn(); results.push(['PASS', name]); console.log('  PASS ', name); }
  catch (e) { failed++; results.push(['FAIL', name, String(e.message || e).split('\n')[0]]); console.log('  FAIL ', name, '\n       ', String(e.message || e).split('\n')[0]); }
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
  await page.waitForFunction(() => document.querySelector('.c-line input')?.value === '2');
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
await step('Sales history, detail and void with password', async () => {
  await page.goto(url('sales'));
  await page.screenshot({ path: `${SHOTS}/09-sales-history.png`, fullPage: true });
  await page.click(`a:has-text("#${txn}") >> nth=0`);
  await page.fill('#void-reason', 'Customer changed mind');
  await page.fill('#void-password', 'wrong');
  await page.click('button:has-text("Void sale")');
  assert(await page.isVisible('text=Password is incorrect'));
  await page.fill('#void-reason', 'Customer changed mind');
  await page.fill('#void-password', NEW_PW);
  await page.click('button:has-text("Void sale")');
  assert(await page.isVisible('text=The sale was voided'));
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
await step('No horizontal overflow on phone and tablet widths', async () => {
  for (const [w, h] of [[375, 812], [768, 1024]]) {
    await page.setViewportSize({ width: w, height: h });
    for (const r of ['dashboard', 'pos', 'products', 'sales', 'reports', 'settings', 'products.create']) {
      await page.goto(url(r));
      const over = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
      assert(over <= 0, `${r} overflows by ${over}px at ${w}px`);
    }
  }
  await page.setViewportSize({ width: 375, height: 812 });
  await page.goto(url('pos'));
  await page.waitForSelector('.p-card');
  await page.screenshot({ path: `${SHOTS}/15-mobile-pos.png`, fullPage: true });
  await page.goto(url('dashboard'));
  await page.click('[data-open-sidebar]');
  await page.screenshot({ path: `${SHOTS}/16-mobile-nav.png` });
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
