#!/usr/bin/env bash
# Copies src/ to a scratch web root, resets the database and installs via the browser installer
# (HTTP POSTs), then starts PHP's built-in server. Usage: tests/fresh_install.sh <webroot> <port>
set -euo pipefail
ROOT="${1:?webroot}"; PORT="${2:-8080}"
HERE="$(cd "$(dirname "$0")/.." && pwd)"
DB="${MOTO_E2E_DB:-motosupply}"
pkill -f "php -S 127.0.0.1:$PORT" 2>/dev/null || true
rm -rf "$ROOT" && cp -r "$HERE/src" "$ROOT"
mysql -e "DROP DATABASE IF EXISTS \`$DB\`; CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
(cd "$ROOT" && nohup php -S 127.0.0.1:$PORT > "$ROOT/../phpserver-$PORT.log" 2>&1 &)
sleep 1
CJ="$(mktemp)"
B="http://127.0.0.1:$PORT"
TOK=$(curl -s -c "$CJ" -b "$CJ" "$B/install/" | grep -oP 'name="_csrf" value="\K[^"]+')
curl -s -c "$CJ" -b "$CJ" -X POST "$B/install/" --data-urlencode "_csrf=$TOK" \
  -d db_host=localhost -d db_port=3306 -d "db_name=$DB" -d db_user=moto -d db_pass=motopass \
  -d "shop_name=MotoSupply Shop" -d timezone=Asia/Manila -d admin_user=admin \
  -d "admin_name=Jamie Dela Cruz" -d admin_pass=admin -d admin_pass2=admin | grep -o "Installation complete"
rm -f "$CJ"
