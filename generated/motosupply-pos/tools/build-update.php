<?php
declare(strict_types=1);

/*
 * Builds the signed update package dist/MotoSupply-POS-Update.zip from src/.
 *
 *   php tools/build-update.php --key=/path/outside/repo/release.key [--min-version=1.2.0]
 *                              [--src=src] [--out=dist/MotoSupply-POS-Update.zip] [--version=X.Y.Z]
 *
 * The package contains ONLY application code (app/, assets/, database/migrations/, index.php and
 * the security .htaccess files) plus motosupply-update.json (file list with SHA-256 checksums),
 * motosupply-update.sig (Ed25519 signature of the manifest), UPDATE-README.md and CHANGELOG.md.
 * It never contains install/, config/config.php, storage/ data, uploads, credentials or logs.
 *
 * The signing key (base64 Ed25519 secret key, 64 bytes) must be kept OUTSIDE the repository.
 * --version overrides the version written into the manifest AND into app/bootstrap.php inside
 * the package (used by tests to build a newer test package from the same source).
 */

$opt = getopt('', ['key:', 'min-version::', 'src::', 'out::', 'version::', 'remove::']);
$root = dirname(__DIR__);
$src = realpath($opt['src'] ?? "$root/src") ?: exit("Source folder not found.\n");
$out = $opt['out'] ?? "$root/dist/MotoSupply-POS-Update.zip";
$keyFile = $opt['key'] ?? exit("Usage: php tools/build-update.php --key=/path/to/release.key\n");
if (str_starts_with(realpath($keyFile) ?: '', realpath($root) ?: "\0")) {
    exit("Refusing: the signing key must be stored outside the project folder.\n");
}
$secret = base64_decode(trim((string) file_get_contents($keyFile)), true);
if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
    exit("Invalid signing key file.\n");
}

define('MOTO_ROOT', $src);
require $src . '/app/Services/Requirements.php';
require $src . '/app/Services/Updater.php';
use App\Services\Updater;

$bootstrap = (string) file_get_contents("$src/app/bootstrap.php");
preg_match("/const MOTO_VERSION = '([^']+)'/", $bootstrap, $m) || exit("Version not found.\n");
$version = $opt['version'] ?? $m[1];
if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
    exit("Invalid version.\n");
}

// Collect the files an update may write.
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile() || $f->isLink()) {
        continue;
    }
    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($src) + 1));
    if (!Updater::allowedTarget($rel) || preg_match('#(\.DS_Store|Thumbs\.db|\.log|\.tmp|\.swp)$#', $rel)) {
        continue;
    }
    $data = (string) file_get_contents($f->getPathname());
    if ($rel === 'app/bootstrap.php' && $version !== $m[1]) {
        $data = str_replace("const MOTO_VERSION = '{$m[1]}';", "const MOTO_VERSION = '$version';", $data);
    }
    $files[$rel] = $data;
}
ksort($files);
foreach (array_keys($files) as $rel) {
    if (preg_match('#^(install/|config/config\.php|storage/(?!\.htaccess$)|uploads/(?!\.htaccess$))#', $rel)) {
        exit("Refusing: $rel must never be in an update package.\n");
    }
}
foreach ($files as $rel => $data) {
    if (preg_match('/motopass|Initial#Pass|Moto[$]hop|BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY/', $data)) {
        exit("Refusing: $rel contains test credentials or a private key.\n");
    }
}

$changelog = is_file("$root/CHANGELOG.md") ? (string) file_get_contents("$root/CHANGELOG.md") : '';
$section = preg_match('/^## \[?' . preg_quote($m[1], '/') . '\]?.*?(?=^## |\z)/ms', $changelog, $cm) ? trim($cm[0]) : '';
$manifest = [
    'product' => 'motosupply-pos',
    'type' => 'update',
    'version' => $version,
    'min_version' => $opt['min-version'] ?? '1.0.0',
    'created' => gmdate('c'),
    'files' => array_map(static fn (string $d) => hash('sha256', $d), $files),
    'remove' => array_values(array_filter(explode(',', (string) ($opt['remove'] ?? '')))),
    'changelog' => $section,
];
$json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$sig = base64_encode(sodium_crypto_sign_detached($json, $secret));
// Self-check with the matching public key.
if (!sodium_crypto_sign_verify_detached(base64_decode($sig), $json, sodium_crypto_sign_publickey_from_secretkey($secret))) {
    exit("Signature self-check failed.\n");
}

@mkdir(dirname($out), 0755, true);
@unlink($out);
$zip = new ZipArchive();
$zip->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true || exit("Cannot create $out\n");
$zip->addFromString(Updater::MANIFEST, $json);
$zip->addFromString(Updater::SIGNATURE, $sig . "\n");
foreach (['UPDATE-README.md', 'CHANGELOG.md'] as $doc) {
    if (is_file("$root/$doc")) {
        $zip->addFromString($doc, (string) file_get_contents("$root/$doc"));
    }
}
foreach ($files as $rel => $data) {
    $zip->addFromString($rel, $data);
}
$zip->close();
file_put_contents("$out.sha256", hash_file('sha256', $out) . "\n");
printf("Built %s: version %s, %d files, signed by %s\n", $out, $version, count($files),
    base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret)));
