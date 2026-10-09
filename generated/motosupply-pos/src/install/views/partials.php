<?php defined('MOTO_ROOT') || exit;
/** Small form helpers for the wizard views. */
function f_err(array $errors, string $k): string
{
    return isset($errors[$k]) ? '<p class="field-error" id="err-' . e($k) . '">' . e($errors[$k]) . '</p>' : '';
}
function f_inv(array $errors, string $k, string $hint = ''): string
{
    $ids = trim((isset($errors[$k]) ? 'err-' . $k : '') . ' ' . $hint);
    return (isset($errors[$k]) ? ' aria-invalid="true"' : '') . ($ids !== '' ? ' aria-describedby="' . e($ids) . '"' : '');
}
