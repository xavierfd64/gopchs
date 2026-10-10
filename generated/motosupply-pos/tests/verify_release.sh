#!/usr/bin/env bash
# Verifies the actual release ZIPs (installer and update) on clean Apache installs (development machine only).
# Layout used: Apache vhost :8090 serving /var/www/mototest (subfolder install "wiz"),
#              Apache vhost :8091 serving /srv/acct/htdocs (root install, parent folder writable).
set -uo pipefail
HERE="$(cd "$(dirname "$0")/.." && pwd)"
ZIP="$HERE/dist/MotoSupply-POS-Installer.zip"
UPD="$HERE/dist/MotoSupply-POS-Update.zip"
OUT="${1:-/tmp/motosupply-verify}"; mkdir -p "$OUT"
export PLAYWRIGHT_MODULE="${PLAYWRIGHT_MODULE:-/opt/node-tools/node_modules/playwright}"
status=0
run() { local log="$OUT/$(echo "$1" | tr -c 'a-z0-9' _).log"; echo "== $1"; shift; "$@" > "$log" 2>&1; local rc=$?; tail -1 "$log"; [ $rc -eq 0 ] || status=1; }

echo "== Package"
unzip -tq "$ZIP" && echo "ZIP integrity OK"
CLEAN="$OUT/clean"; rm -rf "$CLEAN"; mkdir -p "$CLEAN"; (cd "$CLEAN" && unzip -q "$ZIP")
echo "files: $(find "$CLEAN" -type f | wc -l)"
for f in index.php .htaccess install/index.php README.md INSTALLATION-CHECKLIST.md CHANGELOG.md database/migrations/001_initial_schema.sql database/migrations/002_permissions_audit_integrity.php database/migrations/003_api_tokens.php api.php config/config.sample.php; do [ -f "$CLEAN/$f" ] || { echo "MISSING $f"; status=1; }; done
for f in config/config.php storage/installed.lock .env storage/backups uploads/branding; do [ -e "$CLEAN/$f" ] && { echo "UNEXPECTED $f"; status=1; }; done
find "$CLEAN" \( -name '*.key' -o -name 'release.*' -o -name '*.sql.gz' \) | grep . && { echo "UNEXPECTED key or dump"; status=1; }
grep -q "MOTO_VERSION = '1.4.0'" "$CLEAN/app/bootstrap.php" && echo "installer version 1.4.0" || { echo "WRONG VERSION"; status=1; }

echo "== Update package"
unzip -tq "$UPD" && echo "ZIP integrity OK"
php -r '
  define("MOTO_ROOT", $argv[2]); require $argv[2] . "/app/Services/Requirements.php"; require $argv[2] . "/app/Services/Updater.php"; require $argv[2] . "/app/Services/UpdateKeys.php";
  $z = new ZipArchive(); $z->open($argv[1]); $m = $z->getFromName("motosupply-update.json"); $sig = base64_decode(trim($z->getFromName("motosupply-update.sig")));
  $ok = false; foreach (App\Services\UpdateKeys::KEYS as $k) { $ok = $ok || sodium_crypto_sign_verify_detached($sig, $m, base64_decode($k)); }
  echo $ok ? "signature valid (release key)\n" : "SIGNATURE INVALID\n"; $bad = !$ok;
  $man = json_decode($m, true); echo "manifest version {$man["version"]}, " . count($man["files"]) . " files\n";
  foreach ($man["files"] as $p => $h) { if (!App\Services\Updater::allowedTarget($p) || hash("sha256", $z->getFromName($p)) !== $h) { echo "BAD ENTRY $p\n"; $bad = true; } }
  for ($i = 0; $i < $z->numFiles; $i++) { $n = $z->getNameIndex($i); $z->getExternalAttributesIndex($i, $os, $attr);
    if (!isset($man["files"][$n]) && !in_array($n, ["motosupply-update.json", "motosupply-update.sig", "UPDATE-README.md", "CHANGELOG.md"], true)) { echo "UNLISTED $n\n"; $bad = true; }
    if ((($attr >> 16) & 0002) !== 0) { echo "WORLD-WRITABLE $n\n"; $bad = true; } }
  foreach (["install/", "config/config.php", "storage/installed", "storage/backups", "uploads/products/", "uploads/branding/"] as $f) { for ($i = 0; $i < $z->numFiles; $i++) { if (str_starts_with($z->getNameIndex($i), $f)) { echo "FORBIDDEN {$z->getNameIndex($i)}\n"; $bad = true; } } }
  exit($bad ? 1 : 0);' "$UPD" "$HERE/src" && echo "update package contents OK" || status=1
