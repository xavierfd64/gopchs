<?php
$err = static fn (string $k): string => isset($errors[$k]) ? '<p class="field-error" id="err-' . $k . '">' . e($errors[$k]) . '</p>' : '';
$inv = static fn (string $k): string => isset($errors[$k]) ? ' aria-invalid="true" aria-describedby="err-' . $k . '"' : '';
$val = static fn (string $k): string => e($v[$k] ?? '');
?>
<div class="page page-narrow">
  <div class="page-head">
    <div>
      <a class="back-link" href="<?= e(url('products')) ?>">← Products</a>
      <h2 class="page-title"><?= $product ? 'Edit product' : 'Add product' ?></h2>
      <?php if ($product): ?><p class="muted">Changes apply to future sales only. Past receipts keep their original names and prices.</p><?php endif; ?>
    </div>
  </div>
  <form class="card card-pad stack" method="post" action="<?= e(url('products.save')) ?>" enctype="multipart/form-data" data-once novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) ($product['id'] ?? 0) ?>">
    <div class="form-grid">
      <div class="field span-2">
        <label for="name">Product name <span class="req">*</span></label>
        <input id="name" name="name" required maxlength="150" value="<?= $val('name') ?>"<?= $inv('name') ?>>
        <?= $err('name') ?>
      </div>
      <div class="field">
        <label for="sku">SKU <span class="req">*</span></label>
        <input id="sku" name="sku" required maxlength="64" value="<?= $val('sku') ?>" autocapitalize="characters"<?= $inv('sku') ?>>
        <?= $err('sku') ?>
      </div>
      <div class="field">
        <label for="barcode">Barcode <span class="muted">(optional)</span></label>
        <input id="barcode" name="barcode" maxlength="64" value="<?= $val('barcode') ?>" aria-describedby="barcode-hint"<?= $inv('barcode') ?>>
        <small class="hint" id="barcode-hint">Click here and scan the product to fill this in.</small>
        <?= $err('barcode') ?>
      </div>
      <div class="field">
        <label for="category">Category</label>
        <input id="category" name="category" maxlength="80" list="category-list" value="<?= $val('category') ?>"<?= $inv('category') ?>>
        <datalist id="category-list"><?php foreach ($categories as $c): ?><option value="<?= e($c['name']) ?>"><?php endforeach; ?></datalist>
        <?= $err('category') ?>
      </div>
      <div class="field">
        <label for="unit">Unit</label>
        <input id="unit" name="unit" maxlength="20" value="<?= $val('unit') ?>" placeholder="pc, set, bottle, liter"<?= $inv('unit') ?>>
        <?= $err('unit') ?>
      </div>
      <div class="field">
        <label for="cost_price">Cost price</label>
        <input id="cost_price" name="cost_price" inputmode="decimal" maxlength="14" value="<?= $val('cost_price') ?>" placeholder="0.00"<?= $inv('cost_price') ?>>
        <?= $err('cost_price') ?>
      </div>
      <div class="field">
        <label for="selling_price">Selling price <span class="req">*</span></label>
        <input id="selling_price" name="selling_price" inputmode="decimal" required maxlength="14" value="<?= $val('selling_price') ?>" placeholder="0.00"<?= $inv('selling_price') ?>>
        <?= $err('selling_price') ?>
      </div>
      <?php if (!$product): ?>
      <div class="field">
        <label for="stock_qty">Opening stock</label>
        <input id="stock_qty" name="stock_qty" inputmode="numeric" maxlength="7" value="<?= $val('stock_qty') ?>"<?= $inv('stock_qty') ?>>
        <?= $err('stock_qty') ?>
      </div>
      <?php else: ?>
      <div class="field">
        <span class="label">Current stock</span>
        <p class="static-value"><?= number_format((int) $product['stock_qty']) ?> <?= e($product['unit']) ?> · <a href="<?= e(url('products.adjust', ['id' => $product['id']])) ?>">Adjust stock</a></p>
      </div>
      <?php endif; ?>
      <div class="field">
        <label for="low_stock_threshold">Low-stock threshold</label>
        <input id="low_stock_threshold" name="low_stock_threshold" inputmode="numeric" maxlength="6" value="<?= $val('low_stock_threshold') ?>" placeholder="Default (<?= (int) $defaultThreshold ?>)"<?= $inv('low_stock_threshold') ?>>
        <?= $err('low_stock_threshold') ?>
      </div>
      <div class="field span-2">
        <label for="description">Description <span class="muted">(optional)</span></label>
        <textarea id="description" name="description" rows="3" maxlength="2000"<?= $inv('description') ?>><?= $val('description') ?></textarea>
        <?= $err('description') ?>
      </div>
      <div class="field span-2">
        <label for="image">Product image <span class="muted">(optional, JPG/PNG/WEBP/GIF, max 2 MB)</span></label>
        <?php if (!empty($product['image_path'])): ?>
          <div class="image-current">
            <img src="<?= e(\App\Core\Http::basePath() . '/' . $product['image_path']) ?>" alt="Current image of <?= e($product['name']) ?>" class="thumb thumb-lg">
            <label class="check"><input type="checkbox" name="remove_image" value="1"> Remove current image</label>
          </div>
        <?php endif; ?>
        <input id="image" name="image" type="file" accept="image/jpeg,image/png,image/webp,image/gif"<?= $inv('image') ?>>
        <?= $err('image') ?>
      </div>
    </div>
    <div class="form-actions">
      <a class="btn" href="<?= e(url('products')) ?>">Cancel</a>
      <button type="submit" class="btn btn-primary"><?= $product ? 'Save changes' : 'Create product' ?></button>
    </div>
  </form>
</div>
