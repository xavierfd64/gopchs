// Unit tests for the pure modules (run with: npm run test:unit).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const { validateServerUrl, validCart, validAmount, cartSignature, isUuidV4, safeColor } = require('../../dist/shared/validate.cjs');
const { Cart, formatMoney, parseMoney, keypadPress, quickCash } = require('../../dist/shared/cart.cjs');
const { receiptHtml, esc } = require('../../dist/shared/receipt.cjs');

test('server URL: HTTPS required; HTTP only for this computer in explicit development mode', () => {
  assert.equal(validateServerUrl('https://shop.example.com', false).url, 'https://shop.example.com');
  assert.equal(validateServerUrl('shop.example.com/pos/', false).url, 'https://shop.example.com/pos');
  assert.equal(validateServerUrl('https://shop.example.com/pos/index.php', false).url, 'https://shop.example.com/pos');
  assert.equal(validateServerUrl('http://shop.example.com', false).ok, false);
  assert.equal(validateServerUrl('http://shop.example.com', true).ok, false, 'remote HTTP refused even in dev mode');
  assert.equal(validateServerUrl('http://127.0.0.1:8090', false).ok, false);
  assert.equal(validateServerUrl('http://127.0.0.1:8090', true).ok, true);
  assert.equal(validateServerUrl('http://localhost', true).ok, true);
  for (const bad of ['', 'ftp://x.example', 'javascript:alert(1)', 'file:///etc/passwd', 'https://u:p@x.example', 'https://x.example/?a=1', 'https://x.example/#h', 'https://', 'x'.repeat(400)]) {
    assert.equal(validateServerUrl(bad, true).ok, false, bad);
  }
});

test('cart and amount validation before anything is sent', () => {
  assert.ok(validCart([{ product_id: 1, quantity: 2 }]));
  for (const bad of [[], null, [{ product_id: 0, quantity: 1 }], [{ product_id: 1, quantity: 0 }], [{ product_id: 1, quantity: 1.5 }], [{ product_id: '1', quantity: 1 }], [{ product_id: 1, quantity: 10000 }]]) {
    assert.equal(validCart(bad), false, JSON.stringify(bad));
  }
  assert.ok(validAmount('1500') && validAmount('1500.5') && validAmount('0.25'));
  assert.ok(!validAmount('-1') && !validAmount('1.234') && !validAmount('1,500') && !validAmount(''));
  assert.ok(isUuidV4('3f1c2a4e-1b2c-4d3e-8f4a-5b6c7d8e9f01') && !isUuidV4('not-a-uuid'));
  assert.equal(safeColor('#1d4ed8', '#000000'), '#1d4ed8');
  assert.equal(safeColor('red;}body{', '#000000'), '#000000');
});

test('cart signature: same items in any order give the same signature', () => {
  const a = cartSignature([{ product_id: 2, quantity: 1 }, { product_id: 1, quantity: 3 }], 'none:0');
  const b = cartSignature([{ product_id: 1, quantity: 3 }, { product_id: 2, quantity: 1 }], 'none:0');
  assert.equal(a, b);
  assert.notEqual(a, cartSignature([{ product_id: 1, quantity: 2 }, { product_id: 2, quantity: 1 }], 'none:0'));
});

test('money formatting and parsing (centavos)', () => {
  assert.equal(formatMoney(675000), '₱6,750.00');
  assert.equal(formatMoney(5), '₱0.05');
  assert.equal(formatMoney(-12050), '-₱120.50');
  assert.equal(parseMoney('1,234.5'), 123450);
  assert.equal(parseMoney('0.05'), 5);
  assert.equal(parseMoney('1.234'), null);
  assert.equal(parseMoney('abc'), null);
});

const P = (id, price, stock) => ({ id, sku: 'S' + id, barcode: null, name: 'P' + id, unit: 'pc', category: null, price_cents: price, price: '', stock, low: false });

