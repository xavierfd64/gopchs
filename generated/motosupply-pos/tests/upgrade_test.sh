#!/usr/bin/env bash
# Upgrade test (development machine only): installs the real 1.2.0 release, creates data (including
# MyISAM tables and negative stock, as damaged 1.2 sites may have), uploads MotoSupply-POS-Update.zip
# by hand, and checks the automatic backup + migration and that nothing was lost. Then tests the
# in-app updater over HTTP with a newer test package signed by a throwaway key.
#
# Usage: tests/upgrade_test.sh [base-url] [webroot]   (defaults: http://127.0.0.1:8090/up12 /var/www/mototest/up12)
set -uo pipefail
HERE="$(cd "$(dirname "$0")/.." && pwd)"
B="${1:-http://127.0.0.1:8090/up12}"; W="${2:-/var/www/mototest/up12}"; DB=moto_up12
PW='Moto!Counter2026'; pass=0; fail=0
CUR="$(grep -oP "const MOTO_VERSION = '\K[^']+" "$HERE/src/app/bootstrap.php")"           # version in the update package
NEXT="$(echo "$CUR" | awk -F. '{print $1"."$2"."$3+1}')"; OLDER="$(echo "$CUR" | awk -F. '{print $1"."$2-1".9"}')"
check() { if [ "$2" = "$3" ]; then echo "  PASS  $1"; pass=$((pass+1)); else echo "  FAIL  $1 (expected '$2', got '$3')"; fail=$((fail+1)); fi; }
q() { mysql -N -B "$DB" -e "$1"; }
CJ="$(mktemp)"; WORK="$(mktemp -d)"; trap 'rm -rf "$CJ" "$WORK"' EXIT
tok() { curl -s -c "$CJ" -b "$CJ" "$B/index.php?r=$1" | grep -oP 'name="_csrf" value="\K[^"]+' | head -1; }
login() { : > "$CJ"; local t; t=$(tok login); curl -s -o /dev/null -c "$CJ" -b "$CJ" -X POST --data-urlencode "_csrf=$t" -d username=admin --data-urlencode "password=$PW" "$B/index.php?r=login"; }

echo "== Install 1.2.0 from the released ZIP"
git -C "$HERE" show 6d0f796:generated/motosupply-pos/dist/MotoSupply-POS-Installer.zip > "$WORK/v12.zip"
rm -rf "$W"; mkdir -p "$W"; (cd "$W" && unzip -q "$WORK/v12.zip"); chown -R www-data:www-data "$W"
grep -q "MOTO_VERSION = '1.2.0'" "$W/app/bootstrap.php" && echo "  1.2.0 files in place"
bash "$HERE/tests/fresh_install.sh" "$B" "$DB" admin "$PW" | tail -1

echo "== Create data in 1.2 (product form + POS API), then simulate a damaged 1.2 database"
login
for i in 1 2 3; do
  T=$(tok products.create)
  curl -s -o /dev/null -c "$CJ" -b "$CJ" -X POST --data-urlencode "_csrf=$T" -d "id=0&name=Upgrade item $i&sku=UPG-$i&category=Engine Oil&unit=pc&cost_price=100&selling_price=150&stock_qty=20" "$B/index.php?r=products.save"
done
CSRF=$(curl -s -c "$CJ" -b "$CJ" "$B/index.php?r=pos" | grep -oP 'data-csrf="\K[^"]+')
P1=$(q "SELECT id FROM products WHERE sku='UPG-1'")
curl -s -c "$CJ" -b "$CJ" -H 'Content-Type: application/json' -H "X-CSRF-Token: $CSRF" -X POST \
  -d "{\"items\":[{\"product_id\":$P1,\"quantity\":3}],\"discount_type\":\"none\",\"discount_value\":\"0\",\"tendered\":\"1000\",\"client_token\":\"6f1c2a4e-1b2c-4d3e-8f4a-5b6c7d8e9f01\"}" \
  "$B/index.php?r=api.sales.checkout" | grep -q '"ok":true' && echo "  sale recorded in 1.2"
