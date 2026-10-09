<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title ?? 'Receipt') ?></title>
<link rel="icon" href="<?= e(\App\Services\Branding::faviconUrl()) ?>">
<link rel="stylesheet" href="<?= e(asset('css/receipt.css')) ?>">
</head>
<body class="paper-<?= e(in_array($paper ?? '80mm', ['58mm', '80mm', 'a4'], true) ? $paper : '80mm') ?><?= !empty($embed) ? ' is-embed' : '' ?>"<?= !empty($autoprint) ? ' data-autoprint="1"' : '' ?>>
<?= $content ?>
<script src="<?= e(asset('js/receipt.js')) ?>"></script>
</body>
</html>
