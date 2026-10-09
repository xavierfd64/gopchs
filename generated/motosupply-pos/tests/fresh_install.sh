#!/usr/bin/env bash
# Drives the installation wizard over HTTP (curl) against an already-served web root.
# Usage: tests/fresh_install.sh <base-url> <db-name> <admin-user> <admin-password> [--force-change]
#   --force-change marks the admin as "must change password" so tests/e2e.mjs can test that flow.
set -euo pipefail
B="${1:?base url}"; DB="${2:?database}"; AU="${3:?admin user}"; AP="${4:?admin password}"
CJ="$(mktemp)"; trap 'rm -f "$CJ"' EXIT
tok() { curl -s -c "$CJ" -b "$CJ" "$B/install/index.php?step=$1" | grep -oP 'name="_csrf" value="\K[^"]+' | head -1; }
post() { local step=$1; shift; curl -s -o /dev/null -w "%{http_code} %{redirect_url}" -c "$CJ" -b "$CJ" -X POST "$B/install/index.php?step=$step" "$@"; }
mysql -e "DROP DATABASE IF EXISTS \`$DB\`; CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON \`$DB\`.* TO 'moto'@'localhost';"
curl -s -c "$CJ" -b "$CJ" -o /dev/null "$B/install/"
T=$(tok requirements); echo "requirements: $(post requirements --data-urlencode "_csrf=$T")"
T=$(tok database); echo "database:     $(post database --data-urlencode "_csrf=$T" -d action=continue -d db_host=localhost -d db_port=3306 -d "db_name=$DB" -d db_user=moto -d db_pass=motopass)"
T=$(tok shop); echo "shop:         $(post shop --data-urlencode "_csrf=$T" -d "shop_name=MotoSupply Shop" -d timezone=Asia/Manila -d currency_code=PHP -d "shop_address=128 Rizal Avenue, Quezon City" -d "shop_phone=+63 917 555 0182")"
T=$(tok admin); echo "admin:        $(post admin --data-urlencode "_csrf=$T" -d "admin_user=$AU" -d "admin_name=Jamie Dela Cruz" --data-urlencode "admin_pass=$AP" --data-urlencode "admin_pass2=$AP")"
T=$(tok install); echo "install:      $(post install --data-urlencode "_csrf=$T")"
curl -s -c "$CJ" -b "$CJ" "$B/install/index.php?step=done" | grep -o "Installation completed successfully"
if [ "${5:-}" = "--force-change" ]; then mysql -e "UPDATE \`$DB\`.users SET must_change_password = 1"; fi