q "ALTER TABLE settings ENGINE=MyISAM; ALTER TABLE login_attempts ENGINE=MyISAM; UPDATE products SET stock_qty = -2 WHERE sku = 'UPG-3';"
fingerprint() { q "SELECT CONCAT_WS('|', (SELECT GROUP_CONCAT(CONCAT(sku,':',stock_qty,':',selling_price) ORDER BY id) FROM products),
  (SELECT COUNT(*) FROM sales), (SELECT COUNT(*) FROM sale_items), (SELECT COUNT(*) FROM stock_movements),
  (SELECT GROUP_CONCAT(CONCAT(username,':',password_hash)) FROM users), (SELECT setting_value FROM settings WHERE setting_key='shop_name'))" | md5sum | cut -c1-12; }
BEFORE=$(fingerprint); CONF_BEFORE=$(md5sum < "$W/config/config.php")
check "1.2 schema version" "1" "$(q 'SELECT MAX(version) FROM schema_migrations')"

echo "== Upload MotoSupply-POS-Update.zip by hand (extract over the site)"
(cd "$W" && unzip -oq "$HERE/dist/MotoSupply-POS-Update.zip"); chown -R www-data:www-data "$W"
check "config.php untouched by the update files" "$CONF_BEFORE" "$(md5sum < "$W/config/config.php")"
check "extracted files are not world-writable" "0" "$(find "$W" -type f -perm -o+w | wc -l | tr -d ' ')"
sleep 3 # PHP opcache re-checks changed files every 2 s (opcache.revalidate_freq); a person takes longer than that
check "first visit after upload" "200" "$(curl -s -o /dev/null -w '%{http_code}' "$B/index.php?r=login")"
check "schema migrated to the latest version" "$(ls "$HERE"/src/database/migrations/ | grep -cE '^[0-9]{3}_')" "$(q 'SELECT MAX(version) FROM schema_migrations')"
check "database backed up before migrating" "1" "$(ls "$W"/storage/backups/*before-migration*.sql* 2>/dev/null | wc -l | tr -d ' ')"
check "backup is a complete dump" "1" "$(zcat "$W"/storage/backups/*before-migration*.sql.gz | grep -c 'CREATE TABLE `products`')"
check "all tables InnoDB" "0" "$(q "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB' AND engine <> 'InnoDB'")"
check "business data, passwords and settings unchanged" "$BEFORE" "$(fingerprint)"
check "existing admin became Administrator" "administrator" "$(q "SELECT r.slug FROM users u JOIN roles r ON r.id=u.role_id WHERE u.username='admin'")"
check "negative stock kept for review (guard not forced)" "int(11)" "$(q "SELECT column_type FROM information_schema.columns WHERE table_schema='$DB' AND table_name='products' AND column_name='stock_qty'")"
login
check "admin signs in with the same password" "200" "$(curl -s -o /dev/null -w '%{http_code}' -b "$CJ" "$B/index.php?r=dashboard")"
curl -s -b "$CJ" "$B/index.php?r=inventory.integrity" > "$WORK/integrity.html"
check "integrity page lists the negative product" "1" "$(grep -c 'UPG-3' "$WORK/integrity.html" | awk '{print ($1>0)}')"
check "version shown is $CUR" "1" "$(curl -s -b "$CJ" "$B/index.php?r=updates" | grep -c "Installed version: <strong>$CUR")"
check "old installer stays locked (no error)" "1" "$(curl -s "$B/install/" | grep -ciE 'already installed|installation is locked|already been installed' | awk '{print ($1>0)}')"
for f in motosupply-update.json motosupply-update.sig UPDATE-README.md CHANGELOG.md; do
  check "$f is not served" "403" "$(curl -s -o /dev/null -w '%{http_code}' "$B/$f")"
done
check "no PHP errors in the app log" "0" "$(grep -hc 'ERROR' "$W"/storage/logs/*.log 2>/dev/null | awk '{s+=$1} END {print s+0}')"

