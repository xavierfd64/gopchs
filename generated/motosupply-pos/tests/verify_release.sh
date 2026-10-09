#!/usr/bin/env bash
# Verifies the actual release ZIP on clean Apache installs (development machine only).
# Layout used: Apache vhost :8090 serving /var/www/mototest (subfolder install "wiz"),
#              Apache vhost :8091 serving /srv/acct/htdocs (root install, parent folder writable).
set -uo pipefail
HERE="$(cd "$(dirname "$0")/.." && pwd)"
ZIP="$HERE/dist/MotoSupply-POS-Installer.zip"
OUT="${1:-/tmp/motosupply-verify}"; mkdir -p "$OUT"
export PLAYWRIGHT_MODULE="${PLAYWRIGHT_MODULE:-/opt/node-tools/node_modules/playwright}"
status=0
run() { local log="$OUT/$(echo "$1" | tr -c 'a-z0-9' _).log"; echo "== $1"; shift; "$@" > "$log" 2>&1; local rc=$?; tail -1 "$log"; [ $rc -eq 0 ] || status=1; }

echo "== Package"
unzip -tq "$ZIP" && echo "ZIP integrity OK"
CLEAN="$OUT/clean"; rm -rf "$CLEAN"; mkdir -p "$CLEAN"; (cd "$CLEAN" && unzip -q "$ZIP")
echo "files: $(find "$CLEAN" -type f | wc -l)"
for f in index.php .htaccess install/index.php README.md INSTALLATION-CHECKLIST.md database/migrations/001_initial_schema.sql config/config.sample.php; do [ -f "$CLEAN/$f" ] || { echo "MISSING $f"; status=1; }; done
for f in config/config.php storage/installed.lock .env; do [ -e "$CLEAN/$f" ] && { echo "UNEXPECTED $f"; status=1; }; done
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

echo "== Service tests (PHP 8.3)"
run "service tests" php "$HERE/tests/run.php"

echo "== Folder preparation and HTTPS detection (as www-data)"
run "folders and https (php 8.3)" "$HERE/tests/requirements_setup.sh" /tmp/moto-reqtest
if docker ps --format '{{.Names}}' 2>/dev/null | grep -qx moto84; then
  MOTO_PHP84_IMAGE=moto-php84 run "folders and https (php 8.4)" "$HERE/tests/requirements_setup.sh" /tmp/moto-reqtest84
  run "service tests (php 8.4)" docker run --rm --network host -v "$HERE:/app:ro" -w /app -e MOTO_TEST_HOST=127.0.0.1 moto-php84 php tests/run.php

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
