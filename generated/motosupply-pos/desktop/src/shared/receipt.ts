// Receipt HTML for printing (pure function; every value from the server is escaped).

export interface ReceiptItem { name: string; sku: string; quantity: number; unit_price: string; line_total: string }
export interface ReceiptSale {
  id: number; transaction_no: string; status: string; date: string; cashier: string;
  items: ReceiptItem[]; item_count: number; subtotal: string; discount: string; has_discount: boolean;
  total: string; tendered: string; change: string;
}
export interface ShopInfo { name: string; address: string; phone: string; email: string; logo: string | null }
export interface ReceiptSettings { paper: string; show_logo: boolean; footer: string; auto_print: boolean }

export function esc(s: unknown): string {
  return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c] as string));
}

/** Only image data URIs of the types the server sends are embedded. */
function safeLogo(logo: string | null): string | null {
  return logo && /^data:image\/(png|jpeg|webp);base64,[A-Za-z0-9+/=]+$/.test(logo) ? logo : null;
}

export function receiptHtml(sale: ReceiptSale, shop: ShopInfo, rc: ReceiptSettings, opts: { reprint?: boolean } = {}): string {
  const width = rc.paper === '58mm' ? '54mm' : rc.paper === 'a4' ? '120mm' : '72mm';
  const logo = rc.show_logo ? safeLogo(shop.logo) : null;
  const rows = sale.items.map((i) => `
      <tr><td colspan="3" class="name">${esc(i.name)}</td></tr>
      <tr class="sub"><td>${esc(i.quantity)} × ${esc(i.unit_price)}</td><td class="sku">${esc(i.sku)}</td><td class="num">${esc(i.line_total)}</td></tr>`).join('');
  return `<!doctype html><html><head><meta charset="utf-8">
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src data:; style-src 'unsafe-inline'">
<title>Receipt ${esc(sale.transaction_no)}</title>
<style>
  @page { margin: 0; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: "Segoe UI", Arial, sans-serif; color: #000; font-size: 12px; }
  .r { width: ${width}; margin: 0 auto; padding: 4mm 2mm; }
  .c { text-align: center; } .logo { max-width: 60%; max-height: 22mm; display: block; margin: 0 auto 2mm; }
  h1 { font-size: 15px; margin: 0 0 1mm; } p { margin: 0 0 1mm; }
  hr { border: 0; border-top: 1px dashed #000; margin: 2mm 0; }
  table { width: 100%; border-collapse: collapse; } td { padding: .4mm 0; vertical-align: top; }
  .name { font-weight: 600; } .sub td { padding-bottom: 1.2mm; } .sku { color: #333; font-size: 10px; text-align: center; }
  .num { text-align: right; white-space: nowrap; } .tot td { font-size: 12px; } .grand td { font-size: 15px; font-weight: 700; }
  .tag { border: 1px solid #000; padding: 1mm; text-align: center; font-weight: 700; margin: 2mm 0; }
</style></head><body><div class="r">
  <div class="c">
    ${logo ? `<img class="logo" src="${logo}" alt="">` : ''}
    <h1>${esc(shop.name)}</h1>
    ${shop.address ? `<p>${esc(shop.address)}</p>` : ''}
    ${shop.phone ? `<p>${esc(shop.phone)}</p>` : ''}
    ${shop.email ? `<p>${esc(shop.email)}</p>` : ''}
  </div>
  <hr>
  <p>Transaction: <b>${esc(sale.transaction_no)}</b></p>
  <p>Date: ${esc(sale.date)}</p>
  <p>Cashier: ${esc(sale.cashier)}</p>
  ${opts.reprint ? '<div class="tag">REPRINT</div>' : ''}
  ${sale.status === 'voided' ? '<div class="tag">VOIDED</div>' : ''}
  <hr>
  <table>${rows}</table>
  <hr>
  <table>
    <tr class="tot"><td>Subtotal</td><td class="num">${esc(sale.subtotal)}</td></tr>
    ${sale.has_discount ? `<tr class="tot"><td>Discount</td><td class="num">-${esc(sale.discount)}</td></tr>` : ''}
    <tr class="grand"><td>TOTAL</td><td class="num">${esc(sale.total)}</td></tr>
    <tr class="tot"><td>Cash tendered</td><td class="num">${esc(sale.tendered)}</td></tr>
    <tr class="tot"><td>Change</td><td class="num">${esc(sale.change)}</td></tr>
  </table>
  <hr>
  <p class="c">${esc(sale.item_count)} item(s)</p>
  ${rc.footer ? `<p class="c">${esc(rc.footer)}</p>` : ''}
</div></body></html>`;
}
