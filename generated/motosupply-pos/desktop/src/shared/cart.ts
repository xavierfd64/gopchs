// Cart shown on the cashier screen. Amounts here are for DISPLAY ONLY: at checkout the server
// recalculates prices, discount, totals and change from its own database and checks stock again.

export interface Product {
  id: number; sku: string; barcode: string | null; name: string; unit: string; category: string | null;
  price_cents: number; price: string; stock: number; low: boolean;
}
export interface Line { product: Product; qty: number }
export type DiscountType = 'none' | 'amount' | 'percent';

export function formatMoney(cents: number, symbol = '₱'): string {
  const neg = cents < 0;
  const abs = Math.abs(Math.round(cents));
  const whole = Math.floor(abs / 100).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  return `${neg ? '-' : ''}${symbol}${whole}.${String(abs % 100).padStart(2, '0')}`;
}

/** "1,234.5" → 123450 cents; null when not a valid amount (max 2 decimals). */
export function parseMoney(s: string): number | null {
  const t = s.replace(/,/g, '').trim();
  if (!/^\d{1,9}(\.\d{0,2})?$/.test(t)) return null;
  const [w, f = ''] = t.split('.');
  return Number(w) * 100 + Number((f + '00').slice(0, 2));
}

export class Cart {
  private lines = new Map<number, Line>();
  discountType: DiscountType = 'none';
  discountValue = '0';

  get(id: number): Line | undefined { return this.lines.get(id); }
  all(): Line[] { return [...this.lines.values()]; }
  isEmpty(): boolean { return this.lines.size === 0; }
  units(): number { return this.all().reduce((n, l) => n + l.qty, 0); }

  /** Add one (or qty) of a product; refused when it would exceed the known stock. */
  add(p: Product, qty = 1): { ok: boolean; error?: string } {
    const cur = this.lines.get(p.id);
    const next = (cur?.qty ?? 0) + qty;
    if (p.stock <= 0) return { ok: false, error: `${p.name} is out of stock.` };
    if (next > p.stock) return { ok: false, error: `Only ${p.stock} ${p.unit} of ${p.name} in stock.` };
    this.lines.set(p.id, { product: cur ? { ...cur.product, ...p } : p, qty: next });
    return { ok: true };
  }

  setQty(id: number, qty: number): { ok: boolean; error?: string } {
    const l = this.lines.get(id);
    if (!l) return { ok: false, error: 'Not in the cart.' };
    if (!Number.isInteger(qty) || qty < 1) return { ok: false, error: 'Enter a quantity of 1 or more (use the bin icon to remove the item).' };
    if (qty > l.product.stock) return { ok: false, error: `Only ${l.product.stock} in stock. Enter a smaller quantity.` };
    l.qty = qty;
    return { ok: true };
  }

  remove(id: number): void { this.lines.delete(id); }

  clear(): void {
    this.lines.clear();
    this.discountType = 'none';
    this.discountValue = '0';
  }

  /** Apply the latest stock/price from the server. Returns the names of lines now over stock. */
  refresh(latest: { id: number; stock: number; active: boolean; price_cents?: number }[]): string[] {
    const over: string[] = [];
    for (const s of latest) {
      const l = this.lines.get(s.id);
      if (!l) continue;
      l.product = { ...l.product, stock: s.active ? s.stock : 0, price_cents: s.price_cents ?? l.product.price_cents };
      if (l.qty > l.product.stock) over.push(l.product.stock > 0 ? `${l.product.name}: only ${l.product.stock} available` : `${l.product.name}: not available`);
    }
    return over;
  }

  invalidLines(): Line[] { return this.all().filter((l) => l.qty > l.product.stock); }

  subtotalCents(): number { return this.all().reduce((s, l) => s + l.product.price_cents * l.qty, 0); }

  discountCents(): number {
    const sub = this.subtotalCents();
    if (this.discountType === 'amount') return Math.min(parseMoney(this.discountValue) ?? 0, sub);
    if (this.discountType === 'percent') {
      const bp = Math.round((parseMoney(this.discountValue) ?? 0)); // percent with 2 decimals → basis points
      return Math.min(Math.floor((sub * Math.min(bp, 10000) + 5000) / 10000), sub);
    }
    return 0;
  }

  totalCents(): number { return this.subtotalCents() - this.discountCents(); }

  items(): { product_id: number; quantity: number }[] {
    return this.all().map((l) => ({ product_id: l.product.id, quantity: l.qty }));
  }
}

/** Number pad input. mode 'int' for quantities, 'money' for amounts with up to 2 decimals. */
export function keypadPress(value: string, key: string, mode: 'int' | 'money', maxLen = 9): string {
  if (key === 'clear') return '';
  if (key === 'back') return value.slice(0, -1);
  if (key === '.' ) {
    if (mode === 'int' || value.includes('.')) return value;
    return (value === '' ? '0' : value) + '.';
  }
  if (key === '00') return keypadPress(keypadPress(value, '0', mode, maxLen), '0', mode, maxLen);
  if (!/^\d$/.test(key)) return value;
  const dot = value.indexOf('.');
  if (dot >= 0 && value.length - dot > 2) return value; // max 2 decimals
  if (value.replace('.', '').length >= maxLen) return value;
  if (value === '0') return key;
  return value + key;
}

/** Quick cash buttons: exact amount, then the next round amounts above it. */
export function quickCash(dueCents: number): number[] {
  const out = new Set<number>([dueCents]);
  for (const step of [10000, 50000, 100000]) {
    const v = Math.ceil(dueCents / step) * step;
    if (v > dueCents) out.add(v);
  }
  return [...out].slice(0, 4);
}
