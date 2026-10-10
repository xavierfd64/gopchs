#!/usr/bin/env bash
# In-app upgrade test from the real 1.3.0 release (development machine only):
#   1. install the released 1.3.0 ZIP (from git history) and create some data;
#   2. upload dist/MotoSupply-POS-Update.zip (signed with the release key) in 1.3.0's own
#      Settings → Updates page, exactly as a shop administrator would;
#   3. check that 1.3.0's updater accepts and installs it, that the database update runs, that
#      nothing is lost, and that the cashier API of the new version answers.
#
# Usage: tests/upgrade13_test.sh [base-url] [webroot]
set -uo pipefail
HERE="$(cd "$(dirname "$0")/.." && pwd)"
B="${1:-http://127.0.0.1:8090/up13}"; W="${2:-/var/www/mototest/up13}"; DB=moto_up13
PW='Moto!Counter2026'; pass=0; fail=0
NEW="$(grep -oP "const MOTO_VERSION = '\K[^']+" "$HERE/src/app/bootstrap.php")"
check() { if [ "$2" = "$3" ]; then echo "  PASS  $1"; pass=$((pass+1)); else echo "  FAIL  $1 (expected '$2', got '$3')"; fail=$((fail+1)); fi; }
q() { mysql -N -B "$DB" -e "$1"; }
CJ="$(mktemp)"; WORK="$(mktemp -d)"; trap 'rm -rf "$CJ" "$WORK"' EXIT
tok() { curl -s -c "$CJ" -b "$CJ" "$B/index.php?r=$1" | grep -oP 'name="_csrf" value="\K[^"]+' | head -1; }
login() { : > "$CJ"; local t; t=$(tok login); curl -s -o /dev/null -c "$CJ" -b "$CJ" -X POST --data-urlencode "_csrf=$t" -d username=admin --data-urlencode "password=$PW" "$B/index.php?r=login"; }

echo "== Install the released 1.3.0 ZIP"
git -C "$HERE" show 57b5be9:generated/motosupply-pos/dist/MotoSupply-POS-Installer.zip > "$WORK/v13.zip"
rm -rf "$W"; mkdir -p "$W"; (cd "$W" && unzip -q "$WORK/v13.zip"); chown -R www-data:www-data "$W"
check "1.3.0 files in place" "1" "$(grep -c "MOTO_VERSION = '1.3.0'" "$W/app/bootstrap.php")"
bash "$HERE/tests/fresh_install.sh" "$B" "$DB" admin "$PW" | tail -1

echo "== Data in 1.3.0"
login
for i in 1 2; do
  T=$(tok products.create)
  curl -s -o /dev/null -c "$CJ" -b "$CJ" -X POST --data-urlencode "_csrf=$T" -d "id=0&name=Keep item $i&sku=K13-$i&category=Engine Oil&unit=pc&cost_price=100&selling_price=150&stock_qty=20" "$B/index.php?r=products.save"
done
CSRF=$(curl -s -c "$CJ" -b "$CJ" "$B/index.php?r=pos" | grep -oP 'data-csrf="\K[^"]+')
P1=$(q "SELECT id FROM products WHERE sku='K13-1'")
curl -s -c "$CJ" -b "$CJ" -H 'Content-Type: application/json' -H "X-CSRF-Token: $CSRF" -X POST \
  -d "{\"items\":[{\"product_id\":$P1,\"quantity\":2}],\"discount_type\":\"none\",\"discount_value\":\"0\",\"tendered\":\"500\",\"client_token\":\"7a1c2a4e-1b2c-4d3e-8f4a-5b6c7d8e9f02\"}" \
  "$B/index.php?r=api.sales.checkout" | grep -q '"ok":true' && echo "  sale recorded in 1.3.0"
fingerprint() { q "SELECT CONCAT_WS('|', (SELECT GROUP_CONCAT(CONCAT(sku,':',stock_qty,':',selling_price) ORDER BY id) FROM products),
  (SELECT COUNT(*) FROM sales), (SELECT COUNT(*) FROM sale_items), (SELECT COUNT(*) FROM stock_movements),
  (SELECT GROUP_CONCAT(CONCAT(username,':',password_hash)) FROM users))" | md5sum | cut -c1-12; }
BEFORE=$(fingerprint); CONF=$(md5sum < "$W/config/config.php")

echo "== Upload $NEW through 1.3.0's Settings → Updates (release-signed package)"
T=$(tok updates)
curl -s -o /dev/null -b "$CJ" -c "$CJ" -F "_csrf=$T" -F "package=@$HERE/dist/MotoSupply-POS-Update.zip;type=application/zip" "$B/index.php?r=updates.upload"
PAGE=$(curl -s -b "$CJ" -c "$CJ" "$B/index.php?r=updates")
check "1.3.0's updater accepts the package" "1" "$(echo "$PAGE" | grep -c "Ready to install version $NEW")"
echo "$PAGE" | grep -oP 'alert-error[^>]*>.*?</span>' | sed 's/<[^>]*>//g' | head -2
UT=$(echo "$PAGE" | grep -oP 'name="token" value="\K[a-f0-9]{32}' | head -1); T=$(echo "$PAGE" | grep -oP 'name="_csrf" value="\K[^"]+' | head -1)
curl -s -o /dev/null -b "$CJ" -c "$CJ" -X POST --data-urlencode "_csrf=$T" -d "token=$UT&confirm=1" "$B/index.php?r=updates.apply"
PAGE=$(curl -s -b "$CJ" -c "$CJ" "$B/index.php?r=updates")
check "update finished" "1" "$(echo "$PAGE" | grep -c 'Update finished.')"
check "now running $NEW" "1" "$(echo "$PAGE" | grep -c "Installed version: <strong>$NEW")"
check "database migrated (api_tokens table)" "1" "$(q "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB' AND table_name='api_tokens'")"
check "schema at the latest migration" "$(ls "$HERE"/src/database/migrations/ | grep -cE '^[0-9]{3}_')" "$(q 'SELECT MAX(version) FROM schema_migrations')"
check "products, sales and users unchanged" "$BEFORE" "$(fingerprint)"
check "config.php unchanged" "$CONF" "$(md5sum < "$W/config/config.php")"
check "backup made by the updater" "2" "$(ls "$W"/storage/backups/update-*/ 2>/dev/null | grep -cE '^(database.sql.gz|files.zip)$')"
check "cashier API answers" "motosupply-pos" "$(curl -s "$B/index.php?api=ping" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["product"] ?? "";')"
check "cashier API reports the new version" "$NEW" "$(curl -s "$B/index.php?api=ping" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["version"] ?? "";')"
check "cashier sign-in works after the update" "200" "$(curl -s -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' -d "{\"username\":\"admin\",\"password\":\"$PW\"}" "$B/index.php?api=auth.login")"
check "API code is not reachable directly (403/404)" "blocked" "$(c=$(curl -s -o /dev/null -w '%{http_code}' "$B/app/api.php"); [ "$c" = 403 ] || [ "$c" = 404 ] && echo blocked || echo "$c")"
check "web app still works" "200" "$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" "$B/index.php?r=dashboard")"

echo; echo "$pass passed, $fail failed"
[ $fail -eq 0 ]
