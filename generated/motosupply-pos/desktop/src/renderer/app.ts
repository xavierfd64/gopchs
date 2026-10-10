// MotoSupply POS — cashier screen. Talks to the app only through window.moto (preload.ts).

import { Cart, formatMoney, keypadPress, parseMoney, quickCash, type Product } from '../shared/cart';
import type { ReceiptSale } from '../shared/receipt';
import type { MotoBridge } from '../preload/preload';

declare global { interface Window { moto: MotoBridge } }

interface Res { ok: boolean; status?: number; error?: { code: string; message: string }; [k: string]: unknown }
interface User { id: number; username: string; name: string; role: string; can_discount: boolean; can_view_sales: boolean; can_configure: boolean }
interface Shop {
  shop: { name: string; logo: string | null; currency_symbol: string; theme_primary: string };
  receipt: { auto_print: boolean; paper: string };
  pos: { confirm_clear: boolean };
  categories: { id: number; name: string }[];
}

const moto = window.moto;
const $ = <T extends HTMLElement = HTMLElement>(id: string): T => document.getElementById(id) as T;
const cart = new Cart();
let user: User | null = null;
let shop: Shop | null = null;
let category = 0;
let lastQuery = '';
let searchSeq = 0;
let products: Product[] = [];
let busy = false;
let lastSaleId: number | null = null;
let sessionNotice = '';

const money = (c: number) => formatMoney(c, shop?.shop.currency_symbol ?? '₱');

// ------------------------------------------------------------------ small UI helpers

function show(screen: 'setup' | 'login' | 'pos'): void {
  for (const s of ['setup', 'login', 'pos']) $(`screen-${s}`).hidden = s !== screen;
}

let toastTimer = 0;
function toast(msg: string, kind: 'info' | 'error' | 'success' = 'info'): void {
  const t = $('toast');
  t.textContent = msg;
  t.className = `toast is-${kind}`;
  t.hidden = false;
  clearTimeout(toastTimer);
  toastTimer = window.setTimeout(() => { t.hidden = true; }, kind === 'error' ? 6000 : 3000);
}

function setError(id: string, msg: string | null): void {
  const el = $(id);
  el.textContent = msg ?? '';
  el.hidden = !msg;
}

const errMsg = (r: Res, fallback = 'Something went wrong. Please try again.') => r.error?.message ?? fallback;

function openDialog(id: string): HTMLDialogElement {
  const d = $<HTMLDialogElement>(id);
  if (!d.open) d.showModal();
  return d;
}
function closeDialog(id: string): void {
  const d = $<HTMLDialogElement>(id);
  if (d.open) d.close();
}
const anyDialogOpen = () => document.querySelector('dialog[open]') !== null;

function confirmBox(text: string, yes = 'Yes', no = 'No'): Promise<boolean> {
  return new Promise((resolve) => {
    $('confirm-text').textContent = text;
    $('confirm-yes').textContent = yes;
    $('confirm-no').textContent = no;
    const d = openDialog('dlg-confirm');
    const done = (v: boolean) => { d.close(); resolve(v); };
    $('confirm-yes').onclick = () => done(true);
    $('confirm-no').onclick = () => done(false);
    d.oncancel = (e) => { e.preventDefault(); done(false); };
    $('confirm-no').focus();
  });
}

function icon(name: string): SVGSVGElement {
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  svg.setAttribute('class', 'icon');
  const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
  use.setAttribute('href', `#i-${name}`);
  svg.appendChild(use);
  return svg;
}
function el<K extends keyof HTMLElementTagNameMap>(tag: K, cls = '', text = ''): HTMLElementTagNameMap[K] {
  const e = document.createElement(tag);
  if (cls) e.className = cls;
  if (text) e.textContent = text;
  return e;
}

