/* MotoSupply POS — cart, product search and USB barcode scanner handling.
 * Scanners that act as keyboards type the code into the search box and press Enter.
 * The browser only collects product IDs and quantities; prices, totals, stock and change
 * are recalculated and enforced by the PHP server when the sale is completed. */
(function () {
  'use strict';

  var root = document.querySelector('.pos');
  if (!root) return;

  var cfg = {
    searchUrl: root.getAttribute('data-search-url'),
    checkoutUrl: root.getAttribute('data-checkout-url'),
    csrf: root.getAttribute('data-csrf'),
    symbol: root.getAttribute('data-currency') || '₱',
    autoAdd: root.getAttribute('data-auto-add') === '1',
    confirmClear: root.getAttribute('data-confirm-clear') === '1'
  };

  var $ = function (sel, ctx) { return (ctx || document).querySelector(sel); };
  var search = $('#pos-search');
  var grid = $('[data-grid]');
  var listTitle = $('[data-list-title]');
  var listCount = $('[data-list-count]');
  var linesEl = $('[data-cart-lines]');
  var emptyEl = $('[data-cart-empty]');
  var countEl = $('[data-cart-count]');
  var subtotalEl = $('[data-subtotal]');
  var discountEl = $('[data-discount]');
  var totalEl = $('[data-total]');
  var payBtn = $('[data-pay]');
  var clearBtn = $('[data-clear]');
  var discountBox = $('[data-discount-box]');
  var discountType = $('[data-discount-type]');
  var discountValue = $('[data-discount-value]');
  var discountError = $('[data-discount-error]');
  var payDialog = $('#pay-dialog');
  var doneDialog = $('#done-dialog');
  var confirmDialog = $('#confirm-dialog');
  var tenderedInput = $('[data-tendered]');
  var payError = $('[data-pay-error]');
  var completeBtn = $('[data-complete]');
  var toastEl = $('[data-toast]');
  var jumpEl = $('[data-cart-jump]');

  /** @type {Map<number, {id:number,name:string,sku:string,barcode:?string,category:?string,price:number,stock:number,unit:string,image:?string,qty:number}>} */
  var cart = new Map();
  var token = uuid();
  var category = 0;
  var categoryName = 'All Products';
  var submitting = false;

  // ---------- helpers ----------
  function uuid() {
    var b = new Uint8Array(16);
    (window.crypto || window.msCrypto).getRandomValues(b);
    b[6] = (b[6] & 0x0f) | 0x40;
    b[8] = (b[8] & 0x3f) | 0x80;
    var h = Array.prototype.map.call(b, function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
    return h.slice(0, 8) + '-' + h.slice(8, 12) + '-' + h.slice(12, 16) + '-' + h.slice(16, 20) + '-' + h.slice(20);
  }
  function parseCents(str) {
    var s = String(str || '').replace(/[,\s₱$]/g, '');
    var m = /^(\d{1,10})(?:\.(\d{0,2}))?$/.exec(s);
    if (!m) return null;
    return parseInt(m[1], 10) * 100 + parseInt(((m[2] || '') + '00').slice(0, 2), 10);
  }
  function fmt(cents) {
    var neg = cents < 0; cents = Math.abs(cents);
    var whole = Math.floor(cents / 100).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return (neg ? '-' : '') + cfg.symbol + whole + '.' + ('0' + (cents % 100)).slice(-2);
  }
  function initials(text) {
    var w = String(text || '?').trim().split(/[\s\-_/]+/).filter(Boolean);
    return (w[0] || '?').slice(0, 2).toUpperCase();
  }
  function tint(text) {
    // Same idea as the PHP tint(): stable colour per label.
    var h = 0; for (var i = 0; i < text.length; i++) { h = (h * 31 + text.charCodeAt(i)) >>> 0; }
    return 't' + (h % 6);
  }
  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined && text !== null) n.textContent = text;
    return n;
  }
  function svgIcon(name) {
    var ns = 'http://www.w3.org/2000/svg';
    var s = document.createElementNS(ns, 'svg');
    s.setAttribute('class', 'icon'); s.setAttribute('aria-hidden', 'true');
    var u = document.createElementNS(ns, 'use'); u.setAttribute('href', '#i-' + name);
    s.appendChild(u); return s;
  }
  var toastTimer = null;
  function toast(msg, isError) {
    toastEl.textContent = msg;
    toastEl.classList.toggle('is-error', !!isError);
    toastEl.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toastEl.hidden = true; }, 3200);
  }
  function thumb(p, cls) {
    if (p.image) {
      var img = el('img', 'thumb ' + (cls || '')); img.src = p.image; img.alt = ''; img.loading = 'lazy';
      return img;
    }
    var t = el('span', 'thumb ' + tint(p.name) + ' ' + (cls || ''));
    t.setAttribute('aria-hidden', 'true');
    t.appendChild(document.createTextNode(initials(p.name)));
    if (cls === 'big' && p.category) t.appendChild(el('small', null, p.category.slice(0, 3).toUpperCase()));
    return t;
  }

  // ---------- product grid ----------
  var reqSeq = 0;
  function fetchProducts(q) {
    var url = cfg.searchUrl + '&q=' + encodeURIComponent(q || '') + '&category=' + category;
    return fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' }).then(function (r) {
      if (r.status === 401) { window.location.reload(); throw new Error('auth'); }
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    });
  }
  function loadGrid(q) {
    var seq = ++reqSeq;
    listCount.textContent = 'Loading…';
    return fetchProducts(q).then(function (data) {
      if (seq !== reqSeq) return data;
      renderGrid(data.results, q);
      return data;
    }).catch(function (err) {
      if (seq === reqSeq && err.message !== 'auth') {
        listCount.textContent = 'Could not load products. Check your connection.';
      }
    });
  }
  function renderGrid(products, q) {
    grid.textContent = '';
    listTitle.textContent = q ? 'Results for “' + q + '”' : categoryName;
    listCount.textContent = products.length + (products.length === 1 ? ' product' : ' products') + (q ? ' found' : ' available');
    if (!products.length) {
      var empty = el('div', 'empty-state compact');
      empty.appendChild(el('p', null, q ? 'No products match your search.' : 'No active products in this category.'));
      grid.appendChild(empty);
      return;
    }
    products.forEach(function (p) {
      var card = el('div', 'p-card' + (p.stock <= 0 ? ' is-out' : ''));
      card.appendChild(thumb(p, 'big'));
      var info = el('div', 'p-info');
      if (p.category) info.appendChild(el('span', 'p-cat', p.category));
      info.appendChild(el('span', 'p-name', p.name));
      info.appendChild(el('span', 'p-meta', p.sku + (p.barcode ? ' · ' + p.barcode : '')));
      info.appendChild(el('span', 'p-price', p.price_display));
      var st = el('span', 'p-stock' + (p.stock <= 0 ? ' out' : (p.low ? ' low' : '')),
        p.stock <= 0 ? 'Out of stock' : p.stock + ' available');
      info.appendChild(st);
      card.appendChild(info);
      var add = el('button', 'icon-btn p-add');
      add.type = 'button';
      add.setAttribute('aria-label', 'Add ' + p.name + ' to sale');
      add.appendChild(svgIcon('plus'));
      add.disabled = p.stock <= 0;
      add.addEventListener('click', function () { addToCart(p, 1); });
      card.appendChild(add);
      grid.appendChild(card);
    });
  }

  // ---------- cart ----------
  function addToCart(p, qty) {
    var line = cart.get(p.id);
    var current = line ? line.qty : 0;
    if (p.stock <= 0) { toast(p.name + ' is out of stock.', true); return false; }
    if (current + qty > p.stock) { toast('Only ' + p.stock + ' of ' + p.name + ' in stock.', true); return false; }
    if (line) {
      line.qty += qty; line.stock = p.stock; line.price = parseCents(p.price);
    } else {
      cart.set(p.id, {
        id: p.id, name: p.name, sku: p.sku, barcode: p.barcode, category: p.category,
        price: parseCents(p.price), stock: p.stock, unit: p.unit, image: p.image, qty: qty
      });
    }
    renderCart();
    return true;
  }
  function setQty(id, qty) {
    var line = cart.get(id);
    if (!line) return;
    if (qty <= 0) { cart.delete(id); }
    else if (qty > line.stock) { toast('Only ' + line.stock + ' of ' + line.name + ' in stock.', true); line.qty = line.stock; }
    else { line.qty = qty; }
    renderCart();
  }
  function subtotal() {
    var s = 0; cart.forEach(function (l) { s += l.price * l.qty; }); return s;
  }
  function discountCents(sub) {
    if (discountBox.hidden) return { cents: 0, ok: true };
    var raw = discountValue.value.trim();
    if (raw === '') return { cents: 0, ok: true };
    var v = parseCents(raw);
    if (discountType.value === 'percent') {
      if (v === null || v > 10000) return { cents: 0, ok: false, msg: 'Enter a percentage from 0 to 100.' };
      return { cents: Math.floor((sub * v + 5000) / 10000), ok: true };
    }
    if (v === null || v > sub) return { cents: 0, ok: false, msg: 'Discount must be between 0 and the subtotal.' };
    return { cents: v, ok: true };
  }
  function totals() {
    var sub = subtotal();
    var d = discountCents(sub);
    return { sub: sub, disc: d.cents, total: sub - d.cents, discOk: d.ok, discMsg: d.msg };
  }
  function renderCart() {
    linesEl.querySelectorAll('.c-line').forEach(function (n) { n.remove(); });
    var items = 0;
    cart.forEach(function (l) {
      items += l.qty;
      var row = el('div', 'c-line');
      row.appendChild(thumb(l));
      var name = el('div', 'grow');
      name.appendChild(el('div', 'c-name', l.name));
      name.appendChild(el('div', 'c-meta', l.sku + ' · ' + fmt(l.price)));
      row.appendChild(name);
      var rm = el('button', 'icon-btn c-remove');
      rm.type = 'button'; rm.setAttribute('aria-label', 'Remove ' + l.name);
      rm.appendChild(svgIcon('trash'));
      rm.addEventListener('click', function () { setQty(l.id, 0); search.focus(); });
      row.appendChild(rm);
      var bottom = el('div', 'c-bottom');
      var qty = el('div', 'qty');
      var minus = el('button'); minus.type = 'button'; minus.setAttribute('aria-label', 'Decrease quantity of ' + l.name); minus.appendChild(svgIcon('minus'));
      minus.addEventListener('click', function () { setQty(l.id, l.qty - 1); });
      var input = el('input'); input.type = 'text'; input.inputMode = 'numeric'; input.value = String(l.qty);
      input.setAttribute('aria-label', 'Quantity of ' + l.name); input.maxLength = 5;
      input.addEventListener('change', function () {
        var n = /^\d{1,5}$/.test(input.value.trim()) ? parseInt(input.value, 10) : NaN;
        if (isNaN(n)) { input.value = String(l.qty); toast('Enter a whole number quantity.', true); return; }
        setQty(l.id, n);
      });
      input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); input.blur(); } });
      var plus = el('button'); plus.type = 'button'; plus.setAttribute('aria-label', 'Increase quantity of ' + l.name); plus.appendChild(svgIcon('plus'));
      plus.addEventListener('click', function () { setQty(l.id, l.qty + 1); });
      qty.appendChild(minus); qty.appendChild(input); qty.appendChild(plus);
      bottom.appendChild(qty);
      bottom.appendChild(el('span', 'c-total', fmt(l.price * l.qty)));
      row.appendChild(bottom);
      linesEl.appendChild(row);
    });
    emptyEl.hidden = cart.size > 0;
    countEl.textContent = items + (items === 1 ? ' item' : ' items');
    var t = totals();
    subtotalEl.textContent = fmt(t.sub);
    discountEl.textContent = '-' + fmt(t.disc);
    totalEl.textContent = fmt(t.total);
    discountError.hidden = t.discOk;
    discountError.textContent = t.discOk ? '' : t.discMsg;
    discountValue.setAttribute('aria-invalid', t.discOk ? 'false' : 'true');
    jumpEl.hidden = cart.size === 0;
    $('[data-jump-count]').textContent = 'Current sale · ' + countEl.textContent;
    $('[data-jump-total]').textContent = fmt(t.total);
    payBtn.disabled = cart.size === 0 || !t.discOk;
    clearBtn.disabled = cart.size === 0;
  }
  function resetSale() {
    cart.clear();
    discountValue.value = '';
    discountBox.hidden = true;
    token = uuid();
    renderCart();
  }

  // ---------- search & barcode scanning ----------
  var debounce = null;
  var scanQueue = Promise.resolve();
  search.addEventListener('input', function () {
    clearTimeout(debounce);
    var q = search.value.trim();
    debounce = setTimeout(function () { loadGrid(q); }, 250);
  });
  search.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && search.value !== '') {
      e.preventDefault(); search.value = ''; loadGrid('');
      return;
    }
    if (e.key !== 'Enter') return;
    e.preventDefault();
    clearTimeout(debounce);
    var code = search.value.trim();
    if (!code) return;
    // Clear immediately so the next scan starts fresh; scans are processed one at a time in order.
    search.value = '';
    scanQueue = scanQueue.then(function () { return processScan(code); }, function () { return processScan(code); });
  });
  function processScan(code) {
    var seq = ++reqSeq;
    return fetchProducts(code).then(function (data) {
      if (data.exact && cfg.autoAdd) {
        if (addToCart(data.exact, 1)) toast('Added ' + data.exact.name);
        if (seq === reqSeq) loadGrid('');
        return;
      }
      if (data.exact) { renderGrid([data.exact], code); search.value = code; return; }
      if (data.results.length === 1) {
        if (addToCart(data.results[0], 1)) toast('Added ' + data.results[0].name);
        if (seq === reqSeq) loadGrid('');
        return;
      }
      if (!data.results.length) {
        toast('No product found for “' + code + '”.', true);
        search.value = code; search.select();
        if (seq === reqSeq) renderGrid([], code);
        return;
      }
      search.value = code;
      if (seq === reqSeq) renderGrid(data.results, code);
    }).catch(function (err) {
      if (err.message !== 'auth') toast('Search failed. Check your connection and try again.', true);
    });
  }

  // ---------- categories ----------
  document.querySelectorAll('.tab[data-category]').forEach(function (tab) {
    tab.addEventListener('click', function () {
      document.querySelectorAll('.tab[data-category]').forEach(function (t) {
        t.classList.remove('is-active'); t.setAttribute('aria-selected', 'false');
      });
      tab.classList.add('is-active'); tab.setAttribute('aria-selected', 'true');
      category = parseInt(tab.getAttribute('data-category'), 10) || 0;
      categoryName = tab.textContent;
      loadGrid(search.value.trim());
    });
  });

  // ---------- discount ----------
  $('[data-discount-toggle]').addEventListener('click', function () {
    discountBox.hidden = !discountBox.hidden;
    if (!discountBox.hidden) discountValue.focus();
    renderCart();
  });
  $('[data-discount-remove]').addEventListener('click', function () {
    discountValue.value = ''; discountBox.hidden = true; renderCart(); search.focus();
  });
  discountValue.addEventListener('input', renderCart);
  discountType.addEventListener('change', renderCart);

  // ---------- clear ----------
  clearBtn.addEventListener('click', function () {
    if (!cart.size) return;
    if (!cfg.confirmClear) { resetSale(); search.focus(); return; }
    confirmDialog.returnValue = '';
    confirmDialog.showModal();
  });
  confirmDialog.addEventListener('close', function () {
    if (confirmDialog.returnValue === 'ok') { resetSale(); toast('Sale cleared.'); }
    search.focus();
  });

  // ---------- payment ----------
  function updateChange() {
    var t = totals();
    var tendered = parseCents(tenderedInput.value);
    var changeRow = $('.change', payDialog);
    var changeEl = $('[data-pay-change]');
    if (tendered === null) { changeEl.textContent = fmt(0); changeRow.classList.remove('is-short'); return; }
    var diff = tendered - t.total;
    changeRow.classList.toggle('is-short', diff < 0);
    changeEl.textContent = diff < 0 ? 'Short ' + fmt(-diff) : fmt(diff);
  }
  function openPay() {
    if (!cart.size || payBtn.disabled) return;
    var t = totals();
    $('[data-pay-due]').textContent = fmt(t.total);
    payError.hidden = true;
    tenderedInput.value = '';
    var quick = $('[data-quick-cash]');
    quick.textContent = '';
    var opts = [t.total];
    [10000, 50000, 100000].forEach(function (step) {
      var v = Math.ceil(t.total / step) * step;
      if (v > 0 && opts.indexOf(v) === -1) opts.push(v);
    });
    opts.forEach(function (v, i) {
      var b = el('button', 'btn btn-sm', i === 0 ? 'Exact' : fmt(v).replace(/\.00$/, ''));
      b.type = 'button';
      b.addEventListener('click', function () { tenderedInput.value = (v / 100).toFixed(2); updateChange(); tenderedInput.focus(); });
      quick.appendChild(b);
    });
    updateChange();
    payDialog.showModal();
    tenderedInput.focus();
  }
  payBtn.addEventListener('click', openPay);
  tenderedInput.addEventListener('input', updateChange);
  payDialog.querySelectorAll('[data-close]').forEach(function (b) {
    b.addEventListener('click', function () { if (!submitting) payDialog.close(); });
  });
  payDialog.addEventListener('cancel', function (e) { if (submitting) e.preventDefault(); });
  payDialog.addEventListener('close', function () { if (!doneDialog.open) search.focus(); });

  $('[data-pay-form]').addEventListener('submit', function (e) {
    e.preventDefault();
    if (submitting) return; // ignore double clicks / repeated Enter
    var t = totals();
    var tendered = parseCents(tenderedInput.value);
    if (tendered === null) { showPayError('Enter the amount tendered.'); return; }
    if (tendered < t.total) { showPayError('Insufficient payment. Amount due is ' + fmt(t.total) + '.'); return; }
    var items = [];
    cart.forEach(function (l) { items.push({ product_id: l.id, quantity: l.qty }); });
    var body = {
      items: items,
      discount_type: discountBox.hidden || discountValue.value.trim() === '' ? 'none' : discountType.value,
      discount_value: discountValue.value.trim() || '0',
      tendered: tenderedInput.value.trim(),
      client_token: token
    };
    submitting = true;
    completeBtn.disabled = true; completeBtn.classList.add('is-busy');
    payError.hidden = true;
    fetch(cfg.checkoutUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': cfg.csrf },
      body: JSON.stringify(body)
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: 'Unexpected server response (HTTP ' + r.status + ').' }; })
        .then(function (data) { data._status = r.status; return data; });
    }).then(function (data) {
      if (data.ok) {
        // Success is shown only after the server confirms the saved sale.
        payDialog.close();
        $('[data-done-txn]').textContent = 'Transaction #' + data.sale.transaction_no;
        $('[data-done-total]').textContent = data.sale.total;
        $('[data-done-tendered]').textContent = data.sale.tendered;
        $('[data-done-change]').textContent = data.sale.change;
        $('[data-done-receipt]').href = data.receipt_url + '&print=1';
        resetSale();
        doneDialog.showModal();
        $('[data-new-sale]').focus();
        loadGrid('');
        return;
      }
      if (data._status === 401) { showPayError(data.error + ' Reloading…'); setTimeout(function () { window.location.reload(); }, 1500); return; }
      // Validation failures (422) mean nothing was saved; the same token is safe to reuse.
      showPayError(data.error || 'The sale could not be completed.');
      if (data._status === 422) loadGrid(search.value.trim());
    }).catch(function () {
      // Outcome unknown (network). Retrying reuses the same token, so the server will
      // return the existing sale instead of creating a duplicate.
      showPayError('Could not reach the server. Check your connection and press Complete sale again — it will not be charged twice.');
    }).then(function () {
      submitting = false;
      completeBtn.disabled = false; completeBtn.classList.remove('is-busy');
    });
  });
  function showPayError(msg) { payError.textContent = msg; payError.hidden = false; }

  $('[data-new-sale]').addEventListener('click', function () { doneDialog.close(); });
  doneDialog.addEventListener('close', function () { search.focus(); });

  // ---------- keyboard shortcuts ----------
  document.addEventListener('keydown', function (e) {
    if (document.querySelector('dialog[open]')) return;
    if (e.key === 'F2') { e.preventDefault(); search.focus(); search.select(); }
    else if (e.key === 'F4') { e.preventDefault(); $('[data-discount-toggle]').click(); }
    else if (e.key === 'F8') { e.preventDefault(); openPay(); }
  });

  window.addEventListener('beforeunload', function (e) {
    if (cart.size > 0 && !submitting) { e.preventDefault(); e.returnValue = ''; }
  });

  renderCart();
  loadGrid('');
})();
