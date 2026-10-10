// MotoSupply POS — cashier desktop app, main process.
//
// Security model:
//  - The window shows only the bundled screens (file://, sandboxed, contextIsolation, no Node).
//    Navigation, new windows, webviews, permissions and any network request from the screen are blocked.
//  - The screen can call only the fixed, validated functions in preload.ts. All server traffic goes
//    through ApiClient here, with the short-lived sign-in token held in memory only.
//  - The server (PHP backend) decides prices, stock, totals and permissions for every request.

import { app, BrowserWindow, dialog, ipcMain, Menu, session, shell, type IpcMainInvokeEvent } from 'electron';
import { randomUUID } from 'node:crypto';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import { ApiClient, type ApiResult } from './api';
import { insecureLocalhostAllowed, machineConfigLocation, readUserSettings, serverSetting, writeUserSettings } from './config';
import { cartSignature, fileUrlInsideDir, isUuidV4, validAmount, validCart, validCredentials, validateServerUrl, type CartLineInput } from '../shared/validate';
import { receiptHtml, type ReceiptSale, type ReceiptSettings, type ShopInfo } from '../shared/receipt';

const VERSION = app.getVersion();
const RENDERER_DIR = path.join(__dirname, '..', 'renderer');
const RENDERER_URL = pathToFileURL(path.join(RENDERER_DIR, 'index.html')).toString();
const UI_PARTITION = 'motosupply-ui'; // in-memory session used only by the window

type Status = 'not_configured' | 'connecting' | 'connected' | 'offline' | 'server_error' | 'maintenance';

interface User { id: number; username: string; name: string; role: string; can_discount: boolean; can_view_sales: boolean; can_configure: boolean }
interface ShopConfig {
  shop: ShopInfo & { currency_symbol: string; theme_primary: string };
  receipt: ReceiptSettings; pos: { auto_add_barcode: boolean; confirm_clear: boolean };
  categories: { id: number; name: string }[];
}
interface PendingCheckout {
  server: string; user_id: number; client_token: string; signature: string; state: 'sending' | 'unknown';
  items: unknown[]; created: string;
}

let win: BrowserWindow | null = null;
let api: ApiClient | null = null;
let user: User | null = null;
let shopConfig: ShopConfig | null = null;
let status: Status = 'connecting';
let checkoutInFlight = false;
let cartHasItems = false;
let serverChangeAuthorized = false;
let monitor: NodeJS.Timeout | null = null;
const recentSales = new Map<number, ReceiptSale>();

const userAgent = `MotoSupplyPOS/${VERSION} (${process.platform}; ${os.release()})`;

// ------------------------------------------------------------------ helpers

function send(channel: string, payload: unknown): void {
  if (win && !win.isDestroyed()) win.webContents.send(channel, payload);
}

function setStatus(s: Status): void {
  if (s !== status) {
    status = s;
    send('moto:status', s);
  }
}

function statusFrom(r: ApiResult): void {
  if (r.ok) return setStatus('connected');
  if (r.status === 0) return setStatus('offline');
  if (r.error.code === 'maintenance') return setStatus('maintenance');
  if (r.status >= 500) return setStatus('server_error');
  setStatus('connected');
}

function connectApi(): void {
  const s = serverSetting();
  api = s.url ? new ApiClient(s.url, userAgent) : null;
  if (api) {
    api.onSessionLost = (code) => {
      user = null;
      send('moto:session', code);
    };
  }
  setStatus(api ? 'connecting' : 'not_configured');
  void ping();
}

async function ping(): Promise<void> {
  if (!api) return;
  const r = await api.request<{ product: string }>('GET', 'ping', { auth: false, timeoutMs: 8000 });
  if (r.ok && r.product !== 'motosupply-pos') return setStatus('server_error');
  statusFrom(r);
}

function startMonitor(): void {
  if (monitor) clearInterval(monitor);
  monitor = setInterval(() => void ping(), 10000);
}

const pendingFile = (): string => path.join(app.getPath('userData'), 'pending-checkout.json');

function readPending(): PendingCheckout | null {
  try {
    const p = JSON.parse(fs.readFileSync(pendingFile(), 'utf8')) as PendingCheckout;
    return p && isUuidV4(p.client_token) ? p : null;
  } catch {
    return null;
  }
}

function writePending(p: PendingCheckout | null): void {
  try {
    if (p === null) fs.rmSync(pendingFile(), { force: true });
    else fs.writeFileSync(pendingFile(), JSON.stringify(p), { mode: 0o600 });
  } catch {
    // Not fatal: the server still prevents duplicates for retries within this session.
  }
}