function applyBranding(name: string, logo: string | null, color: string | null): void {
  if (color && /^#[0-9a-fA-F]{6}$/.test(color)) document.documentElement.style.setProperty('--accent', color);
  const safeLogo = logo && /^data:image\/(png|jpeg|webp);base64,[A-Za-z0-9+/=]+$/.test(logo) ? logo : null;
  for (const [img, mark] of [['login-logo', 'login-mark'], ['pos-logo', 'pos-mark']]) {
    const i = $<HTMLImageElement>(img);
    if (safeLogo) i.src = safeLogo;
    i.hidden = !safeLogo;
    $(mark).hidden = !!safeLogo;
    $(mark).textContent = (name || 'M').trim().charAt(0).toUpperCase();
  }
  $('login-shop').textContent = name || 'MotoSupply';
  $('pos-shop').textContent = name || 'MotoSupply';
  document.title = `${name || 'MotoSupply'} — POS`;
}

// ------------------------------------------------------------------ connection status

const STATUS_TEXT: Record<string, string> = {
  connected: 'Connected', connecting: 'Connecting…', offline: 'No connection', server_error: 'Server unavailable',
  maintenance: 'Server updating', not_configured: 'Not set up',
};
function renderStatus(s: string): void {
  document.querySelectorAll<HTMLElement>('[data-status-pill]').forEach((p) => { p.dataset.state = s; });
  document.querySelectorAll<HTMLElement>('[data-status-text]').forEach((t) => { t.textContent = STATUS_TEXT[s] ?? s; });
}

// ------------------------------------------------------------------ setup and sign-in

async function boot(): Promise<void> {
  const init = (await moto.init()) as { version: string; status: string; server: { url: string | null; locked: boolean; error: string | null; configFile: string }; lastUsername: string };
  renderStatus(init.status);
  moto.onStatus(renderStatus);
  moto.onSession(onSessionLost);
  if (init.server.error) {
    show('setup');
    setError('setup-error', init.server.error);
    ($('server-url') as HTMLInputElement).disabled = true;
    ($('setup-submit') as HTMLButtonElement).disabled = true;
    return;
  }
  if (!init.server.url) {
    show('setup');
    $('server-url').focus();
    return;
  }
  $('login-host').textContent = init.server.url.replace(/^https?:\/\//, '');
  $('change-server').hidden = init.server.locked;
  ($('login-user') as HTMLInputElement).value = init.lastUsername;
  showLogin();
  void loadBranding();
}

async function loadBranding(): Promise<void> {
  const b = (await moto.branding()) as Res & { name?: string; logo?: string | null; theme_primary?: string };
  if (b.ok) applyBranding(String(b.name ?? ''), (b.logo as string | null) ?? null, String(b.theme_primary ?? ''));
}

function showLogin(notice = ''): void {
  show('login');
  const n = $('login-notice');
  n.textContent = notice;
  n.hidden = !notice;
  ($('login-pass') as HTMLInputElement).value = '';
  const u = $('login-user') as HTMLInputElement;
  (u.value ? $('login-pass') : u).focus();
}

$('setup-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const btn = $('setup-submit') as HTMLButtonElement;
  btn.disabled = true;
  btn.textContent = 'Checking…';
  setError('setup-error', null);
  const r = (await moto.checkServer(($('server-url') as HTMLInputElement).value)) as Res & { url?: string };
  btn.disabled = false;
  btn.textContent = 'Connect';
  if (!r.ok) return setError('setup-error', errMsg(r));
  $('login-host').textContent = String(r.url).replace(/^https?:\/\//, '');
  $('change-server').hidden = false;
  $('setup-cancel').hidden = true;
  showLogin('Connected. Sign in with your MotoSupply account.');
  void loadBranding();
});
$('setup-cancel').addEventListener('click', () => showLogin());

$('change-server').addEventListener('click', () => {
  setError('admin-error', null);
  ($('admin-pass') as HTMLInputElement).value = '';
  openDialog('dlg-admin');
  $('admin-user').focus();
});
$('admin-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const r = (await moto.authorizeServerChange(($('admin-user') as HTMLInputElement).value, ($('admin-pass') as HTMLInputElement).value)) as Res;
  ($('admin-pass') as HTMLInputElement).value = '';
  if (!r.ok) return setError('admin-error', errMsg(r));
  closeDialog('dlg-admin');
  show('setup');
  $('setup-cancel').hidden = false;
  $('server-url').focus();
});

$('pw-toggle').addEventListener('click', () => {
  const i = $('login-pass') as HTMLInputElement;
  const showPw = i.type === 'password';
  i.type = showPw ? 'text' : 'password';
  $('pw-toggle').setAttribute('aria-pressed', String(showPw));
  $('pw-toggle').setAttribute('aria-label', showPw ? 'Hide password' : 'Show password');
  $('pw-toggle').querySelector('use')!.setAttribute('href', showPw ? '#i-eye-off' : '#i-eye');
});

$('login-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const u = ($('login-user') as HTMLInputElement).value.trim();
  const p = ($('login-pass') as HTMLInputElement).value;
  if (!u || !p) return setError('login-error', 'Enter your username and password.');
  const btn = $('login-submit') as HTMLButtonElement;
  btn.disabled = true;
  btn.classList.add('is-busy');
  setError('login-error', null);
  const r = (await moto.login(u, p)) as Res & { user?: User; shop?: Shop; pending?: { completed: boolean; sale?: ReceiptSale; items?: unknown[] } };
  btn.disabled = false;
  btn.classList.remove('is-busy');
  ($('login-pass') as HTMLInputElement).value = '';
  if (!r.ok) return setError('login-error', errMsg(r, 'Sign-in failed.'));
  const previous = user;
  user = r.user!;
  shop = r.shop!;
  if (previous && previous.id !== user.id) cart.clear(); // a different cashier never inherits a cart
  startPos();
  if (r.pending) await handlePending(r.pending);
});

