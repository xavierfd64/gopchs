<?php use App\Core\Settings; ?>
<div class="pos"
     data-search-url="<?= e(url('api.products.search')) ?>"
     data-checkout-url="<?= e(url('api.sales.checkout')) ?>"
     data-csrf="<?= e(\App\Core\Csrf::token()) ?>"
     data-currency="<?= e(Settings::get('currency_symbol', '₱')) ?>"
     data-auto-add="<?= $autoAdd ? '1' : '0' ?>"
     data-confirm-clear="<?= $confirmClear ? '1' : '0' ?>"
     data-auto-print="<?= $autoPrint ? '1' : '0' ?>"
     data-can-discount="<?= $canDiscount ? '1' : '0' ?>">
  <section class="pos-products" aria-label="Products">
    <div class="pos-search">
      <label class="visually-hidden" for="pos-search">Search product name, SKU, or scan barcode</label>
      <?= icon('search') ?>
      <input id="pos-search" type="search" placeholder="Search product name, SKU, or scan barcode…" autocomplete="off" autocapitalize="off" spellcheck="false" maxlength="100" autofocus>
    </div>
    <div class="tabs" role="tablist" aria-label="Categories">
      <button type="button" role="tab" class="tab is-active" aria-selected="true" data-category="0">All Products</button>
      <?php foreach ($categories as $c): ?>
        <button type="button" role="tab" class="tab" aria-selected="false" data-category="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></button>
      <?php endforeach; ?>
    </div>
    <div class="pos-list-head">
      <div><h2 class="section-title" data-list-title>All Products</h2><p class="muted small" data-list-count aria-live="polite">Loading…</p></div>
    </div>
    <div class="product-grid" data-grid aria-live="polite"></div>
    <p class="kbd-hints muted small" aria-label="Keyboard shortcuts">
      <span><kbd>F2</kbd> Search</span><?php if ($canDiscount): ?><span><kbd>F4</kbd> Discount</span><?php endif; ?><span><kbd>F8</kbd> Payment</span><span><kbd>Esc</kbd> Close / clear search</span>
    </p>
  </section>

  <aside class="pos-cart" id="pos-cart" aria-labelledby="cart-title" data-cart>
    <div class="cart-head">
      <div><h2 id="cart-title">Current Sale</h2><p class="muted small" data-cart-count>0 items</p></div>
      <button type="button" class="icon-btn cart-close" data-cart-close aria-label="Close cart"><?= icon('x') ?></button>
    </div>
    <div class="cart-lines" data-cart-lines>
      <div class="empty-state compact" data-cart-empty><?= icon('pos', 'icon-lg') ?><p>Scan a barcode or pick a product to start a sale.</p></div>
    </div>
    <div class="cart-totals">
      <p class="field-error small" data-stock-error role="alert" hidden></p>
      <?php if ($canDiscount): ?>
      <div class="discount-box" data-discount-box hidden>
        <div class="discount-row">
          <label class="visually-hidden" for="discount-type">Discount type</label>
          <select id="discount-type" data-discount-type>
            <option value="amount">Amount (<?= e(Settings::get('currency_symbol', '₱')) ?>)</option>
            <option value="percent">Percent (%)</option>
          </select>
          <label class="visually-hidden" for="discount-value">Discount value</label>
          <input id="discount-value" type="text" inputmode="decimal" placeholder="0.00" data-discount-value maxlength="13">
          <button type="button" class="icon-btn" data-discount-remove aria-label="Remove discount"><?= icon('x') ?></button>
        </div>
        <p class="field-error small" data-discount-error hidden></p>
      </div>
      <?php endif; ?>
      <dl class="totals">
        <div><dt>Subtotal</dt><dd data-subtotal>₱0.00</dd></div>
        <?php if ($canDiscount): ?><div class="discount-line"><dt><button type="button" class="btn-link" data-discount-toggle>Discount</button></dt><dd data-discount>-₱0.00</dd></div><?php endif; ?>
        <div class="grand"><dt>TOTAL</dt><dd data-total>₱0.00</dd></div>
      </dl>
      <button type="button" class="btn btn-primary btn-pay" data-pay disabled>PAY NOW <kbd>F8</kbd><?= icon('arrow-right') ?></button>
      <button type="button" class="btn btn-ghost btn-block" data-clear disabled><?= icon('trash') ?> Clear</button>
    </div>
  </aside>

  <!-- Tablet portrait / phone: sticky summary bar that opens the cart sheet. -->
  <div class="cart-bar" data-cart-bar>
    <button type="button" class="cart-bar-open" data-cart-open aria-controls="pos-cart" aria-expanded="false">
      <?= icon('cart') ?><span><strong data-bar-count>0 items</strong><small>View cart</small></span>
    </button>
    <strong class="cart-bar-total" data-bar-total>₱0.00</strong>
    <button type="button" class="btn btn-primary" data-pay-bar disabled>Pay</button>
  </div>
  <div class="cart-backdrop" data-cart-backdrop hidden></div>