unzip -p "$UPD" | grep -aqE 'motopass|Initial#Pass|Moto[$]hop|PRIVATE KEY' && { echo "UPDATE PACKAGE CONTAINS TEST CREDENTIALS OR A KEY"; status=1; }
find "$CLEAN" -name "*.log" -o -name ".git" -o -name "node_modules" -o -name "tests" | grep . && { echo "UNEXPECTED dev files"; status=1; }
bad=0; for f in $(find "$CLEAN" -name '*.php'); do php -l "$f" >/dev/null 2>&1 || { echo "SYNTAX $f"; bad=1; }; done; [ $bad -eq 0 ] && echo "php -l OK on $(find "$CLEAN" -name '*.php' | wc -l) files ($(php -r 'echo PHP_VERSION;'))" || status=1
# Every asset referenced by the views/layouts exists.
for a in $(grep -rhoE "asset\('([^']+)'\)" "$CLEAN/app" "$CLEAN/install" | sed -E "s/asset\('([^']+)'\)/\1/" | sort -u) js/pos.js; do [ -f "$CLEAN/assets/$a" ] || { echo "MISSING asset $a"; status=1; }; done && echo "assets OK"

echo "== Subfolder install on Apache (config in protected config/)"
W=/var/www/mototest/wiz; rm -rf "$W"; mkdir -p "$W"; (cd "$W" && unzip -q "$ZIP"); chown -R www-data:www-data "$W"
mkdir -p "$OUT/wiz-shots" "$OUT/wiz-app-shots"
run "installer wizard (subfolder)" node "$HERE/tests/installer_e2e.mjs" http://127.0.0.1:8090/wiz "$W" moto_wiz "$OUT/wiz-shots"
mysql -e "UPDATE moto_wiz.users SET must_change_password = 1"
MOTO_ADMIN_USER=shopowner run "application e2e (subfolder)" node "$HERE/tests/e2e.mjs" http://127.0.0.1:8090/wiz "$OUT/wiz-app-shots"
run "http security (subfolder)" "$HERE/tests/http_security.sh" http://127.0.0.1:8090/wiz shopowner 'Moto$hop2026'

echo "== Root install on Apache (config outside document root)"
rm -rf /srv/acct; mkdir -p /srv/acct/htdocs; (cd /srv/acct/htdocs && unzip -q "$ZIP"); chown -R www-data:www-data /srv/acct
mkdir -p "$OUT/root-shots" "$OUT/root-app-shots"
run "installer wizard (root)" node "$HERE/tests/installer_e2e.mjs" http://127.0.0.1:8091 /srv/acct/htdocs moto_acct "$OUT/root-shots"
grep -q "outside the public web folder" /srv/acct/htdocs/config/config.php && ls /srv/acct/motosupply-private/config-*.php >/dev/null && echo "config stored outside document root: yes" || { echo "config stored outside document root: NO"; status=1; }
mysql -e "UPDATE moto_acct.users SET must_change_password = 1"
MOTO_ADMIN_USER=shopowner run "application e2e (root)" node "$HERE/tests/e2e.mjs" http://127.0.0.1:8091 "$OUT/root-app-shots"
run "http security (root)" "$HERE/tests/http_security.sh" http://127.0.0.1:8091 shopowner 'Moto$hop2026'

echo "== 1.3 HTTP tests on a fresh install from the ZIP (roles on every route, CSRF, voids, branding, theme, cron URL)"
H=/var/www/mototest/h13; rm -rf "$H"; mkdir -p "$H"; (cd "$H" && unzip -q "$ZIP"); chown -R www-data:www-data "$H"
bash "$HERE/tests/fresh_install.sh" http://127.0.0.1:8090/h13 moto_h13 admin 'Moto!Counter2026' > /dev/null
run "http tests 1.3 (php 8.3)" php "$HERE/tests/http_v13.php" http://127.0.0.1:8090/h13 moto_h13 admin 'Moto!Counter2026' https://127.0.0.1:8443/h13 "$H"

echo "== Cashier desktop API (api.php) on a fresh install from the ZIP"
A=/var/www/mototest/api14; rm -rf "$A"; mkdir -p "$A"; (cd "$A" && unzip -q "$ZIP"); chown -R www-data:www-data "$A"
bash "$HERE/tests/fresh_install.sh" http://127.0.0.1:8090/api14 moto_api14 admin 'Moto!Counter2026' > /dev/null
run "cashier API (php 8.3)" php "$HERE/tests/api_test.php" http://127.0.0.1:8090/api14 moto_api14 https://127.0.0.1:8443/api14

echo "== Upgrade: real 1.2.0 release + MotoSupply-POS-Update.zip uploaded by hand, then the in-app updater"
run "upgrade 1.2.0 -> 1.4.0 (php 8.3)" "$HERE/tests/upgrade_test.sh" http://127.0.0.1:8090/up12 /var/www/mototest/up12