/** Every IPC call must come from our own screen. */
function fromApp(e: IpcMainInvokeEvent): boolean {
  return e.senderFrame !== null && e.senderFrame.url === RENDERER_URL && win !== null && e.sender === win.webContents;
}

function handle(channel: string, fn: (...args: unknown[]) => Promise<unknown> | unknown): void {
  ipcMain.handle(channel, async (e, ...args) => {
    if (!fromApp(e)) throw new Error('Blocked');
    return fn(...args);
  });
}

const fail = (code: string, message: string) => ({ ok: false, status: 0, error: { code, message } });
const str = (v: unknown, max: number): string | null => (typeof v === 'string' && v.length <= max ? v : null);

// ------------------------------------------------------------------ printing

async function printSale(sale: ReceiptSale, mode: 'auto' | 'manual', reprint: boolean): Promise<{ ok: boolean; error?: string }> {
  if (!shopConfig) return { ok: false, error: 'Not signed in.' };
  const html = receiptHtml(sale, shopConfig.shop, shopConfig.receipt, { reprint });
  const pw = new BrowserWindow({
    show: false, width: 400, height: 800,
    webPreferences: { sandbox: true, contextIsolation: true, nodeIntegration: false, javascript: false, partition: UI_PARTITION },
  });
  try {
    await pw.loadURL('data:text/html;charset=utf-8,' + encodeURIComponent(html));
    // Development/test only: write the receipt to a PDF instead of a printer.
    if (!app.isPackaged && process.env.MOTOSUPPLY_PRINT_TO_PDF_DIR) {
      const pdf = await pw.webContents.printToPDF({ printBackground: true, preferCSSPageSize: false });
      fs.writeFileSync(path.join(process.env.MOTOSUPPLY_PRINT_TO_PDF_DIR, `${sale.transaction_no}${reprint ? '-reprint' : ''}-${Date.now()}.pdf`), pdf);
      return { ok: true };
    }
    const printer = readUserSettings().printer;
    const printers = await pw.webContents.getPrintersAsync();
    const target = printer && printers.some((p) => p.name === printer) ? printer : undefined;
    if (printers.length === 0) return { ok: false, error: 'No printer is installed on this computer.' };
    return await new Promise((resolve) => {
      pw.webContents.print(
        // Automatic printing goes straight to the chosen (or default) printer; manual printing shows the
        // Windows print dialog unless a receipt printer was chosen in Printer settings.
        { silent: mode === 'auto' || target !== undefined, deviceName: target, printBackground: true, margins: { marginType: 'none' } },
        (ok, reason) => resolve(ok ? { ok: true } : { ok: false, error: reason === 'cancelled' ? 'Printing was cancelled.' : `The printer reported a problem (${reason}).` }),
      );
    });
  } catch (e) {
    return { ok: false, error: 'The receipt could not be printed. The sale is saved; print it again from "Today\'s sales".' };
  } finally {
    setTimeout(() => { if (!pw.isDestroyed()) pw.destroy(); }, 1000);
  }
}

// ------------------------------------------------------------------ IPC