function onSessionLost(code: string): void {
  if (!user) return;
  closeAllDialogs();
  sessionNotice = code === 'session_expired' ? 'Your session expired. Sign in again to continue. The current cart is kept.'
    : code === 'account_disabled' ? 'This account was deactivated. Ask your administrator.'
    : code === 'forbidden' ? 'This account is no longer allowed to use the cashier app.'
    : 'Please sign in again.';
  if (code !== 'session_expired') cart.clear();
  ($('login-user') as HTMLInputElement).value = user.username;
  showLogin(sessionNotice);
}

function closeAllDialogs(): void {
  document.querySelectorAll('dialog[open]').forEach((d) => (d as HTMLDialogElement).close());
}

async function handlePending(p: { completed: boolean; sale?: ReceiptSale; items?: unknown[] }): Promise<void> {
  if (p.completed && p.sale) {
    showDone(p.sale, 'The last sale was completed before the app closed. Its receipt can be printed now.');
    return;
  }
  if (Array.isArray(p.items) && p.items.length > 0 && cart.isEmpty()) {
    const restore = await confirmBox('The last sale was not completed (nothing was charged). Restore its items to the cart?', 'Restore items', 'Start empty');
    if (restore) {
      const items = p.items.filter((i): i is { product: Product; qty: number } => !!i && typeof i === 'object' && 'product' in (i as object));
      for (const i of items) cart.add({ ...i.product, stock: Number.MAX_SAFE_INTEGER }, i.qty);
      await refreshStock();
      renderCart();
    } else {
      await moto.discardPending();
    }
  }
}

// ------------------------------------------------------------------ POS screen

function startPos(): void {
  if (!user || !shop) return;
  applyBranding(shop.shop.name, shop.shop.logo, shop.shop.theme_primary);
  $('pos-user').textContent = user.name;
  $('pos-role').textContent = user.role ?? 'Cashier';
  $('pos-avatar').textContent = user.name.split(/\s+/).map((w) => w.charAt(0)).join('').slice(0, 2).toUpperCase();
  $('t-discount-row').hidden = !user.can_discount;
  renderTabs();
  show('pos');
  renderCart();
  void loadProducts('');
  ($('search') as HTMLInputElement).value = '';
  $('search').focus();
}

function renderTabs(): void {
  const tabs = $('tabs');
  tabs.textContent = '';
  const all = [{ id: 0, name: 'All products' }, ...(shop?.categories ?? [])];
  for (const c of all) {
    const b = el('button', 'tab' + (c.id === category ? ' is-active' : ''), c.name);
    b.setAttribute('role', 'tab');
    b.setAttribute('aria-selected', String(c.id === category));
    b.onclick = () => { category = c.id; renderTabs(); void loadProducts(($('search') as HTMLInputElement).value.trim()); };
    tabs.appendChild(b);
  }
}

async function loadProducts(q: string): Promise<void> {
  const seq = ++searchSeq;
  lastQuery = q;
  const r = (await moto.search(q, category)) as Res & { results?: Product[] };
  if (seq !== searchSeq) return; // a newer search started
  if (!r.ok) {
    if (r.error?.code !== 'session_expired' && r.error?.code !== 'unauthenticated') toast(errMsg(r), 'error');
    return;
  }
  products = r.results ?? [];
  $('list-title').textContent = q ? `Results for "${q}"` : category ? (shop?.categories.find((c) => c.id === category)?.name ?? 'Products') : 'All products';
  $('list-count').textContent = `${products.length} product${products.length === 1 ? '' : 's'}`;
  renderGrid();
}