echo "== In-app updater over HTTP (test package $NEXT signed with a throwaway key)"
php -r '$k = sodium_crypto_sign_keypair(); file_put_contents($argv[1], base64_encode(sodium_crypto_sign_secretkey($k))); echo base64_encode(sodium_crypto_sign_publickey($k));' "$WORK/test.key" > "$WORK/test.pub"
php "$HERE/tools/build-update.php" --key="$WORK/test.key" --src="$HERE/src" --out="$WORK/update-next.zip" --version=$NEXT >/dev/null
sed -i -E "s/^return (\[|array \()/return \1\n  'update_trusted_keys' => ['$(cat "$WORK/test.pub" | sed 's/[\/&]/\\&/g')'],/" "$W/config/config.php"
check "test key trusted by this site only" "1" "$(grep -c update_trusted_keys "$W/config/config.php")"
sleep 3 # opcache revalidation of the edited config file
T=$(tok updates)
curl -s -o /dev/null -b "$CJ" -c "$CJ" -F "_csrf=$T" -F "package=@$WORK/update-next.zip;type=application/zip" "$B/index.php?r=updates.upload"
PAGE=$(curl -s -b "$CJ" -c "$CJ" "$B/index.php?r=updates")
check "package verified and summarised" "1" "$(echo "$PAGE" | grep -c "Ready to install version $NEXT")"
UT=$(echo "$PAGE" | grep -oP 'name="token" value="\K[a-f0-9]{32}' | head -1); T=$(echo "$PAGE" | grep -oP 'name="_csrf" value="\K[^"]+' | head -1)
curl -s -o /dev/null -b "$CJ" -c "$CJ" -X POST --data-urlencode "_csrf=$T" -d "token=$UT&confirm=1" "$B/index.php?r=updates.apply"
PAGE=$(curl -s -b "$CJ" -c "$CJ" "$B/index.php?r=updates")
check "update finished" "1" "$(echo "$PAGE" | grep -c 'Update finished.')"
check "now running $NEXT" "1" "$(echo "$PAGE" | grep -c "Installed version: <strong>$NEXT")"
check "data unchanged by the in-app update" "$BEFORE" "$(fingerprint)"
check "backup folder with database and files" "2" "$(ls "$W"/storage/backups/update-*/ | grep -cE '^(database.sql.gz|files.zip)$')"
check "maintenance mode ended" "0" "$(ls "$W/storage/maintenance.flag" 2>/dev/null | wc -l | tr -d ' ')"
HID=$(q "SELECT id FROM update_history ORDER BY id DESC LIMIT 1")
T=$(echo "$PAGE" | grep -oP 'name="_csrf" value="\K[^"]+' | head -1)
curl -s -o /dev/null -b "$CJ" -c "$CJ" -X POST --data-urlencode "_csrf=$T" -d "id=$HID&confirm=1" "$B/index.php?r=updates.rollback"
check "previous files restored ($CUR)" "1" "$(curl -s -b "$CJ" "$B/index.php?r=updates" | grep -c "Installed version: <strong>$CUR")"
check "data unchanged after restore" "$BEFORE" "$(fingerprint)"
check "update and restore recorded in the audit log" "1" "$(q "SELECT COUNT(DISTINCT action) >= 2 FROM audit_log WHERE action LIKE 'system.update%'")"
check "downgrade package refused" "1" "$(php "$HERE/tools/build-update.php" --key="$WORK/test.key" --src="$HERE/src" --out="$WORK/old.zip" --version=$OLDER >/dev/null; T=$(tok updates); curl -s -o /dev/null -b "$CJ" -c "$CJ" -F "_csrf=$T" -F "package=@$WORK/old.zip;type=application/zip" "$B/index.php?r=updates.upload"; curl -s -b "$CJ" "$B/index.php?r=updates" | grep -c 'Downgrades are not allowed')"

echo; echo "$pass passed, $fail failed"
[ $fail -eq 0 ]