function registerIpc(): void {
  handle('moto:init', () => {
    const s = serverSetting();
    return {
      version: VERSION, status, platform: process.platform,
      server: { url: s.url, locked: s.locked, error: s.error ?? null, configFile: machineConfigLocation() },
      lastUsername: readUserSettings().last_username ?? '',
      user, shop: shopConfig,
    };
  });

  handle('moto:branding', async () => (api ? api.request('GET', 'branding', { auth: false, timeoutMs: 8000 }) : fail('not_configured', 'No server.')));

  handle('moto:check-server', async (url) => {
    const v = validateServerUrl(String(url ?? ''), insecureLocalhostAllowed());
    if (!v.ok) return fail('invalid_url', v.error!);
    const s = serverSetting();
    if (s.locked) return fail('locked', 'The server address is set by your administrator and cannot be changed here.');
    if (s.url && !serverChangeAuthorized) return fail('not_authorized', 'Changing the server needs an administrator sign-in first.');
    const probe = new ApiClient(v.url!, userAgent);
    const r = await probe.request<{ product: string; version: string }>('GET', 'ping', { auth: false, timeoutMs: 10000 });
    if (!r.ok) {
      return fail(r.status === 0 ? 'unreachable' : 'not_motosupply', r.status === 0
        ? 'Could not connect. Check the address and the internet connection. (Secure certificate problems also show this message.)'
        : r.error.code === 'https_required' ? 'The server requires https://.' : 'This address does not answer like a MotoSupply server (version 1.4 or newer is required).');
    }
    if (r.product !== 'motosupply-pos') return fail('not_motosupply', 'This is not a MotoSupply server.');
    writeUserSettings({ server_url: v.url });
    serverChangeAuthorized = false;
    user = null;
    shopConfig = null;
    connectApi();
    startMonitor();
    return { ok: true, url: v.url, version: r.version };
  });

  handle('moto:authorize-server-change', async (username, password) => {
    if (!api || !validCredentials(username, password)) return fail('invalid_request', 'Enter the username and password.');
    if (serverSetting().locked) return fail('locked', 'The server address is set by your administrator and cannot be changed here.');
    const probe = new ApiClient(api.base, userAgent);
    const r = await probe.request<{ token: string; user: User }>('POST', 'auth.login', { auth: false, body: { username, password, device: `${os.hostname()} (settings)` } });
    if (!r.ok) return r;
    probe.setToken(r.token);
    await probe.request('POST', 'auth.logout', { body: {} });
    if (!r.user.can_configure) return fail('forbidden', 'Only an administrator (with "Manage settings") can change the server address.');
    serverChangeAuthorized = true;
    return { ok: true };
  });

  handle('moto:login', async (username, password) => {
    if (!api) return fail('not_configured', 'Set the server address first.');
    if (!validCredentials(username, password)) return fail('invalid_request', 'Enter your username and password.');
    const r = await api.request<{ token: string; user: User }>('POST', 'auth.login', {
      auth: false, body: { username: String(username).trim(), password, device: os.hostname().slice(0, 60) },
    });
    statusFrom(r);
    if (!r.ok) return r;
    api.setToken(r.token);
    const c = await api.request<ShopConfig>('GET', 'config');
    if (!c.ok) {
      api.setToken(null);
      return c;
    }
    user = r.user;
    shopConfig = { shop: c.shop, receipt: c.receipt, pos: c.pos, categories: c.categories };
    writeUserSettings({ last_username: user.username });
    // A checkout whose answer was lost (crash, power cut, network): find out whether it went through.
    let pending: unknown = null;
    const p = readPending();
    if (p && p.server === api.base && p.user_id === user.id) {
      const b = await api.request<{ found: boolean; sale?: ReceiptSale }>('GET', 'sales.by-token', { query: { client_token: p.client_token } });
      if (b.ok && b.found && b.sale) {
        writePending(null);
        recentSales.set(b.sale.id, b.sale);
        pending = { completed: true, sale: b.sale };
      } else if (b.ok) {
        pending = { completed: false, items: p.items };
        // Not committed: the cart can be restored; the next checkout gets a fresh token if it changes.
      }
    }
    return { ok: true, user, shop: shopConfig, pending };
  });

  handle('moto:logout', async () => {
    if (checkoutInFlight) return fail('busy', 'Wait until the sale is finished.');
    if (api && api.hasToken()) await api.request('POST', 'auth.logout', { body: {}, timeoutMs: 5000 });
    api?.setToken(null);
    user = null;
    shopConfig = null;
    recentSales.clear();
    return { ok: true };
  });

  handle('moto:search', async (q, category) => {
    const query = str(q, 100);
    const cat = Number.isInteger(category) && (category as number) >= 0 ? String(category) : '0';
    if (!api || query === null) return fail('invalid_request', 'Invalid search.');
    const r = await api.request('GET', 'products.search', { query: { q: query, category: cat } });
    statusFrom(r);
    return r;
  });

  handle('moto:lookup', async (code) => {
    const c = str(code, 64);
    if (!api || !c || c.trim() === '') return fail('invalid_request', 'Invalid barcode.');
    const r = await api.request('GET', 'products.lookup', { query: { code: c.trim() } });
    statusFrom(r);
    return r;
  });

  handle('moto:stock', async (ids) => {
    if (!api || !Array.isArray(ids) || ids.length === 0 || ids.length > 200 || !ids.every((i) => Number.isInteger(i) && i > 0)) {
      return fail('invalid_request', 'Invalid products.');
    }
    const r = await api.request('GET', 'products.stock', { query: { ids: ids.join(',') } });
    statusFrom(r);
    return r;
  });

  handle('moto:checkout', async (input) => {
    if (!api || !user) return fail('unauthenticated', 'Please sign in.');
    const i = (input ?? {}) as { items?: unknown; display?: unknown; tendered?: unknown; discount_type?: unknown; discount_value?: unknown };
    if (!validCart(i.items)) return fail('invalid_request', 'The cart is not valid.');
    if (!validAmount(i.tendered)) return fail('invalid_request', 'Enter the amount tendered.');
    const dType = i.discount_type === 'amount' || i.discount_type === 'percent' ? i.discount_type : 'none';
    const dValue = dType === 'none' ? '0' : validAmount(i.discount_value) ? i.discount_value : null;
    if (dValue === null) return fail('invalid_request', 'Invalid discount.');
    if (checkoutInFlight) return fail('busy', 'This sale is already being completed.');
    checkoutInFlight = true;
    try {
      let p = readPending();
      if (p && (p.server !== api.base || p.user_id !== user.id)) p = null;
      if (p && p.state === 'unknown') {
        // Previous attempt's answer was lost: check before sending anything new.
        const b = await api.request<{ found: boolean; sale?: ReceiptSale }>('GET', 'sales.by-token', { query: { client_token: p.client_token } });
        statusFrom(b);
        if (!b.ok) return { ...b, retryable: true };
        if (b.found && b.sale) {
          writePending(null);
          recentSales.set(b.sale.id, b.sale);
          return { ok: true, status: 200, duplicate: true, recovered: true, sale: b.sale };
        }
      }
      const items = i.items as CartLineInput[];
      const signature = cartSignature(items, `${dType}:${dValue}`);
      const clientToken = p && p.signature === signature ? p.client_token : randomUUID();
      writePending({ server: api.base, user_id: user.id, client_token: clientToken, signature, state: 'sending',
        items: Array.isArray(i.display) ? (i.display as unknown[]).slice(0, 200) : [], created: new Date().toISOString() });
      const r = await api.request<{ duplicate: boolean; sale: ReceiptSale }>('POST', 'sales.checkout', {
        body: { items, tendered: i.tendered, discount_type: dType, discount_value: dValue, client_token: clientToken }, timeoutMs: 30000,
      });
      statusFrom(r);
      if (r.ok) {
        writePending(null);
        recentSales.set(r.sale.id, r.sale);
        return r;
      }
      if (r.status === 0 || (r.status >= 500 && r.error.code !== 'maintenance')) {
        // Outcome unknown (the server may have saved the sale before the connection dropped).
        const cur = readPending();
        if (cur) writePending({ ...cur, state: 'unknown' });
        return { ...r, retryable: true, error: { code: 'unknown_outcome',
          message: 'The connection was lost while completing the sale, so it is not known yet whether it was saved. '
            + 'Do not take payment twice. Press "Check and retry" when the connection is back: if the sale was saved it is shown; otherwise it is completed now.' } };
      }
      if (r.status !== 401) writePending(null); // definitive refusal: nothing was saved
      return r;
    } finally {
      checkoutInFlight = false;
    }
  });

  handle('moto:discard-pending', () => {
    writePending(null);
    return { ok: true };
  });

  handle('moto:receipt', async (id) => {
    if (!api || !Number.isInteger(id) || (id as number) <= 0) return fail('invalid_request', 'Invalid sale.');
    return api.request('GET', 'sales.receipt', { query: { id: String(id) } });
  });

  handle('moto:recent', async () => (api ? api.request('GET', 'sales.recent') : fail('unauthenticated', 'Please sign in.')));

  handle('moto:print', async (saleId, mode) => {
    if (!api || !user || !Number.isInteger(saleId)) return fail('invalid_request', 'Invalid sale.');
    const m = mode === 'auto' ? 'auto' : 'manual';
    // Sales completed in this session print from memory; anything else is fetched (and marked REPRINT).
    let sale = recentSales.get(saleId as number);
    let reprint = false;
    if (!sale) {
      const r = await api.request<{ sale: ReceiptSale }>('GET', 'sales.receipt', { query: { id: String(saleId) } });
      if (!r.ok) return r;
      sale = r.sale;
      reprint = true;
    }
    const res = await printSale(sale, m, reprint);
    return res.ok ? { ok: true } : fail('print_failed', res.error ?? 'Printing failed.');
  });

  handle('moto:reprint', async (saleId) => {
    if (!api || !user || !Number.isInteger(saleId)) return fail('invalid_request', 'Invalid sale.');
    const r = await api.request<{ sale: ReceiptSale }>('GET', 'sales.receipt', { query: { id: String(saleId) } });
    if (!r.ok) return r;
    const res = await printSale(r.sale, 'manual', true);
    return res.ok ? { ok: true } : fail('print_failed', res.error ?? 'Printing failed.');
  });

  handle('moto:printers', async () => {
    const list = win ? await win.webContents.getPrintersAsync() : [];
    return { ok: true, printers: list.map((p) => ({ name: p.name, displayName: p.displayName, isDefault: (p as { isDefault?: boolean }).isDefault === true })),
      selected: readUserSettings().printer ?? '' };
  });

  handle('moto:set-printer', (name) => {
    const n = str(name, 200);
    if (n === null) return fail('invalid_request', 'Invalid printer.');
    writeUserSettings({ printer: n });
    return { ok: true };
  });

  handle('moto:cart-state', (hasItems) => {
    cartHasItems = hasItems === true;
    return { ok: true };
  });
}