function renderGrid(): void {
  const grid = $('grid');
  grid.textContent = '';
  if (products.length === 0) {
    const e = el('div', 'empty');
    e.appendChild(el('p', '', lastQuery ? 'No products match your search.' : 'No products in this category.'));
    grid.appendChild(e);
    return;
  }
  for (const p of products) {
    const inCart = cart.get(p.id)?.qty ?? 0;
    const card = el('button', 'p-card' + (p.stock <= 0 ? ' is-out' : ''));
    card.type = 'button';
    card.disabled = p.stock <= 0;
    card.setAttribute('aria-label', `Add ${p.name} to sale`);
    const thumb = el('span', 'thumb', p.name.trim().slice(0, 2).toUpperCase());
    const info = el('span', 'p-info');
    info.append(el('span', 'p-cat', p.category ?? ''), el('span', 'p-name', p.name), el('span', 'p-meta', [p.sku, p.barcode].filter(Boolean).join(' · ')));
    const foot = el('span', 'p-foot');
    foot.append(el('span', 'p-price', p.price), el('span', 'p-stock' + (p.stock <= 0 ? ' out' : p.low ? ' low' : ''), p.stock <= 0 ? 'Out of stock' : `${p.stock} available${inCart ? ` · ${inCart} in cart` : ''}`));
    info.appendChild(foot);
    card.append(thumb, info);
    card.onclick = () => addProduct(p);
    grid.appendChild(card);
  }
}

function addProduct(p: Product): void {
  const r = cart.add(p);
  if (!r.ok) return toast(r.error!, 'error');
  renderCart();
  renderGrid();
}

function renderCart(): void {
  const lines = $('cart-lines');
  lines.querySelectorAll('.c-line').forEach((n) => n.remove());
  $('cart-empty').hidden = !cart.isEmpty();
  for (const l of cart.all()) {
    const over = l.qty > l.product.stock;
    const row = el('div', 'c-line' + (over ? ' is-invalid' : ''));
    const top = el('div', 'c-top');
    const name = el('div', 'c-name-wrap');
    name.append(el('div', 'c-name', l.product.name), el('div', 'c-meta', `${l.product.sku} · ${money(l.product.price_cents)} each`));
    const rm = el('button', 'icon-btn c-remove');
    rm.type = 'button';
    rm.setAttribute('aria-label', `Remove ${l.product.name}`);
    rm.appendChild(icon('trash'));
    rm.onclick = () => { cart.remove(l.product.id); renderCart(); renderGrid(); };
    top.append(name, rm);
    const bottom = el('div', 'c-bottom');
    const qty = el('div', 'qty');
    const minus = el('button', 'qty-btn');
    minus.type = 'button';
    minus.setAttribute('aria-label', `Decrease quantity of ${l.product.name}`);
    minus.appendChild(icon('minus'));
    minus.disabled = l.qty <= 1;
    minus.onclick = () => { cart.setQty(l.product.id, l.qty - 1); renderCart(); renderGrid(); };
    const val = el('button', 'qty-value', String(l.qty));
    val.type = 'button';
    val.setAttribute('aria-label', `Quantity of ${l.product.name}: ${l.qty}. Tap to change.`);
    val.onclick = () => openQtyKeypad(l.product.id);
    const plus = el('button', 'qty-btn');
    plus.type = 'button';
    plus.setAttribute('aria-label', `Increase quantity of ${l.product.name}`);
    plus.appendChild(icon('plus'));
    plus.disabled = l.qty >= l.product.stock;
    plus.onclick = () => { const r = cart.setQty(l.product.id, l.qty + 1); if (!r.ok) toast(r.error!, 'error'); renderCart(); renderGrid(); };
    qty.append(minus, val, plus);
    bottom.append(qty, el('strong', 'c-total', money(l.product.price_cents * l.qty)));
    row.append(top, bottom);
    if (over) row.appendChild(el('p', 'c-warn', l.product.stock > 0 ? `Only ${l.product.stock} available now. Lower the quantity.` : 'No longer available. Remove this item.'));
    lines.appendChild(row);
  }
  const units = cart.units();
  $('cart-count').textContent = `${units} item${units === 1 ? '' : 's'}`;
  $('t-subtotal').textContent = money(cart.subtotalCents());
  $('t-discount').textContent = '-' + money(cart.discountCents());
  $('btn-discount').textContent = cart.discountType === 'percent' ? `Discount ${cart.discountValue}% (F4)` : 'Discount (F4)';
  $('t-total').textContent = money(cart.totalCents());
  const invalid = cart.invalidLines().length > 0;
  ($('btn-pay') as HTMLButtonElement).disabled = cart.isEmpty() || invalid || busy;
  ($('btn-clear') as HTMLButtonElement).disabled = cart.isEmpty() || busy;
  setError('cart-error', invalid ? 'Some items exceed the available stock. Correct the highlighted quantities to continue.' : null);
  void moto.cartState(!cart.isEmpty());
}

