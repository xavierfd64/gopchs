<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title ?? 'Receipt') ?></title>
<link rel="stylesheet" href="<?= e(asset('css/receipt.css')) ?>">
</head>
<body<?= !empty($autoprint) ? ' data-autoprint="1"' : '' ?>>
<?= $content ?>
<script src="<?= e(asset('js/receipt.js')) ?>"></script>
</body>
</html>