// ------------------------------------------------------------------ window

function createWindow(): void {
  win = new BrowserWindow({
    width: 1366, height: 820, minWidth: 820, minHeight: 600, show: false,
    title: 'MotoSupply POS', backgroundColor: '#1f2024', autoHideMenuBar: true,
    icon: path.join(__dirname, '..', '..', 'build', process.platform === 'win32' ? 'icon.ico' : 'icon.png'),
    webPreferences: {
      preload: path.join(__dirname, '..', 'preload', 'preload.js'),
      contextIsolation: true, nodeIntegration: false, sandbox: true, webSecurity: true,
      allowRunningInsecureContent: false, spellcheck: false, partition: UI_PARTITION,
      devTools: !app.isPackaged,
    },
  });
  win.once('ready-to-show', () => {
    win?.maximize();
    win?.show();
  });
  win.on('close', (e) => {
    if (checkoutInFlight) {
      e.preventDefault();
      void dialog.showMessageBox(win!, { type: 'warning', title: 'Sale in progress', message: 'A sale is being completed. Please wait for the confirmation before closing.' });
      return;
    }
    if (cartHasItems) {
      const choice = dialog.showMessageBoxSync(win!, {
        type: 'question', buttons: ['Keep the sale open', 'Close without saving'], defaultId: 0, cancelId: 0,
        title: 'Unfinished sale', message: 'The current sale has not been paid. Close the app and discard it?',
        detail: 'Nothing has been charged and no stock was deducted for this cart.',
      });
      if (choice === 0) e.preventDefault();
    }
  });
  win.on('closed', () => { win = null; });
  // Never leave the cashier looking at an empty window: say that the screen did not load.
  win.webContents.on('did-fail-load', (_e, code, desc, _url, isMainFrame) => {
    if (isMainFrame && code !== -3) {
      dialog.showErrorBox('MotoSupply POS could not start',
        `The app screen failed to load (${desc}). Please reinstall MotoSupply POS or contact your administrator.`);
    }
  });
  void win.loadURL(RENDERER_URL);
}