// ------------------------------------------------------------------ number pads

const PAD_KEYS: Record<string, string[]> = {
  qty: ['1', '2', '3', '4', '5', '6', '7', '8', '9', 'clear', '0', 'back'],
  pay: ['1', '2', '3', '4', '5', '6', '7', '8', '9', '.', '0', 'back', 'clear', '00'],
  disc: ['1', '2', '3', '4', '5', '6', '7', '8', '9', '.', '0', 'back'],
};
const padValue: Record<string, string> = { qty: '', pay: '', disc: '' };
const padDisplay: Record<string, string> = { qty: 'qty-display', pay: 'pay-display', disc: 'disc-display' };

function buildPads(): void {
  document.querySelectorAll<HTMLElement>('[data-keypad]').forEach((pad) => {
    const name = pad.dataset.keypad!;
    for (const k of PAD_KEYS[name]) {
      const b = el('button', 'key' + (/^\d+$/.test(k) || k === '.' ? '' : ' key-fn'));
      b.type = 'button';
      b.dataset.key = k;
      if (k === 'back') { b.appendChild(icon('backspace')); b.setAttribute('aria-label', 'Backspace'); }
      else b.textContent = k === 'clear' ? 'Clear' : k;
      b.onclick = () => padPress(name, k);
      pad.appendChild(b);
    }
  });
}

function padPress(name: string, key: string): void {
  padValue[name] = keypadPress(padValue[name], key, name === 'qty' ? 'int' : 'money', name === 'qty' ? 4 : 9);
  $(padDisplay[name]).textContent = padValue[name] === '' ? '0' : padValue[name];
  if (name === 'qty') setError('qty-error', null);
  if (name === 'pay') updateChange();
  if (name === 'disc') setError('disc-error', null);
}

// Physical keyboard on number pads: digits, '.', Backspace, Delete (clear), Enter.
function padKeydown(name: string, onEnter: () => void) {
  return (e: KeyboardEvent) => {
    if (/^\d$/.test(e.key) || (e.key === '.' && name !== 'qty')) { e.preventDefault(); padPress(name, e.key); }
    else if (e.key === 'Backspace') { e.preventDefault(); padPress(name, 'back'); }
    else if (e.key === 'Delete') { e.preventDefault(); padPress(name, 'clear'); }
    else if (e.key === 'Enter') { e.preventDefault(); onEnter(); }
  };
}

let qtyFor = 0;
function openQtyKeypad(id: number): void {
  const l = cart.get(id);
  if (!l) return;
  qtyFor = id;
  padValue.qty = '';
  $('qty-display').textContent = '0';
  $('qty-product').textContent = l.product.name;
  $('qty-stock').textContent = `Available stock: ${l.product.stock} ${l.product.unit} · current quantity: ${l.qty}`;
  setError('qty-error', null);
  openDialog('dlg-qty');
  $('qty-ok').focus(); // a button, not a text field: the Windows touch keyboard stays closed
}
function confirmQty(): void {
  const n = padValue.qty === '' ? 0 : parseInt(padValue.qty, 10);
  const r = cart.setQty(qtyFor, n);
  if (!r.ok) return setError('qty-error', r.error!);
  closeDialog('dlg-qty');
  renderCart();
  renderGrid();
}
$('qty-ok').onclick = confirmQty;
$('dlg-qty').addEventListener('keydown', padKeydown('qty', confirmQty));

// ------------------------------------------------------------------ discount