echo "== Service tests (PHP 8.3)"
run "service tests" php "$HERE/tests/run.php"
run "service tests 1.3" php "$HERE/tests/run_v13.php"
MOTO_TEST_DB=motosupply_upd run "updater tests" php "$HERE/tests/updater_test.php"

echo "== Folder preparation and HTTPS detection (as www-data)"
run "folders and https (php 8.3)" "$HERE/tests/requirements_setup.sh" /tmp/moto-reqtest
if docker ps --format '{{.Names}}' 2>/dev/null | grep -qx moto84; then
  MOTO_PHP84_IMAGE=moto-php84 run "folders and https (php 8.4)" "$HERE/tests/requirements_setup.sh" /tmp/moto-reqtest84
  run "service tests (php 8.4)" docker run --rm --network host -v "$HERE:/app:ro" -w /app -e MOTO_TEST_HOST=127.0.0.1 moto-php84 php tests/run.php
  # The PHP 8.4 image has no Python, so the SMTP test server runs on the host.
  SINK=/tmp/moto-sink84; rm -rf "$SINK"; mkdir -p "$SINK"
  openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj "/CN=127.0.0.1" -addext "subjectAltName=IP:127.0.0.1" -keyout "$SINK/key.pem" -out "$SINK/cert.pem" 2>/dev/null
  python3 "$HERE/tests/smtp_sink.py" 2994 "$SINK/mail" "$SINK/cert.pem" "$SINK/key.pem" > /dev/null & SINK_PID=$!; sleep 1
  run "service tests 1.3 (php 8.4)" docker run --rm --network host -v "$HERE:/app:ro" -v "$SINK:$SINK" -w /app -e MOTO_TEST_HOST=127.0.0.1 -e MOTO_TEST_SMTP_SINK="2994:$SINK" moto-php84 php tests/run_v13.php
  kill $SINK_PID 2>/dev/null
  if docker run --rm moto-php84 php -m | grep -qx zip; then
    run "updater tests (php 8.4)" docker run --rm --network host -v "$HERE:/app:ro" -w /app -e MOTO_TEST_HOST=127.0.0.1 -e MOTO_TEST_DB=motosupply_upd moto-php84 php tests/updater_test.php
  else
    echo "== updater tests (php 8.4): SKIPPED: this PHP 8.4 image has no zip extension (the updater then shows a clear notice and manual updates are used)"
  fi

  echo "== PHP 8.4 + Apache container: storage/logs and uploads/products uploaded unwritable (owned by another user)"
  rm -rf /srv/c84 /srv/c84s
  for d in /srv/c84/htdocs /srv/c84s/htdocs; do mkdir -p "$d"; (cd "$d" && unzip -q "$ZIP"); done
  chown -R 33:33 /srv/c84 /srv/c84s; chown -R 0:0 /srv/c84/htdocs/storage/logs /srv/c84/htdocs/uploads/products
  mkdir -p "$OUT/c84-wiz" "$OUT/c84-app" "$OUT/c84-https"
  MOTO_DB_HOST=127.0.0.1 run "installer wizard (php 8.4, http)" node "$HERE/tests/installer_e2e.mjs" http://127.0.0.1:8084 /srv/c84/htdocs moto_c84 "$OUT/c84-wiz"
  ls -d /srv/c84/htdocs/storage/.logs-unwritable-* /srv/c84/htdocs/uploads/.products-unwritable-* >/dev/null 2>&1 && echo "unwritable folders were recreated automatically: yes" || { echo "unwritable folders recreated: NO"; status=1; }
  mysql -e "UPDATE moto_c84.users SET must_change_password = 1"
  MOTO_ADMIN_USER=shopowner run "application e2e (php 8.4)" node "$HERE/tests/e2e.mjs" http://127.0.0.1:8084 "$OUT/c84-app"
  run "http security (php 8.4)" "$HERE/tests/http_security.sh" http://127.0.0.1:8084 shopowner 'Moto$hop2026'
  run "https production mode (php 8.4)" node "$HERE/tests/https_e2e.mjs" https://127.0.0.1:8444 http://127.0.0.1:8085 moto_c84s "$OUT/c84-https"
else
  echo "PHP 8.4 container 'moto84' not running: PHP 8.4 checks skipped"; status=1
fi

grep -hE "PHP (Fatal|Warning|Deprecated|Notice|Parse)" /tmp/claude-0/apache-error.log 2>/dev/null | tail -5
echo; [ $status -eq 0 ] && echo "RELEASE VERIFIED" || echo "RELEASE VERIFICATION FAILED"
exit $status