function harden(): void {
  // The window's session may load only our own files (and data: URLs for receipts); nothing remote.
  const ui = session.fromPartition(UI_PARTITION);
  ui.webRequest.onBeforeRequest((details, cb) => {
    const u = details.url;
    const allowed = fileUrlInsideDir(u, RENDERER_DIR) || u.startsWith('data:') || u.startsWith('devtools://');
    cb({ cancel: !allowed });
  });
  ui.setPermissionRequestHandler((_wc, _perm, cb) => cb(false));
  ui.setPermissionCheckHandler(() => false);
  app.on('web-contents-created', (_e, contents) => {
    contents.on('will-navigate', (e, url) => { if (url !== RENDERER_URL) e.preventDefault(); });
    contents.on('will-redirect', (e) => e.preventDefault());
    contents.on('will-attach-webview', (e) => e.preventDefault());
    contents.setWindowOpenHandler(({ url }) => {
      // Links to the shop website open in the normal browser, never inside the app.
      const base = serverSetting().url;
      if (base && url.startsWith(base + '/') && /^https:/.test(url)) void shell.openExternal(url);
      return { action: 'deny' };
    });
  });
}

if (!app.requestSingleInstanceLock()) {
  app.quit();
} else {
  app.on('second-instance', () => {
    if (win) {
      if (win.isMinimized()) win.restore();
      win.focus();
    }
  });
  app.whenReady().then(() => {
    Menu.setApplicationMenu(null);
    harden();
    registerIpc();
    connectApi();
    startMonitor();
    createWindow();
  });
  app.on('window-all-closed', () => {
    if (api && api.hasToken()) void api.request('POST', 'auth.logout', { body: {}, timeoutMs: 3000 }).finally(() => app.quit());
    else app.quit();
  });
}