test('cart: stock limits, quantities and totals', () => {
  const c = new Cart();
  assert.ok(c.add(P(1, 45000, 3)).ok);
  assert.ok(c.add(P(1, 45000, 3), 2).ok);
  assert.equal(c.add(P(1, 45000, 3)).ok, false, 'cannot exceed stock');
  assert.equal(c.add(P(2, 100, 0)).ok, false, 'out of stock');
  assert.ok(c.add(P(3, 12050, 10)).ok);
  assert.equal(c.subtotalCents(), 3 * 45000 + 12050);
  assert.equal(c.setQty(3, 0).ok, false);
  assert.equal(c.setQty(3, 11).ok, false);
  assert.ok(c.setQty(3, 2).ok);
  assert.equal(c.totalCents(), 135000 + 24100);
  assert.deepEqual(c.items(), [{ product_id: 1, quantity: 3 }, { product_id: 3, quantity: 2 }]);
  const over = c.refresh([{ id: 1, stock: 1, active: true, price_cents: 45000 }]);
  assert.equal(over.length, 1);
  assert.equal(c.invalidLines().length, 1);
  c.remove(1);
  assert.equal(c.invalidLines().length, 0);
  c.clear();
  assert.ok(c.isEmpty());
});

test('cart: discounts never exceed the subtotal; percent rounds half up', () => {
  const c = new Cart();
  c.add(P(1, 99999, 5));
  c.discountType = 'amount'; c.discountValue = '5000';
  assert.equal(c.discountCents(), 99999);
  c.discountType = 'percent'; c.discountValue = '12.5';
  assert.equal(c.discountCents(), 12500); // 999.99 × 12.5% = 124.998… → 125.00
  c.discountValue = '150';
  assert.equal(c.discountCents(), 99999);
});

test('keypad: digits, decimals, backspace, clear, limits', () => {
  let v = '';
  for (const k of ['1', '2', '.', '5', '0', '9']) v = keypadPress(v, k, 'money');
  assert.equal(v, '12.50');
  assert.equal(keypadPress('12', '.', 'int'), '12');
  assert.equal(keypadPress('0', '7', 'int'), '7');
  assert.equal(keypadPress('123', 'back', 'int'), '12');
  assert.equal(keypadPress('123', 'clear', 'int'), '');
  assert.equal(keypadPress('9999', '9', 'int', 4), '9999');
  assert.equal(keypadPress('5', '00', 'money'), '500');
  assert.equal(keypadPress('', '.', 'money'), '0.');
});

test('quick cash suggestions', () => {
  assert.deepEqual(quickCash(675000), [675000, 680000, 700000]);
  assert.deepEqual(quickCash(12050), [12050, 20000, 50000, 100000]);
});

test('receipt HTML escapes every server value and only embeds image data logos', () => {
  const sale = { id: 1, transaction_no: 'MS-1<script>', status: 'completed', date: 'Oct 9', cashier: '<b>x</b>',
    items: [{ name: '<img src=x onerror=alert(1)>', sku: 'A', quantity: 1, unit_price: '₱1.00', line_total: '₱1.00' }],
    item_count: 1, subtotal: '₱1.00', discount: '₱0.00', has_discount: false, total: '₱1.00', tendered: '₱1.00', change: '₱0.00' };
  const html = receiptHtml(sale, { name: 'Shop "&"', address: '', phone: '', email: '', logo: 'javascript:alert(1)' }, { paper: '58mm', show_logo: true, footer: 'Thanks', auto_print: false }, { reprint: true });
  assert.ok(!html.includes('<script>') && !html.includes('<img src=x') && !html.includes('<b>x</b>'));
  assert.ok(html.includes('&lt;img') && html.includes('REPRINT') && html.includes('54mm'));
  assert.ok(!html.includes('javascript:'), 'non-image logo dropped');
  assert.equal(esc(`<>&"'`), '&lt;&gt;&amp;&quot;&#39;');
  for (const k of ['MS-1', 'Cash tendered', 'Change', 'Subtotal', 'TOTAL', 'Thanks']) assert.ok(html.includes(k), k);
});