let discType: 'amount' | 'percent' = 'amount';
function openDiscount(): void {
  if (!user?.can_discount || cart.isEmpty()) return;
  discType = cart.discountType === 'percent' ? 'percent' : 'amount';
  padValue.disc = cart.discountType === 'none' ? '' : cart.discountValue;
  $('disc-display').textContent = padValue.disc || '0';
  syncSeg();
  setError('disc-error', null);
  openDialog('dlg-discount');
  $('disc-ok').focus();
}
function syncSeg(): void {
  document.querySelectorAll<HTMLButtonElement>('[data-dtype]').forEach((b) => {
    const on = b.dataset.dtype === discType;
    b.classList.toggle('is-on', on);
    b.setAttribute('aria-checked', String(on));
  });
}
document.querySelectorAll<HTMLButtonElement>('[data-dtype]').forEach((b) => {
  b.onclick = () => { discType = b.dataset.dtype as 'amount' | 'percent'; syncSeg(); };
});
function applyDiscount(): void {
  const v = parseMoney(padValue.disc || '0');
  if (v === null) return setError('disc-error', 'Enter a valid amount.');
  if (discType === 'percent' && v > 10000) return setError('disc-error', 'The percentage must be between 0 and 100.');
  if (discType === 'amount' && v > cart.subtotalCents()) return setError('disc-error', 'The discount cannot be more than the subtotal.');
  cart.discountType = v === 0 ? 'none' : discType;
  cart.discountValue = v === 0 ? '0' : (padValue.disc || '0');
  closeDialog('dlg-discount');
  renderCart();
}
$('disc-ok').onclick = applyDiscount;
$('disc-remove').onclick = () => { cart.discountType = 'none'; cart.discountValue = '0'; closeDialog('dlg-discount'); renderCart(); };
$('dlg-discount').addEventListener('keydown', padKeydown('disc', applyDiscount));
$('btn-discount').onclick = openDiscount;

// ------------------------------------------------------------------ stock refresh and payment

async function refreshStock(): Promise<boolean> {
  if (cart.isEmpty()) return true;
  const r = (await moto.stock(cart.all().map((l) => l.product.id))) as Res & { products?: { id: number; stock: number; active: boolean; price_cents: number }[] };
  if (!r.ok) {
    toast(errMsg(r, 'Could not check the latest stock.'), 'error');
    return false;
  }
  const over = cart.refresh(r.products ?? []);
  renderCart();
  if (over.length) {
    toast('Stock changed: ' + over.join('; ') + '. Correct the cart to continue.', 'error');
    return false;
  }
  return true;
}

async function openPay(): Promise<void> {
  if (cart.isEmpty() || busy || anyDialogOpen()) return;
  busy = true;
  renderCart();
  const okStock = await refreshStock(); // the latest stock is checked again before taking payment
  busy = false;
  renderCart();
  if (!okStock) return;
  padValue.pay = '';
  $('pay-display').textContent = '0';
  $('pay-due').textContent = money(cart.totalCents());
  const qc = $('quick-cash');
  qc.textContent = '';
  quickCash(cart.totalCents()).forEach((c, i) => {
    const b = el('button', 'btn btn-sm', i === 0 ? 'Exact' : money(c));
    b.type = 'button';
    b.onclick = () => { padValue.pay = (c / 100).toFixed(2); $('pay-display').textContent = padValue.pay; updateChange(); };
    qc.appendChild(b);
  });
  setPayError(null);
  updateChange();
  openDialog('dlg-pay');
  $('pay-ok').focus();
}

function updateChange(): void {
  const tendered = parseMoney(padValue.pay || '0') ?? 0;
  const diff = tendered - cart.totalCents();
  const ch = $('pay-change');
  ch.textContent = diff >= 0 ? money(diff) : `Short ${money(-diff)}`;
  ch.classList.toggle('is-short', diff < 0);
}

function setPayError(msg: string | null, retry = false): void {
  setError('pay-error', msg);
  $('pay-ok').textContent = retry ? 'Check and retry' : 'Complete sale';
}