</div>

<dialog class="modal" id="pay-dialog" aria-labelledby="pay-title">
  <form method="dialog" class="modal-body" data-pay-form novalidate>
    <div class="modal-head"><h2 id="pay-title">Cash payment</h2><button type="button" class="icon-btn" data-close aria-label="Close"><?= icon('x') ?></button></div>
    <dl class="pay-summary">
      <div><dt>Amount due</dt><dd data-pay-due>₱0.00</dd></div>
    </dl>
    <div class="field">
      <label for="tendered">Amount tendered</label>
      <input id="tendered" type="text" inputmode="decimal" autocomplete="off" required maxlength="13" data-tendered>
    </div>
    <div class="quick-cash" data-quick-cash></div>
    <dl class="pay-summary">
      <div class="change"><dt>Change due</dt><dd data-pay-change>₱0.00</dd></div>
    </dl>
    <p class="alert alert-error" data-pay-error role="alert" hidden></p>
    <div class="modal-actions">
      <button type="button" class="btn" data-close>Cancel</button>
      <button type="submit" class="btn btn-primary" data-complete>Complete sale</button>
    </div>
  </form>
</dialog>

<dialog class="modal" id="done-dialog" aria-labelledby="done-title">
  <div class="modal-body">
    <div class="done-icon"><?= icon('check', 'icon-lg') ?></div>
    <h2 id="done-title" class="center-text">Sale completed</h2>
    <p class="center-text muted" data-done-txn></p>
    <dl class="pay-summary">
      <div><dt>Total</dt><dd data-done-total></dd></div>
      <div><dt>Tendered</dt><dd data-done-tendered></dd></div>
      <div class="change"><dt>Change</dt><dd data-done-change></dd></div>
    </dl>
    <p class="alert alert-info small" data-print-status role="status" hidden></p>
    <div class="modal-actions">
      <a class="btn" href="#" target="_blank" rel="noopener" data-done-receipt><?= icon('printer') ?> Print receipt</a>
      <button type="button" class="btn btn-primary" data-new-sale>New sale</button>
    </div>
  </div>
</dialog>

<dialog class="modal modal-keypad" id="keypad-dialog" aria-labelledby="keypad-title">
  <div class="modal-body">
    <div class="modal-head"><h2 id="keypad-title">Quantity</h2><button type="button" class="icon-btn" data-keypad-cancel aria-label="Cancel"><?= icon('x') ?></button></div>
    <p class="keypad-product" data-keypad-product></p>
    <p class="muted small" data-keypad-stock></p>
    <output class="keypad-display" data-keypad-display aria-live="polite" aria-label="Quantity">0</output>
    <p class="field-error small" data-keypad-error role="alert" hidden></p>
    <div class="keypad" role="group" aria-label="Number pad">
      <?php foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9] as $d): ?><button type="button" class="key" data-key="<?= $d ?>"><?= $d ?></button><?php endforeach; ?>
      <button type="button" class="key key-fn" data-key="clear">Clear</button>
      <button type="button" class="key" data-key="0">0</button>
      <button type="button" class="key key-fn" data-key="back" aria-label="Backspace"><?= icon('backspace') ?></button>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn" data-keypad-cancel>Cancel</button>
      <button type="button" class="btn btn-primary" data-keypad-confirm>Confirm</button>
    </div>
  </div>
</dialog>

<dialog class="modal" id="confirm-dialog" aria-labelledby="confirm-title">
  <form method="dialog" class="modal-body">
    <h2 id="confirm-title">Clear this sale?</h2>
    <p class="muted">All items will be removed from the current sale. Nothing has been charged.</p>
    <div class="modal-actions">
      <button value="cancel" class="btn">Keep items</button>
      <button value="ok" class="btn btn-danger">Clear sale</button>
    </div>
  </form>
</dialog>
<div class="print-frame-holder" data-print-holder aria-hidden="true"></div>
<div class="toast" data-toast role="status" aria-live="polite" hidden></div>
