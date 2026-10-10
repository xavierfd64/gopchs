// Pure helpers shared by the main process and the unit tests (no Electron imports here).

export interface ServerUrlResult {
  ok: boolean;
  /** Normalised base URL without trailing slash, e.g. https://shop.example.com/pos */
  url?: string;
  error?: string;
}

const LOOPBACK = new Set(['localhost', '127.0.0.1', '[::1]', '::1']);

/**
 * Validate the MotoSupply server address.
 * HTTPS is required. Plain HTTP is accepted only for a loopback address (this computer) and only
 * when local development mode has been explicitly enabled by the administrator.
 */
export function validateServerUrl(input: string, allowInsecureLocalhost: boolean): ServerUrlResult {
  const raw = String(input ?? '').trim();
  if (raw === '' || raw.length > 300) {
    return { ok: false, error: 'Enter the address of your MotoSupply website, for example https://shop.example.com' };
  }
  let u: URL;
  try {
    u = new URL(/^[a-z][a-z0-9+.-]*:\/\//i.test(raw) ? raw : 'https://' + raw);
  } catch {
    return { ok: false, error: 'That is not a valid web address.' };
  }
  if (u.username || u.password) {
    return { ok: false, error: 'The address must not contain a username or password.' };
  }
  if (u.search || u.hash) {
    return { ok: false, error: 'Enter only the website address, without "?" or "#" parts.' };
  }
  if (u.protocol === 'http:') {
    if (!(allowInsecureLocalhost && LOOPBACK.has(u.hostname))) {
      return { ok: false, error: 'The address must start with https:// (a secure connection is required).' };
    }
  } else if (u.protocol !== 'https:') {
    return { ok: false, error: 'The address must start with https://' };
  }
  let path = u.pathname.replace(/\/+$/, '');
  // Accept the address of a page inside MotoSupply too (…/index.php, …/api.php).
  path = path.replace(/\/(index|api)\.php$/i, '');
  if (!/^[A-Za-z0-9._~\-/%]*$/.test(path)) {
    return { ok: false, error: 'The address contains unsupported characters.' };
  }
  return { ok: true, url: `${u.protocol}//${u.host}${path}` };
}

/** Allowed characters/length for usernames and passwords sent to the server. */
export function validCredentials(username: unknown, password: unknown): boolean {
  return typeof username === 'string' && typeof password === 'string'
    && username.trim().length > 0 && username.length <= 50 && password.length > 0 && password.length <= 200;
}

export function isUuidV4(s: unknown): s is string {
  return typeof s === 'string' && /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(s);
}

export interface CartLineInput { product_id: number; quantity: number }

/** Validate a cart sent from the screen before it goes to the server (which validates again). */
export function validCart(items: unknown): items is CartLineInput[] {
  if (!Array.isArray(items) || items.length === 0 || items.length > 200) return false;
  return items.every((i) => i !== null && typeof i === 'object'
    && Number.isInteger((i as CartLineInput).product_id) && (i as CartLineInput).product_id > 0
    && Number.isInteger((i as CartLineInput).quantity) && (i as CartLineInput).quantity > 0 && (i as CartLineInput).quantity <= 9999);
}

/** Money amount typed by the cashier: digits with up to 2 decimals. */
export function validAmount(s: unknown): s is string {
  return typeof s === 'string' && /^\d{1,9}(\.\d{1,2})?$/.test(s);
}

/** Only #rrggbb colours from the server are applied to the screen. */
export function safeColor(c: unknown, fallback: string): string {
  return typeof c === 'string' && /^#[0-9a-fA-F]{6}$/.test(c) ? c : fallback;
}

/** Cart signature: same products and quantities → the same checkout attempt (same client token). */
export function cartSignature(items: CartLineInput[], discount = ''): string {
  return [...items].sort((a, b) => a.product_id - b.product_id).map((i) => `${i.product_id}x${i.quantity}`).join(',') + '|' + discount;
}