async function completeSale(): Promise<void> {
  if (busy) return; // double clicks and repeated Enter never send a second request
  const tendered = parseMoney(padValue.pay || '0');
  if (tendered === null || tendered < cart.totalCents()) {
    return setPayError(`Insufficient payment: amount due is ${money(cart.totalCents())}.`);
  }
  busy = true;
  const btn = $('pay-ok') as HTMLButtonElement;
  btn.disabled = true;
  btn.classList.add('is-busy');
  setError('pay-error', null);
  const r = (await moto.checkout({
    items: cart.items(),
    display: cart.all().map((l) => ({ product: l.product, qty: l.qty })),
    tendered: (tendered / 100).toFixed(2),
    discount_type: cart.discountType,
    discount_value: cart.discountValue,
  })) as Res & { sale?: ReceiptSale; recovered?: boolean; retryable?: boolean; stock?: { id: number; stock: number; active: boolean; price_cents: number }[] };
  busy = false;
  btn.disabled = false;
  btn.classList.remove('is-busy');
  if (r.ok && r.sale) {
    closeDialog('dlg-pay');
    cart.clear();
    renderCart();
    void loadProducts(lastQuery);
    showDone(r.sale, r.recovered ? 'This sale had already been saved before the connection was lost. It was not charged twice.' : '');
    return;
  }
  if (r.error?.code === 'cart_invalid' && Array.isArray(r.stock)) {
    cart.refresh(r.stock);
    closeDialog('dlg-pay');
    renderCart();
    toast(errMsg(r), 'error');
    return;
  }
  if (r.error?.code === 'session_expired' || r.error?.code === 'unauthenticated') return; // login screen is shown, cart kept
  setPayError(errMsg(r, 'The sale could not be completed.'), r.retryable === true);
}
$('pay-ok').onclick = () => void completeSale();
$('dlg-pay').addEventListener('keydown', padKeydown('pay', () => void completeSale()));
$('btn-pay').onclick = () => void openPay();

// ------------------------------------------------------------------ completed sale and printing

function showDone(sale: ReceiptSale, note: string): void {
  lastSaleId = sale.id;
  $('done-txn').textContent = `Transaction ${sale.transaction_no}`;
  $('done-total').textContent = sale.total;
  $('done-tendered').textContent = sale.tendered;
  $('done-change').textContent = sale.change;
  const p = $('done-print');
  p.className = 'alert alert-info';
  p.textContent = note;
  p.hidden = !note;
  openDialog('dlg-done');
  $('done-new').focus();
  if (shop?.receipt.auto_print && !note) void printReceipt(sale.id, 'auto');
}

async function printReceipt(id: number, mode: 'auto' | 'manual'): Promise<void> {
  const p = $('done-print');
  p.className = 'alert alert-info';
  p.textContent = 'Printing receipt…';
  p.hidden = false;
  const r = (await moto.print(id, mode)) as Res;
  p.className = r.ok ? 'alert alert-success' : 'alert alert-error';
  p.textContent = r.ok ? 'Receipt sent to the printer.' : `${errMsg(r)} The sale is saved; you can print the receipt again.`;
}
$('done-printbtn').onclick = () => { if (lastSaleId) void printReceipt(lastSaleId, 'manual'); };
$('done-new').onclick = () => { closeDialog('dlg-done'); $('search').focus(); };

async function openRecent(): Promise<void> {
  if (anyDialogOpen()) return;
  const list = $('recent-list');
  list.textContent = 'Loading…';
  $('recent-msg').hidden = true;
  openDialog('dlg-recent');
  const r = (await moto.recent()) as Res & { sales?: { id: number; transaction_no: string; time: string; total: string; items: number; status: string }[] };
  list.textContent = '';
  if (!r.ok) { list.textContent = errMsg(r); return; }
  if (!r.sales?.length) { list.appendChild(el('p', 'muted', 'No sales yet today.')); return; }
  for (const s of r.sales) {
    const row = el('div', 'recent-row');
    row.append(el('span', 'mono', s.transaction_no), el('span', 'muted', s.time), el('span', '', `${s.items} item(s)`),
      el('strong', '', s.total), el('span', 'pill ' + (s.status === 'voided' ? 'pill-danger' : 'pill-success'), s.status === 'voided' ? 'Voided' : 'Paid'));
    const b = el('button', 'btn btn-sm');
    b.type = 'button';
    b.append(icon('printer'), document.createTextNode(' Reprint'));
    b.onclick = async () => {
      b.disabled = true;
      const res = (await moto.reprint(s.id)) as Res;
      b.disabled = false;
      const m = $('recent-msg');
      m.className = res.ok ? 'alert alert-success' : 'alert alert-error';
      m.textContent = res.ok ? `Receipt ${s.transaction_no} sent to the printer.` : errMsg(res);
      m.hidden = false;
    };
    row.appendChild(b);
    list.appendChild(row);
  }
}
$('btn-recent').onclick = () => void openRecent();

