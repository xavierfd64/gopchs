<?php
$err = static fn (string $k): string => isset($errors[$k]) ? '<p class="field-error" id="err-' . $k . '">' . e($errors[$k]) . '</p>' : '';
?>
<div class="page page-narrow">
  <div class="page-head">
    <div>
      <a class="back-link" href="<?= e(url('products')) ?>">← Products</a>
      <h2 class="page-title">Adjust stock</h2>
      <p class="muted"><?= e($product['name']) ?> · <?= e($product['sku']) ?></p>
    </div>
    <a class="btn" href="<?= e(url('products.movements', ['id' => $product['id']])) ?>"><?= icon('history') ?> Stock history</a>
  </div>
  <?php if ($errors): ?><div class="alert alert-error" role="alert"><?= icon('alert') ?><span>The stock was not changed. Please correct the fields below.</span></div><?php endif; ?>
  <form class="card card-pad stack" method="post" action="<?= e(url('products.adjust')) ?>" data-once>
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
    <p class="static-value">Current stock: <strong><?= number_format((int) $product['stock_qty']) ?> <?= e($product['unit']) ?></strong></p>
    <fieldset class="field">
      <legend>Adjustment</legend>
      <div class="radio-row">
        <?php foreach (\App\Services\InventoryService::MODES as $k => $label): ?>
          <label class="radio"><input type="radio" name="mode" value="<?= $k ?>"<?= $v['mode'] === $k ? ' checked' : '' ?>> <?= e($label) ?></label>
        <?php endforeach; ?>
      </div>
      <?= $err('mode') ?>
    </fieldset>
    <div class="field">
      <label for="quantity">Quantity</label>
      <input id="quantity" name="quantity" inputmode="numeric" required maxlength="7" value="<?= e($v['quantity']) ?>"<?= isset($errors['quantity']) ? ' aria-invalid="true" aria-describedby="err-quantity"' : '' ?>>
      <?= $err('quantity') ?>
    </div>
    <div class="field">
      <label for="reason">Reason <span class="req">*</span></label>
      <input id="reason" name="reason" required maxlength="255" list="reason-list" value="<?= e($v['reason']) ?>" placeholder="e.g. Delivery received, Physical count, Damaged item"<?= isset($errors['reason']) ? ' aria-invalid="true" aria-describedby="err-reason"' : '' ?>>
      <datalist id="reason-list">
        <option value="Delivery received"><option value="Physical count correction"><option value="Damaged item"><option value="Returned to supplier"><option value="Lost / missing">
      </datalist>
      <?= $err('reason') ?>
    </div>
    <div class="form-actions">
      <a class="btn" href="<?= e(url('products')) ?>">Cancel</a>
      <button type="submit" class="btn btn-primary">Save adjustment</button>
    </div>
  </form>
</div>