$('btn-printer').onclick = async () => {
  const r = (await moto.printers()) as Res & { printers: { name: string; displayName: string; isDefault: boolean }[]; selected: string };
  const sel = $('printer-select') as HTMLSelectElement;
  sel.textContent = '';
  const def = el('option', '', 'Windows default printer');
  def.value = '';
  sel.appendChild(def);
  for (const p of r.printers ?? []) {
    const o = el('option', '', p.displayName + (p.isDefault ? ' (default)' : ''));
    o.value = p.name;
    sel.appendChild(o);
  }
  sel.value = r.selected ?? '';
  $('printer-auto').textContent = shop?.receipt.auto_print
    ? 'Your shop prints receipts automatically after each sale.'
    : 'Receipts print when you press "Print receipt". Your administrator can turn on automatic printing in the MotoSupply website.';
  openDialog('dlg-printer');
};
$('printer-save').onclick = async () => {
  await moto.setPrinter(($('printer-select') as HTMLSelectElement).value);
  closeDialog('dlg-printer');
  toast('Printer saved.', 'success');
};

// ------------------------------------------------------------------ cancel sale and sign out

async function cancelSale(): Promise<void> {
  if (cart.isEmpty() || busy) return;
  if (shop?.pos.confirm_clear !== false && !(await confirmBox('Remove all items from this sale? Nothing has been charged.', 'Cancel sale', 'Keep items'))) return;
  cart.clear();
  await moto.discardPending();
  renderCart();
  renderGrid();
  $('search').focus();
}
$('btn-clear').onclick = () => void cancelSale();

$('btn-logout').onclick = async () => {
  if (busy) return;
  if (!cart.isEmpty() && !(await confirmBox('Sign out and discard the current unpaid sale?', 'Sign out', 'Stay'))) return;
  await moto.logout();
  cart.clear();
  await moto.cartState(false);
  user = null;
  shop = null;
  showLogin('You have signed out.');
};

// ------------------------------------------------------------------ search, scanner, shortcuts

let searchTimer = 0;
const search = $('search') as HTMLInputElement;
search.addEventListener('input', () => {
  clearTimeout(searchTimer);
  searchTimer = window.setTimeout(() => void loadProducts(search.value.trim()), 200);
});
search.addEventListener('keydown', async (e) => {
  if (e.key === 'Enter') {
    e.preventDefault();
    clearTimeout(searchTimer);
    const code = search.value.trim();
    if (!code) return;
    // Scanner or typed code + Enter: exact barcode/SKU match goes straight into the cart.
    const r = (await moto.lookup(code)) as Res & { product?: Product };
    if (r.ok && r.product) {
      addProduct(r.product);
      search.value = '';
      void loadProducts('');
    } else if (r.error?.code === 'not_found') {
      toast(`No active product with barcode or SKU "${code}".`, 'error');
      search.select();
      void loadProducts(code);
    } else if (!r.ok) {
      toast(errMsg(r), 'error');
    }
  } else if (e.key === 'Escape') {
    search.value = '';
    void loadProducts('');
  }
});

document.addEventListener('keydown', (e) => {
  if ($('screen-pos').hidden) return;
  const dlg = anyDialogOpen();
  if (e.key === 'F2' && !dlg) { e.preventDefault(); search.focus(); search.select(); return; }
  if (e.key === 'F4' && !dlg) { e.preventDefault(); openDiscount(); return; }
  if (e.key === 'F8' && !dlg) { e.preventDefault(); void openPay(); return; }
  if (e.key === 'F9' && !dlg) { e.preventDefault(); void openRecent(); return; }
  if (e.key === 'Enter' && $<HTMLDialogElement>('dlg-done').open) { e.preventDefault(); closeDialog('dlg-done'); search.focus(); return; }
  // A barcode scanner types like a keyboard: send stray typing to the search box.
  const t = e.target as HTMLElement;
  if (!dlg && e.key.length === 1 && !e.ctrlKey && !e.altKey && !e.metaKey && !['INPUT', 'SELECT', 'TEXTAREA'].includes(t.tagName)) {
    search.focus();
  }
});

document.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', () => {
  const d = b.closest('dialog') as HTMLDialogElement;
  if (!(busy && d.id === 'dlg-pay')) d.close();
}));
// While a sale is being completed its dialog cannot be dismissed.
$('dlg-pay').addEventListener('cancel', (e) => { if (busy) e.preventDefault(); });

buildPads();
void boot();
